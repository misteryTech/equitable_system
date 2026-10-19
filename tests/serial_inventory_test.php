<?php
/**
 * Tests for the Serial Inventory cleaning rules (no database needed).
 * Run: C:\xampp\php\php.exe tests\serial_inventory_test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../includes/serial_inventory.php';

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

echo "Plain codes\n";
check('already clean',             clean_serial('2367KD8E6059'), '2367KD8E6059');
check('spaces around are trimmed', clean_serial("  2367KD8E6059 \r\n"), '2367KD8E6059');
check('lowercase -> uppercase',    clean_serial('2367kd8e6059'), '2367KD8E6059');
check('dashes and slashes kept',   clean_serial('pax-a920/0012.3'), 'PAX-A920/0012.3');

echo "\nSN labels\n";
check('SN:2367KD8E6059',           clean_serial('SN:2367KD8E6059'), '2367KD8E6059');
check('sn: with spaces',           clean_serial('  sn :  2367kd8e6059 '), '2367KD8E6059');
check('S/N 2367KD8E6059',          clean_serial('S/N 2367KD8E6059'), '2367KD8E6059');
check('SERIAL NO. 2367KD8E6059',   clean_serial('Serial No. 2367KD8E6059'), '2367KD8E6059');
check('Serial Number: …',          clean_serial('Serial Number: 2367KD8E6059'), '2367KD8E6059');
check('SERIAL#2367KD8E6059',       clean_serial('SERIAL#2367KD8E6059'), '2367KD8E6059');
check('sn= inside a URL',          clean_serial('https://example.com/device?model=DX8000&sn=2367kd8e6059&x=1'), '2367KD8E6059');
check('word starting with SN is not a label', clean_serial('SNACK 12345678'), '12345678');

echo "\nMulti-line QR text\n";
check('SN line among others',      clean_serial("MODEL: DX8000\nSN: 2367KD8E6059\nTID: 22019744"), '2367KD8E6059');
check('Windows line endings',      clean_serial("Ingenico\r\nS/N: 2367KD8E6059\r\n"), '2367KD8E6059');
check('no label: longest code with a digit', clean_serial("Ingenico\nDX8000\n2367KD8E6059"), '2367KD8E6059');
check('labelled TID / MODEL skipped', clean_serial("MODEL: DX8000-ABCDEFGH\nTID: 2201974455667\nP1234567"), 'P1234567');
check('GS1 separator splits rows', clean_serial("01234\x1DSN 2367KD8E6059"), '2367KD8E6059');

echo "\nNothing usable\n";
check('empty',                     clean_serial(''), null);
check('only spaces / new lines',   clean_serial("  \n\t "), null);
check('words without digits',      clean_serial('HELLO WORLD'), null);
check('too short',                 clean_serial('A1'), null);
check('SN label with no value',    clean_serial('SN:'), null);

echo "\nDates\n";
check('today is fine',             serial_date_valid('2026-10-19', '2026-10-19'), true);
check('future date rejected',      serial_date_valid('2026-10-20', '2026-10-19'), false);
check('impossible date rejected',  serial_date_valid('2026-02-30', '2026-10-19'), false);
check('bad format rejected',       serial_date_valid('10/19/2026', '2026-10-19'), false);

echo "\nRegister and check (in-memory SQLite copy of serial_inventory)\n";
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE serial_inventory (id INTEGER PRIMARY KEY AUTOINCREMENT, serial VARCHAR(100) NOT NULL UNIQUE, registered_date DATE NOT NULL)');

$first = register_serial($pdo, clean_serial('SN: 2367kd8e6059'), '2026-10-01');
check('register new -> registered',        [$first['status'], $first['serial'], $first['date']], ['registered', '2367KD8E6059', '2026-10-01']);
$again = register_serial($pdo, clean_serial("MODEL: DX8000\nS/N 2367KD8E6059"), '2026-10-19');
check('register duplicate -> exists',      [$again['status'], $again['serial']], ['exists', '2367KD8E6059']);
check('duplicate keeps the first date',    $again['date'], '2026-10-01');
check('duplicate points at the same row',  $again['id'], $first['id']);
check('duplicate is not saved twice',      (int) $pdo->query("SELECT COUNT(*) FROM serial_inventory")->fetchColumn(), 1);

$found = check_serial($pdo, '2367KD8E6059');
check('check found -> exists with date',   [$found['status'], $found['date']], ['exists', '2026-10-01']);
$missing = check_serial($pdo, 'ZZ0000001');
check('check not found -> not_found',      [$missing['status'], $missing['date'], $missing['id']], ['not_found', null, null]);
check('check saves nothing',               (int) $pdo->query("SELECT COUNT(*) FROM serial_inventory")->fetchColumn(), 1);

register_serial($pdo, 'PAX-A920-0001', '2026-10-05');
check('bulk lookup returns only existing serials', serial_dates($pdo, ['2367KD8E6059', 'NOPE123', 'PAX-A920-0001', '2367KD8E6059']),
    ['2367KD8E6059' => '2026-10-01', 'PAX-A920-0001' => '2026-10-05']);
check('bulk lookup of nothing',            serial_dates($pdo, []), []);

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
