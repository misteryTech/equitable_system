<?php
/**
 * Daily Time In / Time Out log (time_logs table).
 * Include after includes/config.php.
 */
require_once __DIR__ . '/time_compute.php';

/**
 * Filing types chosen at TIME IN (label only; hours are computed the same way).
 */
const FILING_TYPES = [
    'regular' => 'Regular',
    'holiday' => 'Holiday',
];

/**
 * Day status on the DAS: a leave day not worked is "Leave"; a leave day the
 * user timed in on is "Duty with pay"; any other worked day is "Duty".
 */
const DAY_STATUSES = [
    'duty'          => ['label' => 'Duty',          'badge' => 'bg-primary'],
    'duty_with_pay' => ['label' => 'Duty with pay', 'badge' => 'bg-success'],
    'leave'         => ['label' => 'Leave',         'badge' => 'bg-secondary'],
];

function day_status(bool $isLeave, bool $hasLog): string
{
    if ($isLeave) {
        return $hasLog ? 'duty_with_pay' : 'leave';
    }
    return 'duty';
}

function day_status_badge(string $status): string
{
    $s = DAY_STATUSES[$status];
    return '<span class="badge ' . $s['badge'] . '">' . e($s['label']) . '</span>';
}

/**
 * The user's leave days between two dates: [Y-m-d => leave_id].
 */
function leave_days_between(int $userId, string $from, string $to): array
{
    $stmt = db()->prepare('SELECT leave_date, leave_id FROM leave_days WHERE user_id = ? AND leave_date BETWEEN ? AND ? ORDER BY leave_date');
    $stmt->execute([$userId, $from, $to]);
    return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

/**
 * Badge for a filing type, e.g. a red "Holiday" badge.
 */
function time_log_filing_badge(string $type): string
{
    $class = $type === 'holiday' ? 'bg-danger' : 'bg-secondary';
    return '<span class="badge ' . $class . '">' . e(FILING_TYPES[$type] ?? $type) . '</span>';
}

/**
 * Where a duty day was spent (time_logs.trip_type), chosen on My DAS.
 */
const TRIP_TYPES = [
    'within_city' => 'Within the city',
    'out_of_town' => 'Out of town',
];

/**
 * The single time_settings row (break, early-in / OT rounding and minimum).
 */
function time_settings(bool $refresh = false): array
{
    static $settings = null;
    if ($settings === null || $refresh) {
        $settings = db()->query('SELECT * FROM time_settings WHERE id = 1')->fetch();
        if (!$settings) {
            db()->exec('INSERT IGNORE INTO time_settings (id) VALUES (1)');
            $settings = db()->query('SELECT * FROM time_settings WHERE id = 1')->fetch();
        }
    }
    return $settings;
}

/**
 * Compute and store the hour columns for one time log that has a time in and out.
 * Returns the computed values, or null if the log is not complete.
 */
function time_log_save_hours(int $logId): ?array
{
    $stmt = db()->prepare('SELECT * FROM time_logs WHERE id = ?');
    $stmt->execute([$logId]);
    $log = $stmt->fetch();
    if (!$log || !$log['time_in'] || !$log['time_out']) {
        return null;
    }

    $hours = time_compute($log['work_date'], $log['sched_in'], $log['sched_out'], $log['time_in'], $log['time_out'], time_settings());
    db()->prepare(
        'UPDATE time_logs SET total_hrs = ?, reg_hrs = ?, early_in_hrs = ?, ot_hrs = ?, late_mins = ?, undertime_mins = ?
         WHERE id = ?'
    )->execute([
        $hours['total_hrs'], $hours['reg_hrs'], $hours['early_in_hrs'], $hours['ot_hrs'],
        $hours['late_mins'], $hours['undertime_mins'], $logId,
    ]);

    return $hours + $log;
}

/**
 * Recompute completed logs with work dates in [$from, $to], for one user or everyone.
 * With $refreshSchedule, each day's saved schedule is first replaced by the
 * user's current regular schedule. Returns the number of logs recomputed.
 */
function time_log_recompute(string $from, string $to, ?int $userId = null, bool $refreshSchedule = false): int
{
    $where  = 't.work_date BETWEEN ? AND ?' . ($userId !== null ? ' AND t.user_id = ?' : '');
    $params = $userId !== null ? [$from, $to, $userId] : [$from, $to];

    if ($refreshSchedule) {
        db()->prepare(
            "UPDATE time_logs t JOIN users u ON u.user_id = t.user_id
             SET t.sched_in = u.reg_time_in, t.sched_out = u.reg_time_out
             WHERE $where"
        )->execute($params);
    }

    $stmt = db()->prepare("SELECT t.id FROM time_logs t WHERE $where AND t.time_in IS NOT NULL AND t.time_out IS NOT NULL");
    $stmt->execute($params);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        time_log_save_hours((int) $id);
    }
    return count($ids);
}

/**
 * Schedule start/end as Unix timestamps for a work date, placed the same way as
 * time_compute_window() (overnight schedules end the next day; an after-midnight
 * time in belongs to the shift that started the previous evening).
 */
function time_log_window(string $workDate, string $schedIn, string $schedOut, int $timeIn): array
{
    $start = strtotime("$workDate $schedIn");
    $end   = strtotime("$workDate $schedOut");
    if ($end <= $start) {
        if ($timeIn < $end) {
            $start -= 86400;          // last night's shift, ending this morning
        } else {
            $end += 86400;            // tonight's shift, ending tomorrow morning
        }
    }
    return [$start, $end];
}

/**
 * Hours for display: 14.5 -> "14.5", 8 -> "8" (compact), or "14.50" with $fixed.
 */
function time_log_hours($hours, bool $fixed = false): string
{
    $formatted = number_format((float) $hours, 2);
    return $fixed ? $formatted : rtrim(rtrim($formatted, '0'), '.');
}

/**
 * Minutes for display: 0 -> "", 15 -> "15 min", 75 -> "1 h 15 min".
 */
function time_log_minutes(int $minutes): string
{
    if ($minutes <= 0) {
        return '';
    }
    return $minutes >= 60
        ? intdiv($minutes, 60) . ' h' . ($minutes % 60 ? ' ' . ($minutes % 60) . ' min' : '')
        : "$minutes min";
}

/**
 * Extra hours = Early In + OT (the hours outside the regular schedule; the break is not extra).
 */
function time_log_extra(array $log): float
{
    return round((float) $log['early_in_hrs'] + (float) $log['ot_hrs'], 2);
}

/**
 * "Worked 14.5 hrs · Regular 8 · Early in 2 · OT 3.5 · Extra 5.5" (or "· No schedule").
 */
function time_log_summary(array $log): string
{
    $parts = ['Worked ' . time_log_hours($log['total_hrs']) . ' hrs'];
    if (!$log['sched_in'] || !$log['sched_out']) {
        $parts[] = 'No schedule';
    } else {
        $parts[] = 'Regular ' . time_log_hours($log['reg_hrs']);
        $parts[] = 'Early in ' . time_log_hours($log['early_in_hrs']);
        $parts[] = 'OT ' . time_log_hours($log['ot_hrs']);
        $parts[] = 'Extra ' . time_log_hours(time_log_extra($log));
        if ($log['late_mins'] > 0) {
            $parts[] = 'Late ' . time_log_minutes((int) $log['late_mins']);
        }
        if ($log['undertime_mins'] > 0) {
            $parts[] = 'Undertime ' . time_log_minutes((int) $log['undertime_mins']);
        }
    }
    return implode(' · ', $parts);
}

/**
 * What the engineer can do right now, based on today's and yesterday's logs:
 *   'time_out_yesterday'  yesterday has a time in but no time out (worked past midnight)
 *   'time_in'             no time in today yet
 *   'time_out'            timed in today, not yet timed out
 *   'done'                timed in and out today
 * Returns ['action' => ..., 'log' => the row the action applies to (or null), 'today' => today's row].
 */
function time_clock_state(int $userId): array
{
    $today     = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));

    $stmt = db()->prepare('SELECT * FROM time_logs WHERE user_id = ? AND work_date IN (?, ?)');
    $stmt->execute([$userId, $today, $yesterday]);
    $logs = [];
    foreach ($stmt->fetchAll() as $row) {
        $logs[$row['work_date']] = $row;
    }
    $todayLog     = $logs[$today] ?? null;
    $yesterdayLog = $logs[$yesterday] ?? null;

    if ($yesterdayLog && $yesterdayLog['time_in'] && !$yesterdayLog['time_out']) {
        return ['action' => 'time_out_yesterday', 'log' => $yesterdayLog, 'today' => $todayLog];
    }
    if (!$todayLog || !$todayLog['time_in']) {
        return ['action' => 'time_in', 'log' => null, 'today' => $todayLog];
    }
    if (!$todayLog['time_out']) {
        return ['action' => 'time_out', 'log' => $todayLog, 'today' => $todayLog];
    }
    return ['action' => 'done', 'log' => $todayLog, 'today' => $todayLog];
}

/**
 * "2026-10-15 07:32:00" -> "7:32 AM" ("" when empty).
 */
function time_log_time(?string $dateTime): string
{
    return $dateTime ? date('g:i A', strtotime($dateTime)) : '';
}

/**
 * A TIME value "09:00:00" -> "9:00 AM" ("" when empty).
 */
function time_log_clock(?string $time): string
{
    return $time ? date('g:i A', strtotime("2000-01-01 $time")) : '';
}

/**
 * Schedule label "9:00 AM – 6:00 PM", or "" when not set.
 */
function time_log_schedule(?string $in, ?string $out): string
{
    return $in && $out ? time_log_clock($in) . ' – ' . time_log_clock($out) : '';
}

/**
 * "2026-10-15" -> "10/15/2026".
 */
function time_log_date(string $date): string
{
    return date('m/d/Y', strtotime($date));
}

/**
 * True when the time out is on the day after the work date.
 */
function time_log_next_day(array $log): bool
{
    return !empty($log['time_out']) && substr($log['time_out'], 0, 10) !== $log['work_date'];
}
