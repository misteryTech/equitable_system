<?php
/**
 * Serial Inventory — Records: every registered serial with its registered date, newest first.
 * Search, date range, Copy all (for Excel), CSV export and Bulk check (paste many
 * serials, see which exist, register the missing ones) for everyone;
 * admins can also edit (serial stays unique) or delete entries.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/serial_inventory.php';
$user    = require_role('field_engineer', 'admin');
$isAdmin = is_admin($user);

$pageTitle  = 'Serial Records';
$activePage = 'serial-records';
$today      = date('Y-m-d');   // Asia/Manila (config.php)
$errors     = [];
$bulk       = null;            // results of a bulk check / register
$bulkText   = '';
const SERIAL_BULK_MAX = 1000;  // lines per bulk check

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id     = (int) ($_POST['id'] ?? 0);

    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($action === 'bulk_check' || $action === 'bulk_register') {
        // One serial per line; each line is cleaned like a scan.
        $bulkText = str_replace(["\r\n", "\r"], "\n", (string) ($_POST['serials'] ?? ''));
        $lines    = array_values(array_filter(array_map('trim', explode("\n", $bulkText)), 'strlen'));
        if (!$lines) {
            $errors[] = 'Paste at least one serial, one per line.';
        } elseif (count($lines) > SERIAL_BULK_MAX) {
            $errors[] = 'Up to ' . SERIAL_BULK_MAX . ' lines at a time; this has ' . count($lines) . '.';
        } else {
            $bulk = [];
            $seen = [];
            foreach ($lines as $line) {
                $serial = clean_serial($line);
                $bulk[] = ['line' => $line, 'serial' => $serial, 'repeat' => $serial !== null && isset($seen[$serial])];
                if ($serial !== null) {
                    $seen[$serial] = true;
                }
            }
            if ($action === 'bulk_register') {
                foreach (array_keys($seen) as $serial) {
                    $done = register_serial(db(), $serial, $today);
                    if ($done['status'] === 'registered') {
                        $seen[$serial] = 'new';
                    }
                }
            }
            $dates = serial_dates(db(), array_keys($seen));
            foreach ($bulk as &$row) {
                if ($row['serial'] === null) {
                    $row['status'] = 'invalid';
                } elseif (isset($dates[$row['serial']])) {
                    $row['status'] = !$row['repeat'] && ($seen[$row['serial']] ?? null) === 'new' ? 'registered' : 'exists';
                    $row['date']   = $dates[$row['serial']];
                } else {
                    $row['status'] = 'not_found';
                }
            }
            unset($row);
        }
    } else {
        if (!$isAdmin) {
            $_SESSION['flash'] = ['danger', 'Only admins can change serial records.'];
        } elseif ($action === 'update') {
            $serial = clean_serial((string) ($_POST['serial'] ?? ''));
            $date   = (string) ($_POST['registered_date'] ?? '');
            if ($serial === null) {
                $_SESSION['flash'] = ['danger', 'Not saved: no valid serial number (letters, numbers and - / . _ only, 3 to 100 characters).'];
            } elseif (!serial_date_valid($date, $today)) {
                $_SESSION['flash'] = ['danger', 'Not saved: please pick a valid date (today or earlier).'];
            } else {
                $other = check_serial(db(), $serial);
                if ($other['status'] === 'exists' && $other['id'] !== $id) {
                    $_SESSION['flash'] = ['danger', "Not saved: $serial is already registered (since " . date('M j, Y', strtotime($other['date'])) . ').'];
                } else {
                    try {
                        db()->prepare('UPDATE serial_inventory SET serial = ?, registered_date = ? WHERE id = ?')->execute([$serial, $date, $id]);
                        $_SESSION['flash'] = ['success', "Saved $serial."];
                    } catch (PDOException $ex) {
                        if ($ex->getCode() !== '23000') {
                            throw $ex;
                        }
                        $_SESSION['flash'] = ['danger', "Not saved: $serial is already registered."];
                    }
                }
            }
        } elseif ($action === 'delete') {
            $stmt = db()->prepare('DELETE FROM serial_inventory WHERE id = ?');
            $stmt->execute([$id]);
            $_SESSION['flash'] = $stmt->rowCount() ? ['success', 'Serial deleted.'] : ['warning', 'Serial not found.'];
        }
        redirect('serial_records.php?' . http_build_query(array_intersect_key($_POST, array_flip(['q', 'from', 'to']))));
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$isDate = static function ($v): bool {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $v);
    return $d !== false && $d->format('Y-m-d') === $v;
};
$q    = strtoupper(trim((string) ($_GET['q'] ?? '')));
$from = $isDate($_GET['from'] ?? '') ? $_GET['from'] : '';
$to   = $isDate($_GET['to'] ?? '') ? $_GET['to'] : '';
if ($from !== '' && $to !== '' && $from > $to) {
    [$from, $to] = [$to, $from];
}

$where  = '1 = 1';
$params = [];
if ($q !== '') {
    $where   .= ' AND serial LIKE ?';
    $params[] = '%' . addcslashes($q, '%_\\') . '%';
}
if ($from !== '') {
    $where   .= ' AND registered_date >= ?';
    $params[] = $from;
}
if ($to !== '') {
    $where   .= ' AND registered_date <= ?';
    $params[] = $to;
}
$select = "SELECT id, serial, registered_date FROM serial_inventory WHERE $where ORDER BY registered_date DESC, id DESC";

// CSV export: every matching row.
if (($_GET['export'] ?? '') === 'csv') {
    $stmt = db()->prepare($select);
    $stmt->execute($params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="serial-inventory' . ($from !== '' ? "-from-$from" : '') . ($to !== '' ? "-to-$to" : '') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // BOM so Excel reads UTF-8
    fputcsv($out, ['Serial', 'Registered Date']);
    while ($row = $stmt->fetch()) {
        // Serials never start with = + - @ (see SERIAL_PATTERN), so no formula escaping is needed.
        fputcsv($out, [$row['serial'], $row['registered_date']]);
    }
    fclose($out);
    exit;
}

$stmt = db()->prepare("SELECT COUNT(*) FROM serial_inventory WHERE $where");
$stmt->execute($params);
$total = (int) $stmt->fetchColumn();

$limit = 1000;
$stmt  = db()->prepare("$select LIMIT $limit");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$filters = array_filter(['q' => $q, 'from' => $from, 'to' => $to], 'strlen');

$bulkCounts = ['registered' => 0, 'exists' => 0, 'not_found' => 0, 'invalid' => 0];
foreach ($bulk ?? [] as $row) {
    if (!$row['repeat']) {   // a serial pasted twice counts once
        $bulkCounts[$row['status']]++;
    }
}
$bulkLook = [
    'registered' => ['bg-success', 'Registered now'],
    'exists'     => ['bg-success-subtle text-success-emphasis border border-success', 'Exists'],
    'not_found'  => ['bg-danger', 'Not registered'],
    'invalid'    => ['bg-secondary', 'No serial found'],
];

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
          <li class="breadcrumb-item">Serial Inventory</li>
          <li class="breadcrumb-item active">Records</li>
        </ol>
      </nav>
    </div><!-- End Page Title -->

    <section class="section">
<?php if ($flash): ?>
      <div class="alert alert-<?= e($flash[0]) ?>"><?= e($flash[1]) ?></div>
<?php endif; ?>
<?php if ($errors): ?>
      <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

      <div class="card">
        <div class="card-body pt-3">
          <a class="fw-semibold" data-bs-toggle="collapse" href="#bulkBox" role="button" aria-expanded="<?= $bulk !== null || $bulkText !== '' ? 'true' : 'false' ?>" aria-controls="bulkBox">
            <i class="bi bi-list-check"></i> Bulk check — paste many serials and see which are registered
          </a>
          <div class="collapse <?= $bulk !== null || $bulkText !== '' ? 'show' : '' ?>" id="bulkBox">
            <form method="post" class="mt-3">
              <?= csrf_field() ?>
              <label for="bulkSerials" class="form-label small">Serials, one per line (up to <?= SERIAL_BULK_MAX ?>). Text like "SN: …" is cleaned the same way as a scan.</label>
              <textarea name="serials" id="bulkSerials" class="form-control font-monospace" rows="6" placeholder="2367KD8E6059&#10;SN: PAX-A920-0001&#10;…"><?= e($bulkText) ?></textarea>
              <div class="d-flex flex-wrap gap-2 mt-2">
                <button type="submit" name="action" value="bulk_check" class="btn btn-primary"><i class="bi bi-search"></i> Check</button>
<?php if ($bulk !== null && $bulkCounts['not_found'] > 0): ?>
                <button type="submit" name="action" value="bulk_register" class="btn btn-success" onclick="return confirm('Register <?= (int) $bulkCounts['not_found'] ?> missing serial<?= $bulkCounts['not_found'] === 1 ? '' : 's' ?> with today\'s date?')">
                  <i class="bi bi-plus-circle"></i> Register all missing (<?= (int) $bulkCounts['not_found'] ?>)
                </button>
<?php endif; ?>
              </div>
            </form>

<?php if ($bulk !== null): ?>
            <div class="d-flex flex-wrap align-items-center gap-2 mt-3 mb-2">
<?php if ($bulkCounts['registered']): ?>
              <span class="badge bg-success fs-6 fw-normal">Registered now <?= (int) $bulkCounts['registered'] ?></span>
<?php endif; ?>
              <span class="badge bg-success-subtle text-success-emphasis border border-success fs-6 fw-normal">Exist <?= (int) $bulkCounts['exists'] ?></span>
              <span class="badge bg-danger fs-6 fw-normal">Not registered <?= (int) $bulkCounts['not_found'] ?></span>
<?php if ($bulkCounts['invalid']): ?>
              <span class="badge bg-secondary fs-6 fw-normal">No serial found <?= (int) $bulkCounts['invalid'] ?></span>
<?php endif; ?>
              <button type="button" class="btn btn-sm btn-outline-primary ms-auto" id="copyBulk"><i class="bi bi-clipboard"></i> Copy results</button>
            </div>
            <div class="table-responsive" style="max-height: 24rem">
              <table class="table table-sm align-middle mb-0">
                <thead><tr><th>#</th><th>Pasted</th><th>Serial</th><th>Status</th><th>Registered</th></tr></thead>
                <tbody>
<?php foreach ($bulk as $i => $row): ?>
                  <tr>
                    <td class="text-muted small"><?= $i + 1 ?></td>
                    <td class="small text-break text-muted"><?= e(mb_strimwidth($row['line'], 0, 60, '…')) ?></td>
                    <td class="font-monospace text-break"><?= e($row['serial'] ?? '') ?></td>
                    <td><span class="badge <?= e($bulkLook[$row['status']][0]) ?>"><?= e($bulkLook[$row['status']][1]) ?></span><?= $row['repeat'] ? ' <span class="small text-muted">(listed twice)</span>' : '' ?></td>
                    <td class="text-nowrap small"><?= isset($row['date']) ? e(date('M j, Y', strtotime($row['date']))) : '' ?></td>
                  </tr>
<?php endforeach; ?>
                </tbody>
              </table>
            </div>
<?php endif; ?>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-body pt-3">

          <form method="get" class="row g-2 align-items-end mb-3">
            <div class="col-md-4">
              <label for="fQ" class="form-label">Serial</label>
              <input type="search" name="q" id="fQ" class="form-control font-monospace text-uppercase" placeholder="Search serial…" value="<?= e($q) ?>">
            </div>
            <div class="col-6 col-md-2">
              <label for="fFrom" class="form-label">Registered from</label>
              <input type="date" name="from" id="fFrom" class="form-control" value="<?= e($from) ?>">
            </div>
            <div class="col-6 col-md-2">
              <label for="fTo" class="form-label">To</label>
              <input type="date" name="to" id="fTo" class="form-control" value="<?= e($to) ?>">
            </div>
            <div class="col-md-4 d-flex flex-wrap gap-2">
              <button type="submit" class="btn btn-primary"><i class="bi bi-funnel"></i> Filter</button>
<?php if ($filters): ?>
              <a href="serial_records.php" class="btn btn-outline-secondary">Clear</a>
<?php endif; ?>
              <a href="serial_scan.php" class="btn btn-success ms-auto"><i class="bi bi-upc-scan"></i> Scan</a>
            </div>
          </form>

          <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
            <span class="fw-semibold"><?= number_format($total) ?> serial<?= $total === 1 ? '' : 's' ?></span>
<?php if ($total > $limit): ?>
            <span class="small text-muted">— showing the newest <?= number_format($limit) ?>; Copy all copies these, CSV export has every one.</span>
<?php endif; ?>
            <button type="button" class="btn btn-sm btn-outline-primary ms-auto" id="copyAll" <?= $rows ? '' : 'disabled' ?>><i class="bi bi-clipboard"></i> Copy all</button>
            <a href="serial_records.php?<?= e(http_build_query($filters + ['export' => 'csv'])) ?>" class="btn btn-sm btn-outline-success <?= $total ? '' : 'disabled' ?>"><i class="bi bi-filetype-csv"></i> CSV</a>
          </div>

          <div class="table-responsive">
            <table class="table table-sm align-middle">
              <thead>
                <tr>
                  <th>Serial</th>
                  <th>Registered Date</th>
<?php if ($isAdmin): ?>
                  <th class="text-end">Action</th>
<?php endif; ?>
                </tr>
              </thead>
              <tbody>
<?php foreach ($rows as $r):
    $shownDate = date('M j, Y', strtotime($r['registered_date']));
?>
                <tr>
                  <td class="font-monospace text-break"><?= e($r['serial']) ?></td>
                  <td class="text-nowrap"><?= e($shownDate) ?></td>
<?php if ($isAdmin): ?>
                  <td class="text-end text-nowrap">
                    <button type="button" class="btn btn-sm btn-outline-primary edit-serial" data-id="<?= (int) $r['id'] ?>" data-serial="<?= e($r['serial']) ?>" data-date="<?= e($r['registered_date']) ?>" title="Edit" aria-label="Edit <?= e($r['serial']) ?>"><i class="bi bi-pencil"></i></button>
                    <form method="post" class="d-inline" onsubmit="return confirm('Delete serial <?= e($r['serial']) ?> (registered <?= e($shownDate) ?>)? This cannot be undone.')">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
<?php foreach ($filters as $key => $value): ?>
                      <input type="hidden" name="<?= e($key) ?>" value="<?= e($value) ?>">
<?php endforeach; ?>
                      <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete" aria-label="Delete <?= e($r['serial']) ?>"><i class="bi bi-trash"></i></button>
                    </form>
                  </td>
<?php endif; ?>
                </tr>
<?php endforeach; ?>
<?php if (!$rows): ?>
                <tr><td colspan="<?= $isAdmin ? 3 : 2 ?>" class="text-muted text-center py-4"><?= $filters ? 'No serials match your filters.' : 'No serials registered yet.' ?></td></tr>
<?php endif; ?>
              </tbody>
            </table>
          </div>

        </div>
      </div>
    </section>

  </main><!-- End #main -->

  <style>
    #fQ::placeholder { text-transform: none; }   /* typed text is upper-cased, the hint isn't */
  </style>

<?php if ($isAdmin): ?>
  <div class="modal fade" id="serialModal" tabindex="-1" aria-labelledby="serialModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <form method="post" class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="serialModalTitle">Edit Serial</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="id" id="smId">
<?php foreach ($filters as $key => $value): ?>
          <input type="hidden" name="<?= e($key) ?>" value="<?= e($value) ?>">
<?php endforeach; ?>
          <div class="mb-3">
            <label for="smSerial" class="form-label">Serial</label>
            <input type="text" name="serial" id="smSerial" class="form-control font-monospace text-uppercase" maxlength="100" required>
            <div class="form-text">Each serial can be registered only once.</div>
          </div>
          <div>
            <label for="smDate" class="form-label">Registered date</label>
            <input type="date" name="registered_date" id="smDate" class="form-control" max="<?= e($today) ?>" required>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Save</button>
        </div>
      </form>
    </div>
  </div>
<?php endif; ?>

  <script src="assets/js/email-generator.js"></script>
  <script>
    (function () {
      // Tab-separated rows paste into Excel as columns.
      function copyRows(header, rows, what) {
        var text = header.join("\t") + "\r\n" + rows.map(function (r) { return r.join("\t"); }).join("\r\n");
        EmailGen.copyText(text).then(function (ok) {
          EmailGen.toast(ok ? "Copied " + rows.length + " " + what + (rows.length === 1 ? "" : "s") + " — paste into Excel." : "Could not copy.", ok ? "success" : "danger");
        });
      }

      var rows = <?= json_encode(array_map(fn($r) => [$r['serial'], $r['registered_date']], $rows)) ?>;
      document.getElementById("copyAll").addEventListener("click", function () { copyRows(["Serial", "Registered Date"], rows, "serial"); });

<?php if ($bulk !== null): ?>
      var bulk = <?= json_encode(array_map(fn($r) => [$r['line'], (string) $r['serial'], $bulkLook[$r['status']][1], $r['date'] ?? ''], $bulk)) ?>;
      document.getElementById("copyBulk").addEventListener("click", function () { copyRows(["Pasted", "Serial", "Status", "Registered Date"], bulk, "row"); });
<?php endif; ?>

      document.querySelectorAll(".edit-serial").forEach(function (btn) {
        btn.addEventListener("click", function () {
          document.getElementById("smId").value = btn.dataset.id;
          document.getElementById("smSerial").value = btn.dataset.serial;
          document.getElementById("smDate").value = btn.dataset.date;
          bootstrap.Modal.getOrCreateInstance(document.getElementById("serialModal")).show();
        });
      });
    })();
  </script>

<?php
require __DIR__ . '/includes/footer.php';
require __DIR__ . '/includes/scripts.php';
