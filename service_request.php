<?php
/**
 * Email Generator — Service Request: pick a registered terminal (fills the model),
 * type the SN, TID and merchant (suggested from your past requests) and choose what
 * needs to be done; the page builds the recipients, subject and body to paste into Gmail
 * (Short or Formal template). Nothing is sent. Copying saves it to My Requests
 * (POST, JSON).
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/email_generator.php';
$user = require_role('field_engineer', 'admin');

$pageTitle  = 'Service Request';
$activePage = 'service-request';

$settings = service_settings();
$types    = service_request_types();

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

    $typed = service_clean_fields($_POST);   // model, sn (capitals), tid, merchant
    $field = static fn(string $key): string => $typed[$key];
    $terminalId = (int) ($_POST['terminal_id'] ?? 0);
    $template   = (string) ($_POST['template'] ?? '');
    $tn         = strtoupper(preg_replace('/\s+/', '', (string) ($_POST['tn'] ?? '')));
    $notes      = trim(str_replace(["\r\n", "\r"], "\n", (string) ($_POST['notes'] ?? '')));
    $hour       = filter_var($_POST['client_hour'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 23]]);
    $errors     = [];

    $stmt = db()->prepare('SELECT terminal_id FROM terminals WHERE terminal_id = ?');
    $stmt->execute([$terminalId]);
    if (!$stmt->fetchColumn()) {
        $errors[] = 'Please choose a registered terminal.';
    }
    if (!array_key_exists($template, SERVICE_TEMPLATES)) {
        $errors[] = 'Please choose a template.';
    }
    foreach (['model' => ['Model', 50], 'sn' => ['SN', 50], 'tid' => ['TID', 20], 'merchant' => ['Merchant', 150]] as $key => [$label, $max]) {
        if (mb_strlen($field($key)) > $max) {
            $errors[] = "$label must be at most $max characters.";
        }
    }
    if (mb_strlen($tn) > 30) {
        $errors[] = 'Ticket number must be at most 30 characters.';
    } elseif ($tn !== '' && !service_tn_valid($tn) && empty($_POST['tn_override'])) {
        $errors[] = 'Ticket number should look like YYMMDD-SERV-NNNN (e.g. 261016-SERV-0123). Tick "Use it anyway" to keep yours.';
    }
    if (mb_strlen($notes) > 1000) {
        $errors[] = 'Notes must be at most 1000 characters.';
    }
    $device = trim(preg_replace('/\s+/', ' ', (string) ($_POST['subject_device'] ?? '')));   // kept as typed (no case change)
    if (mb_strlen($device) > 100) {
        $errors[] = 'The subject terminal name / model must be at most 100 characters.';
    }

    [$actions, $actionErrors]    = service_resolve_actions($types, (array) ($_POST['types'] ?? []), (array) ($_POST['type_text'] ?? []));
    [$recipients, $rcptErrors]   = email_recipients_from_post((string) ($_POST['recipients'] ?? '[]'));
    $errors = array_merge($errors, $actionErrors, $rcptErrors);
    if ($errors) {
        $respond(['errors' => $errors], 422);
    }

    $email = service_email_build([
        'template' => $template,
        'model'    => $field('model'),
        'sn'       => $field('sn'),
        'tid'      => $field('tid'),
        'merchant' => $field('merchant'),
        'tn'       => $tn,
        'notes'    => $notes,
        'name'     => email_person_name($user),
        'area'     => (string) $user['area'],
        'mobile'   => (string) $user['mobile_number'],
        'actions'  => $actions,
        'device'   => $device,
    ], $settings, $hour === false ? (int) date('G') : $hour);

    // Copying the recipients, subject and body of one email saves it once
    // (later copies only refresh the recipients).
    $stmt = db()->prepare(
        'SELECT id FROM service_requests WHERE user_id = ? AND body = ? AND created_at >= NOW() - INTERVAL 1 DAY ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$user['user_id'], $email['body']]);
    $id    = (int) $stmt->fetchColumn();
    $saved = false;
    if ($id) {
        db()->prepare('UPDATE service_requests SET recipients = ?, subject = ? WHERE id = ?')
            ->execute([$recipients, mb_substr($email['subject'], 0, 255), $id]);
    } else {
        $nullable = static fn(string $v): ?string => $v === '' ? null : $v;
        db()->prepare(
            'INSERT INTO service_requests (user_id, terminal_id, tn, model, sn, tid, merchant, actions, template, recipients, subject, body)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $user['user_id'], $terminalId, $nullable($tn), $nullable($field('model')), $nullable($field('sn')),
            $nullable($field('tid')), $nullable($field('merchant')), mb_substr($email['actions'], 0, 500), $template,
            $recipients, mb_substr($email['subject'], 0, 255), $email['body'],
        ]);
        $id    = (int) db()->lastInsertId();
        $saved = true;
    }

    $respond(['ok' => true, 'id' => $id, 'saved' => $saved, 'recipients' => $recipients] + $email);
}

$terminals = db()->query(
    'SELECT terminal_id, terminal_name, brand, model, current_version FROM terminals ORDER BY terminal_name'
)->fetchAll();
$selected    = (int) ($_GET['terminal'] ?? 0);
$recipients  = email_recipients_for('SERVICE');
$suggestions = email_history_suggestions_for((int) $user['user_id']);

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
<?php if (!$terminals): ?>
      <div class="alert alert-warning">
        No terminals are registered yet. <?= is_admin($user) ? '<a href="terminals.php">Register a terminal</a> first.' : 'Ask an admin to register the terminal first.' ?>
      </div>
<?php endif; ?>

      <div class="row">
        <div class="col-lg-7">
          <form class="card" id="egForm" novalidate>
            <div class="card-body">
              <h5 class="card-title">Request Details</h5>
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="save">
              <input type="hidden" name="client_hour" id="egHour" value="">
              <div class="alert alert-danger d-none" id="egErrors" role="alert"></div>

              <div class="row g-3">
                <div class="col-12">
                  <label for="erTerminal" class="form-label">Terminal</label>
                  <select name="terminal_id" id="erTerminal" placeholder="Search terminal name, model or brand…" autocomplete="off">
                    <option value="">Search terminal name, model or brand…</option>
<?php foreach ($terminals as $t): ?>
                    <option value="<?= (int) $t['terminal_id'] ?>" <?= $selected === (int) $t['terminal_id'] ? 'selected' : '' ?>
                            data-data="<?= e(json_encode([
                                'name'    => $t['terminal_name'],
                                'brand'   => $t['brand'],
                                'model'   => (string) $t['model'],
                                'version' => (string) $t['current_version'],
                            ])) ?>"><?= e($t['terminal_name']) ?></option>
<?php endforeach; ?>
                  </select>
                  <div class="form-text" id="erTerminalInfo">Choosing a terminal fills in its model.</div>
                </div>

                <div class="col-md-5">
                  <span class="form-label d-block">Subject uses</span>
                  <div class="btn-group w-100" role="radiogroup" aria-label="Subject uses">
                    <input type="radio" class="btn-check" name="subject_source" id="erSrcName" value="name" autocomplete="off" checked>
                    <label class="btn btn-outline-secondary btn-sm" for="erSrcName">Terminal Name</label>
                    <input type="radio" class="btn-check" name="subject_source" id="erSrcModel" value="model" autocomplete="off">
                    <label class="btn btn-outline-secondary btn-sm" for="erSrcModel">Model</label>
                  </div>
                </div>
                <div class="col-md-7">
                  <label for="erSubjectDevice" class="form-label">In the subject</label>
                  <input type="text" name="subject_device" id="erSubjectDevice" class="form-control" maxlength="100" placeholder="e.g. Castle v3 3g">
                  <div class="form-text">Filled from the terminal; you can edit it.</div>
                </div>

                <div class="col-6 col-md-3">
                  <label for="erModel" class="form-label">Model</label>
                  <input type="text" name="model" id="erModel" class="form-control" maxlength="50">
                </div>
                <div class="col-6 col-md-3">
                  <label for="erTid" class="form-label">TID</label>
                  <input type="text" name="tid" id="erTid" class="form-control font-monospace" maxlength="20" list="erTidList" autocomplete="off" inputmode="numeric">
                </div>
                <div class="col-md-6">
                  <label for="erSn" class="form-label">SN</label>
                  <input type="text" name="sn" id="erSn" class="form-control font-monospace text-uppercase" maxlength="50" list="erSnList" autocomplete="off" autocapitalize="characters">
                </div>
                <div class="col-12">
                  <label for="erMerchant" class="form-label">Merchant Name</label>
                  <input type="text" name="merchant" id="erMerchant" class="form-control" maxlength="150" list="erMerchantList" autocomplete="off">
                  <div class="form-text">Type the TID, SN and merchant. Suggestions come from your past requests; picking a TID you used before fills in its merchant and SN.</div>
                </div>
                <datalist id="erTidList"><?php foreach ($suggestions['tids'] as $v): ?><option value="<?= e($v) ?>"></option><?php endforeach; ?></datalist>
                <datalist id="erSnList"><?php foreach ($suggestions['sns'] as $v): ?><option value="<?= e($v) ?>"></option><?php endforeach; ?></datalist>
                <datalist id="erMerchantList"><?php foreach ($suggestions['merchants'] as $v): ?><option value="<?= e($v) ?>"></option><?php endforeach; ?></datalist>

                <div class="col-12">
                  <span class="form-label d-block">Request <span class="text-muted small">(choose one or more)</span></span>
                  <div class="row g-2">
<?php foreach ($types as $type): ?>
                    <div class="col-sm-6">
                      <div class="form-check">
                        <input class="form-check-input er-type" type="checkbox" name="types[]" value="<?= (int) $type['id'] ?>" id="erType<?= (int) $type['id'] ?>">
                        <label class="form-check-label" for="erType<?= (int) $type['id'] ?>"><?= e($type['label']) ?></label>
                      </div>
<?php if ($type['text_mode'] !== 'none'): ?>
                      <input type="text" name="type_text[<?= (int) $type['id'] ?>]" class="form-control form-control-sm mt-1 er-type-text d-none"
                             data-type="<?= (int) $type['id'] ?>" maxlength="100" placeholder="<?= e($type['text_hint'] ?: 'Details') ?>"
                             aria-label="Details for <?= e($type['label']) ?>">
<?php endif; ?>
                    </div>
<?php endforeach; ?>
<?php if (!$types): ?>
                    <div class="col-12 text-muted small">No request types are set up. <?= is_admin($user) ? 'Add them on <a href="email_templates.php">Email Templates</a>.' : 'Ask an admin to add them.' ?></div>
<?php endif; ?>
                  </div>
                </div>

                <div class="col-md-6">
                  <label for="erTn" class="form-label">Ticket Number (TN)</label>
                  <input type="text" name="tn" id="erTn" class="form-control font-monospace text-uppercase" maxlength="30" placeholder="YYMMDD-SERV-NNNN" autocomplete="off">
                  <div class="invalid-feedback">Expected format YYMMDD-SERV-NNNN, e.g. 261016-SERV-0123.</div>
                  <div class="form-check mt-1 d-none" id="erTnOverrideWrap">
                    <input class="form-check-input" type="checkbox" name="tn_override" value="1" id="erTnOverride">
                    <label class="form-check-label small" for="erTnOverride">Use it anyway</label>
                  </div>
                </div>

                <div class="col-md-6">
                  <span class="form-label d-block">Template</span>
                  <div class="btn-group w-100" role="radiogroup" aria-label="Template">
<?php foreach (SERVICE_TEMPLATES as $key => $text): ?>
                    <input type="radio" class="btn-check" name="template" id="erTpl_<?= e($key) ?>" value="<?= e($key) ?>" autocomplete="off" <?= $key === 'short' ? 'checked' : '' ?>>
                    <label class="btn btn-outline-primary" for="erTpl_<?= e($key) ?>"><?= e($text) ?></label>
<?php endforeach; ?>
                  </div>
                  <div class="form-text">Short: "<?= e($settings['short_greeting']) ?>". Formal: "<?= e($settings['formal_greeting']) ?>" with a greeting for the time of day.</div>
                </div>

                <div class="col-12">
                  <label for="erNotes" class="form-label">Extra Notes <span class="text-muted small">(optional)</span></label>
                  <textarea name="notes" id="erNotes" class="form-control" rows="2" maxlength="1000"></textarea>
                </div>

                <div class="col-12">
                  <div class="bg-light rounded p-2 small">
                    <span class="text-muted">Sign-off from your profile:</span>
                    <?= e(email_person_name($user)) ?>
                    · <?= $user['area'] ? 'F.E ' . e(mb_strtoupper($user['area'])) : '<span class="text-danger">no area</span>' ?>
                    · <?= $user['mobile_number'] ? e($user['mobile_number']) : '<span class="text-danger">no mobile number</span>' ?>
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

  <link href="assets/vendor/tom-select/tom-select.bootstrap5.min.css" rel="stylesheet">
  <script src="assets/vendor/tom-select/tom-select.complete.min.js"></script>
  <script src="assets/js/email-generator.js"></script>
  <script>
    (function () {
      var EG = window.EmailGen;
      var settings = <?= json_encode($settings) ?>;
      var types = <?= json_encode((object) array_column(array_map(fn($t) => ['id' => (int) $t['id'], 'label' => $t['label'], 'phrase' => $t['phrase'], 'text_mode' => $t['text_mode']], $types), null, 'id')) ?>;
      var me = <?= json_encode(['name' => email_person_name($user), 'area' => (string) $user['area'], 'mobile' => (string) $user['mobile_number']]) ?>;
      var TN_PATTERN = /^\d{6}-SERV-\d{4}$/;

      var form = document.getElementById("egForm");
      var errors = document.getElementById("egErrors");
      var tn = document.getElementById("erTn");
      var tnOverride = document.getElementById("erTnOverride");
      var fields = { model: "erModel", sn: "erSn", tid: "erTid", merchant: "erMerchant" };
      var byTid = <?= json_encode((object) $suggestions['by_tid']) ?>;   // TID -> merchant and SN from past requests
      var subjectWords = <?= json_encode((object) SERVICE_SUBJECT_ACTIONS) ?>;   // request type -> word in the subject
      var SOURCE_KEY = "serviceSubjectSource";
      var deviceEdited = false;   // true once the user types in "In the subject"
      var current = null;   // last built email

      function $(id) { return document.getElementById(id); }

      var recipients = EG.RecipientsBox($("egRecipients"), {
        recipients: <?= json_encode($recipients) ?>, group: "SERVICE", isAdmin: <?= json_encode(is_admin($user)) ?>,
        csrf: <?= json_encode(csrf_token()) ?>, endpoint: "email_recipients.php"
      });

      var terminal = new TomSelect("#erTerminal", {
        dataAttr: "data",                 // each <option data-data="{…}"> carries the terminal details as JSON
        searchField: ["text", "name", "model", "brand"],
        maxOptions: 50,
        render: {
          option: function (d, esc) {
            var info = [d.model, d.brand, d.version && "v" + d.version].filter(Boolean).join(" · ");
            return "<div><div>" + esc(d.name) + "</div><div class=\"small text-muted\">" + esc(info) + "</div></div>";
          },
          item: function (d, esc) { return "<div>" + esc(d.name) + (d.model ? " · " + esc(d.model) : "") + "</div>"; }
        },
        onChange: function (value) { fill(value ? terminal.options[value] : null); }
      });

      /* ---------- Subject: terminal name or model ---------- */

      function source() { return form.querySelector('input[name="subject_source"]:checked').value; }
      try { if (localStorage.getItem(SOURCE_KEY) === "model") { $("erSrcModel").checked = true; } } catch (e) {}

      // Terminal Name by default (the model when there is no name), or Model (the name when there is no model).
      function syncDevice() {
        if (deviceEdited) { return; }
        var t = terminal.getValue() ? terminal.options[terminal.getValue()] : null;
        var name = t ? (t.name || "") : "", model = $("erModel").value.trim();
        $("erSubjectDevice").value = source() === "model" ? (model || name) : (name || model);
      }
      form.querySelectorAll('input[name="subject_source"]').forEach(function (r) {
        r.addEventListener("change", function () {
          try { localStorage.setItem(SOURCE_KEY, source()); } catch (e) {}
          deviceEdited = false;
          syncDevice();
          render();
        });
      });
      $("erSubjectDevice").addEventListener("input", function () { deviceEdited = this.value.trim() !== ""; });
      $("erModel").addEventListener("input", function () { syncDevice(); });

      // A terminal fills only the model (SN, TID and merchant are typed).
      function fill(t) {
        $("erModel").value = t ? (t.model || "") : "";
        deviceEdited = false;
        syncDevice();
        $("erTerminalInfo").textContent = !t ? "Choosing a terminal fills in its model."
          : (t.model ? "" : "This terminal has no model on record — type it below. ") + [t.brand, t.version && "current version " + t.version].filter(Boolean).join(" · ");
        render();
      }

      // A TID used before fills its merchant and SN when those are still empty.
      $("erTid").addEventListener("change", function () {
        var known = byTid[this.value.replace(/\s+/g, "")];
        if (!known) { return; }
        if (!$("erMerchant").value.trim() && known.merchant) { $("erMerchant").value = known.merchant; }
        if (!$("erSn").value.trim() && known.sn) { $("erSn").value = known.sn; }
        render();
      });

      function checkedActions() {
        var list = [];
        form.querySelectorAll(".er-type").forEach(function (box) {
          var text = form.querySelector('.er-type-text[data-type="' + box.value + '"]');
          if (text) { text.classList.toggle("d-none", !box.checked); }
          if (box.checked && types[box.value]) { list.push({ type: types[box.value], text: text ? text.value : "" }); }
        });
        return list;
      }

      function tnState() {
        var value = tn.value.replace(/\s+/g, "").toUpperCase();
        var bad = value !== "" && !TN_PATTERN.test(value);
        tn.classList.toggle("is-invalid", bad && !tnOverride.checked);
        $("erTnOverrideWrap").classList.toggle("d-none", !bad);
        return { value: value, bad: bad };
      }

      function render() {
        var hour = new Date().getHours();
        $("egHour").value = hour;
        var input = { template: form.querySelector('input[name="template"]:checked').value, tn: tnState().value, notes: $("erNotes").value,
                      name: me.name, area: me.area, mobile: me.mobile,
                      device: $("erSubjectDevice").value,
                      actions: checkedActions().map(function (c) { return EG.serviceAction(c.type, c.text, subjectWords); }) };
        Object.keys(fields).forEach(function (key) { input[key] = $(fields[key]).value; });
        input.sn = input.sn.replace(/\s+/g, "").toUpperCase();   // same as service_clean_fields()
        input.tid = input.tid.replace(/\s+/g, "");
        current = EG.serviceBuild(input, settings, hour);
        $("egPreview").textContent = current.body;
        $("egSubject").textContent = current.subject || "—";
      }

      // Same checks as the server, so nothing incomplete is copied or saved.
      function problems() {
        var list = [];
        if (!terminal.getValue()) { list.push("Please choose a registered terminal."); }
        var chosen = checkedActions();
        if (!chosen.length) { list.push("Choose at least one request type."); }
        chosen.forEach(function (c) {
          if (c.type.text_mode !== "none" && c.text.trim() === "") { list.push('Type the details for "' + c.type.label + '".'); }
        });
        if (tnState().bad && !tnOverride.checked) { list.push('Ticket number should look like YYMMDD-SERV-NNNN (e.g. 261016-SERV-0123). Tick "Use it anyway" to keep yours.'); }
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
        body.set("tn", tnState().value);
        body.set("recipients", JSON.stringify(recipients.list()));
        return fetch("service_request.php", { method: "POST", body: body })
          .then(function (res) { return res.json().catch(function () { return { errors: ["Unexpected server response (" + res.status + ")."] }; }); })
          .then(function (data) {
            if (data.errors) { throw data.errors; }
            if (data.body !== current.body && window.console) { console.warn("Service Request: saved text differs from the preview", data.body); }
            var status = $("egStatus");
            status.textContent = (data.saved ? "Saved to " : "Updated in ");
            var link = document.createElement("a");
            link.href = "my_requests.php";
            link.textContent = "My Requests";
            status.append(link, ". Paste into Gmail — nothing is sent from here.");
          })
          .catch(function (err) { showErrors(Array.isArray(err) ? err : ["Could not save. Please check your connection and try again."]); });
      }

      // Copy first (must happen inside the click), then save in the background.
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
      $("egCopyRecipients").addEventListener("click", function () { copyAndSave(recipients.string, "recipient list", false); });
      $("egCopySubject").addEventListener("click", function () { copyAndSave(function () { return current.subject; }, "subject", true); });
      $("egCopyBody").addEventListener("click", function () { copyAndSave(function () { return current.body; }, "email body", true); });

      fill(terminal.getValue() ? terminal.options[terminal.getValue()] : null);
    })();
  </script>

<?php
require __DIR__ . '/includes/footer.php';
require __DIR__ . '/includes/scripts.php';
