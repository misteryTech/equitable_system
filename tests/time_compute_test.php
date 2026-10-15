<?php
/**
 * Tests for includes/time_compute.php. No database needed.
 * Run: C:\xampp\php\php.exe tests\time_compute_test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../includes/time_compute.php';

$settings = ['break_minutes' => 60, 'ot_rounding_min' => 30, 'ot_minimum_min' => 30];

$passed = $failed = 0;

/**
 * Check selected result keys. $expected e.g. ['total_hrs' => 9.0, 'reg_hrs' => 8.0].
 */
function check(string $name, array $result, array $expected): void
{
    global $passed, $failed;
    $actual = array_intersect_key($result, $expected);
    if ($actual == $expected) {
        $passed++;
        echo "  PASS  $name\n";
    } else {
        $failed++;
        echo "  FAIL  $name\n        expected: " . json_encode($expected) . "\n        actual:   " . json_encode($actual) . "\n";
    }
}

$d = '2026-10-15';
$day = fn(string $in, string $out, array $s, string $si = '09:00:00', string $so = '18:00:00', string $outDate = '2026-10-15')
    => time_compute($GLOBALS['d'], $si, $so, "{$GLOBALS['d']} $in", "$outDate $out", $s);

echo "Spec test cases (schedule 9:00 AM - 6:00 PM = 8 hrs duty + 1 hr break, rounding 30, minimum 30)\n";
check('9:00 AM - 6:00 PM -> total 8 (9 on site - 1 break), reg 8, early 0, OT 0',
    $day('09:00:00', '18:00:00', $settings), ['total_hrs' => 8.0, 'reg_hrs' => 8.0, 'early_in_hrs' => 0.0, 'ot_hrs' => 0.0]);
check('7:00 AM - 9:30 PM -> total 13.5 (= reg 8 + early 2 + OT 3.5), early kept separate',
    $day('07:00:00', '21:30:00', $settings), ['total_hrs' => 13.5, 'reg_hrs' => 8.0, 'early_in_hrs' => 2.0, 'ot_hrs' => 3.5]);
check('8:40 AM - 6:20 PM -> early 0 and OT 0 (20 min < minimum)',
    $day('08:40:00', '18:20:00', $settings), ['early_in_hrs' => 0.0, 'ot_hrs' => 0.0, 'reg_hrs' => 8.0, 'late_mins' => 0, 'undertime_mins' => 0]);
check('9:15 AM - 5:30 PM -> late 15 mins, undertime 30 mins',
    $day('09:15:00', '17:30:00', $settings), ['late_mins' => 15, 'undertime_mins' => 30, 'reg_hrs' => 7.25, 'ot_hrs' => 0.0]);
check('Overnight 10 PM - 6 AM, worked 9:30 PM - 7:00 AM -> early 0.5, OT 1',
    $day('21:30:00', '07:00:00', $settings, '22:00:00', '06:00:00', '2026-10-16'),
    ['total_hrs' => 8.5, 'reg_hrs' => 7.0, 'early_in_hrs' => 0.5, 'ot_hrs' => 1.0, 'late_mins' => 0, 'undertime_mins' => 0]);

echo "\nMore cases\n";
check('No schedule -> only total_hrs (break still deducted)',
    time_compute($d, null, null, "$d 07:00:00", "$d 21:30:00", $settings),
    ['total_hrs' => 13.5, 'reg_hrs' => 0.0, 'early_in_hrs' => 0.0, 'ot_hrs' => 0.0, 'late_mins' => 0, 'undertime_mins' => 0]);
check('Short day 9:00 AM - 1:00 PM -> no break deducted (worked <= 5 hrs), reg 4, undertime 300',
    $day('09:00:00', '13:00:00', $settings), ['total_hrs' => 4.0, 'reg_hrs' => 4.0, 'undertime_mins' => 300]);
check('OT rounds down: 9:00 AM - 7:50 PM -> OT 1.5',
    $day('09:00:00', '19:50:00', $settings), ['ot_hrs' => 1.5]);
check('Overnight late arrival after midnight 0:10 AM - 6:00 AM -> late 130 mins',
    time_compute($d, '22:00:00', '06:00:00', "$d 00:10:00", "$d 06:00:00", $settings),
    ['total_hrs' => 4.83, 'reg_hrs' => 4.83, 'late_mins' => 130, 'early_in_hrs' => 0.0, 'ot_hrs' => 0.0]);
check('Day schedule, night callout 7:00 PM - 3:00 AM -> all OT, late for the whole 9h schedule',
    $day('19:00:00', '03:00:00', $settings, '09:00:00', '18:00:00', '2026-10-16'),
    ['total_hrs' => 7.0, 'reg_hrs' => 0.0, 'ot_hrs' => 8.0, 'late_mins' => 540, 'undertime_mins' => 0]);
check('Rounding 0 (off), minimum 0: 9:00 AM - 6:10 PM -> OT 0.17',
    $day('09:00:00', '18:10:00', ['ot_rounding_min' => 0, 'ot_minimum_min' => 0] + $settings), ['ot_hrs' => 0.17]);
check('Early only, 5:00 AM - 6:00 PM -> early 4, OT 0 (not merged)',
    $day('05:00:00', '18:00:00', $settings), ['total_hrs' => 12.0, 'reg_hrs' => 8.0, 'early_in_hrs' => 4.0, 'ot_hrs' => 0.0]);
check('Early time in, then left before the schedule: 6:56 AM - 7:30 AM -> early 0.5',
    $day('06:56:00', '07:30:00', $settings), ['total_hrs' => 0.57, 'reg_hrs' => 0.0, 'early_in_hrs' => 0.5, 'ot_hrs' => 0.0]);
check('Rounding off: 7:08 AM - 7:08 PM -> total 11 (12 on site - 1 break) = reg 8 + early 1.87 + OT 1.13',
    $day('07:08:50', '19:08:59', ['ot_rounding_min' => 0, 'ot_minimum_min' => 0] + $settings),
    ['total_hrs' => 11.0, 'reg_hrs' => 8.0, 'early_in_hrs' => 1.87, 'ot_hrs' => 1.13]);
check('Time out not after time in -> all zero',
    $day('09:00:00', '09:00:00', $settings), ['total_hrs' => 0.0, 'reg_hrs' => 0.0]);

echo "\nEarly / late shown at TIME IN (minutes: + early, - late)\n";
$arrival = fn(string $in, string $si = '09:00:00', string $so = '18:00:00')
    => ['minutes' => time_compute_arrival($GLOBALS['d'], $si, $so, "{$GLOBALS['d']} $in")];
check('6:56 AM for a 9:00 AM schedule -> 124 min early', $arrival('06:56:00'), ['minutes' => 124]);
check('9:15 AM for a 9:00 AM schedule -> 15 min late', $arrival('09:15:00'), ['minutes' => -15]);
check('9:00 AM -> on time', $arrival('09:00:00'), ['minutes' => 0]);
check('9:30 PM for an overnight 10:00 PM schedule -> 30 min early', $arrival('21:30:00', '22:00:00', '06:00:00'), ['minutes' => 30]);
check('12:10 AM for an overnight 10:00 PM schedule -> 130 min late', $arrival('00:10:00', '22:00:00', '06:00:00'), ['minutes' => -130]);

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
