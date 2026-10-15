<?php
/**
 * Admin: hour computation settings, and recompute stored hours for a date range.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/time_logs.php';
$user = require_role('admin');

$pageTitle  = 'Time Settings';
$activePage = 'time-settings';
$errors     = [];
$settings   = time_settings();

$isDate = static function ($value): bool {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $value);
    return $d !== false && $d->format('Y-m-d') === $value;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($action === 'save_settings') {
        $minutes = static fn(string $key) => filter_var($_POST[$key] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 240]]);
        $input = [
            'break_minutes'      => $minutes('break_minutes'),
            'ot_rounding_min'    => $minutes('ot_rounding_min'),
            'ot_minimum_min'     => $minutes('ot_minimum_min'),
        ];
        if (in_array(false, $input, true)) {
            $errors[] = 'Break, rounding and minimum must be whole minutes from 0 to 240.';
            $settings = array_map(fn($v) => $v === false ? '' : $v, $input) + $settings;
        } else {
            db()->prepare(
                'UPDATE time_settings SET break_minutes = ?, ot_rounding_min = ?, ot_minimum_min = ? WHERE id = 1'
            )->execute(array_values($input));
            $_SESSION['flash'] = ['success', 'Settings saved. They apply to the next TIME OUT; use Recompute below to update past days.'];
            redirect('time_settings.php');
        }
    } elseif ($action === 'recompute') {
        $from     = $_POST['from'] ?? '';
        $to       = $_POST['to'] ?? '';
        $targetId = (int) ($_POST['user_id'] ?? 0);
        if (!$isDate($from) || !$isDate($to)) {
            $errors[] = 'Please pick a valid date range.';
        } elseif ($from > $to) {
            $errors[] = 'The start date must be on or before the end date.';
        } else {
            $count = time_log_recompute($from, $to, $targetId ?: null, !empty($_POST['refresh_schedule']));
            $_SESSION['flash'] = ['success', "Recomputed $count completed day" . ($count === 1 ? '' : 's')
                . ' from ' . time_log_date($from) . ' to ' . time_log_date($to) . '.'];
            redirect('time_settings.php');
        }
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$people = db()->query(
    'SELECT user_id, role, field_engineer_id, first_name, last_name FROM users ORDER BY last_name, first_name'
)->fetchAll();

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
<?php if ($errors): ?>
      <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

      <div class="row">
        <div class="col-lg-6">
          <div class="card">
            <div class="card-body">
              <h5 class="card-title">Hour Computation</h5>
              <form method="post" class="row g-3">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_settings">

                <div class="col-sm-4">
                  <label for="breakMinutes" class="form-label">Break (minutes)</label>
                  <input type="number" class="form-control" id="breakMinutes" name="break_minutes" min="0" max="240" value="<?= e($settings['break_minutes']) ?>" required>
                </div>
                <div class="col-sm-4">
                  <label for="otRounding" class="form-label">Round early-in / OT to (min)</label>
                  <input type="number" class="form-control" id="otRounding" name="ot_rounding_min" min="0" max="240" value="<?= e($settings['ot_rounding_min']) ?>" required>
                </div>
                <div class="col-sm-4">
                  <label for="otMinimum" class="form-label">Minimum (min)</label>
                  <input type="number" class="form-control" id="otMinimum" name="ot_minimum_min" min="0" max="240" value="<?= e($settings['ot_minimum_min']) ?>" required>
                </div>
                <div class="col-12 small text-muted">
                  The schedule includes the break (e.g. 9:00 AM – 6:00 PM = 8 hours duty + 1 hour break). The break is deducted from total and regular hours only when more than 5 hours were worked.
                  Early-in and OT are kept separate; each is rounded down to the rounding step, and becomes 0 if below the minimum (0 = off).
                </div>
                <div class="col-12">
                  <button type="submit" class="btn btn-primary">Save Settings</button>
                </div>
              </form>
            </div>
          </div>
        </div>

        <div class="col-lg-6">
          <div class="card">
            <div class="card-body">
              <h5 class="card-title">Recompute Hours</h5>
              <p class="small text-muted">Hours are saved at TIME OUT and don't change on their own. Recompute completed days after fixing a setting or schedule.</p>
              <form method="post" class="row g-3" onsubmit="return confirm('Recompute hours for this date range?')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="recompute">

                <div class="col-sm-6">
                  <label for="recomputeFrom" class="form-label">From</label>
                  <input type="date" class="form-control" id="recomputeFrom" name="from" value="<?= e($_POST['from'] ?? date('Y-m-01')) ?>" required>
                </div>
                <div class="col-sm-6">
                  <label for="recomputeTo" class="form-label">To</label>
                  <input type="date" class="form-control" id="recomputeTo" name="to" value="<?= e($_POST['to'] ?? date('Y-m-d')) ?>" required>
                </div>
                <div class="col-12">
                  <label for="recomputeUser" class="form-label">User</label>
                  <select class="form-select" id="recomputeUser" name="user_id">
                    <option value="0">Everyone</option>
<?php foreach ($people as $person): ?>
                    <option value="<?= (int) $person['user_id'] ?>" <?= (int) ($_POST['user_id'] ?? 0) === (int) $person['user_id'] ? 'selected' : '' ?>>
                      <?= e($person['last_name'] . ', ' . $person['first_name']) ?> — <?= e($person['field_engineer_id'] ?? role_label($person['role'])) ?>
                    </option>
<?php endforeach; ?>
                  </select>
                </div>
                <div class="col-12">
                  <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="refresh_schedule" id="refreshSchedule" value="1">
                    <label class="form-check-label" for="refreshSchedule">
                      Also replace each day's saved schedule with the user's <strong>current</strong> schedule
                    </label>
                  </div>
                  <div class="form-text">Leave this off unless the schedule saved on those days was wrong.</div>
                </div>
                <div class="col-12">
                  <button type="submit" class="btn btn-warning"><i class="bi bi-arrow-repeat"></i> Recompute</button>
                </div>
              </form>
            </div>
          </div>
        </div>
      </div>
    </section>

  </main><!-- End #main -->

<?php
require __DIR__ . '/includes/footer.php';
require __DIR__ . '/includes/scripts.php';
