<?php
/**
 * Password reset by request. A user asks on the login page ("Forgot password?");
 * an admin approves the request by setting a new password and gives it to the user
 * (nothing is emailed); the user must choose their own password at the next login.
 * password_policy_error() and password_generate() use no database
 * (tests/password_reset_test.php).
 */

const PASSWORD_MIN_LENGTH = 8;
const PASSWORD_MAX_LENGTH = 72;   // bcrypt only uses the first 72 bytes

// Letters and digits that are hard to confuse when read out or copied by hand (no 0/O, 1/l/I).
const PASSWORD_GENERATE_CHARS = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

/**
 * What is wrong with a new password, or null when it is fine.
 */
function password_policy_error(string $password): ?string
{
    if (strlen($password) < PASSWORD_MIN_LENGTH) {
        return 'The password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.';
    }
    if (strlen($password) > PASSWORD_MAX_LENGTH) {
        return 'The password must be at most ' . PASSWORD_MAX_LENGTH . ' characters.';
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        return 'The password needs at least one letter and one number.';
    }
    return null;
}

/**
 * A random password that is easy to read out, with at least one lower-case letter,
 * one capital and one digit, e.g. "Hq7mT3vkPa".
 */
function password_generate(int $length = 10): string
{
    $length = max(PASSWORD_MIN_LENGTH, $length);
    $chars  = PASSWORD_GENERATE_CHARS;
    do {
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }
    } while (!preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password) || !preg_match('/\d/', $password));
    return $password;
}

/* ---------- Database ---------- */

/**
 * Record a "Forgot password?" request for the account with this username or email.
 * Unknown, pending and rejected accounts are ignored, and the page shows the same
 * message either way, so the form can't be used to find out which accounts exist.
 * A second request while one is pending only updates that one.
 */
function password_reset_request(string $login, string $note, ?string $ip): void
{
    $stmt = db()->prepare("SELECT user_id, first_name, last_name, username, field_engineer_id FROM users WHERE (username = ? OR email = ?) AND status = 'approved' LIMIT 1");
    $stmt->execute([$login, $login]);
    $user = $stmt->fetch();
    if (!$user) {
        return;
    }

    $note = mb_substr($note, 0, 255);
    $stmt = db()->prepare("SELECT id FROM password_reset_requests WHERE user_id = ? AND status = 'pending' LIMIT 1");
    $stmt->execute([$user['user_id']]);
    if ($pendingId = $stmt->fetchColumn()) {
        db()->prepare('UPDATE password_reset_requests SET note = COALESCE(?, note), requested_at = CURRENT_TIMESTAMP, requested_ip = ? WHERE id = ?')
            ->execute([$note === '' ? null : $note, $ip, $pendingId]);
        return;
    }

    db()->prepare('INSERT INTO password_reset_requests (user_id, note, requested_ip) VALUES (?, ?, ?)')
        ->execute([$user['user_id'], $note === '' ? null : $note, $ip]);
    $who = trim($user['first_name'] . ' ' . $user['last_name']) . ($user['field_engineer_id'] ? " ({$user['field_engineer_id']})" : " (@{$user['username']})");
    notify_admins('password_reset', "$who asked for a password reset.", 'password_resets.php', (int) db()->lastInsertId());
}

function password_reset_pending_count(): int
{
    return (int) db()->query("SELECT COUNT(*) FROM password_reset_requests WHERE status = 'pending'")->fetchColumn();
}
