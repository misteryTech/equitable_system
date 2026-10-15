<?php
/**
 * Transpo Filing: record the fares paid to reach each ticket/merchant, leg by leg.
 * Engineers file their own; admins can pick any engineer. Engineers can edit or
 * delete their own entries for the current day only.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/transpo.php';
require_once __DIR__ . '/includes/time_logs.php';
$user    = require_role('field_engineer', 'admin');
$isAdmin = is_admin($user);

$pageTitle  = 'Transpo Filing';
$activePage = 'transpo-filing';

// Whose filing: yourself, or (admins) any approved user.
$engineer = $user;
$people   = [];
if ($isAdmin) {
    $people = db()->query(
        "SELECT user_id, role, field_engineer_id, first_name, last_name, transpo_base_location_id
         FROM users WHERE status = 'approved' ORDER BY last_name, first_name"
    )->fetchAll();
    foreach ($people as $p) {
        if ((int) $p['user_id'] === (int) ($_GET['engineer'] ?? 0)) {
            $engineer = $p;
        }
    }
}
$engineerId = (int) $engineer['user_id'];
$isSelf     = $engineerId === (int) $user['user_id'];

$date = $_GET['date'] ?? date('Y-m-d');
$d    = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $date);
if (!$d || $d->format('Y-m-d') !== $date) {
    $date = date('Y-m-d');
}
$isToday = $date === date('Y-m-d');

// The day's tickets with their legs.
$stmt = db()->prepare(
    'SELECT v.*, m.name AS merchant_name, m.address AS merchant_address, m.type AS merchant_type, m.times_used AS merchant_used
     FROM transpo_ticket_visits v JOIN transpo_locations m ON m.id = v.merchant_location_id
     WHERE v.engineer_id = ? AND DATE(v.visited_at) = ?
     ORDER BY v.visited_at, v.id'
);
$stmt->execute([$engineerId, $date]);
$visits = $stmt->fetchAll();

$legsByVisit = [];
if ($visits) {
    $ids  = array_column($visits, 'id');
    $stmt = db()->prepare(
        'SELECT g.*, f.name AS from_name, f.type AS from_type, t.name AS to_name, t.type AS to_type, r.status AS rate_status
         FROM transpo_trip_legs g
         JOIN transpo_locations f ON f.id = g.from_location_id
         JOIN transpo_locations t ON t.id = g.to_location_id
         LEFT JOIN transpo_route_rates r ON r.id = g.route_rate_id
         WHERE g.visit_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
         ORDER BY g.visit_id, g.seq'
    );
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $leg) {
        $legsByVisit[$leg['visit_id']][] = $leg;
    }
}

$dayTotal = 0.0;
$jsVisits = [];
foreach ($visits as $v) {
    $legs     = $legsByVisit[$v['id']] ?? [];
    $subtotal = array_sum(array_map(fn($l) => (float) $l['fare'], $legs));
    $dayTotal += $subtotal;
    $jsVisits[] = [
        'id'         => (int) $v['id'],
        'ticket_no'  => $v['ticket_no'],
        'visited_at' => str_replace(' ', 'T', substr($v['visited_at'], 0, 16)),
        'remarks'    => (string) $v['remarks'],
        'address'    => (string) $v['merchant_address'],
        'subtotal'   => $subtotal,
        'merchant'   => ['id' => (int) $v['merchant_location_id'], 'name' => $v['merchant_name'], 'type' => $v['merchant_type'], 'address' => (string) $v['merchant_address'], 'times_used' => (int) $v['merchant_used']],
        'legs'       => array_map(fn($l) => [
            'from' => ['id' => (int) $l['from_location_id'], 'name' => $l['from_name'], 'type' => $l['from_type']],
            'to'   => ['id' => (int) $l['to_location_id'], 'name' => $l['to_name'], 'type' => $l['to_type']],
            'mode' => $l['mode'],
            'fare' => (float) $l['fare'],
        ], $legs),
        'can_edit'   => transpo_can_edit_visit($v, $user),
    ];
}

$base = transpo_base_location($engineer);

// DAS Time In / Time Out for the same day.
$stmt = db()->prepare('SELECT time_in, time_out, filing_type FROM time_logs WHERE user_id = ? AND work_date = ?');
$stmt->execute([$engineerId, $date]);
$timeLog = $stmt->fetch() ?: null;

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

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
      <div id="filingMessage" class="alert d-none" role="status"></div>

      <!-- Day picker -->
      <div class="card">
        <div class="card-body pt-3">
          <form method="get" class="row g-2 align-items-end">
<?php if ($isAdmin): ?>
            <div class="col-md-4">
              <label for="engineer" class="form-label">Engineer</label>
              <select name="engineer" id="engineer" class="form-select" onchange="this.form.submit()">
<?php foreach ($people as $p): ?>
                <option value="<?= (int) $p['user_id'] ?>" <?= (int) $p['user_id'] === $engineerId ? 'selected' : '' ?>>
                  <?= e($p['last_name'] . ', ' . $p['first_name']) ?><?= (int) $p['user_id'] === (int) $user['user_id'] ? ' (me)' : ($p['field_engineer_id'] ? ' — ' . e($p['field_engineer_id']) : '') ?>
                </option>
<?php endforeach; ?>
              </select>
            </div>
<?php endif; ?>
            <div class="col-7 col-md-3">
              <label for="date" class="form-label">Date</label>
              <input type="date" name="date" id="date" class="form-control" value="<?= e($date) ?>" max="<?= e(date('Y-m-d')) ?>" onchange="this.form.submit()">
            </div>
            <div class="col-5 col-md-2">
              <a href="transpo_filing.php<?= $isAdmin ? '?engineer=' . $engineerId : '' ?>" class="btn btn-outline-secondary w-100">Today</a>
            </div>
            <div class="col-md-3 small text-muted">
<?php if ($timeLog): ?>
              <i class="bi bi-clock"></i> DAS: In <strong><?= e(time_log_time($timeLog['time_in'])) ?></strong>
              · Out <strong><?= e(time_log_time($timeLog['time_out']) ?: '—') ?></strong>
              <?= $timeLog['filing_type'] === 'holiday' ? time_log_filing_badge('holiday') : '' ?>
<?php else: ?>
              <i class="bi bi-clock"></i> No DAS time-in this day
<?php endif; ?>
            </div>
          </form>

          <div class="row g-2 align-items-end mt-2">
            <div class="col-md-6">
              <label for="baseSelect" class="form-label mb-1"><i class="bi bi-house-door"></i> <?= $isSelf ? 'My' : e($engineer['first_name'] . "'s") ?> daily starting point</label>
              <select id="baseSelect"></select>
            </div>
            <div class="col-md-6 small text-muted">The first leg of the day starts here (e.g. "NEAREST HIGHWAY FROM BASE"). Change it if <?= $isSelf ? 'your' : 'their' ?> base is different.</div>
          </div>
        </div>
      </div>

      <div class="row">
        <!-- Saved tickets for the day -->
        <div class="col-lg-5 order-lg-2">
          <div class="card">
            <div class="card-body pt-3">
              <h5 class="card-title py-0 mb-2 d-flex justify-content-between">
                <span><?= e(date('D, M j, Y', strtotime($date))) ?></span>
                <span class="transpo-total">Day total: <strong id="dayTotal"><?= e(transpo_money($dayTotal)) ?></strong></span>
              </h5>
<?php foreach ($visits as $i => $v):
    $legs = $legsByVisit[$v['id']] ?? [];
    $js   = $jsVisits[$i];
?>
              <div class="transpo-visit py-2" id="visit-<?= (int) $v['id'] ?>">
                <div class="d-flex justify-content-between align-items-start gap-2">
                  <div>
                    <div class="fw-semibold"><?= e($v['ticket_no']) ?></div>
                    <div><?= e($v['merchant_name']) ?></div>
                    <div class="small text-muted"><?= e(date('g:i A', strtotime($v['visited_at']))) ?><?= $v['merchant_address'] ? ' · ' . e($v['merchant_address']) : '' ?></div>
<?php if ($v['remarks']): ?>
                    <div class="small text-danger"><?= e($v['remarks']) ?></div>
<?php endif; ?>
                  </div>
                  <div class="text-end">
                    <div class="fw-semibold transpo-total"><?= e(transpo_money($js['subtotal'])) ?></div>
<?php if ($js['can_edit']): ?>
                    <div class="btn-group btn-group-sm mt-1">
                      <button type="button" class="btn btn-outline-primary edit-visit" data-id="<?= (int) $v['id'] ?>" title="Edit"><i class="bi bi-pencil"></i></button>
                      <button type="button" class="btn btn-outline-danger delete-visit" data-id="<?= (int) $v['id'] ?>" data-ticket="<?= e($v['ticket_no']) ?>" title="Delete"><i class="bi bi-trash"></i></button>
                    </div>
<?php endif; ?>
                  </div>
                </div>
                <ol class="small mb-0 mt-1 ps-3">
<?php foreach ($legs as $leg): ?>
                  <li class="transpo-route">
                    <?= e($leg['from_name']) ?><span class="arrow">→</span><?= e($leg['to_name']) ?>
                    <span class="text-muted">· <?= e($leg['mode']) ?> ·</span> <?= e(transpo_money($leg['fare'])) ?>
                    <?= $leg['fare_overridden'] ? '<span class="badge bg-warning text-dark">override</span>' : '' ?>
                    <?= $leg['rate_status'] === 'pending' ? '<span class="badge bg-light text-dark border">pending fare</span>' : '' ?>
                  </li>
<?php endforeach; ?>
                </ol>
              </div>
<?php endforeach; ?>
<?php if (!$visits): ?>
              <p class="text-muted mb-0">No tickets filed for this day yet.</p>
<?php endif; ?>
            </div>
          </div>
        </div>

        <!-- Add / edit ticket -->
        <div class="col-lg-7 order-lg-1">
          <div class="card">
            <div class="card-body pt-3">
              <h5 class="card-title py-0 mb-2" id="formTitle">Add Ticket</h5>
              <div class="alert alert-danger d-none" id="formErrors"></div>

              <form id="visitForm" novalidate>
                <input type="hidden" id="visitId" value="">
                <div class="row g-2">
                  <div class="col-sm-6">
                    <label for="ticketNo" class="form-label">Ticket No.</label>
                    <input type="text" id="ticketNo" class="form-control text-uppercase" placeholder="260911-BSERV-0523" maxlength="30" autocomplete="off" required>
                    <div class="invalid-feedback">Use the format 260911-BSERV-0523.</div>
                  </div>
                  <div class="col-sm-6">
                    <label for="visitedAt" class="form-label">Date &amp; Time</label>
                    <input type="datetime-local" id="visitedAt" class="form-control" required>
                  </div>
                  <div class="col-12">
                    <label for="merchantSelect" class="form-label">Merchant</label>
                    <select id="merchantSelect"></select>
                  </div>
                  <div class="col-12">
                    <label for="address" class="form-label">Address <span class="text-muted small">(optional)</span></label>
                    <input type="text" id="address" class="form-control" maxlength="255" placeholder="Saved on the merchant the first time">
                  </div>
                  <div class="col-12">
                    <label for="remarks" class="form-label">Remarks <span class="text-muted small">(optional)</span></label>
                    <input type="text" id="remarks" class="form-control" maxlength="255" placeholder="e.g. LATE FILING - DUE ISMS PROBLEM">
                  </div>
                </div>

                <h6 class="mt-3 mb-2">Legs <span class="small text-muted">(From → To · Mode · Fare)</span></h6>
                <div id="legs"></div>
                <button type="button" class="btn btn-sm btn-outline-primary" id="addLeg"><i class="bi bi-plus-lg"></i> Add leg</button>

                <div class="d-flex justify-content-between align-items-center border-top mt-3 pt-2">
                  <span>Ticket subtotal: <strong class="transpo-total" id="subtotal">₱0.00</strong></span>
                  <span class="small text-muted">Day total with this: <strong class="transpo-total" id="dayWithThis"><?= e(transpo_money($dayTotal)) ?></strong></span>
                </div>

                <div class="d-flex flex-wrap gap-2 mt-3 transpo-actions">
                  <button type="submit" class="btn btn-primary" id="saveVisit"><i class="bi bi-save"></i> Save Ticket</button>
                  <button type="button" class="btn btn-outline-secondary d-none" id="cancelEdit">Cancel edit</button>
                </div>
              </form>
            </div>
          </div>
        </div>
      </div>
    </section>

  </main><!-- End #main -->

  <!-- One leg (cloned by the script) -->
  <template id="legTemplate">
    <div class="card card-body p-2 mb-2 transpo-leg">
      <div class="d-flex justify-content-between align-items-center mb-1">
        <span class="leg-no small">Leg 1</span>
        <span class="btn-group btn-group-sm">
          <button type="button" class="btn btn-outline-secondary leg-up" title="Move up"><i class="bi bi-arrow-up"></i></button>
          <button type="button" class="btn btn-outline-secondary leg-down" title="Move down"><i class="bi bi-arrow-down"></i></button>
          <button type="button" class="btn btn-outline-danger leg-remove" title="Remove leg"><i class="bi bi-x-lg"></i></button>
        </span>
      </div>
      <div class="row g-2">
        <div class="col-md-6"><label class="form-label small mb-0">From</label><select class="leg-from"></select></div>
        <div class="col-md-6"><label class="form-label small mb-0">To</label><select class="leg-to"></select></div>
        <div class="col-6 col-md-4">
          <label class="form-label small mb-0">Mode</label>
          <select class="form-select leg-mode">
            <option value="">Mode…</option>
<?php foreach (TRANSPO_MODES as $key => $label): ?>
            <option value="<?= e($key) ?>"><?= e($label) ?></option>
<?php endforeach; ?>
          </select>
        </div>
        <div class="col-6 col-md-4">
          <label class="form-label small mb-0">Fare (₱)</label>
          <input type="number" class="form-control leg-fare" min="0.01" max="<?= TRANSPO_MAX_FARE ?>" step="0.01" inputmode="decimal" placeholder="0.00">
        </div>
        <div class="col-md-4 small leg-rate d-flex align-items-end"></div>
      </div>
      <div class="leg-add-rate d-none mt-2 p-2 bg-light rounded">
        <div class="small mb-1">Add this route to the fare list<?= $isAdmin ? '' : ' (an admin will approve it)' ?>:</div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
          <input type="number" class="form-control form-control-sm rate-fare" style="max-width: 7rem" min="0.01" step="0.01" inputmode="decimal" placeholder="Fare">
          <label class="form-check-label small"><input type="checkbox" class="form-check-input rate-two-way" checked> Same fare both ways</label>
          <button type="button" class="btn btn-sm btn-success rate-save">Save fare</button>
          <button type="button" class="btn btn-sm btn-link rate-cancel">Cancel</button>
        </div>
      </div>
    </div>
  </template>

<?php require __DIR__ . '/includes/transpo_assets.php'; ?>
  <script>
    window.FILING = {
      engineerId: <?= $engineerId ?>,
      date: <?= json_encode($date) ?>,
      isToday: <?= $isToday ? 'true' : 'false' ?>,
      now: <?= json_encode(date('H:i')) ?>,
      base: <?= json_encode(transpo_location_json($base)) ?>,
      visits: <?= json_encode($jsVisits) ?>,
      dayTotal: <?= json_encode($dayTotal) ?>,
      returnUrl: <?= json_encode('transpo_filing.php?date=' . $date . ($isAdmin ? '&engineer=' . $engineerId : '')) ?>
    };
  </script>
  <script src="assets/js/transpo-filing.js"></script>

<?php
require __DIR__ . '/includes/footer.php';
require __DIR__ . '/includes/scripts.php';
