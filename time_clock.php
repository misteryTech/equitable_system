<?php
/**
 * Time Clock: field engineers and admins record today's Time In and Time Out.
 * Times always come from the server clock (Asia/Manila), never the device.
 * Saved times cannot be edited or deleted.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/time_logs.php';
$user = require_role('field_engineer', 'admin');

$pageTitle  = 'Time Clock';
$activePage = 'time-clock';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Re-read the state from the database so stale pages and double submits can't create duplicates.
    $state = time_clock_state((int) $user['user_id']);
    $now   = date('Y-m-d H:i:s');

    if (!csrf_verify()) {
        $flash = ['danger', 'Your session expired. Please try again.'];
    } elseif (($_POST['action'] ?? '') === 'time_in') {
        $filingType = $_POST['filing_type'] ?? '';
        if ($state['action'] !== 'time_in') {
            $flash = ['warning', $state['action'] === 'time_out_yesterday'
                ? 'Please time out from yesterday first.'
                : 'You have already timed in today.'];
        } elseif (!array_key_exists($filingType, FILING_TYPES)) {
            $flash = ['danger', 'Please choose Regular filling or Holiday filling.'];
        } else {
            // The UNIQUE (user_id, work_date) key makes a second insert a no-op.
            // The current schedule is copied so later schedule changes don't alter this day.
            $stmt = db()->prepare(
                'INSERT IGNORE INTO time_logs (user_id, work_date, filing_type, sched_in, sched_out, time_in) VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$user['user_id'], date('Y-m-d'), $filingType, $user['reg_time_in'], $user['reg_time_out'], $now]);
            // On success the page itself shows "Timed in at ...".
            $flash = $stmt->rowCount() === 1 ? null : ['warning', 'You have already timed in today.'];
        }
    } elseif (($_POST['action'] ?? '') === 'time_out') {
        $logId = (int) ($_POST['log_id'] ?? 0);
        if (!in_array($state['action'], ['time_out', 'time_out_yesterday'], true) || (int) $state['log']['id'] !== $logId) {
            $flash = ['warning', 'You have already timed out.'];
        } else {
            $stmt = db()->prepare(
                'UPDATE time_logs SET time_out = ? WHERE id = ? AND user_id = ? AND time_in IS NOT NULL AND time_out IS NULL'
            );
            $stmt->execute([$now, $logId, $user['user_id']]);
            if ($stmt->rowCount() !== 1) {
                $flash = ['warning', 'You have already timed out.'];
            } else {
                // Compute and store the hours from the schedule saved on that day's row.
                $saved = time_log_save_hours($logId);
                if ($state['action'] === 'time_out_yesterday') {
                    // Today's card doesn't show yesterday's shift, so confirm it here.
                    $flash = ['success', 'Timed out at ' . time_log_time($now) . ' for your '
                        . date('M j', strtotime($state['log']['work_date'])) . ' shift. ' . time_log_summary($saved)];
                } else {
                    $flash = null;   // the page itself shows "Timed out at ..." and the summary
                }
            }
        }
    } else {
        $flash = ['danger', 'Unknown action.'];
    }

    if ($flash) {
        $_SESSION['flash'] = $flash;
    }
    redirect('time_clock.php');
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$state    = time_clock_state((int) $user['user_id']);
$todayLog = $state['today'];

// Data for the live (every second) display. It starts from the SERVER time at page
// load and counts forward with a timer, so the device clock is never used.
// Times are only ever saved by the server when a button is clicked.
$settings = time_settings();
$live = [
    'serverNow' => (int) round(microtime(true) * 1000),
    'mode'      => null,
    'rounding'  => (int) $settings['ot_rounding_min'],
    'minimum'   => (int) $settings['ot_minimum_min'],
];
$addSchedule = static function (array &$live, string $workDate, ?string $in, ?string $out, int $timeIn): void {
    if ($in && $out) {
        [$start, $end] = time_log_window($workDate, $in, $out, $timeIn);
        $live += ['start' => $start * 1000, 'end' => $end * 1000, 'startLabel' => time_log_clock($in), 'endLabel' => time_log_clock($out)];
    }
};
if ($state['action'] === 'time_in') {
    $live['mode'] = 'before';
    $addSchedule($live, date('Y-m-d'), $user['reg_time_in'], $user['reg_time_out'], time());
} elseif ($state['action'] === 'time_out' || $state['action'] === 'time_out_yesterday') {
    $timeIn = strtotime($state['log']['time_in']);
    $live['mode']   = 'on_duty';
    $live['timeIn'] = $timeIn * 1000;
    $addSchedule($live, $state['log']['work_date'], $state['log']['sched_in'], $state['log']['sched_out'], $timeIn);
}

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
      <div class="row justify-content-center">
        <div class="col-lg-6 col-md-8">

<?php if ($flash): ?>
          <div class="alert alert-<?= e($flash[0]) ?>"><?= e($flash[1]) ?></div>
<?php endif; ?>

          <div class="card text-center">
            <div class="card-body py-4">
              <h5 class="card-title pb-0"><?= e(date('l, F j, Y')) ?></h5>
              <p class="small text-muted">Times are recorded from the server clock (Philippine time).</p>

<?php if ($schedule = time_log_schedule($user['reg_time_in'], $user['reg_time_out'])): ?>
              <p class="mb-3">Your schedule: <strong><?= e($schedule) ?></strong></p>
<?php else: ?>
              <p class="mb-3 text-warning"><i class="bi bi-exclamation-triangle"></i> Schedule not set — contact admin</p>
<?php endif; ?>

<?php if (leave_days_between((int) $user['user_id'], date('Y-m-d'), date('Y-m-d'))): ?>
              <div class="alert alert-secondary text-start py-2">
                <i class="bi bi-calendar-x"></i> Today is your <strong>leave day</strong>.
                <?= $todayLog && $todayLog['time_in'] ? 'You timed in, so today counts as <strong>Duty with pay</strong> on your DAS.' : 'If you time in, it will count as <strong>Duty with pay</strong> on your DAS.' ?>
              </div>
<?php endif; ?>

<?php if ($state['action'] === 'time_out_yesterday'): ?>
              <div class="alert alert-warning text-start">
                You timed in yesterday (<?= e(date('M j', strtotime($state['log']['work_date']))) ?>) at
                <strong><?= e(time_log_time($state['log']['time_in'])) ?></strong> and haven't timed out.
                Time out for that shift first.
              </div>
<?php endif; ?>

<?php if ($live['mode']): ?>
              <!-- Live panel: filled and updated every second by the script below -->
              <div class="mb-3">
                <div class="display-6 fw-semibold" id="liveClock"><?= e(date('g:i:s A')) ?></div>
                <div class="small text-muted">Server time</div>
              </div>
<?php endif; ?>

<?php if ($todayLog && $todayLog['time_in']): ?>
              <p class="mb-2"><?= time_log_filing_badge($todayLog['filing_type']) ?> <span class="small text-muted">filing</span></p>
              <p class="fs-5 mb-1">Timed in at <strong><?= e(time_log_time($todayLog['time_in'])) ?></strong></p>
<?php endif; ?>

<?php if ($live['mode'] === 'on_duty'): ?>
              <p class="fs-5 mb-1">On duty: <strong id="liveDuty" class="font-monospace">0:00:00</strong></p>
<?php endif; ?>
<?php if ($live['mode'] && isset($live['start'])): ?>
              <p class="mb-1" id="liveArrival"></p>
              <p class="small text-muted mb-1" id="livePhase"></p>
<?php if ($live['mode'] === 'on_duty'): ?>
              <p class="mb-1" id="liveExtra"></p>
<?php endif; ?>
<?php endif; ?>
<?php if ($todayLog && $todayLog['time_out']): ?>
              <p class="fs-5 mb-1">Timed out at <strong><?= e(time_log_time($todayLog['time_out'])) ?></strong></p>
              <p class="mt-3 mb-0 p-2 bg-light rounded"><?= e(time_log_summary($todayLog)) ?></p>
<?php endif; ?>

<?php if ($state['action'] === 'time_in'): ?>
              <form method="post" class="mt-3" data-clock>
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="time_in">

                <div class="mb-3" role="radiogroup" aria-label="Filing type">
                  <div class="small text-muted mb-1">Choose filing type</div>
                  <input type="radio" class="btn-check" name="filing_type" id="filingRegular" value="regular" autocomplete="off" checked>
                  <label class="btn btn-outline-primary" for="filingRegular"><i class="bi bi-briefcase"></i> Regular filling</label>
                  <input type="radio" class="btn-check" name="filing_type" id="filingHoliday" value="holiday" autocomplete="off">
                  <label class="btn btn-outline-danger" for="filingHoliday"><i class="bi bi-calendar-heart"></i> Holiday filling</label>
                </div>

                <button type="submit" class="btn btn-success btn-lg px-5 py-3 fs-4"><i class="bi bi-box-arrow-in-right"></i> TIME IN</button>
              </form>
<?php elseif ($state['action'] === 'time_out' || $state['action'] === 'time_out_yesterday'): ?>
              <form method="post" class="mt-3" data-clock
                    data-confirm="<?= e($state['action'] === 'time_out_yesterday' ? 'Time out now for your shift that started yesterday?' : 'Time out now?') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="time_out">
                <input type="hidden" name="log_id" value="<?= (int) $state['log']['id'] ?>">
                <button type="submit" class="btn btn-danger btn-lg px-5 py-3 fs-4">
                  <i class="bi bi-box-arrow-right"></i> TIME OUT<?= $state['action'] === 'time_out_yesterday' ? ' (' . e(date('M j', strtotime($state['log']['work_date']))) . ')' : '' ?>
                </button>
              </form>
<?php else: ?>
              <p class="text-success mt-3 mb-0"><i class="bi bi-check-circle"></i> You're done for today.</p>
<?php endif; ?>

              <a href="time_records.php" class="d-inline-block small mt-4">View my records</a>
            </div>
          </div>

        </div>
      </div>
    </section>

  </main><!-- End #main -->

  <script>
    // Live display, updated every second, before and after TIME IN.
    // "Now" = server time at page load + time elapsed since load (the device clock is never read).
    (function () {
      var live = <?= json_encode($live) ?>;
      if (!live.mode) { return; }

      var loadedAt = performance.now();
      var clockFormat = new Intl.DateTimeFormat("en-US", { timeZone: "Asia/Manila", hour: "numeric", minute: "2-digit", second: "2-digit" });
      var el = function (id) { return document.getElementById(id); };

      function now() { return live.serverNow + (performance.now() - loadedAt); }
      function minutes(ms) { return Math.max(0, Math.floor(ms / 60000)); }
      function pad(n) { return (n < 10 ? "0" : "") + n; }
      function duration(m) {
        var h = Math.floor(m / 60), r = m % 60;
        return h ? h + " h" + (r ? " " + r + " min" : "") : r + " min";
      }
      function stopwatch(ms) {
        var s = Math.max(0, Math.floor(ms / 1000));
        return Math.floor(s / 3600) + ":" + pad(Math.floor(s % 3600 / 60)) + ":" + pad(s % 60);
      }
      // Same rounding as when the hours are saved at TIME OUT.
      function rounded(m) {
        if (live.rounding > 0) { m = Math.floor(m / live.rounding) * live.rounding; }
        return m < live.minimum ? 0 : m;
      }
      function hrs(m) {
        var h = +(m / 60).toFixed(2);
        return h + (h === 1 ? " hr" : " hrs");
      }
      function countsAs(m) { return hrs(rounded(m)); }
      function show(id, html, cls) {
        var node = el(id);
        if (!node) { return; }
        if (node.dataset.base === undefined) { node.dataset.base = node.className; }
        node.innerHTML = html;
        node.className = node.dataset.base + (cls ? " " + cls : "");
      }

      function tick() {
        var t = now();
        el("liveClock").textContent = clockFormat.format(t);

        if (live.mode === "before") {
          if (live.start === undefined) { return; }
          if (t < live.start) {
            var early = minutes(live.start - t);
            show("liveArrival", '<i class="bi bi-alarm"></i> Time in now = early time in of <strong>' + duration(early) + "</strong>", "text-success");
            show("livePhase", "Counts as " + countsAs(early) + " Early In if you stay until " + live.startLabel + ".");
          } else if (t < live.end) {
            var late = minutes(t - live.start);
            show("liveArrival", late > 0
              ? '<i class="bi bi-exclamation-circle"></i> Time in now = late by <strong>' + duration(late) + "</strong>"
              : '<i class="bi bi-check-circle"></i> On time', late > 0 ? "text-danger" : "text-success");
            show("livePhase", "Your schedule started at " + live.startLabel + ".");
          } else {
            show("liveArrival", "Your schedule (" + live.startLabel + " – " + live.endLabel + ") has ended.", "text-warning");
            show("livePhase", "");
          }
          return;
        }

        // On duty (after TIME IN)
        el("liveDuty").textContent = stopwatch(t - live.timeIn);
        if (live.start === undefined) { return; }

        var earlySoFar = minutes(Math.min(t, live.start) - live.timeIn);
        var lateBy = minutes(live.timeIn - live.start);
        if (earlySoFar > 0) {
          show("liveArrival", '<i class="bi bi-alarm"></i> Early time in: <strong>' + duration(earlySoFar) + "</strong>" +
            ' <span class="text-muted">(counts as ' + countsAs(earlySoFar) + ")</span>", "text-success");
        } else if (lateBy > 0) {
          show("liveArrival", '<i class="bi bi-exclamation-circle"></i> Late: <strong>' + duration(lateBy) + "</strong>", "text-danger");
        } else {
          show("liveArrival", '<i class="bi bi-check-circle"></i> On time', "text-success");
        }

        if (t < live.start) {
          show("livePhase", "Early time in is counting · schedule starts at " + live.startLabel + " (in " + duration(minutes(live.start - t)) + ")");
        } else if (t < live.end) {
          show("livePhase", "On schedule · ends at " + live.endLabel + " (in " + duration(minutes(live.end - t)) + ")");
        }
        var ot = t >= live.end ? minutes(t - Math.max(live.timeIn, live.end)) : 0;
        if (t >= live.end) {
          show("livePhase", "Overtime so far: <strong>" + duration(ot) + "</strong> (counts as " + countsAs(ot) + ")", "text-primary");
        }

        // Extra = Early In + OT, each rounded like the saved hours.
        show("liveExtra", "Extra so far (Early In + OT): <strong>" + hrs(rounded(earlySoFar) + rounded(ot)) + "</strong>",
          rounded(earlySoFar) + rounded(ot) > 0 ? "text-primary" : "text-muted");
      }

      tick();
      setInterval(tick, 1000);
    })();

    // The filing type can't be changed after TIME IN, so confirm Holiday filling.
    document.querySelectorAll('input[name="filing_type"]').forEach(function (radio) {
      radio.addEventListener("change", function () {
        if (radio.value === "holiday") {
          radio.form.dataset.confirm = "Time in as HOLIDAY filling? You can't change this after timing in.";
        } else {
          delete radio.form.dataset.confirm;
        }
      });
    });

    // Confirm where needed, and allow each form to be sent only once (blocks double clicks).
    document.querySelectorAll("form[data-clock]").forEach(function (form) {
      form.addEventListener("submit", function (event) {
        if (form.dataset.sent || (form.dataset.confirm && !confirm(form.dataset.confirm))) {
          event.preventDefault();
          return;
        }
        form.dataset.sent = "1";
        form.querySelector("button[type=submit]").disabled = true;
      });
    });
  </script>

<?php
require __DIR__ . '/includes/footer.php';
require __DIR__ . '/includes/scripts.php';
