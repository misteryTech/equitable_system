<?php
require_once __DIR__ . '/includes/config.php';
require_guest();

$pageTitle = 'Login';
$errors    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['username'] ?? '');

    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif ($login === '' || ($_POST['password'] ?? '') === '') {
        $errors[] = 'Please enter your username and password.';
    } else {
        // Accept either the username or the email address.
        $stmt = db()->prepare('SELECT user_id, password_hash, status, review_note FROM users WHERE username = ? OR email = ? LIMIT 1');
        $stmt->execute([$login, $login]);
        $user = $stmt->fetch();

        if ($user && password_verify($_POST['password'], $user['password_hash']) && $user['status'] === 'pending') {
            $errors[] = 'Your registration is waiting for admin approval. Please try again once it has been approved.';
        } elseif ($user && password_verify($_POST['password'], $user['password_hash']) && $user['status'] === 'rejected') {
            $errors[] = 'Your registration was not approved' . ($user['review_note'] ? ': ' . rtrim($user['review_note'], '.') : '') . '. Please contact an admin.';
        } elseif ($user && password_verify($_POST['password'], $user['password_hash'])) {
            if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                db()->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?')
                    ->execute([password_hash($_POST['password'], PASSWORD_DEFAULT), $user['user_id']]);
            }
            login_user($user);
            redirect('index.php');
        } else {
            $errors[] = 'Invalid username or password.';
        }
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
                <a href="index.php" class="logo d-flex align-items-center w-auto">
                  <img src="assets/img/logo.png" alt="">
                  <span class="d-none d-lg-block"><?= e(APP_NAME) ?></span>
                </a>
              </div><!-- End Logo -->

              <div class="card mb-3 w-100">
                <div class="card-body">

                  <div class="pt-4 pb-2">
                    <h5 class="card-title text-center pb-0 fs-4">Login to Your Account</h5>
                    <p class="text-center small">Enter your username &amp; password to login</p>
                  </div>

<?php if (isset($_GET['registered'])): ?>
                  <div class="alert alert-success">Account created. An admin needs to approve your registration before you can log in.</div>
<?php endif; ?>
<?php if (isset($_GET['logged_out'])): ?>
                  <div class="alert alert-info">You have been signed out.</div>
<?php endif; ?>
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
                      <label for="yourUsername" class="form-label">Username or Email</label>
                      <div class="input-group">
                        <span class="input-group-text">@</span>
                        <input type="text" name="username" class="form-control" id="yourUsername" value="<?= old('username') ?>" required>
                      </div>
                    </div>

                    <div class="col-12">
                      <label for="yourPassword" class="form-label">Password</label>
                      <input type="password" name="password" class="form-control" id="yourPassword" required>
                    </div>

                    <div class="col-12">
                      <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="remember" value="1" id="rememberMe">
                        <label class="form-check-label" for="rememberMe">Remember me</label>
                      </div>
                    </div>

                    <div class="col-12">
                      <button class="btn btn-primary w-100" type="submit">Login</button>
                    </div>

                    <div class="col-12">
                      <p class="small mb-0 text-center">
                        Don't have an account? <a href="registration.php">Create an account</a>
                      </p>
                    </div>
                  </form>

                </div>
              </div>

              <div class="credits">
                <!-- All the links in the footer should remain intact. -->
                <!-- You can delete the links only if you purchased the pro version. -->
                <!-- Licensing information: https://bootstrapmade.com/license/ -->
                Made by <strong><?= e(APP_AUTHOR) ?></strong> &middot; Template by <a href="https://bootstrapmade.com/">BootstrapMade</a>
              </div>

            </div>
          </div>
        </div>

      </section>

    </div>
  </main><!-- End #main -->

<?php require __DIR__ . '/includes/scripts.php'; ?>
