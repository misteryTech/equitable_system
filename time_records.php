<?php
/**
 * My Records: Time In / Time Out history with computed hours for a date range,
 * plus a "Copy all" button for pasting into the Excel DAS. Everyone sees their
 * own records; admins can also pick any user.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/time_logs.php';
$user    = require_role('field_engineer', 'admin');
$isAdmin = is_admin($user);

$pageTitle  = 'My Records';
$activePage = 'time-records';

// Whose records to show: always your own, unless an admin picks someone else.
$viewUserId = (int) $user['user_id'];
$people     = [];
if ($isAdmin) {
    $people = db()->query(
        'SELECT user_id, role, field_engineer_id, first_name, last_name FROM users ORDER BY last_name, first_name'
    )->fetchAll();
    $requested = (int) ($_GET['user'] ?? 0);
    if (in_array($requested, array_map('intval', array_column($people, 'user_id')), true)) {
        $viewUserId = $requested;
    }
}

$isDate = static function ($value): bool {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $value);
    return $d !== false && $d->format('Y-m-d') === $value;
};

$from  = $_GET['from'] ?? '';
$to    = $_GET['to'] ?? '';
$error = '';
if (!$isDate($from)) {
    $from = date('Y-m-01');
}
if (!$isDate($to)) {
    $to = date('Y-m-d');
}
if ($from > $to) {
    $error = 'The start date is after the end date, so they were swapped.';
    [$from, $to] = [$to, $from];
}

$stmt = db()->prepare(
    'SELECT work_date, filing_type, sched_in, sched_out, time_in, time_out,
            total_hrs, reg_hrs, early_in_hrs, ot_hrs, late_mins, undertime_mins
     FROM time_logs
     WHERE user_id = ? AND work_date BETWEEN ? AND ?
     ORDER BY work_date'
);
$stmt->execute([$viewUserId, $from, $to]);
$logs = $stmt->fetchAll();

// Totals over completed days (hours are computed at TIME OUT).
$totals = ['total_hrs' => 0, 'reg_hrs' => 0, 'early_in_hrs' => 0, 'ot_hrs' => 0, 'late_mins' => 0, 'undertime_mins' => 0];
foreach ($logs as $log) {
    if ($log['time_out']) {
        foreach ($totals as $key => $sum) {
            $totals[$key] = $sum + $log[$key];
        }
    }
}

// Tab-separated rows for "Copy all", matching the Excel DAS columns:
// Date, Schedule, Time In, Time Out, Total Hrs, Reg Hrs, Early In, OT Hrs, Extra Hrs (Early In + OT), Filing.
// Filing is last so the existing Excel columns don't shift.
$copyRows = array_map(
    fn($log) => [
        time_log_date($log['work_date']),
        time_log_schedule($log['sched_in'], $log['sched_out']),
        time_log_time($log['time_in']),
        time_log_time($log['time_out']),
        $log['time_out'] ? time_log_hours($log['total_hrs'], true) : '',
        $log['time_out'] ? time_log_hours($log['reg_hrs'], true) : '',
        $log['time_out'] ? time_log_hours($log['early_in_hrs'], true) : '',
        $log['time_out'] ? time_log_hours($log['ot_hrs'], true) : '',
        $log['time_out'] ? time_log_hours(time_log_extra($log), true) : '',
        FILING_TYPES[$log['filing_type']] ?? $log['filing_type'],
    ],
    $logs
);

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
<?php if ($isAdmin): ?>
            <div class="col-md-3">
              <label for="user" class="form-label">User</label>
              <select name="user" id="user" class="form-select">
<?php foreach ($people as $person): ?>
                <option value="<?= (int) $person['user_id'] ?>" <?= (int) $person['user_id'] === $viewUserId ? 'selected' : '' ?>>
                  <?= e($person['last_name'] . ', ' . $person['first_name']) ?><?= (int) $person['user_id'] === (int) $user['user_id'] ? ' (me)' : ' — ' . e($person['field_engineer_id'] ?? role_label($person['role'])) ?>
                </option>
<?php endforeach; ?>
              </select>
            </div>
<?php endif; ?>
            <div class="col-sm-4 col-md-3">
              <label for="from" class="form-label">From</label>
              <input type="date" name="from" id="from" class="form-control" value="<?= e($from) ?>">
            </div>
            <div class="col-sm-4 col-md-3">
              <label for="to" class="form-label">To</label>
              <input type="date" name="to" id="to" class="form-control" value="<?= e($to) ?>">
            </div>
            <div class="col-sm-4 <?= $isAdmin ? 'col-md-3' : 'col-md-6' ?> d-flex gap-2">
              <button type="submit" class="btn btn-primary"><i class="bi bi-funnel"></i> Filter</button>
              <button type="button" class="btn btn-outline-success ms-auto" id="copyAll" <?= $logs ? '' : 'disabled' ?>>
                <i class="bi bi-clipboard"></i> Copy all
              </button>
            </div>
          </form>

<?php if ($viewUserId !== (int) $user['user_id']):
    $viewed = current(array_filter($people, fn($p) => (int) $p['user_id'] === $viewUserId));
?>
          <div class="alert alert-info py-2">Showing records for <strong><?= e($viewed['first_name'] . ' ' . $viewed['last_name']) ?></strong>.</div>
<?php endif; ?>
<?php if ($error): ?>
          <div class="alert alert-warning py-2"><?= e($error) ?></div>
<?php endif; ?>
          <div id="copyMessage" class="alert alert-success py-2 d-none" role="status"></div>

          <div class="table-responsive">
            <table class="table table-bordered table-sm align-middle text-nowrap">
              <thead class="table-light">
                <tr>
                  <th>Date</th>
                  <th>Filing</th>
                  <th>Schedule</th>
                  <th>Time In</th>
                  <th>Time Out</th>
                  <th class="text-end" title="Time in to time out, minus the break">Total Hrs</th>
                  <th class="text-end">Reg Hrs</th>
                  <th class="text-end">Early In</th>
                  <th class="text-end">OT Hrs</th>
                  <th class="text-end" title="Early In + OT">Extra Hrs</th>
                  <th class="text-end">Late</th>
                  <th class="text-end">Undertime</th>
                </tr>
              </thead>
              <tbody>
<?php foreach ($logs as $log):
    $done = (bool) $log['time_out'];
?>
                <tr class="<?= $log['filing_type'] === 'holiday' ? 'table-danger' : '' ?>">
                  <td><?= e(time_log_date($log['work_date'])) ?></td>
                  <td><?= time_log_filing_badge($log['filing_type']) ?></td>
                  <td>
<?php if ($log['sched_in'] && $log['sched_out']): ?>
                    <?= e(time_log_schedule($log['sched_in'], $log['sched_out'])) ?>
<?php else: ?>
                    <span class="text-muted fst-italic">No schedule</span>
<?php endif; ?>
                  </td>
                  <td><?= e(time_log_time($log['time_in'])) ?></td>
                  <td>
                    <?= e(time_log_time($log['time_out'])) ?>
<?php if (time_log_next_day($log)): ?>
                    <span class="badge bg-secondary">next day</span>
<?php endif; ?>
                  </td>
                  <td class="text-end"><?= $done ? e(time_log_hours($log['total_hrs'], true)) : '' ?></td>
                  <td class="text-end"><?= $done ? e(time_log_hours($log['reg_hrs'], true)) : '' ?></td>
                  <td class="text-end"><?= $done ? e(time_log_hours($log['early_in_hrs'], true)) : '' ?></td>
                  <td class="text-end"><?= $done ? e(time_log_hours($log['ot_hrs'], true)) : '' ?></td>
                  <td class="text-end fw-semibold"><?= $done ? e(time_log_hours(time_log_extra($log), true)) : '' ?></td>
                  <td class="text-end"><?= e(time_log_minutes((int) $log['late_mins'])) ?></td>
                  <td class="text-end"><?= e(time_log_minutes((int) $log['undertime_mins'])) ?></td>
                </tr>
<?php endforeach; ?>
<?php if (!$logs): ?>
                <tr><td colspan="12" class="text-muted text-center py-4">No records in this date range.</td></tr>
<?php endif; ?>
              </tbody>
<?php if ($logs): ?>
              <tfoot class="table-light fw-bold">
                <tr>
                  <td colspan="5" class="text-end">Total (<?= e(time_log_date($from)) ?> – <?= e(time_log_date($to)) ?>)</td>
                  <td class="text-end"><?= e(time_log_hours($totals['total_hrs'], true)) ?></td>
                  <td class="text-end"><?= e(time_log_hours($totals['reg_hrs'], true)) ?></td>
                  <td class="text-end"><?= e(time_log_hours($totals['early_in_hrs'], true)) ?></td>
                  <td class="text-end"><?= e(time_log_hours($totals['ot_hrs'], true)) ?></td>
                  <td class="text-end"><?= e(time_log_hours($totals['early_in_hrs'] + $totals['ot_hrs'], true)) ?></td>
                  <td class="text-end"><?= e(time_log_minutes((int) $totals['late_mins'])) ?></td>
                  <td class="text-end"><?= e(time_log_minutes((int) $totals['undertime_mins'])) ?></td>
                </tr>
              </tfoot>
<?php endif; ?>
            </table>
          </div>
          <p class="small text-muted mb-0">
            Hours are computed when you time out, from that day's schedule. Total Hrs is time in to time out minus the break. Early In (before the schedule) and OT Hrs (after the schedule) are counted separately; Extra Hrs = Early In + OT.
            "Copy all" copies the rows without headers, tab-separated, in the order Date, Schedule, Time In, Time Out, Total Hrs, Reg Hrs, Early In, OT Hrs, Extra Hrs, Filing.
          </p>

        </div>
      </div>
    </section>

  </main><!-- End #main -->

  <script>
    (function () {
      var rows = <?= json_encode($copyRows) ?>;
      var button = document.getElementById("copyAll");
      var message = document.getElementById("copyMessage");

      function done() {
        message.textContent = "Copied " + rows.length + " row" + (rows.length === 1 ? "" : "s") + ". Paste into Excel.";
        message.classList.remove("d-none");
      }

      // Fallback for browsers without the Clipboard API (e.g. pages opened over plain http on a LAN IP).
      function legacyCopy(text) {
        var area = document.createElement("textarea");
        area.value = text;
        area.setAttribute("readonly", "");
        area.style.position = "fixed";
        area.style.opacity = "0";
        document.body.appendChild(area);
        area.select();
        area.setSelectionRange(0, area.value.length);   // needed on iPhone (iOS Safari)
        var ok = document.execCommand("copy");
        document.body.removeChild(area);
        return ok;
      }

      button.addEventListener("click", function () {
        var text = rows.map(function (row) { return row.join("\t"); }).join("\r\n");
        if (navigator.clipboard && window.isSecureContext) {
          navigator.clipboard.writeText(text).then(done, function () { if (legacyCopy(text)) { done(); } });
        } else if (legacyCopy(text)) {
          done();
        }
      });
    })();
  </script>

<?php
require __DIR__ . '/includes/footer.php';
require __DIR__ . '/includes/scripts.php';
