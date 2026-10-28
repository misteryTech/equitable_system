<?php
/**
 * "Forgot password?": send a password reset request to the admins. Nothing is emailed;
 * an admin sets a new password and gives it to the user, who must change it at the next login.
 * The page shows the same message whether or not the account exists.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/password_reset.php';
require_guest();

$pageTitle = 'Forgot Password';
$errors    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim((string) ($_POST['username'] ?? ''));
    $note  = trim(preg_replace('/\s+/', ' ', (string) ($_POST['note'] ?? '')));

    // At most 5 requests per browser session per hour.
    $recent = array_filter((array) ($_SESSION['reset_requests'] ?? []), fn($t) => $t > time() - 3600);

    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($login === '' || mb_strlen($login) > 100) {
        $errors[] = 'Please enter your username or email address.';
    } elseif (mb_strlen($note) > 255) {
        $errors[] = 'The message can be at most 255 characters.';
    } elseif (count($recent) >= 5) {
        $errors[] = 'Too many requests. Please wait a while, or contact an admin directly.';
    } else {
        password_reset_request($login, $note, $_SERVER['REMOTE_ADDR'] ?? null);
        $recent[] = time();
        $_SESSION['reset_requests'] = array_values($recent);
        redirect('forgot_password.php?sent=1');
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
                    <h5 class="card-title text-center pb-0 fs-4">Forgot Password</h5>
                    <p class="text-center small">Send a reset request to the admin. The admin sets a new password and gives it to you.</p>
                  </div>

<?php if (isset($_GET['sent'])): ?>
                  <div class="alert alert-success">
                    Your request was sent. If the account exists, an admin will set a new password and give it to you
                    (in person, by phone or chat). You will be asked to choose your own password when you log in with it.
                  </div>
                  <p class="small text-center mb-3"><a href="login.php">Back to login</a></p>
<?php else: ?>
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
                      <label for="resetLogin" class="form-label">Username or Email</label>
                      <div class="input-group">
                        <span class="input-group-text">@</span>
                        <input type="text" name="username" class="form-control" id="resetLogin" maxlength="100" value="<?= old('username') ?>" required autofocus>
                      </div>
                    </div>
                    <div class="col-12">
                      <label for="resetNote" class="form-label">Message to the admin <span class="text-muted small">(optional)</span></label>
                      <textarea name="note" id="resetNote" class="form-control" rows="2" maxlength="255" placeholder="e.g. Please text me at 0917…"><?= old('note') ?></textarea>
                    </div>
                    <div class="col-12">
                      <button class="btn btn-primary w-100" type="submit">Send Request</button>
                    </div>
                    <div class="col-12">
                      <p class="small mb-0 text-center"><a href="login.php">Back to login</a></p>
                    </div>
                  </form>
<?php endif; ?>

                </div>
              </div>

              <div class="credits text-center">
                Made by <strong><?= e(APP_AUTHOR) ?></strong>
                <div class="small text-muted">Version <?= e(app_version_label()) ?></div>
              </div>

            </div>
          </div>
        </div>

      </section>

    </div>
  </main><!-- End #main -->

<?php require __DIR__ . '/includes/scripts.php'; ?>
