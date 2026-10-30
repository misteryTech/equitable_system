<?php
/**
 * Tests for terminal field checks and labels (no database needed).
 * Run: C:\xampp\php\php.exe tests\terminals_test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// terminals.php only needs e() from config.php for the functions under test.
function e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
require __DIR__ . '/../includes/terminals.php';

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

// Labels as terminal_labels() returns them: 1-3 seeded, 4 added by an admin, 5 deactivated.
$labels = [
    1 => ['id' => 1, 'name' => 'CBG-MP', 'is_active' => 1],
    2 => ['id' => 2, 'name' => 'CBG-AB', 'is_active' => 1],
    3 => ['id' => 3, 'name' => 'TBG',    'is_active' => 1],
    4 => ['id' => 4, 'name' => 'SME',    'is_active' => 1],
    5 => ['id' => 5, 'name' => 'OLD',    'is_active' => 0],
];
$good = ['terminal_name' => '  Superama   POS 1 ', 'brand' => 'PAX', 'model' => 'S1F2', 'current_version' => '16.20', 'label_id' => '3', 'bank_type' => 'bdo'];

echo "Valid terminal\n";
[$values, $errors] = terminal_validate($good, $labels);
check('no errors',                   $errors, []);
check('spaces tidied in the name',   $values['terminal_name'], 'Superama POS 1');
check('label id as a number',        $values['label_id'], 3);
check('optional model and version can be empty', terminal_validate(['model' => '', 'current_version' => ''] + $good, $labels)[1], []);

echo "\nRequired fields\n";
check('name missing',  terminal_validate(['terminal_name' => '  '] + $good, $labels)[1], ['Terminal name is required (up to 100 characters).']);
check('brand missing', terminal_validate(['brand' => ''] + $good, $labels)[1], ['Brand is required (up to 100 characters).']);
check('bank missing',  terminal_validate(['bank_type' => ''] + $good, $labels)[1], ['Please choose BDO or Non-BDO.']);
check('bank not in list', terminal_validate(['bank_type' => 'metrobank'] + $good, $labels)[1], ['Please choose BDO or Non-BDO.']);
check('Non-BDO accepted', terminal_validate(['bank_type' => 'non_bdo'] + $good, $labels)[1], []);
check('name too long', terminal_validate(['terminal_name' => str_repeat('A', 101)] + $good, $labels)[1], ['Terminal name is required (up to 100 characters).']);
check('model too long', terminal_validate(['model' => str_repeat('A', 51)] + $good, $labels)[1], ['Model must be at most 50 characters.']);
check('version too long', terminal_validate(['current_version' => str_repeat('1', 31)] + $good, $labels)[1], ['Current version must be at most 30 characters.']);

echo "\nLabels come from the table\n";
check('no label chosen',               terminal_validate(['label_id' => ''] + $good, $labels)[1], ['Please choose a label from the list.']);
check('label that does not exist',     terminal_validate(['label_id' => '99'] + $good, $labels)[1], ['Please choose a label from the list.']);
check('old ENUM text is not accepted', terminal_validate(['label_id' => 'TBG'] + $good, $labels)[1], ['Please choose a label from the list.']);
check('admin-added label accepted',    terminal_validate(['label_id' => '4'] + $good, $labels)[1], []);
check('inactive label refused for a new terminal', terminal_validate(['label_id' => '5'] + $good, $labels)[1], ['The label "OLD" is no longer in use. Please choose another label.']);
check('inactive label kept when editing a terminal that has it', terminal_validate(['label_id' => '5'] + $good, $labels, 5)[1], []);
check('inactive label refused when editing a terminal with another label', terminal_validate(['label_id' => '5'] + $good, $labels, 3)[1], ['The label "OLD" is no longer in use. Please choose another label.']);

echo "\nRemoved fields are ignored\n";
[$values] = terminal_validate($good + ['serial_number' => '2367KD8E6059', 'tid' => '22019744', 'merchant_name' => 'X', 'merchant_address' => 'Y', 'label' => 'TBG'], $labels);
check('only the kept fields are returned', array_keys($values), ['terminal_name', 'brand', 'model', 'current_version', 'label_id', 'bank_type', 'remarks']);

echo "\nLabel names\n";
check('clean: spaces tidied',  terminal_label_clean('  CBG   MP '), 'CBG MP');
check('empty name',            terminal_label_name_error(''), 'Label name is required.');
check('too long',              terminal_label_name_error(str_repeat('L', 51)), 'Label name must be at most 50 characters.');
check('50 characters is fine', terminal_label_name_error(str_repeat('L', 50)), null);

echo "\nLabel badges\n";
check('seeded labels keep their colours', [terminal_label_badge_class(1), terminal_label_badge_class(2), terminal_label_badge_class(3)], ['bg-primary', 'bg-info text-dark', 'bg-secondary']);
check('colours repeat after the list',    terminal_label_badge_class(1 + count(TERMINAL_LABEL_BADGES)), 'bg-primary');
check('badge text is escaped',            terminal_label_badge(4, '<b>X</b>'), '<span class="badge bg-success">&lt;b&gt;X&lt;/b&gt;</span>');

echo "\nRemarks\n";
[$values, $errors] = terminal_validate($good + ['remarks' => "  Line 1\r\nLine 2  "], $labels);
check('remarks: line breaks kept, ends trimmed', $values['remarks'], "Line 1\nLine 2");
check('remarks: optional',                       terminal_validate($good, $labels)[0]['remarks'], '');
[, $errors] = terminal_validate($good + ['remarks' => str_repeat('r', 2001)], $labels);
check('remarks: too long',                       $errors, ['Remarks must be at most 2000 characters.']);

echo "\nFAQs\n";
[$rows, $errors] = terminal_faq_validate([
    'faq_id'       => ['7', '', ''],
    'faq_question' => ['  How to   reset? ', '', 'New one?'],
    'faq_answer'   => ["Hold power\r\nfor 10s", '', 'Yes'],
]);
check('faq: no errors',                 $errors, []);
check('faq: empty rows skipped, order kept, ids kept', $rows, [
    ['faq_id' => 7, 'question' => 'How to reset?', 'answer' => "Hold power\nfor 10s"],
    ['faq_id' => 0, 'question' => 'New one?',      'answer' => 'Yes'],
]);
check('faq: none posted', terminal_faq_validate([]), [[], []]);
[, $errors] = terminal_faq_validate(['faq_question' => ['Q only', ''], 'faq_answer' => ['', 'A only']]);
check('faq: question and answer both required', $errors, ['FAQ 1: the answer is required.', 'FAQ 2: the question is required.']);
[, $errors] = terminal_faq_validate(['faq_question' => [str_repeat('q', 256)], 'faq_answer' => [str_repeat('a', 2001)]]);
check('faq: too long', $errors, ['FAQ 1: the question must be at most 255 characters.', 'FAQ 1: the answer must be at most 2000 characters.']);
[, $errors] = terminal_faq_validate(['faq_question' => array_fill(0, 31, 'Q'), 'faq_answer' => array_fill(0, 31, 'A')]);
check('faq: at most 30', $errors, ['A terminal can have at most 30 FAQs.']);
check('faq: negative id treated as new', terminal_faq_validate(['faq_id' => ['-3'], 'faq_question' => ['Q'], 'faq_answer' => ['A']])[0][0]['faq_id'], 0);
echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
