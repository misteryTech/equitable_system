<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/transpo.php';
$user    = require_login();
$isAdmin = is_admin($user);

$pageTitle  = 'Dashboard';
$activePage = 'dashboard';

// Weekly transpo, Monday to Sunday (?week=any date in the week): admins see every engineer, engineers their own.
$weekStart  = transpo_week_start(isset($_GET['week']) ? (string) $_GET['week'] : null);
$weekDays   = transpo_week_days($weekStart);
$weekRows   = transpo_week_totals($weekStart, $isAdmin ? null : (int) $user['user_id']);
$weekTotal  = array_sum(array_column($weekRows, 'total'));
$weekMine   = $weekRows[(int) $user['user_id']] ?? ['days' => array_fill_keys($weekDays, 0.0), 'tickets' => 0, 'total' => 0.0];
$thisWeek   = transpo_week_start();
$weekPrev   = date('Y-m-d', strtotime($weekStart . ' -7 days'));
$weekNext   = date('Y-m-d', strtotime($weekStart . ' +7 days'));
$weekLabel  = (substr($weekDays[0], 0, 4) === substr($weekDays[6], 0, 4) ? date('M j', strtotime($weekDays[0])) : date('M j, Y', strtotime($weekDays[0])))
            . ' – ' . date('M j, Y', strtotime($weekDays[6]));
$money      = static fn(float $amount): string => $amount > 0 ? transpo_money($amount) : '—';

$stmt = db()->prepare("SELECT COUNT(*) FROM users WHERE role = 'field_engineer' AND location = ?");
$stmt->execute([$user['location']]);
$sameLocation = (int) $stmt->fetchColumn();

if ($isAdmin) {
    $counts = db()->query('SELECT role, COUNT(*) AS total FROM users GROUP BY role')->fetchAll(PDO::FETCH_KEY_PAIR);

    $stats = [
        ['label' => 'Field Engineers', 'value' => $counts['field_engineer'] ?? 0, 'icon' => 'bi-people',       'class' => 'customers-card'],
        ['label' => 'Admins',          'value' => $counts['admin'] ?? 0,          'icon' => 'bi-shield-lock',  'class' => 'sales-card'],
        ['label' => 'Locations',       'value' => (int) db()->query('SELECT COUNT(*) FROM locations')->fetchColumn(), 'icon' => 'bi-building', 'class' => 'revenue-card'],
    ];

    $listTitle = 'All Users';
    $listUsers = db()->query(
        'SELECT role, field_engineer_id, first_name, last_name, username, location, created_at
         FROM users ORDER BY created_at DESC, user_id DESC'
    )->fetchAll();
} else {
    $stats = [
        ['label' => 'Engineers at ' . $user['location'], 'value' => $sameLocation, 'icon' => 'bi-geo-alt', 'class' => 'sales-card'],
    ];

    $listTitle = 'Field Engineers at ' . $user['location'];
    $stmt = db()->prepare(
        "SELECT role, field_engineer_id, first_name, last_name, username, location, created_at
         FROM users WHERE role = 'field_engineer' AND location = ?
         ORDER BY last_name, first_name"
    );
    $stmt->execute([$user['location']]);
    $listUsers = $stmt->fetchAll();
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

<?php if ($isAdmin && ($pending = pending_registration_count()) > 0): ?>
    <div class="alert alert-warning d-flex align-items-center justify-content-between">
      <span><i class="bi bi-person-exclamation"></i> <?= $pending ?> engineer registration<?= $pending === 1 ? ' is' : 's are' ?> waiting for approval.</span>
      <a href="registrations.php" class="btn btn-sm btn-warning">Review</a>
    </div>
<?php endif; ?>
<?php if (isset($_GET['denied'])): ?>
    <div class="alert alert-warning">You don't have permission to open that page.</div>
<?php endif; ?>
<?php if (isset($_GET['password_changed'])): ?>
    <div class="alert alert-success">Your new password is saved. Use it from now on.</div>
<?php endif; ?>

    <section class="section dashboard">
      <div class="row">

        <!-- Left side columns -->
        <div class="col-lg-8">
          <div class="row">

<?php foreach ($stats as $stat): ?>
            <div class="col-xxl-4 col-md-4">
              <div class="card info-card <?= e($stat['class']) ?>">
                <div class="card-body">
                  <h5 class="card-title"><?= e($stat['label']) ?></h5>
                  <div class="d-flex align-items-center">
                    <div class="card-icon rounded-circle d-flex align-items-center justify-content-center">
                      <i class="bi <?= e($stat['icon']) ?>"></i>
                    </div>
                    <div class="ps-3">
                      <h6><?= e($stat['value']) ?></h6>
                    </div>
                  </div>
                </div>
              </div>
            </div>
<?php endforeach; ?>

            <!-- Weekly transpo (Monday to Sunday) -->
            <div class="col-12">
              <div class="card">
                <div class="card-body">
                  <h5 class="card-title d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <span>Weekly Transpo <span>| <?= e($weekLabel) ?></span></span>
                    <span class="btn-group btn-group-sm" role="group" aria-label="Choose week">
                      <a href="index.php?week=<?= e($weekPrev) ?>" class="btn btn-outline-secondary" title="Previous week" aria-label="Previous week"><i class="bi bi-chevron-left"></i></a>
                      <a href="index.php" class="btn btn-outline-secondary<?= $weekStart === $thisWeek ? ' active' : '' ?>">This week</a>
                      <a href="index.php?week=<?= e($weekNext) ?>" class="btn btn-outline-secondary<?= $weekStart >= $thisWeek ? ' disabled' : '' ?>" title="Next week" aria-label="Next week"><i class="bi bi-chevron-right"></i></a>
                    </span>
                  </h5>

<?php if ($isAdmin): ?>
                  <p class="mb-2">
                    Total for all engineers: <strong class="fs-5"><?= e(transpo_money($weekTotal)) ?></strong>
                    <span class="text-muted small">· <?= count($weekRows) ?> engineer<?= count($weekRows) === 1 ? '' : 's' ?> filed transpo</span>
                  </p>
                  <div class="table-responsive">
                    <table class="table table-sm table-bordered align-middle text-nowrap mb-2">
                      <thead class="table-light">
                        <tr>
                          <th scope="col">Engineer</th>
<?php foreach ($weekDays as $day): ?>
                          <th scope="col" class="text-end<?= $day === date('Y-m-d') ? ' table-primary' : '' ?>"><?= e(date('D', strtotime($day))) ?><br><span class="small fw-normal text-muted"><?= e(date('M j', strtotime($day))) ?></span></th>
<?php endforeach; ?>
                          <th scope="col" class="text-end">Total</th>
                        </tr>
                      </thead>
                      <tbody>
<?php foreach ($weekRows as $row): ?>
                        <tr>
                          <td><?= e($row['engineer']) ?><?= $row['field_engineer_id'] ? ' <span class="small text-muted">' . e($row['field_engineer_id']) . '</span>' : '' ?></td>
<?php foreach ($weekDays as $day): ?>
                          <td class="text-end"><?= e($money($row['days'][$day])) ?></td>
<?php endforeach; ?>
                          <td class="text-end fw-semibold"><?= e(transpo_money($row['total'])) ?></td>
                        </tr>
<?php endforeach; ?>
<?php if (!$weekRows): ?>
                        <tr><td colspan="9" class="text-muted text-center py-3">No transpo filed this week.</td></tr>
<?php else: ?>
                        <tr class="table-light fw-semibold">
                          <td>Total</td>
<?php foreach ($weekDays as $day): ?>
                          <td class="text-end"><?= e($money(array_sum(array_map(fn($r) => $r['days'][$day], $weekRows)))) ?></td>
<?php endforeach; ?>
                          <td class="text-end"><?= e(transpo_money($weekTotal)) ?></td>
                        </tr>
<?php endif; ?>
                      </tbody>
                    </table>
                  </div>
                  <a href="transpo_reports.php?view=daily&amp;from=<?= e($weekDays[0]) ?>&amp;to=<?= e($weekDays[6]) ?>" class="small">Open this week in Transpo Reports <i class="bi bi-arrow-right"></i></a>
<?php else: ?>
                  <p class="mb-2">
                    My total: <strong class="fs-5"><?= e(transpo_money($weekMine['total'])) ?></strong>
                    <span class="text-muted small">· <?= (int) $weekMine['tickets'] ?> ticket<?= $weekMine['tickets'] === 1 ? '' : 's' ?></span>
                  </p>
                  <style>
                    .week-days { display: grid; grid-template-columns: repeat(4, 1fr); gap: .5rem; }   /* 7 across from tablet width */
                    @media (min-width: 576px) { .week-days { grid-template-columns: repeat(7, 1fr); } }
                  </style>
                  <div class="week-days mb-2">
<?php foreach ($weekDays as $day): ?>
                    <div>
                      <div class="border rounded text-center p-2 h-100<?= $day === date('Y-m-d') ? ' border-primary bg-primary bg-opacity-10' : '' ?>">
                        <div class="small fw-semibold"><?= e(date('D', strtotime($day))) ?></div>
                        <div class="small text-muted"><?= e(date('M j', strtotime($day))) ?></div>
                        <div class="small mt-1"><?= e($money($weekMine['days'][$day])) ?></div>
                      </div>
                    </div>
<?php endforeach; ?>
                  </div>
                  <a href="transpo_filing.php" class="small">Go to Transpo Filing <i class="bi bi-arrow-right"></i></a>
<?php endif; ?>
                </div>
              </div>
            </div>

            <div class="col-12">
              <div class="card recent-sales overflow-auto">
                <div class="card-body">
                  <h5 class="card-title d-flex justify-content-between align-items-center">
                    <span><?= e($listTitle) ?> <span>| <?= count($listUsers) ?></span></span>
<?php if ($isAdmin): ?>
                    <a href="registration.php" class="btn btn-sm btn-primary"><i class="bi bi-person-plus"></i> Register User</a>
<?php endif; ?>
                  </h5>

                  <table class="table table-borderless">
                    <thead>
                      <tr>
<?php if ($isAdmin): ?>
                        <th scope="col">Role</th>
<?php endif; ?>
                        <th scope="col">Field Engineer ID</th>
                        <th scope="col">Name</th>
                        <th scope="col">Username</th>
                        <th scope="col">Location</th>
                        <th scope="col">Registered</th>
                      </tr>
                    </thead>
                    <tbody>
<?php foreach ($listUsers as $row): ?>
                      <tr>
<?php if ($isAdmin): ?>
                        <td><span class="badge <?= $row['role'] === 'admin' ? 'bg-danger' : 'bg-primary' ?>"><?= e(role_label($row['role'])) ?></span></td>
<?php endif; ?>
                        <th scope="row"><?= e($row['field_engineer_id'] ?? '—') ?></th>
                        <td><?= e($row['first_name'] . ' ' . $row['last_name']) ?></td>
                        <td>@<?= e($row['username']) ?></td>
                        <td><?= e($row['location']) ?></td>
                        <td><?= e(date('M j, Y', strtotime($row['created_at']))) ?></td>
                      </tr>
<?php endforeach; ?>
<?php if (!$listUsers): ?>
                      <tr><td colspan="6" class="text-muted">No users yet.</td></tr>
<?php endif; ?>
                    </tbody>
                  </table>

                </div>
              </div>
            </div>

          </div>
        </div><!-- End Left side columns -->

        <!-- Right side columns -->
        <div class="col-lg-4">
          <div class="card">
            <div class="card-body">
              <h5 class="card-title d-flex justify-content-between align-items-center">
                <span>My Profile</span>
                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#profileModal"><i class="bi bi-pencil"></i> Edit Profile</button>
              </h5>
              <div class="text-center mb-3">
                <img src="<?= e(profile_picture_url($user)) ?>" alt="Profile picture of <?= e(full_name($user)) ?>" class="rounded-circle border" style="width: 120px; height: 120px; object-fit: cover">
              </div>
              <dl class="row mb-0">
                <dt class="col-5">Name</dt>
                <dd class="col-7"><?= e(full_name($user)) ?></dd>

                <dt class="col-5">Role</dt>
                <dd class="col-7"><?= e(role_label($user['role'])) ?></dd>

<?php if ($user['field_engineer_id'] !== null): ?>
                <dt class="col-5">Engineer ID</dt>
                <dd class="col-7"><?= e($user['field_engineer_id']) ?></dd>

<?php endif; ?>
                <dt class="col-5">Location</dt>
                <dd class="col-7"><?= e($user['location']) ?></dd>

                <dt class="col-5">Username</dt>
                <dd class="col-7">@<?= e($user['username']) ?></dd>

                <dt class="col-5">Email</dt>
                <dd class="col-7 text-break"><?= e($user['email']) ?></dd>

                <dt class="col-5">Member since</dt>
                <dd class="col-7"><?= e(date('M j, Y', strtotime($user['created_at']))) ?></dd>
              </dl>
            </div>
          </div>
        </div><!-- End Right side columns -->

      </div>
    </section>

  </main><!-- End #main -->

<?php
require __DIR__ . '/includes/footer.php';
require __DIR__ . '/includes/scripts.php';
