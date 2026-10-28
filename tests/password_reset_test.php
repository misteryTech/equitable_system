<?php
/**
 * Tests for the password rules and the generated passwords (no database needed).
 * Run: C:\xampp\php\php.exe tests\password_reset_test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../includes/password_reset.php';

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

echo "Password rules\n";
check('good password',          password_policy_error('Field2026'), null);
check('too short',              password_policy_error('Ab12345'), 'The password must be at least 8 characters.');
check('too long',               password_policy_error(str_repeat('a1', 37)), 'The password must be at most 72 characters.');
check('letters only',           password_policy_error('password'), 'The password needs at least one letter and one number.');
check('digits only',            password_policy_error('12345678'), 'The password needs at least one letter and one number.');
check('empty',                  password_policy_error(''), 'The password must be at least 8 characters.');

echo "\nGenerated passwords\n";
$samples = array_map(fn() => password_generate(), range(1, 200));
check('10 characters by default',            array_unique(array_map('strlen', $samples)), [10]);
check('all pass the password rules',         array_filter(array_map('password_policy_error', $samples)), []);
check('lower-case, capital and digit in each', count(array_filter($samples, fn($p) => preg_match('/[a-z]/', $p) && preg_match('/[A-Z]/', $p) && preg_match('/\d/', $p))), 200);
check('no look-alike characters (0 O 1 l I)', count(array_filter($samples, fn($p) => preg_match('/[0O1lI]/', $p))), 0);
check('different every time',                count(array_unique($samples)), 200);
check('never shorter than the minimum',      strlen(password_generate(4)), PASSWORD_MIN_LENGTH);

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
