<?php
/**
 * Tests for the transpo normalization and similarity rules (no database needed).
 * Run: C:\xampp\php\php.exe tests\transpo_test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// transpo.php only needs these from config.php for the pure functions under test.
function e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function is_admin(?array $u): bool { return ($u['role'] ?? '') === 'admin'; }
require __DIR__ . '/../includes/transpo.php';

$passed = $failed = 0;
function check(string $name, $actual, $expected): void
{
    global $passed, $failed;
    if ($actual === $expected) {
        $passed++;
        echo "  PASS  $name\n";
    } else {
        $failed++;
        echo "  FAIL  $name\n        expected: " . var_export($expected, true) . "\n        actual:   " . var_export($actual, true) . "\n";
    }
}

echo "Normalization\n";
check('case, spaces, punctuation',          transpo_normalize('  makar   dropoint. '), 'MAKAR DROPOINT');
check('DROP POINT -> DROPOINT',              transpo_normalize('Makar Drop Point'), 'MAKAR DROPOINT');
check('DROPPOINT -> DROPOINT',               transpo_normalize('Uhaw Droppoint'), 'UHAW DROPOINT');
check('DROP PT / DRP PT -> DROPOINT',         [transpo_normalize('Makar Drop Pt.'), transpo_normalize('makar drp pt')], ['MAKAR DROPOINT', 'MAKAR DROPOINT']);
check('HWY / HGWY / HWAY / HIGH WAY',        [transpo_normalize('Natl Hwy'), transpo_normalize('HGWY'), transpo_normalize('hway'), transpo_normalize('High Way')], ['NATIONAL HIGHWAY', 'HIGHWAY', 'HIGHWAY', 'HIGHWAY']);
check("NAT'L -> NATIONAL",                   transpo_normalize("Nat'l Highway"), 'NATIONAL HIGHWAY');
check('GEN SAN / GENERAL SANTOS / GEN SANTOS', [transpo_normalize('Wang Zi Hardware - Gen San'), transpo_normalize('General Santos City'), transpo_normalize('Gen Santos')], ['WANG ZI HARDWARE GENSAN', 'GENSAN CITY', 'GENSAN']);
check('hyphen and # removed',                transpo_normalize('WANG ZI HARDWARE-GENSAN #2'), 'WANG ZI HARDWARE GENSAN 2');
check('word inside a word untouched',        transpo_normalize('HWYLANE'), 'HWYLANE');
check('base phrase',                         transpo_normalize('Nearest Highway from Base'), 'NEAREST HIGHWAY FROM BASE');

echo "\nSimilarity (threshold " . TRANSPO_SIMILAR_THRESHOLD . ")\n";
check('MAKAR vs MAKAR DROPOINT is similar',  transpo_similarity('MAKAR', 'MAKAR DROPOINT') >= TRANSPO_SIMILAR_THRESHOLD, true);
check('typo MAKKAR DROPOINT is similar',     transpo_similarity('MAKKAR DROPOINT', 'MAKAR DROPOINT') >= TRANSPO_SIMILAR_THRESHOLD, true);
check('POLYTECHNIC vs POLYTECNIC similar',   transpo_similarity('POLYTECNIC', 'POLYTECHNIC') >= TRANSPO_SIMILAR_THRESHOLD, true);
check('SM vs ROBINSONS not similar',         transpo_similarity('SM', 'ROBINSONS') >= TRANSPO_SIMILAR_THRESHOLD, false);
check('UHAW vs MAKAR DROPOINT not similar',  transpo_similarity('UHAW DROPOINT', 'MAKAR DROPOINT') >= TRANSPO_SIMILAR_THRESHOLD, false);

echo "\nFares and tickets\n";
check('fare "20" -> 20.0',                   transpo_parse_fare('20'), 20.0);
check('fare "₱1,250.50" -> 1250.5',          transpo_parse_fare('₱1,250.50'), 1250.5);
check('zero / negative / text rejected',     [transpo_parse_fare('0'), transpo_parse_fare('-5'), transpo_parse_fare('abc'), transpo_parse_fare('1.234')], [null, null, null, null]);
check('ticket 260911-BSERV-0523 valid',      (bool) preg_match(TRANSPO_TICKET_PATTERN, '260911-BSERV-0523'), true);
check('ticket 26091-BSERV-0523 invalid',     (bool) preg_match(TRANSPO_TICKET_PATTERN, '26091-BSERV-0523'), false);

echo "\nImport parser\n";
require __DIR__ . '/../includes/transpo_import.php';
$notes = <<<TXT
OCT 3
260911-BSERV-0523 FARMACIA BEATRIZ 2026-10-03 09:12:30
NEAREST HIGHWAY FROM BASE TO POLYTECHNIC - tryke = 20
POLYTECHNIC TO MAKAR DROPOINT - TRYKE = 25
MAKAR DROPOINT  TO  MERCHANT - PUJ = 20
FROM MERCHANT TID: 260911-BSERV-0523 TO MERCHANT - HABAL = 50
260910-BSERV-0011 WANG ZI HARDWARE-GENSAN
10/03/2026 10:30:00
NATIONAL HIGHWAY, LAGAO
FROM MERCHANT TID: 260910-BSERV-0011 TO UHAW DROP POINT - habal-habal = 50
UHAW DROPOINT TO MAKAR DROPOINT - TRYKE = 25
MAKAR DROPOINT TO SM - JEEP = 20
SM TO MERCHANT - PUJ = 25
260922-BSERV-0018 BZ IT SOLUTIONS-GENSAN 2026-10-03 13:05:00
LATE FILLING - DUE ISMS PROBLEM
=====
260915-BSERV-0016 MISS YANG MALATANG 10/03/2026 3:54:41 PM
FROM MERCHANT TID: 0018 TO MERCHANT - PUJ = 25
TXT;
$p = transpo_import_parse($notes);
$t = $p['tickets'];
$sum = fn($x) => array_sum(array_column($x['legs'], 'fare'));
check('4 tickets parsed',                    array_column($t, 'ticket_no'), ['260911-BSERV-0523', '260910-BSERV-0011', '260922-BSERV-0018', '260915-BSERV-0016']);
check('subtotals 65 / 50 / 120 / 25',         array_map($sum, $t), [65.0, 50.0, 120.0, 25.0]);
check('first leg starts at base',             $t[0]['legs'][0]['from'], TRANSPO_IMPORT_BASE);
check('TO MERCHANT -> this ticket merchant',  $t[0]['legs'][2]['to'], TRANSPO_IMPORT_MERCHANT);
check('FROM MERCHANT TID -> that merchant',   $t[1]['legs'][0]['from'], 'FARMACIA BEATRIZ');
check('short TID (0018) resolves',            $t[3]['legs'][0]['from'], 'BZ IT SOLUTIONS-GENSAN');
check('modes: tryke / habal-habal / jeep',    [$t[0]['legs'][0]['mode'], $t[2]['legs'][0]['mode'], $t[2]['legs'][2]['mode']], ['TRYKE', 'HABAL', 'PUJ']);
check('date on its own line + address',       [$t[1]['visited_at'], $t[1]['address']], ['2026-10-03 10:30:00', 'NATIONAL HIGHWAY, LAGAO']);
check('12-hour time with PM',                 $t[3]['visited_at'], '2026-10-03 15:54:41');
check('remark attaches to the next ticket',   [$t[2]['remarks'], $t[3]['remarks']], ['', 'LATE FILLING - DUE ISMS PROBLEM']);
check('===== starts a new group',             $t[3]['group'], 2);
check('unknown line listed as unparsed',      $p['unparsed'], [[1, 'OCT 3']]);

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
