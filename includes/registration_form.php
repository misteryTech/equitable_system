<?php
/**
 * Registration form fields, shared by the public sign-up page and the admin
 * "Register User" page. Expects from the including page:
 *   $errors, $locations, $adminMode, $selectedRole
 */

// Declared for the editor's code checker; the including page sets these.
/** @var string[] $errors */        // validation messages to show above the form
/** @var string[] $locations */     // names for the Location dropdown
/** @var bool $adminMode */         // true when an admin is registering someone
/** @var string $selectedRole */    // role to pre-select (admin mode only)
?>
<?php if ($errors): ?>
                  <div class="alert alert-danger">
                    <ul class="mb-0">
<?php foreach ($errors as $error): ?>
                      <li><?= e($error) ?></li>
<?php endforeach; ?>
                    </ul>
                  </div>
<?php endif; ?>

                  <form method="post" class="row g-3" enctype="multipart/form-data" novalidate>
                    <?= csrf_field() ?>

                    <div class="col-12 d-flex align-items-center gap-3">
                      <img src="<?= e(PROFILE_PICTURE_DEFAULT) ?>" alt="Profile picture preview" id="profilePreview"
                           class="rounded-circle border" style="width: 80px; height: 80px; object-fit: cover">
                      <div class="flex-grow-1">
                        <label for="profilePicture" class="form-label mb-1">Profile Picture <span class="text-muted small">(optional)</span></label>
                        <input type="file" name="profile_picture" id="profilePicture" class="form-control"
                               accept="image/jpeg,image/png,image/webp,image/gif">
                        <div class="form-text">JPG, PNG, WEBP or GIF, up to 5 MB. It will be cropped to a square.<?= $errors ? ' <strong>Please choose the picture again.</strong>' : '' ?></div>
                      </div>
                    </div>

<?php if ($adminMode): ?>
                    <div class="col-12">
                      <label for="role" class="form-label">Role</label>
                      <select name="role" class="form-select" id="role" required>
<?php foreach (ROLES as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $selectedRole === $value ? 'selected' : '' ?>><?= e($label) ?></option>
<?php endforeach; ?>
                      </select>
                    </div>
<?php endif; ?>

                    <div class="col-md-6" id="fieldEngineerIdGroup">
                      <label for="fieldEngineerId" class="form-label">Field Engineer ID</label>
                      <input type="text" name="field_engineer_id" class="form-control" id="fieldEngineerId" value="<?= old('field_engineer_id') ?>" maxlength="20" required>
                    </div>

                    <div class="col-md-6">
                      <label for="location" class="form-label">Location</label>
                      <select name="location" class="form-select" id="location" required>
                        <option value="" disabled <?= old('location', DEFAULT_LOCATION) === '' ? 'selected' : '' ?>>Select a location</option>
<?php foreach ($locations as $loc): ?>
                        <option value="<?= e($loc) ?>" <?= old('location', DEFAULT_LOCATION) === e($loc) ? 'selected' : '' ?>><?= e($loc) ?></option>
<?php endforeach; ?>
                      </select>
                    </div>

                    <div class="col-md-4">
                      <label for="firstName" class="form-label">First Name</label>
                      <input type="text" name="first_name" class="form-control" id="firstName" value="<?= old('first_name') ?>" maxlength="50" required>
                    </div>

                    <div class="col-md-4">
                      <label for="middleName" class="form-label">Middle Name <span class="text-muted small">(optional)</span></label>
                      <input type="text" name="middle_name" class="form-control" id="middleName" value="<?= old('middle_name') ?>" maxlength="50">
                    </div>

                    <div class="col-md-4">
                      <label for="lastName" class="form-label">Last Name</label>
                      <input type="text" name="last_name" class="form-control" id="lastName" value="<?= old('last_name') ?>" maxlength="50" required>
                    </div>

                    <div class="col-12">
                      <label for="yourEmail" class="form-label">Email Address</label>
                      <input type="email" name="email" class="form-control" id="yourEmail" value="<?= old('email') ?>" maxlength="100" required>
                    </div>

                    <div class="col-12">
                      <label for="yourUsername" class="form-label">Username</label>
                      <div class="input-group">
                        <span class="input-group-text">@</span>
                        <input type="text" name="username" class="form-control" id="yourUsername" value="<?= old('username') ?>" maxlength="30" required>
                      </div>
                    </div>

                    <div class="col-md-6">
                      <label for="yourPassword" class="form-label">Password</label>
                      <input type="password" name="password" class="form-control" id="yourPassword" minlength="8" required>
                    </div>

                    <div class="col-md-6">
                      <label for="confirmPassword" class="form-label">Confirm Password</label>
                      <input type="password" name="confirm_password" class="form-control" id="confirmPassword" minlength="8" required>
                    </div>

<?php if (!$adminMode): ?>
                    <div class="col-12">
                      <div class="form-check">
                        <input class="form-check-input" name="terms" type="checkbox" id="acceptTerms" <?= isset($_POST['terms']) ? 'checked' : '' ?> required>
                        <label class="form-check-label" for="acceptTerms">
                          I agree to the terms and conditions.
                        </label>
                      </div>
                    </div>
<?php endif; ?>

                    <div class="col-12">
                      <button class="btn btn-primary w-100" type="submit"><?= $adminMode ? 'Create User' : 'Create Account' ?></button>
                    </div>

<?php if (!$adminMode): ?>
                    <div class="col-12">
                      <p class="small mb-0 text-center">
                        Already have an account? <a href="login.php">Log in</a>
                      </p>
                    </div>
<?php endif; ?>
                  </form>
