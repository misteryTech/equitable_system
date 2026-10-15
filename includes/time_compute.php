<?php
/**
 * Hours computation for one time log. Pure functions (no database), so they can be
 * tested on their own: C:\xampp\php\php.exe tests\time_compute_test.php
 */

/**
 * Compute total, regular, early-in and OT hours plus late and undertime minutes.
 *
 * $workDate   Y-m-d of the time log row
 * $schedIn    TIME "09:00:00" saved on the row, or null (no schedule)
 * $schedOut   TIME "18:00:00"; at or before $schedIn means the schedule ends the next day
 * $timeIn     DATETIME "Y-m-d H:i:s"
 * $timeOut    DATETIME "Y-m-d H:i:s" (already on the next day for overnight work)
 * $settings   break_minutes, ot_rounding_min, ot_minimum_min
 *
 * Rules (e.g. schedule 9:00 AM - 6:00 PM = 8 hours duty + 1 hour break):
 *   total_hrs      = time out - time in - break (break only if worked > 5 hrs),
 *                    e.g. 7:08 AM - 7:08 PM = 12 hrs on site - 1 hr break = 11 duty hours
 *   reg_hrs       = time worked inside the schedule - break (break only if worked > 5 hrs),
 *                    never more than (schedule length - break)
 *   early_in_hrs   = scheduled in - actual in (if positive), rounded down; 0 if below the minimum
 *   ot_hrs         = actual out - scheduled out (if positive), rounded down; 0 if below the minimum
 *                    (early time-in is kept separate in early_in_hrs, never added to OT)
 *   late_mins      = actual in - scheduled in (if positive)
 *   undertime_mins = scheduled out - actual out (if positive)
 *   No schedule: only total_hrs is computed.
 */
function time_compute(string $workDate, ?string $schedIn, ?string $schedOut, string $timeIn, string $timeOut, array $settings): array
{
    $result = [
        'total_hrs' => 0.0, 'reg_hrs' => 0.0, 'early_in_hrs' => 0.0, 'ot_hrs' => 0.0,
        'late_mins' => 0, 'undertime_mins' => 0,
    ];

    $in  = time_compute_minutes($timeIn);
    $out = time_compute_minutes($timeOut);
    if ($out <= $in) {
        return $result;
    }
    $worked = $out - $in;
    $break  = (int) $settings['break_minutes'];
    $deduct = $worked > 5 * 60 ? $break : 0;
    $result['total_hrs'] = time_compute_hours(max(0, $worked - $deduct));   // duty hours, break excluded

    if (!$schedIn || !$schedOut) {
        return $result;
    }
    [$start, $end] = time_compute_window($workDate, $schedIn, $schedOut, $in);
    $length = $end - $start;

    $inside = max(0, min($out, $end) - max($in, $start));
    $result['reg_hrs'] = time_compute_hours(min(max(0, $inside - $deduct), max(0, $length - $break)));

    $round = static function (int $mins) use ($settings): int {
        $step = (int) $settings['ot_rounding_min'];
        if ($step > 0) {
            $mins = intdiv($mins, $step) * $step;
        }
        return $mins < (int) $settings['ot_minimum_min'] ? 0 : $mins;
    };

    $early = $round(max(0, min($out, $start) - $in));
    $after = $round(max(0, $out - max($in, $end)));
    $result['early_in_hrs'] = time_compute_hours($early);
    $result['ot_hrs']       = time_compute_hours($after);

    $result['late_mins']      = min(max(0, $in - $start), $length);
    $result['undertime_mins'] = min(max(0, $end - $out), $length);

    return $result;
}

/**
 * How the TIME IN compares with the schedule start, known as soon as TIME IN is clicked:
 * positive = minutes early, negative = minutes late, 0 = on time.
 * Not rounded (rounding is applied to early_in_hrs at TIME OUT).
 */
function time_compute_arrival(string $workDate, string $schedIn, string $schedOut, string $timeIn): int
{
    $in = time_compute_minutes($timeIn);
    [$start] = time_compute_window($workDate, $schedIn, $schedOut, $in);
    return $start - $in;
}

/**
 * [start, end] of the schedule in minutes, on the same timeline as time_compute_minutes().
 * The schedule is placed on the work date. For an overnight schedule (e.g. 22:00-06:00),
 * a time in before that day's schedule end (e.g. 00:10) belongs to the shift that
 * started the previous evening, so it counts as late rather than ~22 hours early.
 */
function time_compute_window(string $workDate, string $schedIn, string $schedOut, int $in): array
{
    $start  = time_compute_minutes("$workDate $schedIn");
    $length = time_compute_minutes("$workDate $schedOut") - $start;
    if ($length <= 0) {
        $length += 24 * 60;                                          // overnight schedule
        if ($in < time_compute_minutes("$workDate $schedOut")) {
            $start -= 24 * 60;                                       // arrived after midnight for last night's shift
        }
    }
    return [$start, $start + $length];
}

/**
 * A "Y-m-d H:i:s" value as whole minutes on one timeline (UTC, so no DST shifts).
 */
function time_compute_minutes(string $dateTime): int
{
    return intdiv((new DateTimeImmutable($dateTime, new DateTimeZone('UTC')))->getTimestamp(), 60);
}

function time_compute_hours(int $minutes): float
{
    return round($minutes / 60, 2);
}
