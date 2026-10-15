<?php
/**
 * Open a notification: mark it read, then go to its page.
 * Only the notification's own recipient can open it.
 */
require_once __DIR__ . '/includes/config.php';
$user = require_login();

$stmt = db()->prepare('SELECT id, link FROM notifications WHERE id = ? AND user_id = ?');
$stmt->execute([(int) ($_GET['id'] ?? 0), $user['user_id']]);
$notification = $stmt->fetch();

if (!$notification) {
    redirect('notifications.php');
}

db()->prepare('UPDATE notifications SET read_at = NOW() WHERE id = ? AND read_at IS NULL')->execute([$notification['id']]);
redirect(notification_safe_link($notification['link']));
