<?php
/**
 * Email Generator — ATW Request: tickets and merchants (typed, with suggestions from
 * your past ATW requests), schedule, field engineers and address; the page builds the
 * recipients, subject and body to paste into Gmail.
 * Nothing is sent. Copying saves it to My Requests (POST, JSON).
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/email_generator.php';
$user = require_role('field_engineer', 'admin');

$pageTitle  = 'ATW Request';
$activePage = 'atw-request';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $respond = static function (array $data, int $status = 200): void {
        http_response_code($status);
        echo json_encode($data);
        exit;
    };

    if (!csrf_verify()) {
        $respond(['errors' => ['Your session expired. Please reload the page.']], 403);
    }
    if (($_POST['action'] ?? '') !== 'save') {
        $respond(['errors' => ['Unknown action.']], 400);
    }

    $clean    = static fn($v): string => trim(preg_replace('/\s+/', ' ', (string) $v));
    $isDate   = static function (string $v): bool {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        return $d !== false && $d->format('Y-m-d') === $v;
    };
    $errors   = [];
    $override = !empty($_POST['ticket_override']);

    // Merchants: [{ticket, name, address}, ...]
    $merchants = [];
    $posted    = json_decode((string) ($_POST['merchants'] ?? ''), true);
    if (!is_array($posted) || !$posted) {
        $errors[] = 'Add at least one merchant.';
    } elseif (count($posted) > 20) {
        $errors[] = 'Up to 20 merchants per email.';
    } else {
        foreach (array_values($posted) as $i => $m) {
            $n = $i + 1;
            $m = [
                'ticket'  => strtoupper(preg_replace('/\s+/', '', (string) ($m['ticket'] ?? ''))),
                'name'    => $clean($m['name'] ?? ''),
                'address' => $clean($m['address'] ?? ''),
            ];
            if ($m['ticket'] === '' || strlen($m['ticket']) > 30) {
                $errors[] = "Merchant $n: the ticket number is required (up to 30 characters).";
            } elseif (!atw_ticket_valid($m['ticket']) && !$override) {
                $errors[] = "Merchant $n: ticket {$m['ticket']} should look like YYMMDD-XXXX-NNNN (e.g. 261006-BPUL-0019). Tick \"Use the ticket numbers anyway\" to keep it.";
            }
            if ($m['name'] === '' || mb_strlen($m['name']) > 150) {
                $errors[] = "Merchant $n: the merchant name is required (up to 150 characters).";
            }
            if (mb_strlen($m['address']) > 255) {
                $errors[] = "Merchant $n: the address must be at most 255 characters.";
            }
            $merchants[] = $m;
        }
    }

    $from = (string) ($_POST['date_from'] ?? '');
    $to   = (string) ($_POST['date_to'] ?? '');
    if (!$isDate($from) || !$isDate($to)) {
        $errors[] = 'Please pick the scheduled dates.';
    } elseif ($to < $from) {
        $errors[] = 'Date To must be on or after Date From.';
    }

    $fes = json_decode((string) ($_POST['fe_names'] ?? ''), true);
    $fes = is_array($fes) ? array_values(array_unique(array_filter(array_map($clean, $fes), 'strlen'))) : [];
    if (!$fes) {
        $errors[] = 'Choose at least one field engineer.';
    } elseif (count($fes) > 20 || max(array_map('mb_strlen', $fes)) > 100) {
        $errors[] = 'Up to 20 field engineers, each name up to 100 characters.';
    } elseif (mb_strlen(implode(' , ', $fes)) > 500) {
        $errors[] = 'The field engineer names are too long for one email.';
    }

    $address = $clean($_POST['address'] ?? '');
    if (mb_strlen($address) > 255) {
        $errors[] = 'Address must be at most 255 characters.';
    }

    [$recipients, $rcptErrors] = email_recipients_from_post((string) ($_POST['recipients'] ?? '[]'));
    $errors = array_merge($errors, $rcptErrors);
    if ($errors) {
        $respond(['errors' => $errors], 422);
    }

    $email = atw_email_build([
        'merchants' => $merchants,
        'date_from' => $from,
        'date_to'   => $to,
        'fe_names'  => $fes,
        'address'   => $address,
        'name'      => email_person_name($user),
        'area'      => (string) $user['area'],
    ]);

    // Copying the recipients, subject and body of one email saves it once.
    $stmt = db()->prepare(
        'SELECT id FROM atw_requests WHERE user_id = ? AND body = ? AND created_at >= NOW() - INTERVAL 1 DAY ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$user['user_id'], $email['body']]);
    $id    = (int) $stmt->fetchColumn();
    $saved = false;
    if ($id) {
        db()->prepare('UPDATE atw_requests SET recipients = ?, subject = ? WHERE id = ?')
            ->execute([$recipients, mb_substr($email['subject'], 0, 255), $id]);
    } else {
        db()->prepare(
            'INSERT INTO atw_requests (user_id, ticket_no, merchant, merchants, date_from, date_to, fe_names, address, recipients, subject, body)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $user['user_id'], $merchants[0]['ticket'], $merchants[0]['name'], json_encode($merchants),
            $from, $to, implode(' , ', $fes), $address === '' ? null : $address,
            $recipients, mb_substr($email['subject'], 0, 255), $email['body'],
        ]);
        $id    = (int) db()->lastInsertId();
        $saved = true;
    }

    $respond(['ok' => true, 'id' => $id, 'saved' => $saved, 'recipients' => $recipients] + $email);
}

// Merchant names and addresses used in this user's past requests.
$suggestions = email_history_suggestions_for((int) $user['user_id']);

// Field engineers to choose from: approved users, the signed-in user first.
$people = db()->query(
    "SELECT user_id, role, first_name, middle_name, last_name, field_engineer_id FROM users
     WHERE status = 'approved' ORDER BY last_name, first_name"
)->fetchAll();
usort($people, fn($a, $b) => ((int) $b['user_id'] === (int) $user['user_id']) <=> ((int) $a['user_id'] === (int) $user['user_id']));
$myName = email_person_name($user);

$stmt = db()->prepare('SELECT address FROM locations WHERE name = ?');
$stmt->execute([$user['location']]);
$myAddress = (string) $stmt->fetchColumn();

$recipients = email_recipients_for('ATW');
$today      = date('Y-m-d');

require __DIR__ . '/includes/head.php';
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/sidebar.php';
?>

  <main id="main" class="main">

    <div class="pagetitle">
      <h1><?= e($pageTitle) ?></h1>
      <nav>
        <ol class="breadcrumb">
          <li class="breadcrumb-item"><a href="index.php">Home</a></li>
          <li class="breadcrumb-item">Email Generator</li>
          <li class="breadcrumb-item active"><?= e($pageTitle) ?></li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

    <section class="section">
      <div class="row">
        <div class="col-lg-7">
          <form class="card" id="egForm" novalidate>
            <div class="card-body">
              <h5 class="card-title">ATW Details</h5>
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="save">
              <div class="alert alert-danger d-none" id="egErrors" role="alert"></div>

              <div id="atwMerchants"></div>
              <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                <button type="button" class="btn btn-sm btn-outline-primary" id="atwAddMerchant"><i class="bi bi-plus-lg"></i> Add another merchant</button>
                <div class="form-check d-none mb-0" id="atwOverrideWrap">
                  <input class="form-check-input" type="checkbox" name="ticket_override" value="1" id="atwOverride">
                  <label class="form-check-label small" for="atwOverride">Use the ticket numbers anyway</label>
                </div>
              </div>

              <div class="row g-3">
                <div class="col-6">
                  <label for="atwFrom" class="form-label">Date From</label>
                  <input type="date" name="date_from" id="atwFrom" class="form-control" value="<?= e($today) ?>" required>
                </div>
                <div class="col-6">
                  <label for="atwTo" class="form-label">Date To</label>
                  <input type="date" name="date_to" id="atwTo" class="form-control" value="<?= e($today) ?>" min="<?= e($today) ?>" required>
                  <div class="invalid-feedback">Date To must be on or after Date From.</div>
                </div>

                <div class="col-12">
                  <label for="atwFes" class="form-label">FE Names</label>
                  <select id="atwFes" multiple placeholder="Choose field engineers…" autocomplete="off">
<?php foreach ($people as $p):
    $pName = email_person_name($p);
?>
                    <option value="<?= e($pName) ?>" <?= (int) $p['user_id'] === (int) $user['user_id'] ? 'selected' : '' ?>><?= e($pName) ?><?= $p['field_engineer_id'] ? ' (' . e($p['field_engineer_id']) . ')' : '' ?></option>
<?php endforeach; ?>
                  </select>
                  <div class="form-text">Shown as "Name , Name , Name". Type a name to add someone who isn't in the list.</div>
                </div>

                <div class="col-12">
                  <label for="atwAddress" class="form-label">Address</label>
                  <input type="text" name="address" id="atwAddress" class="form-control" maxlength="255" value="<?= e($myAddress) ?>" placeholder="e.g. C/O 3F BDO PIONEER, Pioneer Ave, General Santos City">
                  <div class="form-text"><?= $myAddress !== '' ? 'From your location (' . e($user['location']) . ').' : 'Your location (' . e($user['location']) . ') has no address yet' . (is_admin($user) ? ' — add it on <a href="locations.php">Locations</a>.' : ' — ask an admin to add it.') ?></div>
                </div>

                <div class="col-12">
                  <div class="bg-light rounded p-2 small">
                    <span class="text-muted">Sign-off from your profile:</span>
                    <?= e($myName) ?> - <?= $user['area'] ? e(mb_strtoupper($user['area'])) . ' FE' : 'FE <span class="text-danger">(no area)</span>' ?>
                    <a href="#" class="ms-1" data-bs-toggle="modal" data-bs-target="#profileModal">Edit profile</a>
                  </div>
                </div>
              </div>
            </div>
          </form>
        </div>

        <div class="col-lg-5">
<?php require __DIR__ . '/includes/email_output.php'; ?>
        </div>
      </div>
    </section>

  </main><!-- End #main -->

  <template id="atwMerchantTemplate">
    <div class="border rounded p-2 p-sm-3 mb-2 atw-merchant">
      <div class="d-flex align-items-center mb-2">
        <span class="fw-semibold small atw-merchant-title">Merchant</span>
        <button type="button" class="btn btn-sm btn-link text-danger ms-auto p-0 atw-remove"><i class="bi bi-x-lg"></i> Remove</button>
      </div>
      <div class="row g-2">
        <div class="col-md-5">
          <label class="form-label small mb-1">Ticket No</label>
          <input type="text" class="form-control font-monospace text-uppercase atw-ticket" maxlength="30" placeholder="YYMMDD-XXXX-NNNN" autocomplete="off">
          <div class="invalid-feedback">Expected format YYMMDD-XXXX-NNNN, e.g. 261006-BPUL-0019.</div>
        </div>
        <div class="col-md-7">
          <label class="form-label small mb-1">Merchant Name</label>
          <input type="text" class="form-control atw-name" maxlength="150" placeholder="e.g. COLUMBIA COMPUTER CENT" list="atwMerchantList" autocomplete="off">
        </div>
        <div class="col-12">
          <label class="form-label small mb-1">Merchant Address</label>
          <input type="text" class="form-control atw-address" maxlength="255" placeholder="e.g. SM CITY SANTIAGO BLVD COR">
        </div>
      </div>
    </div>
  </template>
  <datalist id="atwMerchantList"><?php foreach ($suggestions['merchants'] as $v): ?><option value="<?= e($v) ?>"></option><?php endforeach; ?></datalist>

  <link href="<?= asset('assets/vendor/tom-select/tom-select.bootstrap5.min.css') ?>" rel="stylesheet">
  <script src="<?= asset('assets/vendor/tom-select/tom-select.complete.min.js') ?>"></script>
  <script src="<?= asset('assets/js/email-generator.js') ?>"></script>
  <script>
    (function () {
      var EG = window.EmailGen;
      var addresses = <?= json_encode((object) $suggestions['addresses']) ?>;   // lower-case merchant name -> last address used
      var me = <?= json_encode(['name' => $myName, 'area' => (string) $user['area']]) ?>;
      var TICKET_PATTERN = /^\d{6}-[A-Z]{2,6}-\d{4}$/;
      var MAX_MERCHANTS = 20;

      var form = document.getElementById("egForm");
      var errors = document.getElementById("egErrors");
      var holder = document.getElementById("atwMerchants");
      var override = document.getElementById("atwOverride");
      var current = null;

      function $(id) { return document.getElementById(id); }

      var recipients = EG.RecipientsBox($("egRecipients"), {
        recipients: <?= json_encode($recipients) ?>, group: "ATW", isAdmin: <?= json_encode(is_admin($user)) ?>,
        csrf: <?= json_encode(csrf_token()) ?>, endpoint: "email_recipients.php"
      });

      var fes = new TomSelect("#atwFes", {
        plugins: ["remove_button", "drag_drop"],
        create: true,
        persist: false,
        onChange: render
      });

      /* ---------- Merchant blocks ---------- */

      function addMerchant(focus) {
        if (holder.children.length >= MAX_MERCHANTS) { EG.toast("Up to " + MAX_MERCHANTS + " merchants per email.", "warning"); return; }
        var block = $("atwMerchantTemplate").content.firstElementChild.cloneNode(true);
        holder.appendChild(block);
        var name = block.querySelector(".atw-name");
        var address = block.querySelector(".atw-address");
        // A merchant used before fills its last address when the address is still empty.
        name.addEventListener("change", function () {
          var known = addresses[name.value.trim().toLowerCase()];
          if (known && !address.value.trim()) { address.value = known; render(); }
        });
        block.querySelector(".atw-remove").addEventListener("click", function () { block.remove(); renumber(); render(); });
        renumber();
        render();
        if (focus) { block.querySelector(".atw-ticket").focus(); }
      }

      function renumber() {
        var blocks = holder.querySelectorAll(".atw-merchant");
        blocks.forEach(function (b, i) {
          b.querySelector(".atw-merchant-title").textContent = "Merchant " + (i + 1);
          b.querySelector(".atw-remove").classList.toggle("d-none", blocks.length === 1);
        });
      }

      function merchants() {
        return Array.prototype.map.call(holder.querySelectorAll(".atw-merchant"), function (b) {
          var ticket = b.querySelector(".atw-ticket");
          return { ticket: ticket.value.replace(/\s+/g, "").toUpperCase(), name: b.querySelector(".atw-name").value, address: b.querySelector(".atw-address").value, input: ticket };
        });
      }

      /* ---------- Preview ---------- */

      function render() {
        var list = merchants();
        var bad = false;
        list.forEach(function (m) {
          var wrong = m.ticket !== "" && !TICKET_PATTERN.test(m.ticket);
          bad = bad || wrong;
          m.input.classList.toggle("is-invalid", wrong && !override.checked);
        });
        $("atwOverrideWrap").classList.toggle("d-none", !bad);
        $("atwTo").min = $("atwFrom").value;
        $("atwTo").classList.toggle("is-invalid", $("atwFrom").value !== "" && $("atwTo").value !== "" && $("atwTo").value < $("atwFrom").value);

        current = EG.atwBuild({
          merchants: list, date_from: $("atwFrom").value, date_to: $("atwTo").value,
          fe_names: fes ? fes.getValue() : [], address: $("atwAddress").value, name: me.name, area: me.area
        });
        $("egPreview").textContent = current.body;
        $("egSubject").textContent = current.subject;
      }

      // Same checks as the server, so nothing incomplete is copied or saved.
      function problems() {
        var list = [];
        merchants().forEach(function (m, i) {
          var n = i + 1;
          if (m.ticket === "") { list.push("Merchant " + n + ": the ticket number is required."); }
          else if (!TICKET_PATTERN.test(m.ticket) && !override.checked) { list.push("Merchant " + n + ": ticket " + m.ticket + ' should look like YYMMDD-XXXX-NNNN (e.g. 261006-BPUL-0019). Tick "Use the ticket numbers anyway" to keep it.'); }
          if (m.name.trim() === "") { list.push("Merchant " + n + ": the merchant name is required."); }
        });
        if (!$("atwFrom").value || !$("atwTo").value) { list.push("Please pick the scheduled dates."); }
        else if ($("atwTo").value < $("atwFrom").value) { list.push("Date To must be on or after Date From."); }
        if (!fes.getValue().length) { list.push("Choose at least one field engineer."); }
        return list;
      }

      function showErrors(list) {
        errors.innerHTML = "";
        if (!list.length) { errors.classList.add("d-none"); return; }
        var ul = document.createElement("ul");
        ul.className = "mb-0";
        list.forEach(function (text) { var li = document.createElement("li"); li.textContent = text; ul.appendChild(li); });
        errors.appendChild(ul);
        errors.classList.remove("d-none");
        errors.scrollIntoView({ block: "nearest", behavior: "smooth" });
      }

      function save() {
        render();
        var body = new FormData(form);
        body.set("merchants", JSON.stringify(merchants().map(function (m) { return { ticket: m.ticket, name: m.name, address: m.address }; })));
        body.set("fe_names", JSON.stringify(fes.getValue()));
        body.set("recipients", JSON.stringify(recipients.list()));
        return fetch("atw_request.php", { method: "POST", body: body })
          .then(function (res) { return res.json().catch(function () { return { errors: ["Unexpected server response (" + res.status + ")."] }; }); })
          .then(function (data) {
            if (data.errors) { throw data.errors; }
            if (data.body !== current.body && window.console) { console.warn("ATW Request: saved text differs from the preview", data.body); }
            var status = $("egStatus");
            status.textContent = (data.saved ? "Saved to " : "Updated in ");
            var link = document.createElement("a");
            link.href = "my_requests.php?type=atw";
            link.textContent = "My Requests";
            status.append(link, ". Paste into Gmail — nothing is sent from here.");
          })
          .catch(function (err) { showErrors(Array.isArray(err) ? err : ["Could not save. Please check your connection and try again."]); });
      }

      function copyAndSave(text, what, needsForm) {
        var list = problems();
        if (needsForm) {
          showErrors(list);
          if (list.length) { return; }
        }
        render();
        EG.copyWithToast(text(), what);
        if (!list.length) { save(); }
      }

      form.addEventListener("input", render);
      form.addEventListener("change", render);
      form.addEventListener("submit", function (e) { e.preventDefault(); });
      $("atwAddMerchant").addEventListener("click", function () { addMerchant(true); });
      $("egCopyRecipients").addEventListener("click", function () { copyAndSave(recipients.string, "recipient list", false); });
      $("egCopySubject").addEventListener("click", function () { copyAndSave(function () { return current.subject; }, "subject", true); });
      $("egCopyBody").addEventListener("click", function () { copyAndSave(function () { return current.body; }, "email body", true); });

      addMerchant(false);
    })();
  </script>

<?php
require __DIR__ . '/includes/footer.php';
require __DIR__ . '/includes/scripts.php';
