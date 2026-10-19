<?php
/**
 * Global configuration and helpers.
 * Include this at the very top of every page, before any output.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Matches the MySQL server; Time In / Time Out are saved in this timezone.
date_default_timezone_set('Asia/Manila');

define('APP_NAME', 'Equitable System');
define('APP_AUTHOR', 'Mistery');

// Matches the DEFAULT on users.location.
define('DEFAULT_LOCATION', 'M2C Depot');

// Database connection: kept in config.local.php so passwords never go to GitHub.
if (!is_file(__DIR__ . '/config.local.php')) {
    http_response_code(500);
    exit('Missing includes/config.local.php — copy includes/config.local.example.php to config.local.php and enter your database settings.');
}
require_once __DIR__ . '/config.local.php';

/**
 * Shared PDO connection (created on first use).
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    }
    return $pdo;
}

/**
 * Escape a value for safe HTML output.
 */
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Return the previously submitted value of a form field (for re-filling forms).
 */
function old(string $key, string $default = ''): string
{
    return e($_POST[$key] ?? $default);
}

/**
 * CSRF protection (native replacement for Django's {% csrf_token %}).
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): bool
{
    return isset($_POST['csrf_token'])
        && hash_equals(csrf_token(), (string) $_POST['csrf_token']);
}

/**
 * Session-based authentication.
 */
function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

/**
 * Store the user in the session after a successful login.
 */
function login_user(array $user): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['user_id'];
}

/**
 * The logged-in user's row from the database, or null for guests.
 * Re-read on each request so deleted or edited accounts take effect immediately.
 */
function current_user(): ?array
{
    static $user = false;
    if ($user === false) {
        $user = null;
        if (!empty($_SESSION['user_id'])) {
            $stmt = db()->prepare(
                'SELECT user_id, role, status, field_engineer_id, location, mobile_number, area, profile_picture, reg_time_in, reg_time_out,
                        first_name, middle_name, last_name, username, email, created_at
                 FROM users WHERE user_id = ?'
            );
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch() ?: null;
            // Deleted, pending or rejected accounts are signed out.
            if ($user === null || $user['status'] !== 'approved') {
                $user = null;
                unset($_SESSION['user_id']);
            }
        }
    }
    return $user;
}

/**
 * User roles: database value => display label.
 */
const ROLES = [
    'admin'          => 'Admin',
    'field_engineer' => 'Field Engineer',
];

function role_label(string $role): string
{
    return ROLES[$role] ?? $role;
}

function is_admin(?array $user): bool
{
    return $user !== null && $user['role'] === 'admin';
}

/**
 * Number of self-registrations waiting for admin approval.
 */
function pending_registration_count(): int
{
    return (int) db()->query("SELECT COUNT(*) FROM users WHERE status = 'pending'")->fetchColumn();
}

/**
 * Mobile number: digits, optionally starting with +, with spaces, dashes or brackets
 * (e.g. 09171234567, +63 917 123 4567).
 */
const MOBILE_PATTERN = '/^\+?[0-9][0-9 ()-]{6,18}$/';

function full_name(array $user): string
{
    return implode(' ', array_filter([$user['first_name'], $user['middle_name'] ?? null, $user['last_name']]));
}

/**
 * Pages for logged-in users only (e.g. the dashboard).
 */
function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        redirect('login.php');
    }
    return $user;
}

/**
 * Pages for logged-in users with one of the given roles; others go to the dashboard.
 */
function require_role(string ...$roles): array
{
    $user = require_login();
    if (!in_array($user['role'], $roles, true)) {
        redirect('index.php?denied=1');
    }
    return $user;
}

/**
 * Pages for guests only (login): logged-in users go to the dashboard.
 */
function require_guest(): void
{
    if (current_user() !== null) {
        redirect('index.php');
    }
}

// Profile picture and notification helpers (used by the header on every page).
require_once __DIR__ . '/profile_picture.php';
require_once __DIR__ . '/notifications.php';

/**
 * Mark a sidebar link as active / collapsed.
 */
function nav_class(string $page): string
{
    global $activePage;
    return ($activePage ?? '') === $page ? '' : 'collapsed';
}
