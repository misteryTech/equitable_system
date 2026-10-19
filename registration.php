<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/profile_picture.php';

// Guests may self-register as field engineers; logged-in admins may create
// accounts with any role. Logged-in field engineers are sent to the dashboard.
$viewer    = current_user();
$adminMode = is_admin($viewer);
if ($viewer !== null && !$adminMode) {
    redirect('index.php');
}

$pageTitle  = $adminMode ? 'Register User' : 'Register';
$activePage = 'registration';
$errors     = [];
$locations  = db()->query('SELECT name FROM locations ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please try again.';
    }

    $role       = $adminMode ? ($_POST['role'] ?? '') : 'field_engineer';
    $engineerId = trim($_POST['field_engineer_id'] ?? '');
    $location   = trim($_POST['location'] ?? '');
    $firstName  = trim($_POST['first_name'] ?? '');
    $middleName = trim($_POST['middle_name'] ?? '');
    $lastName   = trim($_POST['last_name'] ?? '');
    $username   = trim($_POST['username'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $mobile     = trim(preg_replace('/\s+/', ' ', $_POST['mobile_number'] ?? ''));
    $area       = trim(preg_replace('/\s+/', ' ', $_POST['area'] ?? ''));

    // Optional profile picture: checked now, stored only once everything else is valid.
    [$pictureError, $hasPicture] = profile_picture_check($_FILES['profile_picture'] ?? null);
    if ($pictureError) {
        $errors[] = $pictureError;
    }

    if (!array_key_exists($role, ROLES)) {
        $errors[] = 'Please select a valid role.';
    }

    $required = [
        'location'   => 'Location',
        'first_name' => 'First name',
        'last_name'  => 'Last name',
        'email'      => 'Email address',
        'username'   => 'Username',
        'password'   => 'Password',
    ];
    // Admins don't need a field engineer ID.
    if ($role === 'field_engineer') {
        $required = ['field_engineer_id' => 'Field engineer ID'] + $required;
    }
    foreach ($required as $field => $label) {
        if (trim($_POST[$field] ?? '') === '') {
            $errors[] = "$label is required.";
        }
    }

    // Lengths match the column sizes in the users table.
    $maxLengths = [
        'Field engineer ID' => [$engineerId, 20],
        'First name'    => [$firstName, 50],
        'Middle name'   => [$middleName, 50],
        'Last name'     => [$lastName, 50],
        'Username'      => [$username, 30],
        'Email address' => [$email, 100],
        'Area'          => [$area, 100],
    ];
    foreach ($maxLengths as $label => [$value, $max]) {
        if (mb_strlen($value) > $max) {
            $errors[] = "$label must be at most $max characters.";
        }
    }

    if ($location !== '' && !in_array($location, $locations, true)) {
        $errors[] = 'Please select a valid location.';
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Email address is not valid.';
    }
    if ($mobile !== '' && !preg_match(MOBILE_PATTERN, $mobile)) {
        $errors[] = 'Mobile number is not valid (e.g. 09171234567).';
    }
    if ($username !== '' && !preg_match('/^[A-Za-z0-9_.]+$/', $username)) {
        $errors[] = 'Username may only contain letters, numbers, dots and underscores.';
    }
    if (strlen($_POST['password'] ?? '') < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if (($_POST['password'] ?? '') !== ($_POST['confirm_password'] ?? '')) {
        $errors[] = 'Passwords do not match.';
    }
    if (!$adminMode && empty($_POST['terms'])) {
        $errors[] = 'You must agree to the terms and conditions.';
    }

    if (!$errors) {
        $check = db()->prepare(
            'SELECT field_engineer_id, username, email FROM users
             WHERE field_engineer_id = ? OR username = ? OR email = ?'
        );
        $check->execute([$engineerId === '' ? null : $engineerId, $username, $email]);
        foreach ($check->fetchAll() as $row) {
            if ($engineerId !== '' && strcasecmp((string) $row['field_engineer_id'], $engineerId) === 0) {
                $errors[] = 'That field engineer ID is already registered.';
            }
            if (strcasecmp($row['username'], $username) === 0) {
                $errors[] = 'That username is already taken.';
            }
            if (strcasecmp($row['email'], $email) === 0) {
                $errors[] = 'That email address is already registered.';
            }
        }
        $errors = array_unique($errors);
    }

    if (!$errors) {
        $picture = $hasPicture ? profile_picture_store($_FILES['profile_picture']) : null;
        try {
            $insert = db()->prepare(
                'INSERT INTO users (role, status, reviewed_by, reviewed_at, field_engineer_id, location, mobile_number, area, profile_picture,
                                    first_name, middle_name, last_name, username, email, password_hash)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            // Self-registrations wait for admin approval; accounts an admin creates are approved right away.
            $insert->execute([
                $role,
                $adminMode ? 'approved' : 'pending',
                $adminMode ? $viewer['user_id'] : null,
                $adminMode ? date('Y-m-d H:i:s') : null,
                $engineerId === '' ? null : $engineerId,
                $location,
                $mobile === '' ? null : $mobile,
                $area === '' ? null : $area,
                $picture,
                $firstName,
                $middleName === '' ? null : $middleName,
                $lastName,
                $username,
                $email,
                password_hash($_POST['password'], PASSWORD_DEFAULT),
            ]);

            if (!$adminMode) {
                // Tell every admin there is a registration to review.
                $newId = (int) db()->lastInsertId();
                $who   = trim("$firstName $lastName") . ($engineerId !== '' ? " ($engineerId)" : '');
                notify_admins('registration', "$who registered and is waiting for approval.", 'registrations.php', $newId);
            }

            redirect($adminMode ? 'registration.php?created=' . urlencode($username) : 'login.php?registered=1');
        } catch (PDOException $ex) {
            profile_picture_delete($picture);   // don't keep a picture for a user that wasn't saved
            // 23000 = unique constraint hit by a concurrent registration.
            if ($ex->getCode() === '23000') {
                $errors[] = 'That field engineer ID, username or email address is already registered.';
            } else {
                throw $ex;
            }
        }
    }
}

$selectedRole = $adminMode ? ($_POST['role'] ?? 'field_engineer') : 'field_engineer';

require __DIR__ . '/includes/head.php';

if ($adminMode):
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
      <div class="row">
        <div class="col-xl-8 col-lg-10">

          <div class="card">
            <div class="card-body">
              <h5 class="card-title">New Account</h5>

<?php if (isset($_GET['created'])): ?>
              <div class="alert alert-success">Account <strong>@<?= e($_GET['created']) ?></strong> was created.</div>
<?php endif; ?>
<?php require __DIR__ . '/includes/registration_form.php'; ?>

            </div>
          </div>

        </div>
      </div>
    </section>

  </main><!-- End #main -->

<?php
    require __DIR__ . '/includes/footer.php';
else:
?>

  <main>
    <div class="container">

      <section class="section register min-vh-100 d-flex flex-column align-items-center justify-content-center py-4">
        <div class="container">
          <div class="row justify-content-center">
            <div class="col-xl-6 col-lg-8 col-md-10 d-flex flex-column align-items-center justify-content-center">

              <div class="d-flex justify-content-center py-4">
                <a href="index.php" class="logo d-flex align-items-center w-auto">
                  <img src="assets/img/logo.png" alt="">
                  <span class="d-none d-lg-block"><?= e(APP_NAME) ?></span>
                </a>
              </div><!-- End Logo -->

              <div class="card mb-3 w-100">
                <div class="card-body">

                  <div class="pt-4 pb-2">
                    <h5 class="card-title text-center pb-0 fs-4">Create an Account</h5>
                    <p class="text-center small">Enter your personal details to create account</p>
                  </div>

<?php require __DIR__ . '/includes/registration_form.php'; ?>

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

<?php endif; ?>

  <script>
    // Show the chosen profile picture before submitting.
    (function () {
      var input = document.getElementById("profilePicture");
      var preview = document.getElementById("profilePreview");
      var fallback = preview.src;
      input.addEventListener("change", function () {
        var file = input.files[0];
        if (file && file.size > 5 * 1024 * 1024) {
          alert("Profile picture must be 5 MB or smaller.");
          input.value = "";
          file = null;
        }
        preview.src = file ? URL.createObjectURL(file) : fallback;
      });
    })();

    // Field engineer ID is only required for the field engineer role.
    (function () {
      var role = document.getElementById("role");
      if (!role) return;
      var toggle = function () {
        var isEngineer = role.value === "field_engineer";
        document.getElementById("fieldEngineerIdGroup").classList.toggle("d-none", !isEngineer);
        document.getElementById("fieldEngineerId").required = isEngineer;
      };
      role.addEventListener("change", toggle);
      toggle();
    })();
  </script>

<?php require __DIR__ . '/includes/scripts.php'; ?>
