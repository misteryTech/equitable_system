<?php
/**
 * My DAS (Data Activity Sheet): built from the user's own time records.
 * Date, filing (Regular / Holiday), schedule, regular hours, early in and OT
 * come from the time logs; the user types the Task for each day and picks whether
 * it was spent Within the city or Out of town.
 *
 * Leave: the user marks any dates they pick as leave (several at once is fine).
 * On the DAS a leave day shows as "Leave", or "Duty with pay" if they timed in that day.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/time_logs.php';
$user = require_role('field_engineer', 'admin');

$pageTitle  = 'My DAS';
$activePage = 'das';

$isDate = static function ($value): bool {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $value);
    return $d !== false && $d->format('Y-m-d') === $value;
};

$from = $isDate($_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
$to   = $isDate($_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
$back = 'das.php?from=' . urlencode($from) . '&to=' . urlencode($to);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save_tasks';

    if (!csrf_verify()) {
        $_SESSION['flash'] = ['danger', 'Your session expired. Please try again.'];
    } elseif ($action === 'set_leave') {
        // Any dates the user picks (several at once); a date already marked is skipped.
        $dates   = array_values(array_unique(array_map('strval', (array) ($_POST['leave_dates'] ?? []))));
        $minDate = date('Y-m-d', strtotime('-1 year'));
        $maxDate = date('Y-m-d', strtotime('+1 year'));
        $invalid = array_filter($dates, fn($d) => !$isDate($d) || $d < $minDate || $d > $maxDate);

        if (!$dates) {
            $_SESSION['flash'] = ['danger', 'Pick at least one leave date.'];
        } elseif (count($dates) > 60) {
            $_SESSION['flash'] = ['danger', 'You can add up to 60 leave dates at a time.'];
        } elseif ($invalid) {
            $_SESSION['flash'] = ['danger', 'Leave dates must be valid and within one year of today.'];
        } else {
            sort($dates);
            $insert = db()->prepare('INSERT IGNORE INTO leave_days (user_id, leave_date) VALUES (?, ?)');
            $added  = [];
            foreach ($dates as $date) {
                $insert->execute([$user['user_id'], $date]);
                if ($insert->rowCount()) {
                    $added[] = date('M j', strtotime($date));
                }
            }
            $skipped = count($dates) - count($added);
            $_SESSION['flash'] = $added
                ? ['success', 'Leave set for ' . implode(', ', $added) . '.' . ($skipped ? " $skipped date" . ($skipped === 1 ? ' was' : 's were') . ' already marked as leave.' : '')]
                : ['warning', 'Those dates are already marked as leave.'];
        }
    } elseif ($action === 'remove_leave') {
        $stmt = db()->prepare('DELETE FROM leave_days WHERE leave_id = ? AND user_id = ?');
        $stmt->execute([(int) ($_POST['leave_id'] ?? 0), $user['user_id']]);
        $_SESSION['flash'] = $stmt->rowCount() ? ['success', 'Leave day removed.'] : ['warning', 'That leave day was already removed.'];
    } else {
        $tasks   = (array) ($_POST['task'] ?? []);
        $trips   = (array) ($_POST['trip'] ?? []);
        $tooLong = false;
        foreach ($tasks as $task) {
            if (mb_strlen(trim((string) $task)) > 500) {
                $tooLong = true;
                break;
            }
        }
        $badTrip = array_diff(array_map('strval', $trips), array_keys(TRIP_TYPES));
        if ($tooLong) {
            $_SESSION['flash'] = ['danger', 'A task can be at most 500 characters.'];
        } elseif ($badTrip) {
            $_SESSION['flash'] = ['danger', 'Please choose Within the city or Out of town.'];
        } else {
            // Only the task and the location are saved, and only on the user's own records.
            $saveTask = db()->prepare('UPDATE time_logs SET task = ? WHERE id = ? AND user_id = ?');
            $saveTrip = db()->prepare('UPDATE time_logs SET trip_type = ? WHERE id = ? AND user_id = ?');
            $changed  = [];
            foreach ($tasks as $logId => $task) {
                $task = trim((string) $task);
                $saveTask->execute([$task === '' ? null : $task, (int) $logId, $user['user_id']]);
                if ($saveTask->rowCount()) {
                    $changed[(int) $logId] = true;
                }
            }
            foreach ($trips as $logId => $trip) {
                $saveTrip->execute([(string) $trip, (int) $logId, $user['user_id']]);
                if ($saveTrip->rowCount()) {
                    $changed[(int) $logId] = true;
                }
            }
            $saved = count($changed);
            $_SESSION['flash'] = ['success', $saved ? "Saved $saved day" . ($saved === 1 ? '' : 's') . '.' : 'No changes to save.'];
        }
    }
    redirect($back);
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$stmt = db()->prepare(
    'SELECT id, work_date, filing_type, trip_type, sched_in, sched_out, time_out, reg_hrs, early_in_hrs, ot_hrs, task
     FROM time_logs
     WHERE user_id = ? AND work_date BETWEEN ? AND ?
     ORDER BY work_date'
);
$stmt->execute([$user['user_id'], $from, $to]);
$logsByDate = [];
foreach ($stmt->fetchAll() as $log) {
    $logsByDate[$log['work_date']] = $log;
}
$leaves = leave_days_between((int) $user['user_id'], $from, $to);

// One DAS row per date that has a time record and/or a leave day.
$dates = array_unique(array_merge(array_keys($logsByDate), array_keys($leaves)));
sort($dates);
$rows = [];
foreach ($dates as $date) {
    $log = $logsByDate[$date] ?? null;
    $rows[] = [
        'date'     => $date,
        'log'      => $log,
        'leave_id' => $leaves[$date] ?? null,
        'status'   => day_status(isset($leaves[$date]), $log !== null),
    ];
}

$totals = ['reg_hrs' => 0, 'early_in_hrs' => 0, 'ot_hrs' => 0];
$counts = array_fill_keys(array_keys(DAY_STATUSES), 0);
$outOfTown = 0;
foreach ($rows as $row) {
    $counts[$row['status']]++;
    $outOfTown += $row['log'] && $row['log']['trip_type'] === 'out_of_town' ? 1 : 0;
    if ($row['log'] && $row['log']['time_out']) {
        foreach ($totals as $key => $sum) {
            $totals[$key] = $sum + $row['log'][$key];
        }
    }
}

// Tab-separated rows for "Copy all":
// Date, Schedule, Task, Reg Hrs, Early In, OT Hrs, Filing, Status, Location.
// Filing, Status and Location are last so the earlier Excel columns don't shift.
// Line breaks in a task become ", " so each day stays on one Excel row.
$copyRows = array_map(function ($row) {
    $log  = $row['log'];
    $done = $log && $log['time_out'];
    return [
        time_log_date($row['date']),
        $log ? time_log_schedule($log['sched_in'], $log['sched_out']) : '',
        $log ? preg_replace('/\s*[\r\n]+\s*/', ', ', trim((string) $log['task'])) : 'LEAVE',
        $done ? time_log_hours($log['reg_hrs'], true) : '',
        $done ? time_log_hours($log['early_in_hrs'], true) : '',
        $done ? time_log_hours($log['ot_hrs'], true) : '',
        $log ? (FILING_TYPES[$log['filing_type']] ?? $log['filing_type']) : '',
        DAY_STATUSES[$row['status']]['label'],
        $log ? (TRIP_TYPES[$log['trip_type']] ?? $log['trip_type']) : '',
    ];
}, $rows);

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
            <div class="col-sm-4 col-md-3">
              <label for="from" class="form-label">From</label>
              <input type="date" name="from" id="from" class="form-control" value="<?= e($from) ?>">
            </div>
            <div class="col-sm-4 col-md-3">
              <label for="to" class="form-label">To</label>
              <input type="date" name="to" id="to" class="form-control" value="<?= e($to) ?>">
            </div>
            <div class="col-sm-4 col-md-6 d-flex flex-wrap gap-2">
              <button type="submit" class="btn btn-primary"><i class="bi bi-funnel"></i> Show</button>
              <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#leaveModal">
                <i class="bi bi-calendar-x"></i> Set Leave Day
              </button>
              <button type="button" class="btn btn-outline-success ms-auto" id="copyAll" <?= $rows ? '' : 'disabled' ?>>
                <i class="bi bi-clipboard"></i> Copy all
              </button>
            </div>
          </form>

<?php if ($flash): ?>
          <div class="alert alert-<?= e($flash[0]) ?> py-2"><?= e($flash[1]) ?></div>
<?php endif; ?>
          <div id="copyMessage" class="alert alert-success py-2 d-none" role="status"></div>

<?php if ($rows): ?>
          <p class="small mb-2">
            <?= day_status_badge('duty') ?> <?= $counts['duty'] ?>
            <span class="ms-2"><?= day_status_badge('duty_with_pay') ?> <?= $counts['duty_with_pay'] ?></span>
            <span class="ms-2"><?= day_status_badge('leave') ?> <?= $counts['leave'] ?></span>
            <span class="ms-2"><span class="badge bg-info text-dark">Out of town</span> <?= $outOfTown ?></span>
          </p>
<?php endif; ?>

          <form method="post" action="<?= e($back) ?>" id="dasForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_tasks">

            <div class="table-responsive">
              <table class="table table-bordered table-sm align-middle text-nowrap">
                <thead class="table-light">
                  <tr>
                    <th style="width: 7rem">Date</th>
                    <th class="d-none d-md-table-cell">Schedule</th>
                    <th>Task</th>
                    <th style="width: 6rem">Filing</th>
                    <th style="width: 10rem">Location</th>
                    <th style="width: 7rem">Status</th>
                    <th class="text-end">Reg Hrs</th>
                    <th class="text-end">Early In</th>
                    <th class="text-end">OT Hrs</th>
                  </tr>
                </thead>
                <tbody>
<?php foreach ($rows as $row):
    $log      = $row['log'];
    $done     = $log && $log['time_out'];
    $schedule = $log ? time_log_schedule($log['sched_in'], $log['sched_out']) : '';
    $rowClass = $row['status'] === 'leave' ? 'table-secondary' : ($log && $log['filing_type'] === 'holiday' ? 'table-danger' : '');
?>
                  <tr class="<?= $rowClass ?>">
                    <td>
                      <?= e(time_log_date($row['date'])) ?>
                      <div class="small text-muted"><?= e(date('l', strtotime($row['date']))) ?></div>
                      <!-- On phones the Schedule column is hidden, so show it here -->
<?php if ($log): ?>
                      <div class="small text-muted d-md-none"><?= e($schedule ?: 'No schedule') ?></div>
<?php endif; ?>
                    </td>
                    <td class="d-none d-md-table-cell">
<?php if ($log): ?>
                      <?= $schedule ? e($schedule) : '<span class="text-muted fst-italic">No schedule</span>' ?>
<?php else: ?>
                      <span class="text-muted">—</span>
<?php endif; ?>
                    </td>
                    <td>
<?php if ($log): ?>
                      <textarea name="task[<?= (int) $log['id'] ?>]" class="form-control form-control-sm das-task" rows="2" maxlength="500" style="min-width: 12rem; white-space: pre-wrap"
                                placeholder="Tickets / activities performed" aria-label="Task for <?= e(time_log_date($row['date'])) ?>"><?= e($log['task']) ?></textarea>
<?php else: ?>
                      <span class="fw-semibold text-secondary">LEAVE</span>
<?php endif; ?>
                    </td>
                    <td><?= $log ? time_log_filing_badge($log['filing_type']) : '<span class="text-muted">—</span>' ?></td>
                    <td>
<?php if ($log): ?>
                      <select name="trip[<?= (int) $log['id'] ?>]" class="form-select form-select-sm das-trip" aria-label="Location on <?= e(time_log_date($row['date'])) ?>">
<?php foreach (TRIP_TYPES as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $log['trip_type'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
<?php endforeach; ?>
                      </select>
<?php else: ?>
                      <span class="text-muted">—</span>
<?php endif; ?>
                    </td>
                    <td><?= day_status_badge($row['status']) ?></td>
                    <td class="text-end"><?= $done ? e(time_log_hours($log['reg_hrs'], true)) : ($log ? '<span class="text-muted small">on duty</span>' : '') ?></td>
                    <td class="text-end"><?= $done ? e(time_log_hours($log['early_in_hrs'], true)) : '' ?></td>
                    <td class="text-end"><?= $done ? e(time_log_hours($log['ot_hrs'], true)) : '' ?></td>
                  </tr>
<?php endforeach; ?>
<?php if (!$rows): ?>
                  <tr><td colspan="9" class="text-muted text-center py-4">No time records or leave days in this date range. Use the Time Clock to time in and out, or set a leave day.</td></tr>
<?php endif; ?>
                </tbody>
<?php if ($rows): ?>
                <tfoot class="table-light fw-bold">
                  <tr>
                    <td colspan="6" class="text-end">Total (<?= e(time_log_date($from)) ?> – <?= e(time_log_date($to)) ?>)</td>
                    <td class="text-end"><?= e(time_log_hours($totals['reg_hrs'], true)) ?></td>
                    <td class="text-end"><?= e(time_log_hours($totals['early_in_hrs'], true)) ?></td>
                    <td class="text-end"><?= e(time_log_hours($totals['ot_hrs'], true)) ?></td>
                  </tr>
                </tfoot>
<?php endif; ?>
              </table>
            </div>

<?php if ($logsByDate): ?>
            <div class="d-flex align-items-center gap-3">
              <button type="submit" class="btn btn-primary" id="dasSave"><i class="bi bi-save"></i> Save</button>
              <span class="small text-muted">Saves the tasks and locations. Date, filing, schedule and hours come from your time records.</span>
            </div>
<?php endif; ?>
          </form>

          <p class="small text-muted mt-3 mb-0">
            <strong>Status:</strong> Duty = a regular working day · Leave = a day you marked as leave · Duty with pay = you worked on your leave day.
            <br>"Copy all" copies the rows without headers, tab-separated, in the order Date, Schedule, Task, Reg Hrs, Early In, OT Hrs, Filing, Status, Location. It copies what is saved, so save first.
          </p>

        </div>
      </div>
    </section>

  </main><!-- End #main -->

  <!-- Set leave day modal -->
  <div class="modal fade" id="leaveModal" tabindex="-1" aria-labelledby="leaveModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="leaveModalTitle">Leave Day</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <form method="post" action="<?= e($back) ?>" id="leaveForm" class="mb-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="set_leave">
            <div class="row g-2 align-items-end">
              <div class="col-sm-5">
                <label for="leaveFrom" class="form-label">Date</label>
                <input type="date" id="leaveFrom" class="form-control">
              </div>
              <div class="col-sm-5">
                <label for="leaveTo" class="form-label">To <span class="text-muted small">(optional, for a range)</span></label>
                <input type="date" id="leaveTo" class="form-control">
              </div>
              <div class="col-sm-2">
                <button type="button" class="btn btn-outline-primary w-100" id="leaveAdd"><i class="bi bi-plus-lg"></i> Add</button>
              </div>
            </div>
            <div class="form-text">Pick any days you want as leave. Add one date, or a From–To range, as many times as you like. If you time in on a leave day, it shows as "Duty with pay".</div>
            <div id="leaveChips" class="d-flex flex-wrap gap-1 my-2"></div>
            <div class="text-danger small d-none" id="leaveError"></div>
            <button type="submit" class="btn btn-primary" id="leaveSave" disabled><i class="bi bi-calendar-plus"></i> Set Leave (<span id="leaveCount">0</span>)</button>
          </form>

          <h6 class="small text-muted text-uppercase">Leave days in this range</h6>
<?php if ($leaves): ?>
          <ul class="list-group">
<?php foreach ($leaves as $date => $leaveId): ?>
            <li class="list-group-item d-flex justify-content-between align-items-center">
              <span>
                <?= e(date('D, M j, Y', strtotime($date))) ?>
                <?= day_status_badge(day_status(true, isset($logsByDate[$date]))) ?>
              </span>
              <form method="post" action="<?= e($back) ?>" onsubmit="return confirm('Remove this leave day?')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="remove_leave">
                <input type="hidden" name="leave_id" value="<?= (int) $leaveId ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger" title="Remove"><i class="bi bi-x-lg"></i> Remove</button>
              </form>
            </li>
<?php endforeach; ?>
          </ul>
<?php else: ?>
          <p class="text-muted small mb-0">None between <?= e(time_log_date($from)) ?> and <?= e(time_log_date($to)) ?>.</p>
<?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <script>
    (function () {
      var rows = <?= json_encode($copyRows) ?>;
      var form = document.getElementById("dasForm");
      var copyButton = document.getElementById("copyAll");
      var message = document.getElementById("copyMessage");
      var dirty = false;

      // Warn before leaving with unsaved tasks or locations; block double submits.
      form.addEventListener("input", function (e) {
        if (e.target.classList.contains("das-task") || e.target.classList.contains("das-trip")) { dirty = true; }
      });
      form.addEventListener("submit", function () {
        dirty = false;
        var save = document.getElementById("dasSave");
        if (save) { save.disabled = true; }
      });
      window.addEventListener("beforeunload", function (e) {
        if (dirty) { e.preventDefault(); e.returnValue = ""; }
      });

      // Leave picker: collect dates (single days or From-To ranges) as removable chips.
      (function () {
        var fromInput = document.getElementById("leaveFrom");
        var toInput = document.getElementById("leaveTo");
        var chips = document.getElementById("leaveChips");
        var error = document.getElementById("leaveError");
        var save = document.getElementById("leaveSave");
        var picked = {};
        var MAX = 60;

        function iso(d) { return d.getFullYear() + "-" + String(d.getMonth() + 1).padStart(2, "0") + "-" + String(d.getDate()).padStart(2, "0"); }
        function label(value) { return new Date(value + "T00:00:00").toLocaleDateString("en-US", { weekday: "short", month: "short", day: "numeric", year: "numeric" }); }
        function showError(text) { error.textContent = text; error.classList.toggle("d-none", !text); }

        function render() {
          chips.innerHTML = "";
          Object.keys(picked).sort().forEach(function (value) {
            var chip = document.createElement("span");
            chip.className = "badge bg-secondary d-inline-flex align-items-center gap-1";
            chip.textContent = label(value);
            var hidden = document.createElement("input");
            hidden.type = "hidden"; hidden.name = "leave_dates[]"; hidden.value = value;
            var x = document.createElement("button");
            x.type = "button"; x.className = "btn-close btn-close-white"; x.style.fontSize = "0.6rem";
            x.setAttribute("aria-label", "Remove " + label(value));
            x.addEventListener("click", function () { delete picked[value]; render(); });
            chip.appendChild(hidden); chip.appendChild(x); chips.appendChild(chip);
          });
          var count = Object.keys(picked).length;
          document.getElementById("leaveCount").textContent = count;
          save.disabled = count === 0;
        }

        document.getElementById("leaveAdd").addEventListener("click", function () {
          showError("");
          if (!fromInput.value) { showError("Pick a date first."); return; }
          var start = new Date(fromInput.value + "T00:00:00");
          var end = toInput.value ? new Date(toInput.value + "T00:00:00") : start;
          if (end < start) { var t = start; start = end; end = t; }
          for (var d = new Date(start); d <= end; d.setDate(d.getDate() + 1)) {
            if (Object.keys(picked).length >= MAX) { showError("You can add up to " + MAX + " dates at a time."); break; }
            picked[iso(d)] = true;
          }
          fromInput.value = ""; toInput.value = "";
          render();
        });

        document.getElementById("leaveForm").addEventListener("submit", function () { save.disabled = true; });
      })();

      function done() {
        message.textContent = "Copied " + rows.length + " row" + (rows.length === 1 ? "" : "s") + ". Paste into Excel." +
          (dirty ? " Note: you have unsaved changes that were not copied." : "");
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

      copyButton.addEventListener("click", function () {
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
