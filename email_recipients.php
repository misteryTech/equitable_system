<?php
/**
 * Admin: recipients shown on the Service / ATW Request generators.
 * Add, edit (pop-up), turn Active on/off (bounced addresses), delete, reorder and note.
 * All changes are POSTed with AJAX and answered with JSON; the generators'
 * "Save to list" / "Update in list" buttons use the same endpoint.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/email_generator.php';
$user = require_role('admin');

$pageTitle  = 'Email Recipients';
$activePage = 'email-recipients';

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

    $action   = $_POST['action'] ?? '';
    $id       = (int) ($_POST['id'] ?? 0);
    $existing = null;
    if ($id) {
        $stmt = db()->prepare('SELECT * FROM email_recipients WHERE id = ?');
        $stmt->execute([$id]);
        $existing = $stmt->fetch() ?: null;
        if (!$existing) {
            $respond(['errors' => ['Recipient not found. Please reload the page.']], 404);
        }
    }
    if (in_array($action, ['quick_update', 'toggle', 'delete', 'move'], true) && !$existing) {
        $respond(['errors' => ['Recipient not found. Please reload the page.']], 404);
    }

    // Name / email / group checks shared by save and quick_update.
    $checkAddress = static function (string $name, string $email, string $group, int $exceptId): array {
        $errors = [];
        if (mb_strlen($name) > 100) {
            $errors[] = 'Name must be at most 100 characters.';
        }
        if ($email === '' || strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }
        if (!array_key_exists($group, EMAIL_RECIPIENT_GROUPS)) {
            $errors[] = 'Please choose a group.';
        }
        if (!$errors) {
            $stmt = db()->prepare('SELECT 1 FROM email_recipients WHERE group_tag = ? AND email = ? AND id <> ?');
            $stmt->execute([$group, $email, $exceptId]);
            if ($stmt->fetchColumn()) {
                $errors[] = "$email is already in the " . EMAIL_RECIPIENT_GROUPS[$group] . ' list.';
            }
        }
        return $errors;
    };
    $clean = static fn(string $key): string => trim(preg_replace('/\s+/', ' ', (string) ($_POST[$key] ?? '')));

    try {
        if ($action === 'save') {
            $name   = $clean('display_name');
            $email  = $clean('email');
            $group  = $existing && !isset($_POST['group_tag']) ? $existing['group_tag'] : $clean('group_tag');
            $notes  = $clean('notes');
            $active = !empty($_POST['is_active']) ? 1 : 0;
            $errors = $checkAddress($name, $email, $group, $id);
            if (mb_strlen($notes) > 255) {
                $errors[] = 'Notes must be at most 255 characters.';
            }
            if ($errors) {
                $respond(['errors' => $errors], 422);
            }
            if ($existing) {
                db()->prepare('UPDATE email_recipients SET display_name = ?, email = ?, group_tag = ?, is_active = ?, notes = ? WHERE id = ?')
                    ->execute([$name, $email, $group, $active, $notes === '' ? null : $notes, $id]);
            } else {
                // New recipients go to the end of their group.
                $stmt = db()->prepare('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM email_recipients WHERE group_tag = ?');
                $stmt->execute([$group]);
                db()->prepare('INSERT INTO email_recipients (display_name, email, group_tag, is_active, sort_order, notes) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$name, $email, $group, $active, min(32000, (int) $stmt->fetchColumn()), $notes === '' ? null : $notes]);
                $id = (int) db()->lastInsertId();
            }
            $respond(['ok' => true, 'id' => $id]);
        }

        if ($action === 'quick_update') {
            $name   = $clean('display_name');
            $email  = $clean('email');
            $errors = $checkAddress($name, $email, $existing['group_tag'], $id);
            if ($errors) {
                $respond(['errors' => $errors], 422);
            }
            db()->prepare('UPDATE email_recipients SET display_name = ?, email = ? WHERE id = ?')->execute([$name, $email, $id]);
            $respond(['ok' => true, 'id' => $id]);
        }

        if ($action === 'toggle') {
            $active = !empty($_POST['is_active']) ? 1 : 0;
            db()->prepare('UPDATE email_recipients SET is_active = ? WHERE id = ?')->execute([$active, $id]);
            $respond(['ok' => true, 'is_active' => $active]);
        }

        if ($action === 'delete') {
            db()->prepare('DELETE FROM email_recipients WHERE id = ?')->execute([$id]);
            $respond(['ok' => true]);
        }

        if ($action === 'move') {
            // Renumber the group 10, 20, 30… then swap with the neighbour.
            $stmt = db()->prepare('SELECT id FROM email_recipients WHERE group_tag = ? ORDER BY sort_order, id');
            $stmt->execute([$existing['group_tag']]);
            $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            $pos = array_search($id, $ids, true);
            $to  = ($_POST['dir'] ?? '') === 'up' ? $pos - 1 : $pos + 1;
            if ($to >= 0 && $to < count($ids)) {
                [$ids[$pos], $ids[$to]] = [$ids[$to], $ids[$pos]];
            }
            $pdo = db();
            $pdo->beginTransaction();
            $update = $pdo->prepare('UPDATE email_recipients SET sort_order = ? WHERE id = ?');
            foreach ($ids as $i => $rowId) {
                $update->execute([($i + 1) * 10, $rowId]);
            }
            $pdo->commit();
            $respond(['ok' => true]);
        }
    } catch (PDOException $ex) {
        if ($ex->getCode() === '23000') {
            $respond(['errors' => ['That email address is already in this list.']], 422);
        }
        throw $ex;
    }

    $respond(['errors' => ['Unknown action.']], 400);
}

$group = array_key_exists($_GET['group'] ?? '', EMAIL_RECIPIENT_GROUPS) ? $_GET['group'] : '';
$sql   = 'SELECT * FROM email_recipients' . ($group !== '' ? ' WHERE group_tag = ?' : '') . " ORDER BY FIELD(group_tag, 'ATW', 'SERVICE', 'ALL'), sort_order, id";
$stmt  = db()->prepare($sql);
$stmt->execute($group !== '' ? [$group] : []);
$recipients = $stmt->fetchAll();
$counts     = db()->query('SELECT group_tag, COUNT(*) FROM email_recipients GROUP BY group_tag')->fetchAll(PDO::FETCH_KEY_PAIR);

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
          <li class="breadcrumb-item active"><?= e($pageTitle) ?></li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

    <section class="section">
      <div class="card">
        <div class="card-body pt-3">

          <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
            <ul class="nav nav-pills">
              <li class="nav-item"><a class="nav-link <?= $group === '' ? 'active' : '' ?>" href="email_recipients.php">All <span class="badge bg-light text-dark"><?= array_sum($counts) ?></span></a></li>
<?php foreach (EMAIL_RECIPIENT_GROUPS as $key => $text): ?>
              <li class="nav-item"><a class="nav-link <?= $group === $key ? 'active' : '' ?>" href="email_recipients.php?group=<?= e($key) ?>"><?= e($text) ?> <span class="badge bg-light text-dark"><?= (int) ($counts[$key] ?? 0) ?></span></a></li>
<?php endforeach; ?>
            </ul>
            <button type="button" class="btn btn-success ms-auto" id="addRecipient"><i class="bi bi-plus-lg"></i> Add Recipient</button>
          </div>

          <p class="small text-muted">
            Active recipients appear checked on the generator of their group (<strong>Both</strong> = Service and ATW). Turn <strong>Active</strong> off for an address that bounces — it stays on the generators, greyed out and unchecked.
            The system never sends email; users copy the list into Gmail.
          </p>

          <div class="table-responsive">
            <table class="table align-middle">
              <thead>
                <tr>
                  <th style="width: 4.5rem">Order</th>
                  <th>Name</th>
                  <th>Email</th>
                  <th>Group</th>
                  <th class="text-center">Active</th>
                  <th class="d-none d-md-table-cell">Notes</th>
                  <th class="text-end">Action</th>
                </tr>
              </thead>
              <tbody>
<?php foreach ($recipients as $r): ?>
                <tr class="<?= $r['is_active'] ? '' : 'text-muted' ?>">
                  <td class="text-nowrap">
                    <button type="button" class="btn btn-sm btn-link p-0 move" data-id="<?= (int) $r['id'] ?>" data-dir="up" title="Move up" aria-label="Move up"><i class="bi bi-arrow-up"></i></button>
                    <button type="button" class="btn btn-sm btn-link p-0 move" data-id="<?= (int) $r['id'] ?>" data-dir="down" title="Move down" aria-label="Move down"><i class="bi bi-arrow-down"></i></button>
                  </td>
                  <td><?= e($r['display_name']) ?></td>
                  <td class="text-break"><?= e($r['email']) ?></td>
                  <td><span class="badge <?= ['ATW' => 'bg-primary', 'SERVICE' => 'bg-info text-dark', 'ALL' => 'bg-secondary'][$r['group_tag']] ?>"><?= e(EMAIL_RECIPIENT_GROUPS[$r['group_tag']]) ?></span></td>
                  <td class="text-center">
                    <div class="form-check form-switch d-inline-block mb-0">
                      <input class="form-check-input toggle" type="checkbox" role="switch" data-id="<?= (int) $r['id'] ?>" <?= $r['is_active'] ? 'checked' : '' ?> aria-label="Active">
                    </div>
                  </td>
                  <td class="small d-none d-md-table-cell"><?= e($r['notes'] ?? '') ?></td>
                  <td class="text-end text-nowrap">
                    <button type="button" class="btn btn-sm btn-outline-primary edit"
                            data-id="<?= (int) $r['id'] ?>" data-name="<?= e($r['display_name']) ?>" data-email="<?= e($r['email']) ?>"
                            data-group="<?= e($r['group_tag']) ?>" data-active="<?= (int) $r['is_active'] ?>" data-notes="<?= e($r['notes'] ?? '') ?>">
                      <i class="bi bi-pencil"></i><span class="d-none d-sm-inline"> Edit</span>
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-danger delete" data-id="<?= (int) $r['id'] ?>" data-email="<?= e($r['email']) ?>" title="Delete" aria-label="Delete">
                      <i class="bi bi-trash"></i>
                    </button>
                  </td>
                </tr>
<?php endforeach; ?>
<?php if (!$recipients): ?>
                <tr><td colspan="7" class="text-muted text-center py-4">No recipients<?= $group !== '' ? ' in this group' : '' ?> yet.</td></tr>
<?php endif; ?>
              </tbody>
            </table>
          </div>

        </div>
      </div>
    </section>

  </main><!-- End #main -->

  <!-- Add / edit recipient modal -->
  <div class="modal fade" id="recipientModal" tabindex="-1" aria-labelledby="recipientModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <form class="modal-content" id="recipientForm" novalidate>
        <div class="modal-header">
          <h5 class="modal-title" id="recipientModalTitle">Add Recipient</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="id" id="rcId" value="">
          <div class="alert alert-danger d-none" id="recipientErrors" role="alert"></div>
          <div class="row g-3">
            <div class="col-12">
              <label for="rcName" class="form-label">Display Name</label>
              <input type="text" name="display_name" id="rcName" class="form-control" maxlength="100" placeholder="e.g. ECS POS HelpDesk">
            </div>
            <div class="col-12">
              <label for="rcEmail" class="form-label">Email</label>
              <input type="email" name="email" id="rcEmail" class="form-control" maxlength="255" required placeholder="name@example.com">
            </div>
            <div class="col-sm-7">
              <label for="rcGroup" class="form-label">Used on</label>
              <select name="group_tag" id="rcGroup" class="form-select">
<?php foreach (EMAIL_RECIPIENT_GROUPS as $key => $text): ?>
                <option value="<?= e($key) ?>"><?= e($text) ?></option>
<?php endforeach; ?>
              </select>
            </div>
            <div class="col-sm-5 d-flex align-items-end">
              <div class="form-check form-switch mb-2">
                <input class="form-check-input" type="checkbox" role="switch" name="is_active" value="1" id="rcActive" checked>
                <label class="form-check-label" for="rcActive">Active</label>
              </div>
            </div>
            <div class="col-12">
              <label for="rcNotes" class="form-label">Notes <span class="text-muted small">(optional)</span></label>
              <input type="text" name="notes" id="rcNotes" class="form-control" maxlength="255" placeholder="e.g. bounced 10/15/2026">
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary" id="rcSave"><i class="bi bi-save"></i> Save</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    (function () {
      var csrf = <?= json_encode(csrf_token()) ?>;
      var form = document.getElementById("recipientForm");
      var errors = document.getElementById("recipientErrors");
      var save = document.getElementById("rcSave");
      var defaultGroup = <?= json_encode($group !== '' ? $group : 'ATW') ?>;

      function modal() { return bootstrap.Modal.getOrCreateInstance(document.getElementById("recipientModal")); }

      function showErrors(list) {
        errors.innerHTML = "";
        var ul = document.createElement("ul");
        ul.className = "mb-0";
        list.forEach(function (text) { var li = document.createElement("li"); li.textContent = text; ul.appendChild(li); });
        errors.appendChild(ul);
        errors.classList.remove("d-none");
      }

      function post(body) {
        return fetch("email_recipients.php", { method: "POST", body: body })
          .then(function (res) { return res.json().catch(function () { return { errors: ["Unexpected server response (" + res.status + ")."] }; }); })
          .then(function (data) { if (data.errors) { throw data.errors; } return data; });
      }
      function action(data) {
        var body = new FormData();
        body.append("csrf_token", csrf);
        Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
        return post(body);
      }

      function open(data) {
        form.reset();
        errors.classList.add("d-none");
        document.getElementById("recipientModalTitle").textContent = data ? "Edit Recipient" : "Add Recipient";
        document.getElementById("rcId").value = data ? data.id : "";
        document.getElementById("rcName").value = data ? data.name : "";
        document.getElementById("rcEmail").value = data ? data.email : "";
        document.getElementById("rcGroup").value = data ? data.group : defaultGroup;
        document.getElementById("rcActive").checked = data ? data.active === "1" : true;
        document.getElementById("rcNotes").value = data ? data.notes : "";
        modal().show();
      }

      document.getElementById("addRecipient").addEventListener("click", function () { open(null); });
      document.querySelectorAll(".edit").forEach(function (btn) { btn.addEventListener("click", function () { open(btn.dataset); }); });

      form.addEventListener("submit", function (e) {
        e.preventDefault();
        errors.classList.add("d-none");
        save.disabled = true;
        post(new FormData(form))
          .then(function () { location.reload(); })
          .catch(function (err) { showErrors(Array.isArray(err) ? err : ["Could not save. Please try again."]); save.disabled = false; });
      });

      document.querySelectorAll(".toggle").forEach(function (box) {
        box.addEventListener("change", function () {
          box.disabled = true;
          action({ action: "toggle", id: box.dataset.id, is_active: box.checked ? 1 : 0 })
            .then(function () { box.closest("tr").classList.toggle("text-muted", !box.checked); })
            .catch(function (err) { box.checked = !box.checked; alert(Array.isArray(err) ? err.join("\n") : "Could not save."); })
            .then(function () { box.disabled = false; });
        });
      });

      document.querySelectorAll(".delete").forEach(function (btn) {
        btn.addEventListener("click", function () {
          if (!confirm("Delete " + btn.dataset.email + " from the recipient list? This cannot be undone.\n\nTip: turn Active off instead to keep it for later.")) { return; }
          btn.disabled = true;
          action({ action: "delete", id: btn.dataset.id }).then(function () { location.reload(); })
            .catch(function (err) { alert(Array.isArray(err) ? err.join("\n") : "Could not delete."); btn.disabled = false; });
        });
      });

      document.querySelectorAll(".move").forEach(function (btn) {
        btn.addEventListener("click", function () {
          btn.disabled = true;
          action({ action: "move", id: btn.dataset.id, dir: btn.dataset.dir }).then(function () { location.reload(); })
            .catch(function (err) { alert(Array.isArray(err) ? err.join("\n") : "Could not move."); btn.disabled = false; });
        });
      });
    })();
  </script>

<?php
require __DIR__ . '/includes/footer.php';
require __DIR__ . '/includes/scripts.php';
