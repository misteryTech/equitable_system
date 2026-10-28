<?php
/**
 * My Requests: Service and ATW Request emails made on the Email Generator,
 * to view and copy again. Field engineers see their own; admins see everyone's,
 * filtered by engineer and date, and can export them to CSV.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/email_generator.php';
$user    = require_role('field_engineer', 'admin');
$isAdmin = is_admin($user);

$pageTitle  = $isAdmin ? 'Email Requests' : 'My Requests';
$activePage = 'my-requests';

$kinds = [
    'service' => ['label' => 'Service Requests', 'table' => 'service_requests', 'search' => ['r.body', 'r.subject', 'r.tn', 'r.tid', 'r.merchant']],
    'atw'     => ['label' => 'ATW Requests',     'table' => 'atw_requests',     'search' => ['r.body', 'r.subject', 'r.ticket_no', 'r.merchant', 'r.fe_names']],
];
$type  = array_key_exists($_REQUEST['type'] ?? '', $kinds) ? $_REQUEST['type'] : 'service';
$kind  = $kinds[$type];
$table = $kind['table'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $_SESSION['flash'] = ['danger', 'Your session expired. Please try again.'];
    } elseif (($_POST['action'] ?? '') === 'delete') {
        // Users delete their own; admins any.
        $stmt = db()->prepare("DELETE FROM $table WHERE id = ?" . ($isAdmin ? '' : ' AND user_id = ?'));
        $stmt->execute($isAdmin ? [(int) ($_POST['id'] ?? 0)] : [(int) ($_POST['id'] ?? 0), $user['user_id']]);
        $_SESSION['flash'] = $stmt->rowCount() ? ['success', 'Request deleted.'] : ['warning', 'Request not found.'];
    }
    redirect('my_requests.php?' . http_build_query(array_intersect_key($_POST, array_flip(['type', 'from', 'to', 'engineer', 'q']))));
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$isDate = static function ($v): bool {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $v);
    return $d !== false && $d->format('Y-m-d') === $v;
};
$from = $isDate($_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-d', strtotime('-30 days'));
$to   = $isDate($_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
$engineerId = $isAdmin ? (int) ($_GET['engineer'] ?? 0) : (int) $user['user_id'];
$q          = trim((string) ($_GET['q'] ?? ''));

$where  = 'r.created_at >= ? AND r.created_at < ? + INTERVAL 1 DAY';
$params = [$from, $to];
if ($engineerId) {
    $where   .= ' AND r.user_id = ?';
    $params[] = $engineerId;
}
if ($q !== '') {
    $where .= ' AND (' . implode(' OR ', array_map(fn($col) => "$col LIKE ?", $kind['search'])) . ')';
    array_push($params, ...array_fill(0, count($kind['search']), '%' . addcslashes($q, '%_\\') . '%'));
}
$select = "SELECT r.*, CONCAT(u.last_name, ', ', u.first_name) AS engineer, u.field_engineer_id
           FROM $table r JOIN users u ON u.user_id = r.user_id
           WHERE $where ORDER BY r.created_at DESC, r.id DESC";

// CSV export (admins): every matching row, no limit.
if ($isAdmin && ($_GET['export'] ?? '') === 'csv') {
    $stmt = db()->prepare($select);
    $stmt->execute($params);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $type . '-requests-' . $from . '-to-' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // BOM so Excel reads UTF-8
    $headers = $type === 'service'
        ? ['Date', 'Engineer', 'FE ID', 'TN', 'Model', 'SN', 'TID', 'Merchant', 'Request', 'Template', 'Recipients', 'Subject', 'Body']
        : ['Date', 'Engineer', 'FE ID', 'Ticket No', 'Merchant', 'Other Merchants', 'Date From', 'Date To', 'FE Names', 'Address', 'Recipients', 'Subject', 'Body'];
    fputcsv($out, $headers);
    // Leading = + - @ would run as formulas in Excel; prefix them with a quote.
    $safe = static fn($v): string => preg_match('/^[=+\-@\t\r]/', (string) $v) ? "'" . $v : (string) $v;
    foreach ($stmt->fetchAll() as $r) {
        $common = [$r['created_at'], $r['engineer'], (string) $r['field_engineer_id']];
        if ($type === 'service') {
            $row = [$r['tn'], $r['model'], $r['sn'], $r['tid'], $r['merchant'], $r['actions'], SERVICE_TEMPLATES[$r['template']] ?? $r['template']];
        } else {
            $others = array_slice(json_decode($r['merchants'], true) ?: [], 1);
            $row = [$r['ticket_no'], $r['merchant'], implode('; ', array_map(fn($m) => trim(($m['ticket'] ?? '') . ' ' . ($m['name'] ?? '')), $others)),
                    $r['date_from'], $r['date_to'], $r['fe_names'], $r['address']];
        }
        fputcsv($out, array_map($safe, array_merge($common, $row, [$r['recipients'], $r['subject'], $r['body']])));
    }
    fclose($out);
    exit;
}

$limit = 200;
$stmt  = db()->prepare($select . ' LIMIT ' . ($limit + 1));
$stmt->execute($params);
$requests = $stmt->fetchAll();
$more     = count($requests) > $limit;
$requests = array_slice($requests, 0, $limit);

$engineers = $isAdmin ? db()->query(
    "SELECT user_id, first_name, last_name, field_engineer_id FROM users WHERE status = 'approved' ORDER BY last_name, first_name"
)->fetchAll() : [];

$filters = ['type' => $type, 'from' => $from, 'to' => $to, 'q' => $q] + ($isAdmin ? ['engineer' => $engineerId] : []);
$columns = $isAdmin ? 6 : 5;

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
<?php if ($flash): ?>
      <div class="alert alert-<?= e($flash[0]) ?>"><?= e($flash[1]) ?></div>
<?php endif; ?>

      <div class="card">
        <div class="card-body pt-3">

          <ul class="nav nav-tabs mb-3">
<?php foreach ($kinds as $key => $k): ?>
            <li class="nav-item">
              <a class="nav-link <?= $type === $key ? 'active' : '' ?>" href="my_requests.php?<?= e(http_build_query(['type' => $key] + $filters)) ?>"><?= e($k['label']) ?></a>
            </li>
<?php endforeach; ?>
          </ul>

          <form method="get" class="row g-2 align-items-end mb-3">
            <input type="hidden" name="type" value="<?= e($type) ?>">
            <div class="col-6 col-md-2">
              <label for="fFrom" class="form-label">From</label>
              <input type="date" name="from" id="fFrom" class="form-control" value="<?= e($from) ?>">
            </div>
            <div class="col-6 col-md-2">
              <label for="fTo" class="form-label">To</label>
              <input type="date" name="to" id="fTo" class="form-control" value="<?= e($to) ?>">
            </div>
<?php if ($isAdmin): ?>
            <div class="col-md-3">
              <label for="fEngineer" class="form-label">Engineer</label>
              <select name="engineer" id="fEngineer" class="form-select">
                <option value="0">Everyone</option>
<?php foreach ($engineers as $eng): ?>
                <option value="<?= (int) $eng['user_id'] ?>" <?= $engineerId === (int) $eng['user_id'] ? 'selected' : '' ?>>
                  <?= e($eng['last_name'] . ', ' . $eng['first_name']) ?><?= $eng['field_engineer_id'] ? ' — ' . e($eng['field_engineer_id']) : '' ?>
                </option>
<?php endforeach; ?>
              </select>
            </div>
<?php endif; ?>
            <div class="col-md-3">
              <label for="fQ" class="form-label">Search</label>
              <input type="search" name="q" id="fQ" class="form-control" placeholder="<?= $type === 'service' ? 'TN, TID, merchant…' : 'Ticket, merchant, FE…' ?>" value="<?= e($q) ?>">
            </div>
            <div class="col-md-2 d-flex gap-2">
              <button type="submit" class="btn btn-primary"><i class="bi bi-funnel"></i> Filter</button>
<?php if ($isAdmin): ?>
              <a href="my_requests.php?<?= e(http_build_query($filters + ['export' => 'csv'])) ?>" class="btn btn-outline-success" title="Export to CSV"><i class="bi bi-filetype-csv"></i></a>
<?php endif; ?>
            </div>
          </form>

          <p class="small text-muted mb-2">
            <?= count($requests) ?> <?= $type === 'service' ? 'service' : 'ATW' ?> request<?= count($requests) === 1 ? '' : 's' ?> from <?= e(date('M j, Y', strtotime($from))) ?> to <?= e(date('M j, Y', strtotime($to))) ?>.
<?php if ($more): ?>
            Showing the newest <?= $limit ?> — narrow the dates<?= $isAdmin ? ' or export to CSV' : '' ?> to see older ones.
<?php endif; ?>
            <a href="<?= $type === 'service' ? 'service_request.php' : 'atw_request.php' ?>" class="ms-1"><i class="bi bi-plus-lg"></i> New <?= $type === 'service' ? 'Service' : 'ATW' ?> Request</a>
          </p>

          <div class="table-responsive">
            <table class="table align-middle">
              <thead>
                <tr>
                  <th class="text-nowrap">Date</th>
<?php if ($isAdmin): ?>
                  <th>Engineer</th>
<?php endif; ?>
<?php if ($type === 'service'): ?>
                  <th>Terminal</th>
                  <th>TN</th>
                  <th>Request</th>
<?php else: ?>
                  <th>Ticket / Merchant</th>
                  <th>Schedule</th>
                  <th>FE Names</th>
<?php endif; ?>
                  <th class="text-end">Copy</th>
                </tr>
              </thead>
              <tbody>
<?php foreach ($requests as $r):
    $collapseId = 'req' . $type . (int) $r['id'];
?>
                <tr>
                  <td class="small text-nowrap"><?= e(date('M j, Y', strtotime($r['created_at']))) ?><br><span class="text-muted"><?= e(date('g:i A', strtotime($r['created_at']))) ?></span></td>
<?php if ($isAdmin): ?>
                  <td class="small"><?= e($r['engineer']) ?><?= $r['field_engineer_id'] ? '<br><span class="text-muted">' . e($r['field_engineer_id']) . '</span>' : '' ?></td>
<?php endif; ?>
<?php if ($type === 'service'): ?>
                  <td class="small"><?= e($r['merchant'] ?? '') ?><?= $r['tid'] ? '<br><span class="text-muted font-monospace">' . e($r['model'] ? $r['model'] . ' · ' : '') . e($r['tid']) . '</span>' : '' ?></td>
                  <td class="small font-monospace text-nowrap"><?= e($r['tn'] ?? '') ?></td>
                  <td class="small"><?= e($r['actions']) ?> <span class="badge <?= $r['template'] === 'formal' ? 'bg-info text-dark' : 'bg-secondary' ?>"><?= e(SERVICE_TEMPLATES[$r['template']] ?? $r['template']) ?></span></td>
<?php else:
    $count = count(json_decode($r['merchants'], true) ?: []);
?>
                  <td class="small"><span class="font-monospace"><?= e($r['ticket_no']) ?></span><br><?= e($r['merchant']) ?><?= $count > 1 ? ' <span class="badge bg-secondary">+' . ($count - 1) . '</span>' : '' ?></td>
                  <td class="small text-nowrap"><?= e(atw_date($r['date_from'])) ?><?= $r['date_to'] !== $r['date_from'] ? '<br>to ' . e(atw_date($r['date_to'])) : '' ?></td>
                  <td class="small"><?= e($r['fe_names']) ?></td>
<?php endif; ?>
                  <td class="text-end">
                    <div class="d-inline-flex flex-wrap justify-content-end gap-1">
                      <button type="button" class="btn btn-sm btn-outline-primary eg-copy" data-text="<?= e($r['recipients'] ?? '') ?>" data-what="recipient list" title="Copy recipients"><i class="bi bi-people"></i></button>
                      <button type="button" class="btn btn-sm btn-outline-primary eg-copy" data-text="<?= e($r['subject']) ?>" data-what="subject" title="Copy subject"><i class="bi bi-type"></i></button>
                      <button type="button" class="btn btn-sm btn-primary eg-copy" data-text="<?= e($r['body']) ?>" data-what="email body" title="Copy email body"><i class="bi bi-clipboard"></i> Body</button>
                      <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#<?= e($collapseId) ?>" aria-expanded="false" aria-controls="<?= e($collapseId) ?>" title="View email"><i class="bi bi-eye"></i></button>
                      <form method="post" class="d-inline" onsubmit="return confirm('Delete this request from the history?')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
<?php foreach ($filters as $key => $value): ?>
                        <input type="hidden" name="<?= e($key) ?>" value="<?= e($value) ?>">
<?php endforeach; ?>
                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                      </form>
                    </div>
                  </td>
                </tr>
                <tr class="collapse" id="<?= e($collapseId) ?>">
                  <td colspan="<?= $columns ?>" class="bg-light small">
                    <div class="mb-1"><span class="text-muted">To:</span> <?= $r['recipients'] ? e($r['recipients']) : '<span class="text-muted">(none chosen)</span>' ?></div>
                    <div class="mb-1"><span class="text-muted">Subject:</span> <?= e($r['subject']) ?></div>
                    <pre class="mb-0" style="white-space: pre-wrap; word-break: break-word; font-family: inherit"><?= e($r['body']) ?></pre>
                  </td>
                </tr>
<?php endforeach; ?>
<?php if (!$requests): ?>
                <tr><td colspan="<?= $columns ?>" class="text-muted text-center py-4">No <?= $type === 'service' ? 'service' : 'ATW' ?> requests in this period.</td></tr>
<?php endif; ?>
              </tbody>
            </table>
          </div>

        </div>
      </div>
    </section>

  </main><!-- End #main -->

  <script src="<?= asset('assets/js/email-generator.js') ?>"></script>
  <script>
    document.querySelectorAll(".eg-copy").forEach(function (btn) {
      btn.addEventListener("click", function () { EmailGen.copyWithToast(btn.dataset.text, btn.dataset.what); });
    });
  </script>

<?php
require __DIR__ . '/includes/footer.php';
require __DIR__ . '/includes/scripts.php';
