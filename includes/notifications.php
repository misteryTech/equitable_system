<?php
/**
 * In-app notifications (the bell in the top bar).
 * Included by includes/config.php.
 */

/**
 * Send a notification to every approved admin. Returns how many were created.
 */
function notify_admins(string $type, string $message, ?string $link = null, ?int $refId = null): int
{
    $stmt = db()->prepare(
        "INSERT INTO notifications (user_id, type, ref_id, message, link)
         SELECT user_id, ?, ?, ?, ? FROM users WHERE role = 'admin' AND status = 'approved'"
    );
    $stmt->execute([$type, $refId, mb_substr($message, 0, 255), $link]);
    return $stmt->rowCount();
}

/**
 * Mark every notification about a record as read for everyone (e.g. once a
 * registration has been approved or rejected, it no longer needs attention).
 */
function notifications_resolve(string $type, int $refId): void
{
    db()->prepare('UPDATE notifications SET read_at = NOW() WHERE type = ? AND ref_id = ? AND read_at IS NULL')
        ->execute([$type, $refId]);
}

function notifications_unread_count(int $userId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL');
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

/**
 * Latest notifications for a user, newest first.
 */
function notifications_latest(int $userId, int $limit = 5, bool $unreadOnly = false): array
{
    $stmt = db()->prepare(
        'SELECT id, type, message, link, read_at, created_at FROM notifications
         WHERE user_id = ?' . ($unreadOnly ? ' AND read_at IS NULL' : '') . '
         ORDER BY created_at DESC, id DESC LIMIT ' . max(1, (int) $limit)
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/**
 * Only follow links inside this site (no "http://", "//" or "javascript:").
 */
function notification_safe_link(?string $link): string
{
    return $link && preg_match('/^[a-z0-9_\-]+\.php(\?[A-Za-z0-9_=&%.\-]*)?$/', $link) ? $link : 'notifications.php';
}

/**
 * "just now", "5 min ago", "3 hrs ago", "Oct 12".
 */
function notification_time_ago(string $dateTime): string
{
    $seconds = time() - strtotime($dateTime);
    if ($seconds < 60) {
        return 'just now';
    }
    if ($seconds < 3600) {
        return intdiv($seconds, 60) . ' min ago';
    }
    if ($seconds < 86400) {
        $hours = intdiv($seconds, 3600);
        return $hours . ' hr' . ($hours === 1 ? '' : 's') . ' ago';
    }
    return date('M j', strtotime($dateTime));
}

/**
 * The <li> items inside the bell dropdown (header + list + footer), in the
 * NiceAdmin notification style. Used by the header and by notifications_api.php.
 */
function notifications_dropdown_html(int $unread, array $items): string
{
    $html = '<li class="dropdown-header">'
        . ($unread ? "You have $unread new notification" . ($unread === 1 ? '' : 's') : 'You have no new notifications')
        . ($items ? ' <a href="notifications.php"><span class="badge rounded-pill bg-primary p-2 ms-2">View all</span></a>' : '')
        . '</li><li><hr class="dropdown-divider"></li>';

    foreach ($items as $n) {
        $html .= '<li class="notification-item' . ($n['read_at'] ? ' opacity-50' : '') . '">'
            . '<a href="notification_open.php?id=' . (int) $n['id'] . '" class="d-flex text-reset text-decoration-none">'
            . '<i class="bi ' . e(notification_icon($n['type'])) . '"></i>'
            . '<div><h4>' . e(notification_title($n['type'])) . '</h4>'
            . '<p>' . e($n['message']) . '</p>'
            . '<p>' . e(notification_time_ago($n['created_at'])) . '</p></div></a></li>'
            . '<li><hr class="dropdown-divider"></li>';
    }

    return $html . '<li class="dropdown-footer"><a href="notifications.php">Show all notifications</a></li>';
}

/**
 * Heading shown above a notification in the bell.
 */
function notification_title(string $type): string
{
    return [
        'registration'   => 'New registration',
        'transpo_rate'   => 'Route fare to approve',
        'transpo_report' => 'Wrong fare reported',
        'transpo_mode'   => 'New transport mode',
        'password_reset' => 'Password reset request',
    ][$type] ?? 'Notification';
}

/**
 * Bootstrap icon for a notification type.
 */
function notification_icon(string $type): string
{
    return [
        'registration'   => 'bi-person-plus text-warning',
        'transpo_rate'   => 'bi-signpost-split text-primary',
        'transpo_report' => 'bi-flag text-danger',
        'transpo_mode'   => 'bi-bus-front text-success',
        'password_reset' => 'bi-key text-warning',
    ][$type] ?? 'bi-info-circle text-primary';
}
