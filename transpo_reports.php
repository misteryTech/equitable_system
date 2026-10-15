<?php
/**
 * Admin: transportation expense reports, for a date range and one or all engineers.
 *   daily    transpo per engineer per day, beside the DAS Time In / Time Out
 *   tickets  per-ticket breakdown with legs
 *   monthly  monthly totals for reimbursement
 *   flagged  legs with overridden fares or fares still pending approval
 * Every view can be exported to CSV (opens in Excel).
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/transpo.php';
require_once __DIR__ . '/includes/time_logs.php';
$user = require_role('admin');

$pageTitle  = 'Transpo Reports';
$activePage = 'transpo-reports';

$views = ['daily' => 'Per engineer per day', 'tickets' => 'Per ticket', 'monthly' => 'Monthly totals', 'flagged' => 'Overrides & pending fares'];
$view  = array_key_exists($_GET['view'] ?? '', $views) ? $_GET['view'] : 'daily';

$isDate = static function ($v): bool {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $v);
    return $d !== false && $d->format('Y-m-d') === $v;
};
$from = $isDate($_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
$to   = $isDate($_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
$engineerId = (int) ($_GET['engineer'] ?? 0);

$engineers = db()->query(
    "SELECT user_id, first_name, last_name, field_engineer_id FROM users WHERE status = 'approved' ORDER BY last_name, first_name"
)->fetchAll();

// Shared WHERE for visits in range (and engineer).
$where  = 'v.visited_at >= ? AND v.visited_at < ? + INTERVAL 1 DAY';
$params = [$from, $to];
if ($engineerId) {
    $where   .= ' AND v.engineer_id = ?';
    $params[] = $engineerId;
}
$nameSql = "CONCAT(u.last_name, ', ', u.first_name)";

$columns = [];
$rows    = [];
$total   = 0.0;

if ($view === 'daily') {
    $stmt = db()->prepare(
        "SELECT v.engineer_id, $nameSql AS engineer, u.field_engineer_id, DATE(v.visited_at) AS day,
                COUNT(DISTINCT v.id) AS tickets, COUNT(g.id) AS legs, COALESCE(SUM(g.fare), 0) AS total,
                t.time_in, t.time_out, t.total_hrs, t.filing_type
         FROM transpo_ticket_visits v
         JOIN users u ON u.user_id = v.engineer_id
         LEFT JOIN transpo_trip_legs g ON g.visit_id = v.id
         LEFT JOIN time_logs t ON t.user_id = v.engineer_id AND t.work_date = DATE(v.visited_at)
         WHERE $where
         GROUP BY v.engineer_id, DATE(v.visited_at)
         ORDER BY day, engineer"
    );
    $stmt->execute($params);
    $columns = ['Date', 'Engineer', 'ID', 'Tickets', 'Legs', 'Transpo', 'DAS Time In', 'DAS Time Out', 'DAS Total Hrs'];
    foreach ($stmt->fetchAll() as $r) {
        $total += $r['total'];
        $rows[] = [
            time_log_date($r['day']), $r['engineer'], (string) $r['field_engineer_id'], (int) $r['tickets'], (int) $r['legs'],
            (float) $r['total'], time_log_time($r['time_in']), time_log_time($r['time_out']),
            $r['time_out'] ? time_log_hours($r['total_hrs'], true) : '',
        ];
    }
} elseif ($view === 'tickets') {
    $stmt = db()->prepare(
        "SELECT v.id, v.ticket_no, v.visited_at, v.remarks, $nameSql AS engineer, m.name AS merchant, m.address
         FROM transpo_ticket_visits v
         JOIN users u ON u.user_id = v.engineer_id
         JOIN transpo_locations m ON m.id = v.merchant_location_id
         WHERE $where ORDER BY v.visited_at, v.id"
    );
    $stmt->execute($params);
    $visits = $stmt->fetchAll();
    $legs   = [];
    if ($visits) {
        $ids  = array_column($visits, 'id');
        $stmt = db()->prepare(
            'SELECT g.visit_id, g.mode, g.fare, g.fare_overridden, f.name AS from_name, t.name AS to_name
             FROM transpo_trip_legs g JOIN transpo_locations f ON f.id = g.from_location_id JOIN transpo_locations t ON t.id = g.to_location_id
             WHERE g.visit_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY g.visit_id, g.seq'
        );
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $g) {
            $legs[$g['visit_id']][] = $g;
        }
    }
    $columns = ['Date & Time', 'Engineer', 'Ticket No.', 'Merchant', 'Legs', 'Subtotal', 'Remarks'];
    foreach ($visits as $v) {
        $route = [];
        $sub   = 0.0;
        foreach ($legs[$v['id']] ?? [] as $g) {
            $sub    += $g['fare'];
            $route[] = $g['from_name'] . ' → ' . $g['to_name'] . ' (' . $g['mode'] . ' ' . transpo_money($g['fare']) . ($g['fare_overridden'] ? ', override' : '') . ')';
        }
        $total += $sub;
        $rows[] = [date('m/d/Y g:i A', strtotime($v['visited_at'])), $v['engineer'], $v['ticket_no'], $v['merchant'], implode(' ; ', $route), $sub, (string) $v['remarks']];
    }
} elseif ($view === 'monthly') {
    $stmt = db()->prepare(
        "SELECT DATE_FORMAT(v.visited_at, '%Y-%m') AS month, $nameSql AS engineer, u.field_engineer_id,
                COUNT(DISTINCT DATE(v.visited_at)) AS days, COUNT(DISTINCT v.id) AS tickets, COALESCE(SUM(g.fare), 0) AS total
         FROM transpo_ticket_visits v
         JOIN users u ON u.user_id = v.engineer_id
         LEFT JOIN transpo_trip_legs g ON g.visit_id = v.id
         WHERE $where
         GROUP BY month, v.engineer_id ORDER BY month, engineer"
    );
    $stmt->execute($params);
    $columns = ['Month', 'Engineer', 'ID', 'Days', 'Tickets', 'Total for reimbursement'];
    foreach ($stmt->fetchAll() as $r) {
        $total += $r['total'];
        $rows[] = [date('F Y', strtotime($r['month'] . '-01')), $r['engineer'], (string) $r['field_engineer_id'], (int) $r['days'], (int) $r['tickets'], (float) $r['total']];
    }
} else {
    $stmt = db()->prepare(
        "SELECT v.visited_at, v.ticket_no, $nameSql AS engineer, f.name AS from_name, t.name AS to_name, g.mode, g.fare,
                g.fare_overridden, r.fare AS list_fare, r.status AS rate_status
         FROM transpo_trip_legs g
         JOIN transpo_ticket_visits v ON v.id = g.visit_id
         JOIN users u ON u.user_id = v.engineer_id
         JOIN transpo_locations f ON f.id = g.from_location_id
         JOIN transpo_locations t ON t.id = g.to_location_id
         LEFT JOIN transpo_route_rates r ON r.id = g.route_rate_id
         WHERE $where AND (g.fare_overridden = 1 OR r.status = 'pending')
         ORDER BY v.visited_at, g.seq"
    );
    $stmt->execute($params);
    $columns = ['Date & Time', 'Engineer', 'Ticket No.', 'Leg', 'Mode', 'Fare paid', 'List fare', 'Flag'];
    foreach ($stmt->fetchAll() as $r) {
        $total += $r['fare'];
        $flags  = array_filter([$r['fare_overridden'] ? 'Override' : null, $r['rate_status'] === 'pending' ? 'Pending fare' : null]);
        $rows[] = [date('m/d/Y g:i A', strtotime($r['visited_at'])), $r['engineer'], $r['ticket_no'], $r['from_name'] . ' → ' . $r['to_name'],
                   $r['mode'], (float) $r['fare'], $r['list_fare'] !== null ? (float) $r['list_fare'] : '', implode(', ', $flags)];
    }
}

// Money columns (floats) and their position for the totals row.
$moneyCol = ['daily' => 5, 'tickets' => 5, 'monthly' => 5, 'flagged' => 5][$view];

/* ---------- CSV export ---------- */
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="transpo_' . $view . '_' . $from . '_to_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");                       // BOM so Excel reads UTF-8 (₱, →)
    fputcsv($out, $columns);
    foreach ($rows as $row) {
        // Plain numbers for money columns; text that starts with = + - @ is quoted so Excel won't run it.
        fputcsv($out, array_map(fn($c) => is_float($c) ? number_format($c, 2, '.', '') : (preg_match('/^[=+\-@]/', (string) $c) ? "'" . $c : $c), $row));
    }
    $totalRow = array_fill(0, count($columns), '');
    $totalRow[0] = 'TOTAL';
    $totalRow[$moneyCol] = number_format($total, 2, '.', '');
    fputcsv($out, $totalRow);
    fclose($out);
    exit;
}

$query = fn(array $extra = []) => 'transpo_reports.php?' . http_build_query(array_merge(['view' => $view, 'from' => $from, 'to' => $to, 'engineer' => $engineerId ?: null], $extra));

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
          <form method="get" class="row g-2 align-items-end mb-3">
            <input type="hidden" name="view" value="<?= e($view) ?>">
            <div class="col-6 col-md-2"><label class="form-label" for="from">From</label><input type="date" id="from" name="from" class="form-control" value="<?= e($from) ?>"></div>
            <div class="col-6 col-md-2"><label class="form-label" for="to">To</label><input type="date" id="to" name="to" class="form-control" value="<?= e($to) ?>"></div>
            <div class="col-md-4"><label class="form-label" for="engineer">Engineer</label>
              <select id="engineer" name="engineer" class="form-select"><option value="">All engineers</option>
<?php foreach ($engineers as $en): ?>
                <option value="<?= (int) $en['user_id'] ?>" <?= $engineerId === (int) $en['user_id'] ? 'selected' : '' ?>><?= e($en['last_name'] . ', ' . $en['first_name']) ?><?= $en['field_engineer_id'] ? ' — ' . e($en['field_engineer_id']) : '' ?></option>
<?php endforeach; ?>
              </select></div>
            <div class="col-md-4 d-flex gap-2">
              <button type="submit" class="btn btn-primary"><i class="bi bi-funnel"></i> Show</button>
              <a href="<?= e($query(['export' => 'csv'])) ?>" class="btn btn-outline-success ms-auto"><i class="bi bi-file-earmark-spreadsheet"></i> Export CSV</a>
            </div>
          </form>

          <ul class="nav nav-tabs mb-3">
<?php foreach ($views as $key => $label): ?>
            <li class="nav-item"><a class="nav-link <?= $view === $key ? 'active' : '' ?>" href="<?= e('transpo_reports.php?' . http_build_query(['view' => $key, 'from' => $from, 'to' => $to, 'engineer' => $engineerId ?: null])) ?>"><?= e($label) ?></a></li>
<?php endforeach; ?>
          </ul>

          <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle">
              <thead class="table-light"><tr>
<?php foreach ($columns as $i => $c): ?>
                <th class="<?= $i === $moneyCol || in_array($c, ['Tickets', 'Legs', 'Days', 'List fare', 'DAS Total Hrs'], true) ? 'text-end' : '' ?>"><?= e($c) ?></th>
<?php endforeach; ?>
              </tr></thead>
              <tbody>
<?php foreach ($rows as $row): ?>
                <tr>
<?php foreach ($row as $i => $cell): ?>
                  <td class="<?= is_float($cell) || is_int($cell) || $columns[$i] === 'DAS Total Hrs' ? 'text-end text-nowrap' : ($columns[$i] === 'Legs' && $view === 'tickets' ? 'small' : '') ?>">
                    <?php if (is_float($cell)): ?><?= e(transpo_money($cell)) ?>
                    <?php elseif ($view === 'tickets' && $columns[$i] === 'Legs'): ?><?= str_replace(' ; ', '<br>', e($cell)) ?>
                    <?php elseif ($view === 'flagged' && $columns[$i] === 'Flag'): ?><?php foreach (array_filter(explode(', ', $cell)) as $flag): ?><span class="badge <?= $flag === 'Override' ? 'bg-warning text-dark' : 'bg-light text-dark border' ?>"><?= e($flag) ?></span> <?php endforeach; ?>
                    <?php else: ?><?= e($cell) ?><?php endif; ?>
                  </td>
<?php endforeach; ?>
                </tr>
<?php endforeach; ?>
<?php if (!$rows): ?>
                <tr><td colspan="<?= count($columns) ?>" class="text-muted text-center py-4">Nothing for this date range.</td></tr>
<?php endif; ?>
              </tbody>
<?php if ($rows): ?>
              <tfoot class="table-light fw-bold"><tr>
                <td colspan="<?= $moneyCol ?>" class="text-end">Total (<?= e(time_log_date($from)) ?> – <?= e(time_log_date($to)) ?>)</td>
                <td class="text-end text-nowrap"><?= e(transpo_money($total)) ?></td>
                <td colspan="<?= count($columns) - $moneyCol - 1 ?>"></td>
              </tr></tfoot>
<?php endif; ?>
            </table>
          </div>
        </div>
      </div>
    </section>

  </main><!-- End #main -->

<?php
require __DIR__ . '/includes/footer.php';
require __DIR__ . '/includes/scripts.php';
