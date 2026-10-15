<?php
/**
 * All of the user's notifications, with "Mark all as read".
 */
require_once __DIR__ . '/includes/config.php';
$user = require_login();

$pageTitle  = 'Notifications';
$activePage = 'notifications';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify() && ($_POST['action'] ?? '') === 'read_all') {
    db()->prepare('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL')->execute([$user['user_id']]);
    redirect('notifications.php');
}

$items  = notifications_latest((int) $user['user_id'], 100);
$unread = notifications_unread_count((int) $user['user_id']);

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
        <div class="col-lg-8">
          <div class="card">
            <div class="card-body pt-3">
              <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="text-muted"><?= $unread ? "$unread unread" : 'All caught up' ?></span>
<?php if ($unread): ?>
                <form method="post">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="read_all">
                  <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-check2-all"></i> Mark all as read</button>
                </form>
<?php endif; ?>
              </div>

              <div class="list-group list-group-flush">
<?php foreach ($items as $n): ?>
                <a href="notification_open.php?id=<?= (int) $n['id'] ?>" class="list-group-item list-group-item-action d-flex gap-3 py-3 <?= $n['read_at'] ? '' : 'bg-light' ?>">
                  <i class="bi <?= e(notification_icon($n['type'])) ?> fs-4"></i>
                  <div class="flex-grow-1">
                    <div class="<?= $n['read_at'] ? '' : 'fw-semibold' ?>"><?= e($n['message']) ?></div>
                    <div class="small text-muted"><?= e(notification_time_ago($n['created_at'])) ?> · <?= e(date('M j, Y g:i A', strtotime($n['created_at']))) ?></div>
                  </div>
<?php if (!$n['read_at']): ?>
                  <span class="badge bg-danger align-self-center">New</span>
<?php endif; ?>
                </a>
<?php endforeach; ?>
<?php if (!$items): ?>
                <div class="text-muted text-center py-4">No notifications yet.</div>
<?php endif; ?>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

  </main><!-- End #main -->

<?php
require __DIR__ . '/includes/footer.php';
require __DIR__ . '/includes/scripts.php';
