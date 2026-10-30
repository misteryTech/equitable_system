<?php
/**
 * Terminals: register terminals (name, up to 3 photos, brand, model, label, BDO / Non-BDO,
 * current version, remarks, FAQs). Labels come from the terminal_labels table (Terminal Labels page).
 * Everyone signed in can view and search; admins add, edit and delete
 * through the modal (saved with AJAX, JSON response).
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/terminals.php';
$user    = require_login();
$isAdmin = is_admin($user);

$pageTitle  = 'Terminals';
$activePage = 'terminals';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $respond = static function (array $data, int $status = 200): void {
        http_response_code($status);
        echo json_encode($data);
        exit;
    };

    if (!$isAdmin) {
        $respond(['errors' => ['Only admins can register or change terminals.']], 403);
    }
    if (!csrf_verify()) {
        $respond(['errors' => ['Your session expired. Please reload the page.']], 403);
    }

    $action = $_POST['action'] ?? '';

    // "+ Add new label" in the form's Label dropdown.
    if ($action === 'add_label') {
        [$labelId, $error] = terminal_label_add((string) ($_POST['name'] ?? ''));
        if ($error) {
            $respond(['errors' => [$error]], 422);
        }
        $respond(['ok' => true, 'id' => $labelId, 'name' => terminal_label_clean((string) $_POST['name'])]);
    }

    $id       = (int) ($_POST['terminal_id'] ?? 0);
    $existing = null;
    if ($id) {
        $stmt = db()->prepare('SELECT * FROM terminals WHERE terminal_id = ?');
        $stmt->execute([$id]);
        $existing = $stmt->fetch() ?: null;
        if (!$existing) {
            $respond(['errors' => ['Terminal not found.']], 404);
        }
    }
    $oldPhotos = $existing ? (terminal_photos([$id])[$id] ?? []) : [];

    if ($action === 'delete') {
        if (!$existing) {
            $respond(['errors' => ['Terminal not found.']], 404);
        }
        db()->prepare('DELETE FROM terminals WHERE terminal_id = ?')->execute([$id]);   // photos rows cascade
        foreach ($oldPhotos as $photo) {
            terminal_image_delete($photo['file']);
        }
        $_SESSION['flash'] = ['success', "Terminal \"{$existing['terminal_name']}\" deleted."];
        $respond(['ok' => true]);
    }

    if ($action !== 'save') {
        $respond(['errors' => ['Unknown action.']], 400);
    }

    [$values, $errors] = terminal_validate($_POST, terminal_labels(), $existing ? (int) $existing['label_id'] : null);
    [$faqRows, $faqErrors] = terminal_faq_validate($_POST);
    $errors = array_merge($errors, $faqErrors);
    $name   = $values['terminal_name'];
    $remove = array_map('intval', (array) ($_POST['remove_slot'] ?? []));

    if ($name !== '' && !$errors) {
        $stmt = db()->prepare('SELECT 1 FROM terminals WHERE terminal_name = ? AND terminal_id <> ?');
        $stmt->execute([$name, $id]);
        if ($stmt->fetchColumn()) {
            $errors[] = "A terminal named \"$name\" is already registered.";
        }
    }

    // Each of the 3 slots: a new upload replaces that slot's photo; "remove" clears it.
    $uploads = [];
    for ($slot = 1; $slot <= TERMINAL_MAX_PHOTOS; $slot++) {
        [$photoError, $hasFile] = terminal_image_check($_FILES["photo_$slot"] ?? null);
        if ($photoError) {
            $errors[] = ucfirst(str_ireplace('terminal image', "photo $slot", $photoError));
        } elseif ($hasFile) {
            $uploads[$slot] = $_FILES["photo_$slot"];
        }
    }
    if ($errors) {
        $respond(['errors' => $errors], 422);
    }

    // Store the new files first; if saving the record fails, they are removed again.
    $stored = [];
    foreach ($uploads as $slot => $file) {
        $stored[$slot] = terminal_image_store($file);
    }

    $nullable = static fn(string $v): ?string => $v === '' ? null : $v;
    $fields   = [$name, $values['brand'], $nullable($values['model']), $nullable($values['current_version']), $values['label_id'], $values['bank_type'], $nullable($values['remarks'])];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($existing) {
            $pdo->prepare('UPDATE terminals SET terminal_name = ?, brand = ?, model = ?, current_version = ?, label_id = ?, bank_type = ?, remarks = ? WHERE terminal_id = ?')
                ->execute([...$fields, $id]);
        } else {
            $pdo->prepare('INSERT INTO terminals (terminal_name, brand, model, current_version, label_id, bank_type, remarks, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([...$fields, $user['user_id']]);
            $id = (int) $pdo->lastInsertId();
        }
        terminal_faqs_save($pdo, $id, $faqRows);

        $obsolete = [];
        for ($slot = 1; $slot <= TERMINAL_MAX_PHOTOS; $slot++) {
            $old = $oldPhotos[$slot] ?? null;
            if (isset($stored[$slot]) || ($old && in_array($slot, $remove, true))) {
                if ($old) {
                    $pdo->prepare('DELETE FROM terminal_photos WHERE photo_id = ?')->execute([$old['photo_id']]);
                    $obsolete[] = $old['file'];
                }
                if (isset($stored[$slot])) {
                    $pdo->prepare('INSERT INTO terminal_photos (terminal_id, slot, file) VALUES (?, ?, ?)')->execute([$id, $slot, $stored[$slot]]);
                }
            }
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        $pdo->rollBack();
        foreach ($stored as $file) {
            terminal_image_delete($file);
        }
        if ($ex instanceof PDOException && $ex->getCode() === '23000') {
            $respond(['errors' => ["A terminal named \"$name\" is already registered."]], 422);
        }
        throw $ex;
    }

    // Replaced or removed photo files are no longer used.
    foreach ($obsolete as $file) {
        terminal_image_delete($file);
    }

    $_SESSION['flash'] = ['success', $existing ? "Terminal \"$name\" updated." : "Terminal \"$name\" registered."];
    $respond(['ok' => true]);
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$labels       = terminal_labels();
$activeLabels = array_filter($labels, fn($l) => $l['is_active']);

// Filters
$q       = trim((string) ($_GET['q'] ?? ''));
$labelId = isset($labels[(int) ($_GET['label'] ?? 0)]) ? (int) $_GET['label'] : 0;
$bank    = array_key_exists($_GET['bank'] ?? '', TERMINAL_BANKS) ? $_GET['bank'] : '';

$sql    = 'SELECT t.*, l.name AS label_name, CONCAT(u.first_name, \' \', u.last_name) AS created_by_name
           FROM terminals t
           JOIN terminal_labels l ON l.id = t.label_id
           LEFT JOIN users u ON u.user_id = t.created_by WHERE 1 = 1';
$params = [];
if ($q !== '') {
    $sql .= ' AND (t.terminal_name LIKE ? OR t.brand LIKE ? OR t.model LIKE ? OR t.current_version LIKE ? OR t.remarks LIKE ?
                   OR EXISTS (SELECT 1 FROM terminal_faqs f WHERE f.terminal_id = t.terminal_id AND (f.question LIKE ? OR f.answer LIKE ?)))';
    $like = '%' . addcslashes($q, '%_\\') . '%';
    array_push($params, ...array_fill(0, 7, $like));
}
if ($labelId) {
    $sql .= ' AND t.label_id = ?';
    $params[] = $labelId;
}
if ($bank !== '') {
    $sql .= ' AND t.bank_type = ?';
    $params[] = $bank;
}
$stmt = db()->prepare($sql . ' ORDER BY t.terminal_name');
$stmt->execute($params);
$terminals = $stmt->fetchAll();
$photos    = terminal_photos(array_column($terminals, 'terminal_id'));
$faqs      = terminal_faqs(array_column($terminals, 'terminal_id'));

$total = (int) db()->query('SELECT COUNT(*) FROM terminals')->fetchColumn();

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
<?php if ($flash): ?>
      <div class="alert alert-<?= e($flash[0]) ?>"><?= e($flash[1]) ?></div>
<?php endif; ?>

      <div class="card">
        <div class="card-body pt-3">

          <form method="get" class="row g-2 align-items-end mb-3">
            <div class="col-md-4">
              <label for="q" class="form-label">Search</label>
              <input type="search" name="q" id="q" class="form-control" placeholder="Name, brand, model, version, remarks or FAQ" value="<?= e($q) ?>">
            </div>
            <div class="col-6 col-md-2">
              <label for="fLabel" class="form-label">Label</label>
              <select name="label" id="fLabel" class="form-select">
                <option value="">All</option>
<?php foreach ($labels as $l): ?>
                <option value="<?= (int) $l['id'] ?>" <?= $labelId === $l['id'] ? 'selected' : '' ?>><?= e($l['name']) ?><?= $l['is_active'] ? '' : ' (inactive)' ?></option>
<?php endforeach; ?>
              </select>
            </div>
            <div class="col-6 col-md-2">
              <label for="fBank" class="form-label">Bank</label>
              <select name="bank" id="fBank" class="form-select">
                <option value="">All</option>
<?php foreach (TERMINAL_BANKS as $key => $text): ?>
                <option value="<?= e($key) ?>" <?= $bank === $key ? 'selected' : '' ?>><?= e($text) ?></option>
<?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4 d-flex flex-wrap gap-2">
              <button type="submit" class="btn btn-primary"><i class="bi bi-funnel"></i> Filter</button>
<?php if ($q !== '' || $labelId || $bank !== ''): ?>
              <a href="terminals.php" class="btn btn-outline-secondary">Clear</a>
<?php endif; ?>
<?php if ($isAdmin): ?>
              <button type="button" class="btn btn-success ms-auto" id="addTerminal"><i class="bi bi-plus-lg"></i> Register Terminal</button>
<?php endif; ?>
            </div>
          </form>

          <p class="small text-muted mb-2">Showing <?= count($terminals) ?> of <?= $total ?> terminal<?= $total === 1 ? '' : 's' ?>.</p>

          <div class="table-responsive">
            <table class="table align-middle text-nowrap">
              <thead>
                <tr>
                  <th>Photos</th>
                  <th>Terminal Name</th>
                  <th>Model / Brand</th>
                  <th>Label</th>
                  <th>Bank</th>
                  <th class="d-none d-md-table-cell">Registered</th>
<?php if ($isAdmin): ?>
                  <th class="text-end">Action</th>
<?php endif; ?>
                </tr>
              </thead>
              <tbody>
<?php foreach ($terminals as $t):
    $tPhotos = $photos[(int) $t['terminal_id']] ?? [];
    $tFaqs   = $faqs[(int) $t['terminal_id']] ?? [];
    $faqJson = json_encode(array_map(fn($f) => ['id' => $f['faq_id'], 'q' => $f['question'], 'a' => $f['answer']], $tFaqs));
?>
                <tr>
                  <td>
                    <div class="d-flex gap-1">
<?php foreach ($tPhotos as $slot => $photo): ?>
                      <a href="<?= e($photo['url']) ?>" target="_blank" rel="noopener" title="Open photo <?= (int) $slot ?>">
                        <img src="<?= e($photo['url']) ?>" alt="<?= e($t['terminal_name']) ?> photo <?= (int) $slot ?>" class="rounded border" style="width: 48px; height: 48px; object-fit: cover">
                      </a>
<?php endforeach; ?>
<?php if (!$tPhotos): ?>
                      <img src="<?= e(TERMINAL_IMAGE_DEFAULT) ?>" alt="No photo" class="rounded border" style="width: 48px; height: 48px; object-fit: cover">
<?php endif; ?>
                    </div>
                  </td>
                  <td>
                    <span class="fw-semibold"><?= e($t['terminal_name']) ?></span>
<?php if ($t['remarks']): ?>
                    <span class="d-block small text-muted text-truncate" style="max-width: 240px" title="<?= e($t['remarks']) ?>"><?= e($t['remarks']) ?></span>
<?php endif; ?>
<?php if ($t['remarks'] || $tFaqs): ?>
                    <button type="button" class="btn btn-sm btn-link p-0 view-terminal" data-name="<?= e($t['terminal_name']) ?>" data-remarks="<?= e($t['remarks'] ?? '') ?>" data-faqs="<?= e($faqJson) ?>">
                      <i class="bi bi-info-circle"></i> <?= $tFaqs ? 'Remarks &amp; FAQ (' . count($tFaqs) . ')' : 'Remarks' ?>
                    </button>
<?php endif; ?>
                  </td>
                  <td>
                    <?= e($t['model'] ?? '') ?><?= $t['model'] ? '<br>' : '' ?>
                    <span class="small text-muted"><?= e($t['brand']) ?><?= $t['current_version'] ? ' · v' . e($t['current_version']) : '' ?></span>
                  </td>
                  <td><?= terminal_label_badge((int) $t['label_id'], $t['label_name']) ?></td>
                  <td><?= terminal_bank_badge($t['bank_type']) ?></td>
                  <td class="small text-muted d-none d-md-table-cell">
                    <?= e(date('M j, Y', strtotime($t['created_at']))) ?><?= $t['created_by_name'] ? ' by ' . e($t['created_by_name']) : '' ?>
                  </td>
<?php if ($isAdmin): ?>
                  <td class="text-end">
                    <button type="button" class="btn btn-sm btn-outline-primary edit-terminal"
                            data-id="<?= (int) $t['terminal_id'] ?>" data-name="<?= e($t['terminal_name']) ?>" data-brand="<?= e($t['brand']) ?>"
                            data-model="<?= e($t['model'] ?? '') ?>" data-version="<?= e($t['current_version'] ?? '') ?>"
                            data-label="<?= (int) $t['label_id'] ?>" data-label-name="<?= e($t['label_name']) ?>" data-bank="<?= e($t['bank_type']) ?>"
                            data-remarks="<?= e($t['remarks'] ?? '') ?>" data-faqs="<?= e($faqJson) ?>"
                            data-photos="<?= e(json_encode(array_map(fn($p) => $p['url'], $tPhotos))) ?>">
                      <i class="bi bi-pencil"></i> Edit
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-danger delete-terminal" data-id="<?= (int) $t['terminal_id'] ?>" data-name="<?= e($t['terminal_name']) ?>" title="Delete">
                      <i class="bi bi-trash"></i>
                    </button>
                  </td>
<?php endif; ?>
                </tr>
<?php endforeach; ?>
<?php if (!$terminals): ?>
                <tr><td colspan="<?= $isAdmin ? 7 : 6 ?>" class="text-muted text-center py-4"><?= $total ? 'No terminals match your filters.' : 'No terminals registered yet.' ?></td></tr>
<?php endif; ?>
              </tbody>
            </table>
          </div>

        </div>
      </div>
    </section>

  </main><!-- End #main -->

<?php if ($isAdmin): ?>
  <!-- Register / edit terminal modal -->
  <div class="modal fade" id="terminalModal" tabindex="-1" aria-labelledby="terminalModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
      <form class="modal-content" id="terminalForm" enctype="multipart/form-data" novalidate>
        <div class="modal-header">
          <h5 class="modal-title" id="terminalModalTitle">Register Terminal</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="terminal_id" id="tmId" value="">
          <div class="alert alert-danger d-none" id="terminalErrors" role="alert"></div>

          <div class="alert alert-light border small mb-3">
            <div class="fw-semibold mb-1">Fill in the details</div>
            <p class="mb-1">Four fields are required: <strong>Terminal Name</strong>, <strong>Brand</strong>, <strong>Label</strong> and <strong>Bank</strong>.</p>
            <ul class="mb-0 ps-3">
              <li><strong>Terminal Name</strong>: a unique name for this unit; no two terminals can share one.</li>
              <li><strong>Brand</strong> and <strong>Model</strong>: as printed on the device, e.g. PAX and S1F2, or Ingenico and DX8000.</li>
              <li><strong>Label</strong>: choose from the list. Admins can add new labels.</li>
              <li><strong>Bank</strong>: BDO or Non-BDO.</li>
              <li><strong>Current Version</strong>: optional, the software version now on the terminal.</li>
              <li><strong>Remarks</strong> and <strong>FAQ</strong>: optional notes, and common questions with their answers. Add, edit or remove FAQs any time.</li>
            </ul>
          </div>

          <div class="row g-3">
            <div class="col-12">
              <label for="tmName" class="form-label">Terminal Name</label>
              <input type="text" name="terminal_name" id="tmName" class="form-control" maxlength="100" required>
            </div>

            <div class="col-12">
              <span class="form-label d-block">Photos <span class="text-muted small">(up to <?= TERMINAL_MAX_PHOTOS ?>, JPG/PNG/WEBP/GIF, 5 MB each)</span></span>
              <div class="row g-2">
<?php for ($slot = 1; $slot <= TERMINAL_MAX_PHOTOS; $slot++): ?>
                <div class="col-sm-4">
                  <div class="border rounded p-2 h-100 text-center photo-slot" data-slot="<?= $slot ?>">
                    <img src="<?= e(TERMINAL_IMAGE_DEFAULT) ?>" alt="Photo <?= $slot ?> preview" class="rounded mb-2 slot-preview" style="width: 100%; height: 120px; object-fit: cover">
                    <label for="tmPhoto<?= $slot ?>" class="btn btn-sm btn-outline-primary w-100 mb-1">
                      <i class="bi bi-camera"></i> <span class="slot-action">Add photo <?= $slot ?></span>
                    </label>
                    <input type="file" name="photo_<?= $slot ?>" id="tmPhoto<?= $slot ?>" class="d-none slot-input" accept="image/jpeg,image/png,image/webp,image/gif">
                    <div class="form-check d-none slot-remove-wrap text-start">
                      <input class="form-check-input slot-remove" type="checkbox" name="remove_slot[]" value="<?= $slot ?>" id="tmRemove<?= $slot ?>">
                      <label class="form-check-label small" for="tmRemove<?= $slot ?>">Remove</label>
                    </div>
                    <button type="button" class="btn btn-sm btn-link text-danger p-0 d-none slot-clear">Undo new photo</button>
                  </div>
                </div>
<?php endfor; ?>
              </div>
            </div>

            <div class="col-md-6">
              <label for="tmBrand" class="form-label">Brand</label>
              <input type="text" name="brand" id="tmBrand" class="form-control" maxlength="100" placeholder="e.g. Verifone, Ingenico, PAX" required>
            </div>
            <div class="col-md-6">
              <label for="tmModel" class="form-label">Model <span class="text-muted small">(optional)</span></label>
              <input type="text" name="model" id="tmModel" class="form-control" maxlength="50" placeholder="e.g. DX8000, S1F2">
            </div>

            <div class="col-md-6">
              <label for="tmLabel" class="form-label">Label</label>
              <select name="label_id" id="tmLabel" class="form-select" required>
                <option value="" disabled selected>Select a label</option>
<?php foreach ($activeLabels as $l): ?>
                <option value="<?= (int) $l['id'] ?>"><?= e($l['name']) ?></option>
<?php endforeach; ?>
                <option value="__new">+ Add new label…</option>
              </select>
              <div class="input-group input-group-sm mt-2 d-none" id="tmNewLabelWrap">
                <input type="text" id="tmNewLabel" class="form-control" maxlength="<?= TERMINAL_LABEL_MAX ?>" placeholder="New label name" aria-label="New label name">
                <button type="button" class="btn btn-primary" id="tmNewLabelAdd">Add</button>
                <button type="button" class="btn btn-outline-secondary" id="tmNewLabelCancel">Cancel</button>
              </div>
              <div class="form-text"><a href="terminal_labels.php">Manage labels</a></div>
            </div>

            <div class="col-md-6">
              <span class="form-label d-block">Bank</span>
              <div role="radiogroup" aria-label="Bank">
<?php foreach (TERMINAL_BANKS as $key => $text): ?>
                <input type="radio" class="btn-check" name="bank_type" id="tmBank_<?= e($key) ?>" value="<?= e($key) ?>" autocomplete="off">
                <label class="btn btn-outline-<?= $key === 'bdo' ? 'success' : 'warning' ?>" for="tmBank_<?= e($key) ?>"><?= e($text) ?></label>
<?php endforeach; ?>
              </div>
            </div>

            <div class="col-md-6">
              <label for="tmVersion" class="form-label">Current Version <span class="text-muted small">(optional)</span></label>
              <input type="text" name="current_version" id="tmVersion" class="form-control" maxlength="30" placeholder="e.g. 16.20">
            </div>

            <div class="col-12">
              <label for="tmRemarks" class="form-label">Remarks <span class="text-muted small">(optional)</span></label>
              <textarea name="remarks" id="tmRemarks" class="form-control" rows="3" maxlength="<?= TERMINAL_REMARKS_MAX ?>" placeholder="Notes about this terminal"></textarea>
            </div>

            <div class="col-12">
              <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="form-label mb-0">FAQ <span class="text-muted small">(optional, up to <?= TERMINAL_FAQ_MAX ?>)</span></span>
                <button type="button" class="btn btn-sm btn-outline-primary" id="tmAddFaq"><i class="bi bi-plus-lg"></i> Add FAQ</button>
              </div>
              <div id="tmFaqList"></div>
              <p class="small text-muted mb-0" id="tmFaqEmpty">No FAQs yet. Use <strong>Add FAQ</strong> to add a question and its answer.</p>
              <template id="tmFaqTemplate">
                <div class="border rounded p-2 mb-2 faq-row">
                  <input type="hidden" name="faq_id[]" class="faq-id" value="">
                  <div class="d-flex align-items-center gap-1 mb-1">
                    <span class="small fw-semibold me-auto faq-number">FAQ</span>
                    <button type="button" class="btn btn-sm btn-outline-secondary py-0 faq-up" title="Move up" aria-label="Move up"><i class="bi bi-arrow-up"></i></button>
                    <button type="button" class="btn btn-sm btn-outline-secondary py-0 faq-down" title="Move down" aria-label="Move down"><i class="bi bi-arrow-down"></i></button>
                    <button type="button" class="btn btn-sm btn-outline-danger py-0 faq-remove" title="Remove" aria-label="Remove FAQ"><i class="bi bi-trash"></i></button>
                  </div>
                  <input type="text" name="faq_question[]" class="form-control form-control-sm mb-1 faq-question" maxlength="<?= TERMINAL_FAQ_QUESTION_MAX ?>" placeholder="Question" aria-label="Question">
                  <textarea name="faq_answer[]" class="form-control form-control-sm faq-answer" rows="2" maxlength="<?= TERMINAL_FAQ_ANSWER_MAX ?>" placeholder="Answer" aria-label="Answer"></textarea>
                </div>
              </template>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary" id="tmSave"><i class="bi bi-save"></i> Save Terminal</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    (function () {
      var csrf = <?= json_encode(csrf_token()) ?>;
      var defaultImage = <?= json_encode(TERMINAL_IMAGE_DEFAULT) ?>;
      var form = document.getElementById("terminalForm");
      var errors = document.getElementById("terminalErrors");
      var save = document.getElementById("tmSave");
      var labelSelect = document.getElementById("tmLabel");
      var newLabelWrap = document.getElementById("tmNewLabelWrap");
      var newLabelInput = document.getElementById("tmNewLabel");
      var slots = Array.prototype.slice.call(form.querySelectorAll(".photo-slot"));
      var lastLabel = "";

      function modal() { return bootstrap.Modal.getOrCreateInstance(document.getElementById("terminalModal")); }

      function showErrors(list) {
        errors.innerHTML = "";
        var ul = document.createElement("ul");
        ul.className = "mb-0";
        list.forEach(function (text) { var li = document.createElement("li"); li.textContent = text; ul.appendChild(li); });
        errors.appendChild(ul);
        errors.classList.remove("d-none");
        errors.scrollIntoView({ block: "nearest" });
      }

      /* ---------- Label dropdown (+ Add new label) ---------- */

      function newOption() { return labelSelect.querySelector('option[value="__new"]'); }

      function addLabelOption(id, name, extra) {
        var o = document.createElement("option");
        o.value = String(id);
        o.textContent = name + (extra || "");
        labelSelect.insertBefore(o, newOption());
        return o;
      }

      labelSelect.addEventListener("change", function () {
        if (labelSelect.value === "__new") {
          newLabelWrap.classList.remove("d-none");
          newLabelInput.value = "";
          newLabelInput.focus();
        } else {
          lastLabel = labelSelect.value;
          newLabelWrap.classList.add("d-none");
        }
      });

      function closeNewLabel() {
        newLabelWrap.classList.add("d-none");
        labelSelect.value = lastLabel;
      }
      document.getElementById("tmNewLabelCancel").addEventListener("click", closeNewLabel);

      function addNewLabel() {
        var name = newLabelInput.value.trim();
        if (!name) { newLabelInput.focus(); return; }
        var btn = document.getElementById("tmNewLabelAdd");
        btn.disabled = true;
        var body = new FormData();
        body.append("csrf_token", csrf);
        body.append("action", "add_label");
        body.append("name", name);
        post(body)
          .then(function (data) {
            addLabelOption(data.id, data.name);
            lastLabel = String(data.id);
            labelSelect.value = lastLabel;
            newLabelWrap.classList.add("d-none");
            // Keep the Label filter in step too.
            var filter = document.getElementById("fLabel");
            var o = document.createElement("option"); o.value = String(data.id); o.textContent = data.name; filter.append(o);
          })
          .catch(function (err) { showErrors(Array.isArray(err) ? err : ["Could not add the label."]); })
          .then(function () { btn.disabled = false; });
      }
      document.getElementById("tmNewLabelAdd").addEventListener("click", addNewLabel);
      newLabelInput.addEventListener("keydown", function (e) {
        if (e.key === "Enter") { e.preventDefault(); addNewLabel(); }
        if (e.key === "Escape") { e.preventDefault(); e.stopPropagation(); closeNewLabel(); }
      });

      /* ---------- Photos ---------- */

      // Each slot remembers the saved photo (data-current) so "Undo" and "Remove" can switch back.
      function renderSlot(slot) {
        var input = slot.querySelector(".slot-input");
        var remove = slot.querySelector(".slot-remove");
        var preview = slot.querySelector(".slot-preview");
        var hasSaved = !!slot.dataset.current;
        var file = input.files[0];
        preview.src = file ? URL.createObjectURL(file) : (hasSaved && !remove.checked ? slot.dataset.current : defaultImage);
        preview.style.opacity = remove.checked && !file ? "0.4" : "1";
        slot.querySelector(".slot-action").textContent = (file || hasSaved ? "Replace" : "Add") + " photo " + slot.dataset.slot;
        slot.querySelector(".slot-remove-wrap").classList.toggle("d-none", !hasSaved || !!file);
        slot.querySelector(".slot-clear").classList.toggle("d-none", !file);
      }

      slots.forEach(function (slot) {
        var input = slot.querySelector(".slot-input");
        input.addEventListener("change", function () {
          if (input.files[0] && input.files[0].size > 5 * 1024 * 1024) {
            showErrors(["Photo " + slot.dataset.slot + " must be 5 MB or smaller."]);
            input.value = "";
          }
          slot.querySelector(".slot-remove").checked = false;
          renderSlot(slot);
        });
        slot.querySelector(".slot-remove").addEventListener("change", function () { renderSlot(slot); });
        slot.querySelector(".slot-clear").addEventListener("click", function () { input.value = ""; renderSlot(slot); });
      });

      /* ---------- FAQs ---------- */

      var faqList = document.getElementById("tmFaqList");
      var faqTemplate = document.getElementById("tmFaqTemplate");
      var addFaqBtn = document.getElementById("tmAddFaq");
      var faqMax = <?= TERMINAL_FAQ_MAX ?>;

      function renumberFaqs() {
        var rows = faqList.querySelectorAll(".faq-row");
        rows.forEach(function (row, i) {
          row.querySelector(".faq-number").textContent = "FAQ " + (i + 1);
          row.querySelector(".faq-up").disabled = i === 0;
          row.querySelector(".faq-down").disabled = i === rows.length - 1;
        });
        document.getElementById("tmFaqEmpty").classList.toggle("d-none", rows.length > 0);
        addFaqBtn.disabled = rows.length >= faqMax;
      }

      function addFaq(faq) {
        var row = faqTemplate.content.firstElementChild.cloneNode(true);
        row.querySelector(".faq-id").value = faq && faq.id ? faq.id : "";
        row.querySelector(".faq-question").value = faq ? faq.q : "";
        row.querySelector(".faq-answer").value = faq ? faq.a : "";
        row.querySelector(".faq-remove").addEventListener("click", function () {
          var filled = row.querySelector(".faq-question").value.trim() || row.querySelector(".faq-answer").value.trim();
          if (filled && !confirm("Remove this FAQ? It is deleted when you save the terminal.")) { return; }
          row.remove();
          renumberFaqs();
        });
        row.querySelector(".faq-up").addEventListener("click", function () {
          if (row.previousElementSibling) { faqList.insertBefore(row, row.previousElementSibling); renumberFaqs(); }
        });
        row.querySelector(".faq-down").addEventListener("click", function () {
          if (row.nextElementSibling) { faqList.insertBefore(row.nextElementSibling, row); renumberFaqs(); }
        });
        faqList.appendChild(row);
        renumberFaqs();
        return row;
      }

      addFaqBtn.addEventListener("click", function () { addFaq(null).querySelector(".faq-question").focus(); });

      /* ---------- Open / save / delete ---------- */

      function open(data) {
        form.reset();
        errors.classList.add("d-none");
        newLabelWrap.classList.add("d-none");
        labelSelect.querySelectorAll("option[data-inactive]").forEach(function (o) { o.remove(); });
        document.getElementById("terminalModalTitle").textContent = data ? "Edit Terminal" : "Register Terminal";
        document.getElementById("tmId").value = data ? data.id : "";
        document.getElementById("tmName").value = data ? data.name : "";
        document.getElementById("tmBrand").value = data ? data.brand : "";
        document.getElementById("tmModel").value = data ? data.model : "";
        document.getElementById("tmVersion").value = data ? data.version : "";
        document.getElementById("tmRemarks").value = data ? data.remarks : "";
        faqList.innerHTML = "";
        (data ? JSON.parse(data.faqs || "[]") : []).forEach(addFaq);
        renumberFaqs();
        // A terminal may keep a label that has since been deactivated.
        if (data && !labelSelect.querySelector('option[value="' + data.label + '"]')) {
          addLabelOption(data.label, data.labelName, " (inactive)").setAttribute("data-inactive", "");
        }
        lastLabel = data ? data.label : "";
        labelSelect.value = lastLabel;
        form.querySelectorAll('input[name="bank_type"]').forEach(function (r) { r.checked = !!data && r.value === data.bank; });
        var saved = data ? JSON.parse(data.photos || "{}") : {};
        slots.forEach(function (slot) {
          slot.dataset.current = saved[slot.dataset.slot] || "";
          renderSlot(slot);
        });
        modal().show();
      }

      document.getElementById("addTerminal").addEventListener("click", function () { open(null); });
      document.querySelectorAll(".edit-terminal").forEach(function (btn) {
        btn.addEventListener("click", function () { open(btn.dataset); });
      });

      function post(body) {
        return fetch("terminals.php", { method: "POST", body: body })
          .then(function (res) { return res.json().catch(function () { return { errors: ["Unexpected server response (" + res.status + ")."] }; }); })
          .then(function (data) { if (data.errors) { throw data.errors; } return data; });
      }

      form.addEventListener("submit", function (e) {
        e.preventDefault();
        errors.classList.add("d-none");
        if (labelSelect.value === "__new") { showErrors(["Add the new label first, or choose one from the list."]); return; }
        save.disabled = true;
        post(new FormData(form))
          .then(function () { location.reload(); })
          .catch(function (err) {
            showErrors(Array.isArray(err) ? err : ["Could not save. Please check your connection and try again."]);
            save.disabled = false;
          });
      });

      document.querySelectorAll(".delete-terminal").forEach(function (btn) {
        btn.addEventListener("click", function () {
          if (!confirm('Delete terminal "' + btn.dataset.name + '" and its photos? This cannot be undone.')) { return; }
          var body = new FormData();
          body.append("csrf_token", csrf);
          body.append("action", "delete");
          body.append("terminal_id", btn.dataset.id);
          btn.disabled = true;
          post(body).then(function () { location.reload(); })
            .catch(function (err) { alert(Array.isArray(err) ? err.join("\n") : "Could not delete."); btn.disabled = false; });
        });
      });
    })();
  </script>
<?php endif; ?>

  <!-- Remarks & FAQ (everyone) -->
  <div class="modal fade" id="terminalInfoModal" tabindex="-1" aria-labelledby="terminalInfoTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="terminalInfoTitle">Terminal</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div id="tiRemarksWrap" class="mb-3">
            <h6 class="fw-semibold">Remarks</h6>
            <p id="tiRemarks" class="mb-0" style="white-space: pre-wrap"></p>
          </div>
          <div id="tiFaqWrap">
            <h6 class="fw-semibold">FAQ</h6>
            <div class="accordion" id="tiFaqs"></div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>

  <script>
    (function () {
      var list = document.getElementById("tiFaqs");
      document.querySelectorAll(".view-terminal").forEach(function (btn) {
        btn.addEventListener("click", function () {
          var faqs = JSON.parse(btn.dataset.faqs || "[]");
          document.getElementById("terminalInfoTitle").textContent = btn.dataset.name;
          document.getElementById("tiRemarks").textContent = btn.dataset.remarks;
          document.getElementById("tiRemarksWrap").classList.toggle("d-none", !btn.dataset.remarks);
          document.getElementById("tiFaqWrap").classList.toggle("d-none", !faqs.length);
          list.innerHTML = "";
          faqs.forEach(function (faq, i) {
            var item = document.createElement("div");
            item.className = "accordion-item";
            var header = document.createElement("h2");
            header.className = "accordion-header";
            var toggle = document.createElement("button");
            toggle.type = "button";
            toggle.className = "accordion-button collapsed";
            toggle.setAttribute("data-bs-toggle", "collapse");
            toggle.setAttribute("data-bs-target", "#tiFaq" + i);
            toggle.textContent = faq.q;
            header.appendChild(toggle);
            var body = document.createElement("div");
            body.id = "tiFaq" + i;
            body.className = "accordion-collapse collapse";
            body.setAttribute("data-bs-parent", "#tiFaqs");
            var inner = document.createElement("div");
            inner.className = "accordion-body";
            inner.style.whiteSpace = "pre-wrap";
            inner.textContent = faq.a;
            body.appendChild(inner);
            item.appendChild(header);
            item.appendChild(body);
            list.appendChild(item);
          });
          bootstrap.Modal.getOrCreateInstance(document.getElementById("terminalInfoModal")).show();
        });
      });
    })();
  </script>

<?php
require __DIR__ . '/includes/footer.php';
require __DIR__ . '/includes/scripts.php';
