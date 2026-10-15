<?php
/**
 * Admin: approve or reject engineer self-registrations.
 * Pending and rejected accounts can't log in. Rejected registrations can be
 * approved later, or deleted to free their username and email.
 */
require_once __DIR__ . '/includes/config.php';
$admin = require_role('admin');

$pageTitle  = 'Registrations';
$activePage = 'registrations';
$errors     = [];

$tabs   = ['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'];
$status = array_key_exists($_GET['status'] ?? '', $tabs) ? $_GET['status'] : 'pending';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $userId = (int) ($_POST['user_id'] ?? 0);
    $note   = trim((string) ($_POST['note'] ?? ''));

    $stmt = db()->prepare('SELECT user_id, status, first_name, last_name, profile_picture FROM users WHERE user_id = ?');
    $stmt->execute([$userId]);
    $target = $stmt->fetch();
    $name   = $target ? $target['first_name'] . ' ' . $target['last_name'] : '';

    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!$target) {
        $errors[] = 'Registration not found.';
    } elseif ((int) $target['user_id'] === (int) $admin['user_id']) {
        $errors[] = "You can't change your own account here.";
    } elseif ($action === 'approve') {
        $update = db()->prepare(
            "UPDATE users SET status = 'approved', reviewed_by = ?, reviewed_at = NOW(), review_note = NULL
             WHERE user_id = ? AND status IN ('pending', 'rejected')"
        );
        $update->execute([$admin['user_id'], $userId]);
        notifications_resolve('registration', $userId);   // handled: clear it from every admin's bell
        $_SESSION['flash'] = $update->rowCount()
            ? ['success', "$name was approved and can now log in."]
            : ['warning', "$name was already approved."];
        redirect('registrations.php?status=' . $status);
    } elseif ($action === 'reject') {
        if ($note === '' || mb_strlen($note) > 255) {
            $errors[] = 'Please give a reason for rejecting (up to 255 characters). The engineer sees it when trying to log in.';
        } else {
            $update = db()->prepare(
                "UPDATE users SET status = 'rejected', reviewed_by = ?, reviewed_at = NOW(), review_note = ?
                 WHERE user_id = ? AND status = 'pending'"
            );
            $update->execute([$admin['user_id'], $note, $userId]);
            notifications_resolve('registration', $userId);
            $_SESSION['flash'] = $update->rowCount()
                ? ['success', "$name's registration was rejected."]
                : ['warning', "$name's registration is no longer pending."];
            redirect('registrations.php?status=' . $status);
        }
    } elseif ($action === 'delete') {
        // Only rejected registrations (they never logged in, so they have no time records).
        $logs = db()->prepare('SELECT COUNT(*) FROM time_logs WHERE user_id = ?');
        $logs->execute([$userId]);
        if ($target['status'] !== 'rejected') {
            $errors[] = 'Only rejected registrations can be deleted.';
        } elseif ($logs->fetchColumn() > 0) {
            $errors[] = "$name has time records and can't be deleted.";
        } else {
            db()->prepare("DELETE FROM users WHERE user_id = ? AND status = 'rejected'")->execute([$userId]);
            profile_picture_delete($target['profile_picture']);
            $_SESSION['flash'] = ['success', "$name's registration was deleted. The username and email can be registered again."];
            redirect('registrations.php?status=rejected');
        }
    } else {
        $errors[] = 'Unknown action.';
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$counts = db()->query('SELECT status, COUNT(*) FROM users GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);

$stmt = db()->prepare(
    "SELECT u.user_id, u.role, u.field_engineer_id, u.location, u.profile_picture, u.first_name, u.middle_name, u.last_name,
            u.username, u.email, u.created_at, u.reviewed_at, u.review_note,
            CONCAT(r.first_name, ' ', r.last_name) AS reviewer
     FROM users u
     LEFT JOIN users r ON r.user_id = u.reviewed_by
     WHERE u.status = ?
     ORDER BY " . ($status === 'pending' ? 'u.created_at ASC' : 'COALESCE(u.reviewed_at, u.created_at) DESC')
);
$stmt->execute([$status]);
$people = $stmt->fetchAll();

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

      <div class="card">
        <div class="card-body pt-3">

          <ul class="nav nav-tabs mb-3">
<?php foreach ($tabs as $key => $label): ?>
            <li class="nav-item">
              <a class="nav-link <?= $status === $key ? 'active' : '' ?>" href="registrations.php?status=<?= e($key) ?>">
                <?= e($label) ?>
                <span class="badge <?= $key === 'pending' && ($counts['pending'] ?? 0) > 0 ? 'bg-danger' : 'bg-light text-dark' ?>"><?= (int) ($counts[$key] ?? 0) ?></span>
              </a>
            </li>
<?php endforeach; ?>
          </ul>

<?php if ($status === 'pending'): ?>
          <p class="small text-muted">Engineers who registered themselves. They can't log in until approved.</p>
<?php endif; ?>

          <div class="table-responsive">
            <table class="table align-middle">
              <thead>
                <tr>
                  <th>Engineer</th>
                  <th>Contact</th>
                  <th>Registered</th>
<?php if ($status !== 'pending'): ?>
                  <th><?= $status === 'approved' ? 'Approved' : 'Rejected' ?></th>
<?php endif; ?>
                  <th class="text-end">Action</th>
                </tr>
              </thead>
              <tbody>
<?php foreach ($people as $p):
    $isSelf = (int) $p['user_id'] === (int) $admin['user_id'];
?>
                <tr>
                  <td>
                    <div class="d-flex align-items-center gap-2">
                      <img src="<?= e(profile_picture_url($p)) ?>" alt="" class="rounded-circle border" style="width: 44px; height: 44px; object-fit: cover">
                      <div>
                        <strong><?= e(full_name($p)) ?></strong>
                        <span class="badge <?= $p['role'] === 'admin' ? 'bg-danger' : 'bg-primary' ?> ms-1"><?= e(role_label($p['role'])) ?></span><br>
                        <span class="small text-muted"><?= e(implode(' · ', array_filter([$p['field_engineer_id'], $p['location']]))) ?></span>
                      </div>
                    </div>
                  </td>
                  <td class="small">@<?= e($p['username']) ?><br><span class="text-muted text-break"><?= e($p['email']) ?></span></td>
                  <td class="small text-nowrap"><?= e(date('M j, Y g:i A', strtotime($p['created_at']))) ?></td>
<?php if ($status !== 'pending'): ?>
                  <td class="small">
                    <?= $p['reviewed_at'] ? e(date('M j, Y', strtotime($p['reviewed_at']))) . ($p['reviewer'] ? ' by ' . e($p['reviewer']) : '') : '<span class="text-muted">—</span>' ?>
<?php if ($p['review_note']): ?>
                    <div class="text-danger">Reason: <?= e($p['review_note']) ?></div>
<?php endif; ?>
                  </td>
<?php endif; ?>
                  <td class="text-end text-nowrap">
<?php if ($isSelf): ?>
                    <span class="small text-muted">You</span>
<?php else: ?>
<?php if ($status === 'pending' || $status === 'rejected'): ?>
                    <form method="post" class="d-inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="approve">
                      <input type="hidden" name="user_id" value="<?= (int) $p['user_id'] ?>">
                      <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-check-lg"></i> Approve</button>
                    </form>
<?php endif; ?>
<?php if ($status === 'pending'): ?>
                    <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="collapse" data-bs-target="#reject<?= (int) $p['user_id'] ?>">
                      <i class="bi bi-x-lg"></i> Reject
                    </button>
<?php endif; ?>
<?php if ($status === 'rejected'): ?>
                    <form method="post" class="d-inline" onsubmit="return confirm('Delete this rejected registration? This cannot be undone.')">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="user_id" value="<?= (int) $p['user_id'] ?>">
                      <button type="submit" class="btn btn-sm btn-outline-secondary" title="Delete"><i class="bi bi-trash"></i></button>
                    </form>
<?php endif; ?>
<?php endif; ?>
                  </td>
                </tr>
<?php if ($status === 'pending' && !$isSelf): ?>
                <tr class="collapse" id="reject<?= (int) $p['user_id'] ?>">
                  <td colspan="5" class="bg-light">
                    <form method="post" class="d-flex flex-wrap gap-2 align-items-center">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="reject">
                      <input type="hidden" name="user_id" value="<?= (int) $p['user_id'] ?>">
                      <input type="text" name="note" class="form-control form-control-sm" style="max-width: 28rem" maxlength="255" required
                             placeholder="Reason (shown to the engineer at login)" aria-label="Reason for rejecting <?= e(full_name($p)) ?>">
                      <button type="submit" class="btn btn-sm btn-danger">Reject registration</button>
                    </form>
                  </td>
                </tr>
<?php endif; ?>
<?php endforeach; ?>
<?php if (!$people): ?>
                <tr><td colspan="5" class="text-muted text-center py-4"><?= $status === 'pending' ? 'No registrations waiting for approval.' : 'Nothing here.' ?></td></tr>
<?php endif; ?>
              </tbody>
            </table>
          </div>

        </div>
      </div>
    </section>

  </main><!-- End #main -->

<?php
require __DIR__ . '/includes/footer.php';
require __DIR__ . '/includes/scripts.php';
