<?php
/**
 * Choose your own password after an admin reset it (users.must_change_password = 1).
 * require_login() sends the user here until it is done; other password changes
 * use Edit Profile.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/password_reset.php';
$user = require_login();
if (empty($user['must_change_password'])) {
    redirect('index.php');
}

$pageTitle = 'Choose a New Password';
$errors    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new     = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');
    $stmt    = db()->prepare('SELECT password_hash FROM users WHERE user_id = ?');
    $stmt->execute([$user['user_id']]);
    $currentHash = (string) $stmt->fetchColumn();

    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($error = password_policy_error($new)) {
        $errors[] = $error;
    } elseif ($new !== $confirm) {
        $errors[] = 'The two passwords do not match.';
    } elseif (password_verify($new, $currentHash)) {
        $errors[] = 'Choose a password different from the one the admin gave you.';
    } else {
        db()->prepare('UPDATE users SET password_hash = ?, must_change_password = 0 WHERE user_id = ?')
            ->execute([password_hash($new, PASSWORD_DEFAULT), $user['user_id']]);
        session_regenerate_id(true);
        redirect('index.php?password_changed=1');
    }
}

require __DIR__ . '/includes/head.php';
?>

  <main>
    <div class="container">

      <section class="section register min-vh-100 d-flex flex-column align-items-center justify-content-center py-4">
        <div class="container">
          <div class="row justify-content-center">
            <div class="col-lg-4 col-md-6 d-flex flex-column align-items-center justify-content-center">

              <div class="d-flex justify-content-center py-4">
                <a href="index.php" class="d-flex flex-column align-items-center text-decoration-none">
                  <img src="<?= asset('mistery.jpg') ?>" alt="<?= e(APP_NAME) ?> logo" class="rounded-circle border shadow-sm" style="width: 96px; height: 96px; object-fit: cover">
                  <span class="mt-2 fw-bold" style="font-family: Nunito, sans-serif; font-size: 1.6rem; color: #012970"><?= e(APP_NAME) ?></span>
                </a>
              </div><!-- End Logo -->

              <div class="card mb-3 w-100">
                <div class="card-body">

                  <div class="pt-4 pb-2">
                    <h5 class="card-title text-center pb-0 fs-4">Choose a New Password</h5>
                    <p class="text-center small">Hi <?= e($user['first_name']) ?>, an admin reset your password. Choose your own to continue.</p>
                  </div>

<?php if ($errors): ?>
                  <div class="alert alert-danger">
                    <ul class="mb-0">
<?php foreach ($errors as $error): ?>
                      <li><?= e($error) ?></li>
<?php endforeach; ?>
                    </ul>
                  </div>
<?php endif; ?>

                  <form method="post" class="row g-3" novalidate>
                    <?= csrf_field() ?>
                    <div class="col-12">
                      <label for="newPassword" class="form-label">New password</label>
                      <input type="password" name="new_password" id="newPassword" class="form-control" minlength="<?= PASSWORD_MIN_LENGTH ?>" maxlength="<?= PASSWORD_MAX_LENGTH ?>" autocomplete="new-password" required autofocus>
                      <div class="form-text">At least <?= PASSWORD_MIN_LENGTH ?> characters, with a letter and a number.</div>
                    </div>
                    <div class="col-12">
                      <label for="confirmPassword" class="form-label">Confirm new password</label>
                      <input type="password" name="confirm_password" id="confirmPassword" class="form-control" maxlength="<?= PASSWORD_MAX_LENGTH ?>" autocomplete="new-password" required>
                    </div>
                    <div class="col-12">
                      <button class="btn btn-primary w-100" type="submit">Save Password</button>
                    </div>
                    <div class="col-12">
                      <p class="small mb-0 text-center"><a href="logout.php">Sign out</a></p>
                    </div>
                  </form>

                </div>
              </div>

            </div>
          </div>
        </div>

      </section>

    </div>
  </main><!-- End #main -->

<?php require __DIR__ . '/includes/scripts.php'; ?>
