<?php
/**
 * Save the signed-in user's own profile (Edit Profile modal, AJAX).
 * Editable: name, email, mobile number, area, profile picture, password (needs the current password).
 * Username, role, field engineer ID and location stay admin-controlled.
 * Returns JSON: {ok: true} or {errors: [...]}.
 */
require_once __DIR__ . '/includes/config.php';

header('Content-Type: application/json');

function respond(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

$user = current_user();
if ($user === null) {
    respond(['errors' => ['Your session has expired. Please log in again.']], 401);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['errors' => ['POST required.']], 405);
}
if (!csrf_verify()) {
    respond(['errors' => ['Your session expired. Please reload the page.']], 403);
}

$firstName  = trim((string) ($_POST['first_name'] ?? ''));
$middleName = trim((string) ($_POST['middle_name'] ?? ''));
$lastName   = trim((string) ($_POST['last_name'] ?? ''));
$email      = trim((string) ($_POST['email'] ?? ''));
$mobile     = trim(preg_replace('/\s+/', ' ', (string) ($_POST['mobile_number'] ?? '')));
$area       = trim(preg_replace('/\s+/', ' ', (string) ($_POST['area'] ?? '')));
$current    = (string) ($_POST['current_password'] ?? '');
$new        = (string) ($_POST['new_password'] ?? '');
$confirm    = (string) ($_POST['confirm_password'] ?? '');
$removePic  = !empty($_POST['remove_picture']);
$errors     = [];

foreach (['First name' => [$firstName, 50, true], 'Middle name' => [$middleName, 50, false],
          'Last name' => [$lastName, 50, true], 'Email address' => [$email, 100, true], 'Area' => [$area, 100, false]] as $label => [$value, $max, $required]) {
    if ($required && $value === '') {
        $errors[] = "$label is required.";
    } elseif (mb_strlen($value) > $max) {
        $errors[] = "$label must be at most $max characters.";
    }
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Email address is not valid.';
} elseif ($email !== '') {
    $stmt = db()->prepare('SELECT 1 FROM users WHERE email = ? AND user_id <> ?');
    $stmt->execute([$email, $user['user_id']]);
    if ($stmt->fetchColumn()) {
        $errors[] = 'That email address is already used by another account.';
    }
}
if ($mobile !== '' && !preg_match(MOBILE_PATTERN, $mobile)) {
    $errors[] = 'Mobile number is not valid (e.g. 09171234567).';
}

// Password change is optional; when asked for, the current password must be right.
$changePassword = $new !== '' || $confirm !== '';
if ($changePassword) {
    $stmt = db()->prepare('SELECT password_hash FROM users WHERE user_id = ?');
    $stmt->execute([$user['user_id']]);
    if ($current === '' || !password_verify($current, (string) $stmt->fetchColumn())) {
        $errors[] = 'Your current password is incorrect.';
    }
    if (strlen($new) < 8) {
        $errors[] = 'New password must be at least 8 characters.';
    }
    if ($new !== $confirm) {
        $errors[] = 'New passwords do not match.';
    }
}

[$pictureError, $hasPicture] = profile_picture_check($_FILES['profile_picture'] ?? null);
if ($pictureError) {
    $errors[] = $pictureError;
}

if ($errors) {
    respond(['errors' => $errors], 422);
}

$oldPicture = $user['profile_picture'];
$newPicture = $hasPicture ? profile_picture_store($_FILES['profile_picture']) : ($removePic ? null : $oldPicture);

try {
    $fields = 'first_name = ?, middle_name = ?, last_name = ?, email = ?, mobile_number = ?, area = ?, profile_picture = ?';
    $params = [$firstName, $middleName === '' ? null : $middleName, $lastName, $email,
               $mobile === '' ? null : $mobile, $area === '' ? null : $area, $newPicture];
    if ($changePassword) {
        $fields  .= ', password_hash = ?';
        $params[] = password_hash($new, PASSWORD_DEFAULT);
    }
    $params[] = $user['user_id'];
    db()->prepare("UPDATE users SET $fields WHERE user_id = ?")->execute($params);
} catch (PDOException $ex) {
    if ($newPicture !== $oldPicture) {
        profile_picture_delete($newPicture);
    }
    if ($ex->getCode() === '23000') {
        respond(['errors' => ['That email address is already used by another account.']], 422);
    }
    throw $ex;
}

// The old picture file is no longer used once it was replaced or removed.
if ($oldPicture && $newPicture !== $oldPicture) {
    profile_picture_delete($oldPicture);
}
if ($changePassword) {
    session_regenerate_id(true);
}

respond(['ok' => true, 'message' => 'Profile saved.' . ($changePassword ? ' Your password was changed.' : '')]);
