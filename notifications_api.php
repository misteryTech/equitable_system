<?php
/**
 * JSON for the bell's 30-second refresh: unread count and the dropdown HTML.
 */
require_once __DIR__ . '/includes/config.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$user = current_user();
if ($user === null) {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in.']);
    exit;
}

$unread = notifications_unread_count((int) $user['user_id']);
echo json_encode([
    'unread' => $unread,
    'html'   => notifications_dropdown_html($unread, notifications_latest((int) $user['user_id'], 5)),
]);
