<?php
/**
 * Route Fares: the ROUTE FARE MASTER LIST (e.g. "MAKAR DROPOINT ↔ SM — PUJ — ₱20").
 * Admins and engineers can add. Admin fares are approved immediately; engineer
 * fares are pending (usable right away, marked "pending") until an admin approves.
 * Engineers edit/delete only their own pending fares and can report a wrong fare.
 * Every fare change is logged in transpo_route_rate_history.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/transpo.php';
$user    = require_role('field_engineer', 'admin');
$isAdmin = is_admin($user);

$pageTitle  = 'Route Fares';
$activePage = 'route-fares';

$tab = $isAdmin && ($_GET['tab'] ?? '') === 'reports' ? 'reports' : 'list';

/** Fetch a rate with its location names. */
function load_rate(int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT r.*, f.name AS from_name, t.name AS to_name FROM transpo_route_rates r
         JOIN transpo_locations f ON f.id = r.from_location_id JOIN transpo_locations t ON t.id = r.to_location_id
         WHERE r.id = ?'
    );
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
}

function route_label(array $r): string
{
    return $r['from_name'] . ($r['is_two_way'] ? ' ↔ ' : ' → ') . $r['to_name'] . ' — ' . $r['mode'] . ' — ' . transpo_money($r['fare']);
}

/** Engineers may change only their own pending fares; admins any. */
function can_change(array $rate, array $user): bool
{
    return is_admin($user) || ($rate['status'] === 'pending' && (int) $rate['created_by'] === (int) $user['user_id']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $back   = $_POST['back'] ?? 'route_fares.php';
    $back   = preg_match('/^route_fares\.php(\?[A-Za-z0-9_=&%.\-]*)?(#[A-Za-z0-9\-]+)?$/', $back) ? $back : 'route_fares.php';
    $flash  = null;

    if (!csrf_verify()) {
        $flash = ['danger', 'Your session expired. Please try again.'];
    } elseif ($action === 'add') {
        $fare = transpo_parse_fare($_POST['fare'] ?? '');
        if ($fare === null) {
            $flash = ['danger', 'Fare must be a positive amount (e.g. 20 or 25.50).'];
        } else {
            $result = transpo_add_rate((int) ($_POST['from_id'] ?? 0), (int) ($_POST['to_id'] ?? 0), (string) ($_POST['mode'] ?? ''), $fare, !empty($_POST['two_way']), $user);
            if ($result['ok']) {
                $flash = ['success', 'Route fare added: ' . route_label(load_rate((int) $result['rate']['id'])) . ($isAdmin ? '.' : ' (pending admin approval — you can use it right away).')];
            } elseif (!empty($result['conflict'])) {
                $flash = ['warning', $result['error'], 'route_fares.php?highlight=' . $result['conflict']['id'] . '#rate-' . $result['conflict']['id']];
            } else {
                $flash = ['danger', $result['error']];
            }
        }
    } elseif ($action === 'bulk_add') {
        $fromId = (int) ($_POST['from_id'] ?? 0);
        $added  = 0;
        $issues = [];
        foreach ((array) ($_POST['rows'] ?? []) as $i => $row) {
            $toId = (int) ($row['to_id'] ?? 0);
            $fareText = trim((string) ($row['fare'] ?? ''));
            if (!$toId && $fareText === '') {
                continue;                                    // empty row
            }
            $label = transpo_location($toId)['name'] ?? ('row ' . ($i + 1));
            $fare  = transpo_parse_fare($fareText);
            if ($fare === null) {
                $issues[] = "$label: fare must be a positive amount";
                continue;
            }
            $result = transpo_add_rate($fromId, $toId, (string) ($row['mode'] ?? ''), $fare, !empty($row['two_way']), $user);
            if ($result['ok']) {
                $added++;
            } else {
                $issues[] = "$label: " . rtrim($result['error'], '.');
            }
        }
        $flash = [$issues ? ($added ? 'warning' : 'danger') : 'success',
            "Added $added route fare" . ($added === 1 ? '' : 's') . '.' . ($issues ? ' Skipped: ' . implode('; ', $issues) . '.' : '')];
    } else {
        $rate = load_rate((int) ($_POST['rate_id'] ?? 0));
        if (!$rate && $action !== 'resolve_report') {
            $flash = ['danger', 'Route fare not found.'];
        } elseif ($action === 'edit') {
            $fare   = transpo_parse_fare($_POST['fare'] ?? '');
            $twoWay = !empty($_POST['two_way']);
            if (!can_change($rate, $user)) {
                $flash = ['danger', 'Approved fares can only be changed by an admin. Use "Report wrong fare" instead.'];
            } elseif ($fare === null) {
                $flash = ['danger', 'Fare must be a positive amount.'];
            } elseif ($twoWay !== (bool) $rate['is_two_way'] && ($conflict = transpo_rate_conflict((int) $rate['from_location_id'], (int) $rate['to_location_id'], $rate['mode'], $twoWay, (int) $rate['id']))) {
                $flash = ['warning', 'The reverse route already has its own fare (' . transpo_money($conflict['fare']) . '), so this one cannot be two-way.'];
            } else {
                db()->prepare('UPDATE transpo_route_rates SET fare = ?, is_two_way = ? WHERE id = ?')->execute([$fare, $twoWay ? 1 : 0, $rate['id']]);
                if (abs($fare - (float) $rate['fare']) > 0.001) {
                    transpo_log_fare((int) $rate['id'], (float) $rate['fare'], $fare, (int) $user['user_id']);
                }
                $flash = ['success', 'Route fare updated: ' . route_label(load_rate((int) $rate['id'])) . '.'];
            }
        } elseif ($action === 'delete') {
            if (!can_change($rate, $user)) {
                $flash = ['danger', 'You can only delete your own pending fares.'];
            } else {
                db()->prepare('DELETE FROM transpo_route_rates WHERE id = ?')->execute([$rate['id']]);   // filed legs keep their fare
                $flash = ['success', 'Route fare deleted: ' . route_label($rate) . '. Filed legs keep the fare they were saved with.'];
            }
        } elseif ($action === 'report') {
            $note = trim((string) ($_POST['note'] ?? ''));
            $sugg = trim((string) ($_POST['suggested_fare'] ?? ''));
            $fare = $sugg === '' ? null : transpo_parse_fare($sugg);
            if ($note === '' || mb_strlen($note) > 255) {
                $flash = ['danger', 'Please describe what is wrong (up to 255 characters).'];
            } elseif ($sugg !== '' && $fare === null) {
                $flash = ['danger', 'Suggested fare must be a positive amount.'];
            } else {
                db()->prepare('INSERT INTO transpo_route_rate_reports (route_rate_id, reported_by, suggested_fare, note) VALUES (?, ?, ?, ?)')
                    ->execute([$rate['id'], $user['user_id'], $fare, $note]);
                notify_admins('transpo_report', full_name($user) . ' reported a wrong fare for ' . route_label($rate) . ': ' . $note, 'route_fares.php?tab=reports', (int) db()->lastInsertId());
                $flash = ['success', 'Thanks — an admin will review the fare for ' . route_label($rate) . '.'];
            }
        } elseif (!$isAdmin) {
            $flash = ['danger', 'Only admins can do that.'];
        } elseif ($action === 'approve') {
            db()->prepare("UPDATE transpo_route_rates SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ? AND status = 'pending'")
                ->execute([$user['user_id'], $rate['id']]);
            notifications_resolve('transpo_rate', (int) $rate['id']);
            $flash = ['success', 'Approved: ' . route_label($rate) . '.'];
        } elseif ($action === 'reject') {
            db()->prepare("DELETE FROM transpo_route_rates WHERE id = ? AND status = 'pending'")->execute([$rate['id']]);
            notifications_resolve('transpo_rate', (int) $rate['id']);
            $flash = ['success', 'Rejected and removed: ' . route_label($rate) . '. Legs already filed keep their fare.'];
        } elseif ($action === 'toggle_active') {
            db()->prepare('UPDATE transpo_route_rates SET is_active = 1 - is_active WHERE id = ?')->execute([$rate['id']]);
            $flash = ['success', ($rate['is_active'] ? 'Deactivated: ' : 'Activated: ') . route_label($rate) . '.'];
        } elseif ($action === 'resolve_report') {
            $stmt = db()->prepare('SELECT * FROM transpo_route_rate_reports WHERE id = ?');
            $stmt->execute([(int) ($_POST['report_id'] ?? 0)]);
            $report = $stmt->fetch();
            if (!$report) {
                $flash = ['danger', 'Report not found.'];
            } else {
                $rate = load_rate((int) $report['route_rate_id']);
                if (!empty($_POST['apply']) && $report['suggested_fare'] !== null && $rate) {
                    db()->prepare('UPDATE transpo_route_rates SET fare = ? WHERE id = ?')->execute([$report['suggested_fare'], $rate['id']]);
                    transpo_log_fare((int) $rate['id'], (float) $rate['fare'], (float) $report['suggested_fare'], (int) $user['user_id']);
                }
                db()->prepare("UPDATE transpo_route_rate_reports SET status = 'resolved' WHERE id = ?")->execute([$report['id']]);
                notifications_resolve('transpo_report', (int) $report['id']);
                $flash = ['success', !empty($_POST['apply']) ? 'Fare updated to ' . transpo_money($report['suggested_fare']) . ' and the report resolved.' : 'Report resolved (fare kept).'];
            }
        } else {
            $flash = ['danger', 'Unknown action.'];
        }
    }

    $_SESSION['flash'] = $flash;
    redirect($flash[2] ?? $back);
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

/* ---------- list ---------- */

$q       = trim((string) ($_GET['q'] ?? ''));
$mode    = array_key_exists($_GET['mode'] ?? '', TRANSPO_MODES) ? $_GET['mode'] : '';
$status  = in_array($_GET['status'] ?? '', ['approved', 'pending', 'inactive'], true) ? $_GET['status'] : '';
$addedBy = (int) ($_GET['added_by'] ?? 0);
$highlight = (int) ($_GET['highlight'] ?? 0);

$sql = "SELECT r.*, f.name AS from_name, t.name AS to_name,
               CONCAT(c.first_name, ' ', c.last_name) AS created_by_name,
               (SELECT COUNT(*) FROM transpo_route_rate_reports x WHERE x.route_rate_id = r.id AND x.status = 'open') AS open_reports
        FROM transpo_route_rates r
        JOIN transpo_locations f ON f.id = r.from_location_id
        JOIN transpo_locations t ON t.id = r.to_location_id
        LEFT JOIN users c ON c.user_id = r.created_by
        WHERE 1 = 1";
$params = [];
if ($q !== '') {
    $sql .= ' AND (f.normalized_name LIKE ? OR t.normalized_name LIKE ?)';
    $like = '%' . addcslashes(transpo_normalize($q), '%_\\') . '%';
    array_push($params, $like, $like);
}
if ($mode !== '') {
    $sql .= ' AND r.mode = ?';
    $params[] = $mode;
}
if ($status === 'inactive') {
    $sql .= ' AND r.is_active = 0';
} elseif ($status !== '') {
    $sql .= ' AND r.status = ? AND r.is_active = 1';
    $params[] = $status;
}
if ($addedBy) {
    $sql .= ' AND r.created_by = ?';
    $params[] = $addedBy;
}
$stmt = db()->prepare($sql . " ORDER BY (r.status = 'pending') DESC, f.name, t.name, r.mode LIMIT 500");
$stmt->execute($params);
$rates = $stmt->fetchAll();

$creators = db()->query(
    "SELECT DISTINCT u.user_id, u.first_name, u.last_name FROM transpo_route_rates r JOIN users u ON u.user_id = r.created_by ORDER BY u.last_name"
)->fetchAll();
$counts = transpo_pending_counts();

$reports = [];
if ($tab === 'reports') {
    $reports = db()->query(
        "SELECT x.*, r.fare AS current_fare, r.mode, r.is_two_way, f.name AS from_name, t.name AS to_name,
                CONCAT(u.first_name, ' ', u.last_name) AS reporter
         FROM transpo_route_rate_reports x
         JOIN transpo_route_rates r ON r.id = x.route_rate_id
         JOIN transpo_locations f ON f.id = r.from_location_id
         JOIN transpo_locations t ON t.id = r.to_location_id
         LEFT JOIN users u ON u.user_id = x.reported_by
         ORDER BY (x.status = 'open') DESC, x.created_at DESC LIMIT 200"
    )->fetchAll();
}

$filterQuery = http_build_query(array_filter(['q' => $q, 'mode' => $mode, 'status' => $status, 'added_by' => $addedBy ?: '']));
$back = 'route_fares.php' . ($filterQuery ? "?$filterQuery" : '');

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
      <div class="alert alert-<?= e($flash[0]) ?>">
        <?= e($flash[1]) ?>
<?php if (!empty($flash[2])): ?>
        <a href="<?= e($flash[2]) ?>" class="alert-link ms-1">Edit instead</a>
<?php endif; ?>
      </div>
<?php endif; ?>

<?php if ($isAdmin): ?>
      <ul class="nav nav-tabs mb-3">
        <li class="nav-item"><a class="nav-link <?= $tab === 'list' ? 'active' : '' ?>" href="route_fares.php">Route Fares
          <?= $counts['rates'] ? '<span class="badge bg-warning text-dark">' . $counts['rates'] . ' pending</span>' : '' ?></a></li>
        <li class="nav-item"><a class="nav-link <?= $tab === 'reports' ? 'active' : '' ?>" href="route_fares.php?tab=reports">Wrong-fare Reports
          <?= $counts['reports'] ? '<span class="badge bg-danger">' . $counts['reports'] . '</span>' : '' ?></a></li>
      </ul>
<?php endif; ?>

<?php if ($tab === 'reports'): ?>
      <div class="card">
        <div class="card-body pt-3">
          <div class="table-responsive">
            <table class="table align-middle">
              <thead><tr><th>Route</th><th class="text-end">Current</th><th class="text-end">Suggested</th><th>Note</th><th>Reported</th><th class="text-end">Action</th></tr></thead>
              <tbody>
<?php foreach ($reports as $x): ?>
                <tr class="<?= $x['status'] === 'resolved' ? 'text-muted' : '' ?>">
                  <td><?= e($x['from_name']) ?> <?= $x['is_two_way'] ? '↔' : '→' ?> <?= e($x['to_name']) ?> <span class="badge bg-light text-dark border"><?= e($x['mode']) ?></span></td>
                  <td class="text-end text-nowrap"><?= e(transpo_money($x['current_fare'])) ?></td>
                  <td class="text-end text-nowrap"><?= $x['suggested_fare'] !== null ? e(transpo_money($x['suggested_fare'])) : '—' ?></td>
                  <td><?= e($x['note']) ?></td>
                  <td class="small text-nowrap"><?= e($x['reporter'] ?? '—') ?><br><?= e(date('M j, Y', strtotime($x['created_at']))) ?></td>
                  <td class="text-end text-nowrap">
<?php if ($x['status'] === 'open'): ?>
                    <form method="post" class="d-inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="resolve_report"><input type="hidden" name="report_id" value="<?= (int) $x['id'] ?>">
                      <input type="hidden" name="back" value="route_fares.php?tab=reports">
<?php if ($x['suggested_fare'] !== null): ?>
                      <button type="submit" name="apply" value="1" class="btn btn-sm btn-success">Apply <?= e(transpo_money($x['suggested_fare'])) ?></button>
<?php endif; ?>
                      <button type="submit" class="btn btn-sm btn-outline-secondary">Keep fare</button>
                    </form>
<?php else: ?>
                    <span class="badge bg-light text-muted border">Resolved</span>
<?php endif; ?>
                  </td>
                </tr>
<?php endforeach; ?>
<?php if (!$reports): ?>
                <tr><td colspan="6" class="text-muted text-center py-4">No wrong-fare reports.</td></tr>
<?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
<?php else: ?>

      <!-- Add fares -->
      <div class="card">
        <div class="card-body pt-3">
          <ul class="nav nav-pills mb-3" role="tablist">
            <li class="nav-item"><button class="nav-link active" data-bs-toggle="pill" data-bs-target="#addSingle" type="button">Add route fare</button></li>
            <li class="nav-item"><button class="nav-link" data-bs-toggle="pill" data-bs-target="#addBulk" type="button">Bulk add from one place</button></li>
          </ul>
          <div class="tab-content">
            <div class="tab-pane fade show active" id="addSingle">
              <form method="post" class="row g-2 align-items-end">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="add"><input type="hidden" name="back" value="<?= e($back) ?>">
                <div class="col-md-4"><label class="form-label">From</label><select name="from_id" class="loc-select" required></select></div>
                <div class="col-md-4"><label class="form-label">To</label><select name="to_id" class="loc-select" required></select></div>
                <div class="col-6 col-md-2">
                  <label class="form-label">Mode</label>
                  <select name="mode" class="form-select" required>
<?php foreach (TRANSPO_MODES as $key => $label): ?>
                    <option value="<?= e($key) ?>"><?= e($label) ?></option>
<?php endforeach; ?>
                  </select>
                </div>
                <div class="col-6 col-md-2"><label class="form-label">Fare (₱)</label><input type="number" name="fare" class="form-control" min="0.01" step="0.01" inputmode="decimal" required></div>
                <div class="col-md-8">
                  <div class="form-check"><input class="form-check-input" type="checkbox" name="two_way" value="1" id="twoWay" checked>
                    <label class="form-check-label" for="twoWay">Two-way (same fare both directions)</label></div>
                </div>
                <div class="col-md-4 text-md-end"><button type="submit" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Add Route Fare</button></div>
              </form>
            </div>

            <div class="tab-pane fade" id="addBulk">
              <form method="post" id="bulkForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="bulk_add"><input type="hidden" name="back" value="<?= e($back) ?>">
                <div class="row g-2 mb-2">
                  <div class="col-md-6"><label class="form-label">Starting place (e.g. a dropoint)</label><select name="from_id" class="loc-select" required></select></div>
                </div>
                <div class="table-responsive">
                  <table class="table table-sm align-middle mb-2">
                    <thead><tr><th style="min-width: 14rem">To</th><th>Mode</th><th style="width: 8rem">Fare (₱)</th><th>Two-way</th><th></th></tr></thead>
                    <tbody id="bulkRows"></tbody>
                  </table>
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary" id="bulkAddRow"><i class="bi bi-plus-lg"></i> Add destination</button>
                <button type="submit" class="btn btn-primary btn-sm ms-2"><i class="bi bi-save"></i> Save all</button>
              </form>
            </div>
          </div>
          <p class="small text-muted mb-0 mt-2"><?= $isAdmin ? 'Fares you add are approved right away.' : 'Fares you add are marked pending until an admin approves them, but you can use them in filing right away.' ?></p>
        </div>
      </div>

      <!-- List -->
      <div class="card">
        <div class="card-body pt-3">
          <form method="get" class="row g-2 align-items-end mb-3">
            <div class="col-md-4"><label class="form-label" for="q">Location</label><input type="search" id="q" name="q" class="form-control" placeholder="Search a place" value="<?= e($q) ?>"></div>
            <div class="col-6 col-md-2"><label class="form-label" for="fMode">Mode</label>
              <select id="fMode" name="mode" class="form-select"><option value="">All</option>
<?php foreach (TRANSPO_MODES as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= $mode === $key ? 'selected' : '' ?>><?= e($label) ?></option>
<?php endforeach; ?>
              </select></div>
            <div class="col-6 col-md-2"><label class="form-label" for="fStatus">Status</label>
              <select id="fStatus" name="status" class="form-select">
                <option value="">All</option>
<?php foreach (['approved' => 'Approved', 'pending' => 'Pending', 'inactive' => 'Inactive'] as $key => $label): ?>
                <option value="<?= $key ?>" <?= $status === $key ? 'selected' : '' ?>><?= $label ?></option>
<?php endforeach; ?>
              </select></div>
            <div class="col-md-2"><label class="form-label" for="fBy">Added by</label>
              <select id="fBy" name="added_by" class="form-select"><option value="">Anyone</option>
<?php foreach ($creators as $c): ?>
                <option value="<?= (int) $c['user_id'] ?>" <?= $addedBy === (int) $c['user_id'] ? 'selected' : '' ?>><?= e($c['first_name'] . ' ' . $c['last_name']) ?></option>
<?php endforeach; ?>
              </select></div>
            <div class="col-md-2 d-flex gap-2"><button type="submit" class="btn btn-primary"><i class="bi bi-funnel"></i> Filter</button>
<?php if ($filterQuery): ?><a href="route_fares.php" class="btn btn-outline-secondary">Clear</a><?php endif; ?></div>
          </form>

          <div class="table-responsive">
            <table class="table align-middle">
              <thead><tr><th>Route</th><th>Mode</th><th class="text-end">Fare</th><th>Status</th><th class="text-end">Used</th><th class="d-none d-md-table-cell">Added by</th><th class="text-end">Action</th></tr></thead>
              <tbody>
<?php foreach ($rates as $r):
    $mine = (int) $r['created_by'] === (int) $user['user_id'];
?>
                <tr id="rate-<?= (int) $r['id'] ?>" class="<?= $highlight === (int) $r['id'] ? 'table-warning' : '' ?><?= $r['is_active'] ? '' : ' text-muted' ?>">
                  <td class="transpo-route"><?= e($r['from_name']) ?><span class="arrow"><?= $r['is_two_way'] ? '↔' : '→' ?></span><?= e($r['to_name']) ?>
                    <?= $r['open_reports'] ? '<span class="badge bg-danger" title="Reported as wrong">reported</span>' : '' ?></td>
                  <td><?= e($r['mode']) ?></td>
                  <td class="text-end text-nowrap fw-semibold"><?= e(transpo_money($r['fare'])) ?></td>
                  <td><?= transpo_status_badge($r['status'], (bool) $r['is_active']) ?></td>
                  <td class="text-end"><?= (int) $r['times_used'] ?></td>
                  <td class="small d-none d-md-table-cell"><?= e($r['created_by_name'] ?? '—') ?><?= $mine ? ' <span class="text-muted">(you)</span>' : '' ?></td>
                  <td class="text-end text-nowrap">
                    <button type="button" class="btn btn-sm btn-link px-1 history-btn" data-id="<?= (int) $r['id'] ?>" data-route="<?= e(route_label($r)) ?>" title="Fare history"><i class="bi bi-clock-history"></i></button>
<?php if ($isAdmin && $r['status'] === 'pending'): ?>
                    <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="back" value="<?= e($back) ?>">
                      <input type="hidden" name="rate_id" value="<?= (int) $r['id'] ?>">
                      <button name="action" value="approve" class="btn btn-sm btn-success" title="Approve"><i class="bi bi-check-lg"></i></button>
                      <button name="action" value="reject" class="btn btn-sm btn-outline-danger" title="Reject" onclick="return confirm('Reject and remove this fare?')"><i class="bi bi-x-lg"></i></button>
                    </form>
<?php endif; ?>
<?php if (can_change($r, $user)): ?>
                    <button type="button" class="btn btn-sm btn-outline-primary edit-btn" data-id="<?= (int) $r['id'] ?>" data-fare="<?= e($r['fare']) ?>" data-two-way="<?= (int) $r['is_two_way'] ?>" data-route="<?= e($r['from_name'] . ' → ' . $r['to_name'] . ' — ' . $r['mode']) ?>" title="Edit"><i class="bi bi-pencil"></i></button>
<?php if ($isAdmin): ?>
                    <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="back" value="<?= e($back) ?>">
                      <input type="hidden" name="rate_id" value="<?= (int) $r['id'] ?>">
                      <button name="action" value="toggle_active" class="btn btn-sm btn-outline-secondary" title="<?= $r['is_active'] ? 'Deactivate' : 'Activate' ?>"><i class="bi <?= $r['is_active'] ? 'bi-pause-circle' : 'bi-play-circle' ?>"></i></button>
                    </form>
<?php endif; ?>
                    <form method="post" class="d-inline" onsubmit="return confirm('Delete this route fare? Filed legs keep their fare.')"><?= csrf_field() ?><input type="hidden" name="back" value="<?= e($back) ?>">
                      <input type="hidden" name="rate_id" value="<?= (int) $r['id'] ?>">
                      <button name="action" value="delete" class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                    </form>
<?php else: ?>
                    <button type="button" class="btn btn-sm btn-outline-warning report-btn" data-id="<?= (int) $r['id'] ?>" data-route="<?= e(route_label($r)) ?>" title="Report wrong fare"><i class="bi bi-flag"></i> Report</button>
<?php endif; ?>
                  </td>
                </tr>
<?php endforeach; ?>
<?php if (!$rates): ?>
                <tr><td colspan="7" class="text-muted text-center py-4">No route fares found.</td></tr>
<?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>
<?php endif; ?>
    </section>

  </main><!-- End #main -->

  <!-- Edit fare -->
  <div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><form method="post" class="modal-content">
      <div class="modal-header"><h5 class="modal-title" id="editTitle">Edit Route Fare</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <?= csrf_field() ?><input type="hidden" name="action" value="edit"><input type="hidden" name="back" value="<?= e($back) ?>">
        <input type="hidden" name="rate_id" id="editId">
        <p class="fw-semibold" id="editRoute"></p>
        <label class="form-label" for="editFare">Fare (₱)</label>
        <input type="number" name="fare" id="editFare" class="form-control mb-2" min="0.01" step="0.01" inputmode="decimal" required>
        <div class="form-check"><input class="form-check-input" type="checkbox" name="two_way" value="1" id="editTwoWay"><label class="form-check-label" for="editTwoWay">Two-way</label></div>
        <div class="form-text">The change is saved in the fare history. Tickets already filed keep their fare.</div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Save</button></div>
    </form></div>
  </div>

  <!-- Report wrong fare -->
  <div class="modal fade" id="reportModal" tabindex="-1" aria-labelledby="reportTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><form method="post" class="modal-content">
      <div class="modal-header"><h5 class="modal-title" id="reportTitle">Report Wrong Fare</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <?= csrf_field() ?><input type="hidden" name="action" value="report"><input type="hidden" name="back" value="<?= e($back) ?>">
        <input type="hidden" name="rate_id" id="reportId">
        <p class="fw-semibold" id="reportRoute"></p>
        <label class="form-label" for="reportFare">Correct fare (₱) <span class="text-muted small">(optional)</span></label>
        <input type="number" name="suggested_fare" id="reportFare" class="form-control mb-2" min="0.01" step="0.01" inputmode="decimal">
        <label class="form-label" for="reportNote">What is wrong?</label>
        <textarea name="note" id="reportNote" class="form-control" rows="2" maxlength="255" required placeholder="e.g. Fare went up to ₱25 this month"></textarea>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-warning">Send Report</button></div>
    </form></div>
  </div>

  <!-- Fare history -->
  <div class="modal fade" id="historyModal" tabindex="-1" aria-labelledby="historyTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" id="historyTitle">Fare History</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body"><p class="fw-semibold" id="historyRoute"></p><div id="historyBody" class="small"></div></div>
    </div></div>
  </div>

  <template id="bulkRowTemplate">
    <tr>
      <td><select class="bulk-to"></select></td>
      <td><select class="form-select form-select-sm bulk-mode">
<?php foreach (TRANSPO_MODES as $key => $label): ?>
        <option value="<?= e($key) ?>"><?= e($label) ?></option>
<?php endforeach; ?>
      </select></td>
      <td><input type="number" class="form-control form-control-sm bulk-fare" min="0.01" step="0.01" inputmode="decimal"></td>
      <td class="text-center"><input type="checkbox" class="form-check-input bulk-two-way" value="1" checked></td>
      <td><button type="button" class="btn btn-sm btn-outline-danger bulk-remove" title="Remove"><i class="bi bi-x-lg"></i></button></td>
    </tr>
  </template>

<?php require __DIR__ . '/includes/transpo_assets.php'; ?>
  <script>
    (function () {
      var T = window.Transpo;
      document.querySelectorAll(".loc-select").forEach(function (el) { T.locationSelect(el); });

      // Bulk add: one starting place, many destinations. Field names are set
      // when saving so removed rows don't leave gaps.
      var rows = document.getElementById("bulkRows");
      var tpl = document.getElementById("bulkRowTemplate");
      function addRow() {
        var tr = tpl.content.firstElementChild.cloneNode(true);
        rows.appendChild(tr);
        tr._ts = T.locationSelect(tr.querySelector(".bulk-to"), { placeholder: "Destination…" });
        tr.querySelector(".bulk-remove").addEventListener("click", function () { tr._ts.destroy(); tr.remove(); });
      }
      var bulkBtn = document.getElementById("bulkAddRow");
      if (bulkBtn) {
        bulkBtn.addEventListener("click", addRow);
        addRow(); addRow(); addRow();
        document.getElementById("bulkForm").addEventListener("submit", function () {
          Array.prototype.forEach.call(rows.children, function (tr, i) {
            tr.querySelector(".bulk-to").name = "rows[" + i + "][to_id]";
            tr.querySelector(".bulk-mode").name = "rows[" + i + "][mode]";
            tr.querySelector(".bulk-fare").name = "rows[" + i + "][fare]";
            tr.querySelector(".bulk-two-way").name = "rows[" + i + "][two_way]";
          });
        });
      }

      function modal(id) { return bootstrap.Modal.getOrCreateInstance(document.getElementById(id)); }
      document.querySelectorAll(".edit-btn").forEach(function (b) {
        b.addEventListener("click", function () {
          document.getElementById("editId").value = b.dataset.id;
          document.getElementById("editRoute").textContent = b.dataset.route;
          document.getElementById("editFare").value = b.dataset.fare;
          document.getElementById("editTwoWay").checked = b.dataset.twoWay === "1";
          modal("editModal").show();
        });
      });
      document.querySelectorAll(".report-btn").forEach(function (b) {
        b.addEventListener("click", function () {
          document.getElementById("reportId").value = b.dataset.id;
          document.getElementById("reportRoute").textContent = b.dataset.route;
          modal("reportModal").show();
        });
      });
      document.querySelectorAll(".history-btn").forEach(function (b) {
        b.addEventListener("click", function () {
          document.getElementById("historyRoute").textContent = b.dataset.route;
          var body = document.getElementById("historyBody");
          body.textContent = "Loading…";
          modal("historyModal").show();
          T.get({ action: "rate_history", rate_id: b.dataset.id }).then(function (json) {
            body.innerHTML = (json.history || []).map(function (h) {
              return "<div class=\"border-bottom py-1\">" + T.esc(h.changed_at) + " · " + T.esc(h.changed_by) + "<br><strong>" +
                (h.old_fare === null ? "Added at " + T.money(h.new_fare) : T.money(h.old_fare) + " → " + T.money(h.new_fare)) + "</strong></div>";
            }).join("") || "No changes recorded.";
          });
        });
      });
<?php if ($highlight): ?>
      var row = document.getElementById("rate-<?= $highlight ?>");
      if (row) { row.scrollIntoView({ block: "center" }); }
<?php endif; ?>
    })();
  </script>

<?php
require __DIR__ . '/includes/footer.php';
require __DIR__ . '/includes/scripts.php';
