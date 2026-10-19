<?php
/**
 * Tests for the Email Generator builders (no database needed).
 * Run: C:\xampp\php\php.exe tests\email_generator_test.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../includes/email_generator.php';

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

// Same request types as the migration seeds.
$types = [
    'transfer' => ['label' => 'Transfer',                 'phrase' => 'transfer {model}',                     'text_mode' => 'none'],
    'latest'   => ['label' => 'Update to latest version', 'phrase' => 'update {model} to the latest version', 'text_mode' => 'none'],
    'version'  => ['label' => 'Update to version',        'phrase' => 'update {model} to version {text}',     'text_mode' => 'append'],
    'globe'    => ['label' => 'Change SIM to Globe IOT',  'phrase' => 'change the SIM to Globe IOT',          'text_mode' => 'none'],
    'other'    => ['label' => 'Other',                    'phrase' => '{text}',                               'text_mode' => 'replace'],
];
$act = static fn(string $key, string $text = ''): array => service_action($types[$key], $text);

$terminal = ['model' => 'DX8000', 'sn' => '2367KD8E6059', 'tid' => '22019744', 'merchant' => 'Converse Veranza', 'tn' => '261016-SERV-0123'];
$engineer = ['name' => 'Juan Dela Cruz', 'area' => 'Cotabato City', 'mobile' => '09171234567'];

echo "Service: Short template\n";
$short = service_email_build($terminal + $engineer + [
    'template' => 'short',
    'actions'  => [$act('transfer'), $act('latest'), $act('globe')],
], SERVICE_DEFAULTS, 9);
check('body like the first sample', $short['body'], implode("\n", [
    'Hi system, for assistance',
    'DX8000 for Transfer',
    'SN: 2367KD8E6059',
    'TN: 261016-SERV-0123',
    'TID: 22019744',
    'Merchant Name: Converse Veranza',
    'Update to latest version, Change SIM to Globe IOT',
    'Thanks and regards',
    'Juan Dela Cruz',
]));
check('subject: device ACTIONS TN TID', $short['subject'], 'DX8000 TRANSFER / VERSION UPDATE / SIM CHANGE TN: 261016-SERV-0123 TID: 22019744');
check('combined actions',                   $short['actions'], 'Transfer, Update to latest version, Change SIM to Globe IOT');

echo "\nService: Formal template\n";
$formal = service_email_build([
    'template' => 'formal', 'model' => 'S1F2', 'tid' => '31812022', 'merchant' => 'Superama Supermart',
    'tn' => '261003-SERV-0035', 'actions' => [$act('version', '16.21')],
] + $engineer, SERVICE_DEFAULTS, 8);
check('body like the second sample', $formal['body'], implode("\n", [
    'Hi Maam/Sir',
    'Goodmorning',
    'For assistance',
    'please update S1F2 to version 16.21',
    'Merchant: Superama Supermart',
    'TID: 31812022',
    'TN: 261003-SERV-0035',
    'Thank you',
    'F.E COTABATO CITY',
    'Juan Dela Cruz',
    '09171234567',
]));
check('Update to version X -> VERSION UPDATE', $formal['subject'], 'S1F2 VERSION UPDATE TN: 261003-SERV-0035 TID: 31812022');

echo "\nService: combined actions\n";
check('three actions: model once, then "it"',
    service_sentence([$act('transfer'), $act('latest'), $act('version', '16.21')], 'DX8000'),
    'please transfer DX8000, update it to the latest version and update it to version 16.21');
check('no phrase mentions the model -> "for <model>"',
    service_sentence([$act('globe')], 'S1F2'), 'please change the SIM to Globe IOT for S1F2');
check('model only in a later phrase',
    service_sentence([$act('globe'), $act('transfer')], 'S1F2'), 'please change the SIM to Globe IOT and transfer S1F2');
check('Other replaces the label with the text', $act('other', 'Replace  the  printer cover'),
    ['label' => 'Replace the printer cover', 'phrase' => 'Replace the printer cover', 'subject' => 'REPLACE THE PRINTER COVER']);
check('one action: no extra actions line in Short',
    substr_count(service_email_build($terminal + ['template' => 'short', 'actions' => [$act('transfer')]], SERVICE_DEFAULTS, 9)['body'], 'Transfer'), 1);

echo "\nService: missing optional fields\n";
$bare = service_email_build([
    'template' => 'formal', 'model' => 'S1F2', 'tid' => '31812022', 'merchant' => 'Superama Supermart',
    'tn' => '', 'name' => 'Juan Dela Cruz', 'area' => '', 'mobile' => '', 'actions' => [$act('transfer')],
], SERVICE_DEFAULTS, 8);
check('no TN / area / mobile lines', $bare['body'], implode("\n", [
    'Hi Maam/Sir', 'Goodmorning', 'For assistance', 'please transfer S1F2',
    'Merchant: Superama Supermart', 'TID: 31812022', 'Thank you', 'Juan Dela Cruz',
]));
$noModel = service_email_build(['template' => 'short', 'tid' => '22019744', 'actions' => [$act('transfer')], 'name' => 'Juan'], SERVICE_DEFAULTS, 9);
check('Short without model or SN', $noModel['body'], "Hi system, for assistance\nTransfer\nTID: 22019744\nThanks and regards\nJuan");
check('subject without device or TN', $noModel['subject'], 'TRANSFER TID: 22019744');
check('Formal without model says "the terminal"', service_sentence([$act('transfer')], ''), 'please transfer the terminal');
check('notes go before the closing',
    service_email_build($terminal + ['template' => 'short', 'actions' => [$act('transfer')], 'notes' => "Merchant closes at 5PM\r\nCall first", 'name' => 'Juan'], SERVICE_DEFAULTS, 9)['body'],
    "Hi system, for assistance\nDX8000 for Transfer\nSN: 2367KD8E6059\nTN: 261016-SERV-0123\nTID: 22019744\nMerchant Name: Converse Veranza\nMerchant closes at 5PM\nCall first\nThanks and regards\nJuan");
check('admin-edited closing is used',
    substr(service_email_build(['template' => 'short', 'actions' => [$act('transfer')], 'name' => 'Juan'], ['short_closing' => 'Salamat'] + SERVICE_DEFAULTS, 9)['body'], -12),
    "Salamat\nJuan");

echo "\nService subject\n";
$castle = ['template' => 'short', 'model' => 'V3', 'device' => 'Castle v3 3g', 'tn' => '261016-SERV-0071', 'tid' => '10029702'];
check('reference: Castle v3 3g + Update to latest',
    service_email_build($castle + ['actions' => [$act('latest')]], SERVICE_DEFAULTS, 9)['subject'],
    'Castle v3 3g VERSION UPDATE TN: 261016-SERV-0071 TID: 10029702');
check('device text kept as typed (no case change)',
    service_email_build(['device' => 'castle V3 3G'] + $castle + ['actions' => [$act('transfer')]], SERVICE_DEFAULTS, 9)['subject'],
    'castle V3 3G TRANSFER TN: 261016-SERV-0071 TID: 10029702');
check('empty terminal name -> model is used',
    service_email_build(['device' => ''] + $castle + ['actions' => [$act('latest')]], SERVICE_DEFAULTS, 9)['subject'],
    'V3 VERSION UPDATE TN: 261016-SERV-0071 TID: 10029702');
check('multiple actions joined with " / " in checkbox order',
    service_subject('Castle v3 3g', [$act('transfer'), $act('latest')], '', ''), 'Castle v3 3g TRANSFER / VERSION UPDATE');
check('same word listed once (latest + version X)',
    service_subject('S1F2', [$act('latest'), $act('version', '16.21'), $act('globe')], '', ''), 'S1F2 VERSION UPDATE / SIM CHANGE');
check('missing TID -> ends after TN',
    service_subject('Castle v3 3g', [$act('latest')], '261016-SERV-0071', ''), 'Castle v3 3g VERSION UPDATE TN: 261016-SERV-0071');
check('missing TN -> only TID',
    service_subject('Castle v3 3g', [$act('latest')], '', '10029702'), 'Castle v3 3g VERSION UPDATE TID: 10029702');
check('Other [text] is uppercased',
    service_subject('S1F2', [$act('other', 'Replace  printer cover')], '', ''), 'S1F2 REPLACE PRINTER COVER');
check('spaces collapsed and trimmed, no dashes or commas',
    service_subject('  Castle   v3  3g ', [$act('transfer')], ' 261016-SERV-0071 ', ''), 'Castle v3 3g TRANSFER TN: 261016-SERV-0071');
check('every mapped type', array_map(fn($label) => service_action(['label' => $label, 'phrase' => 'x', 'text_mode' => 'none'])['subject'],
    ['Transfer', 'Update to latest version', 'Change SIM to Globe IOT', 'Change SIM to Smart', 'Replacement', 'Pull-out', 'Reprogram/Reset', 'Inspect unit']),
    ['TRANSFER', 'VERSION UPDATE', 'SIM CHANGE', 'SIM CHANGE', 'REPLACEMENT', 'PULL OUT', 'REPROGRAM', 'INSPECT UNIT']);

echo "\nTime-of-day greeting\n";
check('00:00 -> Goodmorning',    email_greeting(0), 'Goodmorning');
check('11:00 -> Goodmorning',    email_greeting(11), 'Goodmorning');
check('12:00 -> Good afternoon', email_greeting(12), 'Good afternoon');
check('17:00 -> Good afternoon', email_greeting(17), 'Good afternoon');
check('18:00 -> Good evening',   email_greeting(18), 'Good evening');
check('23:00 -> Good evening',   email_greeting(23), 'Good evening');
check('Formal at 15:00 uses Good afternoon', explode("\n", service_email_build(['template' => 'formal', 'actions' => [$act('transfer')]], SERVICE_DEFAULTS, 15)['body'])[1], 'Good afternoon');

echo "\nTicket numbers\n";
check('service 261016-SERV-0123 valid',   service_tn_valid('261016-SERV-0123'), true);
check('service 26101-SERV-0123 invalid',  service_tn_valid('26101-SERV-0123'), false);
check('service 261016-BSERV-0123 invalid', service_tn_valid('261016-BSERV-0123'), false);
check('ATW 261006-BPUL-0019 valid',       atw_ticket_valid('261006-BPUL-0019'), true);
check('ATW 261006-BPUL-019 invalid',      atw_ticket_valid('261006-BPUL-019'), false);

echo "\nATW Request\n";
check('date without leading zeros', [atw_date('2026-10-07'), atw_date('2026-01-02'), atw_date('2026-12-12')], ['10-7-2026', '1-2-2026', '12-12-2026']);
$atwIn = [
    'merchants' => [['ticket' => '261006-BPUL-0019', 'name' => 'COLUMBIA COMPUTER CENT', 'address' => 'SM CITY SANTIAGO BLVD COR']],
    'date_from' => '2026-10-07', 'date_to' => '2026-10-12',
    'fe_names'  => ['Reymark S. Escalante', 'Crispin Tapec', 'Rodel Delay Cruz'],
    'address'   => 'C/O 3F BDO PIONEER, Pioneer Ave, General Santos City',
    'name'      => 'Reymark S. Escalante', 'area' => 'Gensan',
];
$atw = atw_email_build($atwIn);
check('body like the ATW sample (multiple FEs)', $atw['body'], implode("\n", [
    "Hi ma'am, sir",
    '261006-BPUL-0019',
    'COLUMBIA COMPUTER CENT',
    'SM CITY SANTIAGO BLVD COR',
    '',
    'Good day requesting ATW:',
    'Scheduled: 10-7-2026 to 10-12-2026',
    'Fe name : Reymark S. Escalante , Crispin Tapec , Rodel Delay Cruz',
    'Address: C/O 3F BDO PIONEER, Pioneer Ave, General Santos City',
    'Reymark S. Escalante - GENSAN FE',
]));
check('ATW subject: Merchant ATW TN: ticket', $atw['subject'], 'COLUMBIA COMPUTER CENT ATW TN: 261006-BPUL-0019');
check('ATW subject: merchant name kept as typed', atw_subject(['Columbia  Computer Cent'], ['261006-BPUL-0019']), 'Columbia Computer Cent ATW TN: 261006-BPUL-0019');
check('ATW subject: no ticket -> no "TN:"', atw_subject(['COLUMBIA COMPUTER CENT'], ['']), 'COLUMBIA COMPUTER CENT ATW');
check('ATW subject: no merchant name', atw_subject([''], ['261006-BPUL-0019']), 'ATW TN: 261006-BPUL-0019');
check('ATW subject: same merchant twice listed once', atw_subject(['SUPERAMA', 'superama'], ['261006-BPUL-0020', '261006-BPUL-0021']), 'SUPERAMA ATW TN: 261006-BPUL-0020 / 261006-BPUL-0021');

$two = atw_email_build(['merchants' => [
    $atwIn['merchants'][0],
    ['ticket' => '261006-BPUL-0020', 'name' => 'SUPERAMA SUPERMART', 'address' => ''],
]] + $atwIn);
check('multiple merchants: blocks separated by a blank line',
    implode("\n", array_slice(explode("\n", $two['body']), 0, 8)),
    "Hi ma'am, sir\n261006-BPUL-0019\nCOLUMBIA COMPUTER CENT\nSM CITY SANTIAGO BLVD COR\n\n261006-BPUL-0020\nSUPERAMA SUPERMART\n");
check('multiple merchants: names and tickets joined with " / "', $two['subject'], 'COLUMBIA COMPUTER CENT / SUPERAMA SUPERMART ATW TN: 261006-BPUL-0019 / 261006-BPUL-0020');
check('one FE, same-day schedule, no area',
    array_slice(explode("\n", atw_email_build(['fe_names' => ['Crispin Tapec'], 'date_from' => '2026-10-07', 'date_to' => '2026-10-07', 'area' => '', 'name' => 'Crispin Tapec', 'address' => ''] + $atwIn)['body']), -3),
    ['Scheduled: 10-7-2026', 'Fe name : Crispin Tapec', 'Crispin Tapec - FE']);

echo "\nRecipients\n";
check('unchecked skipped, extra added, duplicates once', email_recipients_string([
    ['name' => 'ECS POS HelpDesk', 'email' => 'poshelpdesk@equicom.com', 'checked' => true],
    ['name' => 'BDO', 'email' => 'cashagad-helpdesk@bdo.com.ph', 'checked' => false],
    ['name' => 'ECS POS Helpdesk Group', 'email' => 'ecsposhelpdeskgroup@equicom.com', 'checked' => true],
    ['name' => '', 'email' => 'extra.person@example.com', 'checked' => true],
    ['name' => 'Again', 'email' => 'POSHELPDESK@equicom.com', 'checked' => true],
]), 'ECS POS HelpDesk <poshelpdesk@equicom.com>, ECS POS Helpdesk Group <ecsposhelpdeskgroup@equicom.com>, extra.person@example.com');
check('name with a comma is quoted', email_recipient_format('Dela Cruz, Juan', 'juan@example.com'), '"Dela Cruz, Juan" <juan@example.com>');
check('nothing checked -> empty', email_recipients_string([['name' => 'A', 'email' => 'a@example.com', 'checked' => false]]), '');

echo "\nTyped terminal details (SN, TID, merchant are no longer on terminals)\n";
check('SN in capitals without spaces, TID without spaces', service_clean_fields(['model' => ' S1F2 ', 'sn' => ' 2367 kd8e6059 ', 'tid' => '2201 9744', 'merchant' => ' Superama   Supermart ']),
    ['model' => 'S1F2', 'sn' => '2367KD8E6059', 'tid' => '22019744', 'merchant' => 'Superama Supermart']);
check('missing fields become empty', service_clean_fields([]), ['model' => '', 'sn' => '', 'tid' => '', 'merchant' => '']);

echo "\nSuggestions from past requests\n";
$sug = email_history_suggestions(
    [
        ['sn' => '2367KD8E6059', 'tid' => '22019744', 'merchant' => 'Converse Veranza'],
        ['sn' => 'ZZSN0002', 'tid' => '31812022', 'merchant' => 'Superama Supermart'],
        ['sn' => 'OLDSN', 'tid' => '22019744', 'merchant' => 'converse veranza'],   // older, same TID
        ['sn' => '', 'tid' => '', 'merchant' => ''],
    ],
    [
        [['ticket' => '261006-BPUL-0019', 'name' => 'COLUMBIA COMPUTER CENT', 'address' => 'SM CITY SANTIAGO BLVD COR']],
        [['ticket' => '261001-BPUL-0001', 'name' => 'Columbia Computer Cent', 'address' => 'OLD ADDRESS'], ['ticket' => 'X', 'name' => 'Superama Supermart', 'address' => '']],
    ]
);
check('merchants listed once, newest spelling first', $sug['merchants'], ['Converse Veranza', 'Superama Supermart', 'COLUMBIA COMPUTER CENT']);
check('TIDs listed once',                $sug['tids'], ['22019744', '31812022']);
check('a TID fills its newest merchant and SN', $sug['by_tid']['22019744'], ['merchant' => 'Converse Veranza', 'sn' => '2367KD8E6059']);
check('merchant address = most recent non-empty', $sug['addresses'], ['columbia computer cent' => 'SM CITY SANTIAGO BLVD COR']);
check('no history -> empty lists', email_history_suggestions([], []), ['merchants' => [], 'tids' => [], 'sns' => [], 'by_tid' => [], 'addresses' => []]);

echo "\nNames in emails\n";
check('First M. Last',          email_person_name(['first_name' => 'Reymark', 'middle_name' => 'santos', 'last_name' => 'Escalante']), 'Reymark S. Escalante');
check('no middle name',         email_person_name(['first_name' => 'Crispin', 'middle_name' => null, 'last_name' => 'Tapec']), 'Crispin Tapec');

echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
