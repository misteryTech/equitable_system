<?php
/**
 * Email Generator: builds Service Request and ATW Request emails as text.
 * Nothing is sent — users copy the recipients, subject and body into Gmail.
 *
 * The building functions use no database and are covered by tests/email_generator_test.php.
 * assets/js/email-generator.js builds the same text in the browser for the live preview;
 * keep the two in step when changing a format.
 */

/* ---------- Shared ---------- */

const EMAIL_RECIPIENT_GROUPS = [
    'ATW'     => 'ATW Request',
    'SERVICE' => 'Service Request',
    'ALL'     => 'Both',
];

/**
 * Name used in emails: "First M. Last" (middle name shortened to its initial).
 */
function email_person_name(array $user): string
{
    $middle = trim((string) ($user['middle_name'] ?? ''));
    $parts  = [trim((string) $user['first_name']), $middle === '' ? '' : mb_strtoupper(mb_substr($middle, 0, 1)) . '.', trim((string) $user['last_name'])];
    return implode(' ', array_filter($parts, 'strlen'));
}

/**
 * One recipient as "Name <email>" (just the address when there is no name).
 * Names with characters that confuse mail apps are quoted.
 */
function email_recipient_format(string $name, string $email): string
{
    $name  = trim(preg_replace('/\s+/', ' ', $name));
    $email = trim($email);
    if ($name === '') {
        return $email;
    }
    if (preg_match('/[",;<>@()\[\]:]/', $name)) {
        $name = '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $name) . '"';
    }
    return "$name <$email>";
}

/**
 * Recipients line to paste into Gmail's "To": only checked entries
 * (missing 'checked' = checked), each address once, comma-separated.
 * $recipients: list of ['name', 'email', 'checked'].
 */
function email_recipients_string(array $recipients): string
{
    $seen = [];
    $out  = [];
    foreach ($recipients as $r) {
        $email = trim((string) ($r['email'] ?? ''));
        $key   = strtolower($email);
        if ($email === '' || (array_key_exists('checked', $r) && !$r['checked']) || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[]      = email_recipient_format((string) ($r['name'] ?? ''), $email);
    }
    return implode(', ', $out);
}

/**
 * Greeting line of the Formal service template for an hour of the day (0-23).
 */
function email_greeting(int $hour): string
{
    if ($hour < 12) {
        return 'Goodmorning';
    }
    return $hour < 18 ? 'Good afternoon' : 'Good evening';
}

/* ---------- Service Request ---------- */

const SERVICE_TEMPLATES = [
    'short'  => 'Short',
    'formal' => 'Formal',
];

// How a request type uses the text typed next to its checkbox.
const SERVICE_TEXT_MODES = [
    'none'    => 'No text box',
    'append'  => 'Text added after the label',
    'replace' => 'Text replaces the label',
];

// Ticket number, e.g. 261016-SERV-0123 (users may override a non-matching one).
const SERVICE_TN_PATTERN = '/^\d{6}-SERV-\d{4}$/';

// Template texts used when the settings row is missing (and in tests).
const SERVICE_DEFAULTS = [
    'short_greeting'  => 'Hi system, for assistance',
    'short_closing'   => 'Thanks and regards',
    'formal_greeting' => 'Hi Maam/Sir',
    'formal_intro'    => 'For assistance',
    'formal_closing'  => 'Thank you',
];

function service_tn_valid(string $tn): bool
{
    return (bool) preg_match(SERVICE_TN_PATTERN, $tn);
}

/**
 * The typed terminal details of a Service Request: spaces tidied, SN and TID
 * without spaces, SN in capitals. Returns model, sn, tid, merchant.
 */
function service_clean_fields(array $in): array
{
    $squash = static fn(string $key): string => trim(preg_replace('/\s+/', ' ', (string) ($in[$key] ?? '')));
    return [
        'model'    => $squash('model'),
        'sn'       => strtoupper(preg_replace('/\s+/', '', (string) ($in['sn'] ?? ''))),
        'tid'      => preg_replace('/\s+/', '', (string) ($in['tid'] ?? '')),
        'merchant' => $squash('merchant'),
    ];
}

/**
 * Suggestions for the generator forms from a user's own past requests (newest first).
 *   $service: rows with sn, tid, merchant (service_requests)
 *   $atw:     lists of merchants [{ticket, name, address}] (atw_requests.merchants)
 * Returns merchants, tids, sns (each listed once, max 200), by_tid (tid => {merchant, sn})
 * and addresses (merchant name => most recent address).
 */
function email_history_suggestions(array $service, array $atw): array
{
    $out  = ['merchants' => [], 'tids' => [], 'sns' => [], 'by_tid' => [], 'addresses' => []];
    $seen = ['merchants' => [], 'tids' => [], 'sns' => []];
    $add  = static function (string $list, string $value) use (&$out, &$seen): void {
        $key = mb_strtolower($value);
        if ($value !== '' && !isset($seen[$list][$key]) && count($out[$list]) < 200) {
            $seen[$list][$key] = true;
            $out[$list][]      = $value;
        }
    };
    foreach ($service as $row) {
        $merchant = trim((string) ($row['merchant'] ?? ''));
        $tid      = trim((string) ($row['tid'] ?? ''));
        $sn       = trim((string) ($row['sn'] ?? ''));
        $add('merchants', $merchant);
        $add('tids', $tid);
        $add('sns', $sn);
        if ($tid !== '' && !isset($out['by_tid'][$tid])) {
            $out['by_tid'][$tid] = ['merchant' => $merchant, 'sn' => $sn];
        }
    }
    foreach ($atw as $merchants) {
        foreach ((array) $merchants as $m) {
            $name    = trim((string) ($m['name'] ?? ''));
            $address = trim((string) ($m['address'] ?? ''));
            $add('merchants', $name);
            if ($name !== '' && $address !== '' && !isset($out['addresses'][mb_strtolower($name)])) {
                $out['addresses'][mb_strtolower($name)] = $address;
            }
        }
    }
    return $out;
}

/**
 * Word for each request type in the Service Request SUBJECT, keyed by the type's label
 * in lower case (labels are edited on Email Templates). A type not listed here uses
 * its label in capitals; "Other" (its text replaces the label) uses the typed text in capitals.
 * The Service Request page sends this table to the browser, so this is the only copy.
 */
const SERVICE_SUBJECT_ACTIONS = [
    'update to latest version' => 'VERSION UPDATE',
    'update to version'        => 'VERSION UPDATE',
    'transfer'                 => 'TRANSFER',
    'change sim to globe iot'  => 'SIM CHANGE',
    'change sim to smart'      => 'SIM CHANGE',
    'replacement'              => 'REPLACEMENT',
    'pull-out'                 => 'PULL OUT',
    'reprogram/reset'          => 'REPROGRAM',
];

/**
 * One checked request type plus its typed text, as
 * ['label' => wording for the Short template, 'phrase' => wording for the Formal sentence,
 *  'subject' => word for the subject line (SERVICE_SUBJECT_ACTIONS)].
 * $type needs label, phrase and text_mode (a row of email_request_types).
 */
function service_action(array $type, string $text = ''): array
{
    $text  = trim(preg_replace('/\s+/', ' ', $text));
    $mode  = $type['text_mode'] ?? 'none';
    $label = (string) $type['label'];
    $base  = trim(preg_replace('/\s+/', ' ', $label));
    if ($text !== '' && $mode === 'append') {
        $label .= ' ' . $text;
    } elseif ($text !== '' && $mode === 'replace') {
        $label = $text;
    }
    $phrase  = trim(preg_replace('/\s+/', ' ', str_replace('{text}', $mode === 'none' ? '' : $text, (string) $type['phrase'])));
    $subject = $mode === 'replace' && $text !== ''
        ? mb_strtoupper($text)
        : (SERVICE_SUBJECT_ACTIONS[mb_strtolower($base)] ?? mb_strtoupper($base));
    return ['label' => $label, 'phrase' => $phrase !== '' ? $phrase : $label, 'subject' => $subject];
}

/**
 * Service Request subject: "<device> <ACTIONS> TN: <tn> TID: <tid>", e.g.
 * "Castle v3 3g VERSION UPDATE TN: 261016-SERV-0071 TID: 10029702".
 * The device text is kept as typed; actions are joined with " / " in checkbox order
 * (each word once); an empty TN or TID leaves its part out.
 */
function service_subject(string $device, array $actions, string $tn, string $tid): string
{
    $words = [];
    foreach ($actions as $action) {
        $word = trim((string) ($action['subject'] ?? mb_strtoupper((string) ($action['label'] ?? ''))));
        if ($word !== '' && !in_array($word, $words, true)) {
            $words[] = $word;
        }
    }
    $tn  = trim($tn);
    $tid = trim($tid);
    $parts = [$device, implode(' / ', $words), $tn === '' ? '' : "TN: $tn", $tid === '' ? '' : "TID: $tid"];
    return trim(preg_replace('/\s+/', ' ', implode(' ', $parts)));
}
/**
 * The request sentence of the Formal template, e.g. "please update S1F2 to version 16.21".
 * The first {model} becomes the model and later ones "it"; when no phrase mentions
 * the model, " for <model>" is added at the end.
 */
function service_sentence(array $actions, string $model): string
{
    if (!$actions) {
        return '';
    }
    $model     = trim($model);
    $usedModel = false;
    $parts     = [];
    foreach ($actions as $action) {
        $phrase = $action['phrase'];
        if (strpos($phrase, '{model}') !== false) {
            $phrase    = str_replace('{model}', $usedModel ? 'it' : ($model !== '' ? $model : 'the terminal'), $phrase);
            $usedModel = true;
        }
        $parts[] = $phrase;
    }
    $last     = array_pop($parts);
    $sentence = $parts ? implode(', ', $parts) . ' and ' . $last : $last;
    if (!$usedModel && $model !== '') {
        $sentence .= ' for ' . $model;
    }
    return 'please ' . $sentence;
}

/**
 * Build a Service Request email.
 *   $in: template ('short' | 'formal'), model, sn, tid, merchant, tn, notes,
 *        name, area, mobile, actions (list of service_action() results),
 *        device (terminal name or model for the subject; the model when empty)
 *   $settings: template texts (see SERVICE_DEFAULTS)
 *   $hour: hour of day for the Formal greeting
 * Empty fields leave their line out. Returns ['subject', 'actions', 'body'].
 */
function service_email_build(array $in, array $settings, int $hour): array
{
    $v = static fn(string $key): string => trim(preg_replace('/[ \t]+/', ' ', (string) ($in[$key] ?? '')));
    $s = $settings + SERVICE_DEFAULTS;

    $actions = array_values($in['actions'] ?? []);
    $labels  = array_column($actions, 'label');
    $main    = $labels[0] ?? '';
    $model   = $v('model');
    $notes   = trim(str_replace(["\r\n", "\r"], "\n", (string) ($in['notes'] ?? '')));
    $field   = static fn(string $label, string $value): string => $value === '' ? '' : "$label: $value";

    if (($in['template'] ?? 'short') === 'formal') {
        $lines = [
            $s['formal_greeting'],
            email_greeting($hour),
            $s['formal_intro'],
            service_sentence($actions, $model),
            $field('Merchant', $v('merchant')),
            $field('TID', $v('tid')),
            $field('TN', $v('tn')),
            $notes,
            $s['formal_closing'],
            $v('area') === '' ? '' : 'F.E ' . mb_strtoupper($v('area')),
            $v('name'),
            $v('mobile'),
        ];
    } else {
        $lines = [
            $s['short_greeting'],
            $model !== '' && $main !== '' ? "$model for $main" : $model . $main,
            $field('SN', $v('sn')),
            $field('TN', $v('tn')),
            $field('TID', $v('tid')),
            $field('Merchant Name', $v('merchant')),
            implode(', ', array_slice($labels, 1)),
            $notes,
            $s['short_closing'],
            $v('name'),
        ];
    }

    return [
        'subject' => service_subject($v('device') !== '' ? $v('device') : $model, $actions, $v('tn'), $v('tid')),
        'actions' => implode(', ', $labels),
        'body'    => implode("\n", array_filter(array_map('trim', $lines), 'strlen')),
    ];
}

/* ---------- ATW Request ---------- */

// Ticket number, e.g. 261006-BPUL-0019 (users may override a non-matching one).
const ATW_TICKET_PATTERN = '/^\d{6}-[A-Z]{2,6}-\d{4}$/';

function atw_ticket_valid(string $ticket): bool
{
    return (bool) preg_match(ATW_TICKET_PATTERN, $ticket);
}

/**
 * 2026-10-07 -> "10-7-2026" (no leading zeros).
 */
function atw_date(string $ymd): string
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);
    return $d ? $d->format('n-j-Y') : $ymd;
}

/**
 * ATW Request subject: "<Merchant> ATW TN: <Ticket No>", e.g.
 * "COLUMBIA COMPUTER CENT ATW TN: 261006-BPUL-0019". Several merchants and tickets are
 * each joined with " / " (each listed once); names are kept as typed; an empty part is left out.
 */
function atw_subject(array $merchantNames, array $tickets): string
{
    $unique = static function (array $values): array {
        $out = [];
        foreach ($values as $value) {
            $value = trim(preg_replace('/\s+/', ' ', (string) $value));
            if ($value !== '' && !in_array(mb_strtolower($value), array_map('mb_strtolower', $out), true)) {
                $out[] = $value;
            }
        }
        return $out;
    };
    $tickets = $unique($tickets);
    $parts   = [implode(' / ', $unique($merchantNames)), 'ATW', $tickets ? 'TN: ' . implode(' / ', $tickets) : ''];
    return trim(preg_replace('/\s+/', ' ', implode(' ', $parts)));
}

/**
 * Build an ATW Request email.
 *   $in: merchants (list of ['ticket', 'name', 'address']), date_from, date_to (Y-m-d),
 *        fe_names (list), address, name, area
 * Returns ['subject', 'body'].
 */
function atw_email_build(array $in): array
{
    $clean = static fn($value): string => trim(preg_replace('/\s+/', ' ', (string) $value));

    $blocks  = [];
    $names   = [];
    $tickets = [];
    foreach ($in['merchants'] ?? [] as $m) {
        $lines = array_filter([$clean($m['ticket'] ?? ''), $clean($m['name'] ?? ''), $clean($m['address'] ?? '')], 'strlen');
        if ($lines) {
            $blocks[]  = implode("\n", $lines);
            $names[]   = $clean($m['name'] ?? '');
            $tickets[] = $clean($m['ticket'] ?? '');
        }
    }

    $from = atw_date($clean($in['date_from'] ?? ''));
    $to   = atw_date($clean($in['date_to'] ?? ''));
    $fes  = array_values(array_filter(array_map($clean, $in['fe_names'] ?? []), 'strlen'));
    $area = mb_strtoupper($clean($in['area'] ?? ''));
    $name = $clean($in['name'] ?? '');

    $lines = [
        "Hi ma'am, sir",
        implode("\n\n", $blocks),
        '',
        'Good day requesting ATW:',
        $from === '' ? '' : 'Scheduled: ' . ($to === '' || $to === $from ? $from : "$from to $to"),
        $fes ? 'Fe name : ' . implode(' , ', $fes) : '',
        $clean($in['address'] ?? '') === '' ? '' : 'Address: ' . $clean($in['address']),
        trim($name . ' - ' . ($area === '' ? 'FE' : "$area FE"), ' -'),
    ];
    // Drop empty lines, but keep the one blank line before "Good day requesting ATW:".
    $body = [];
    foreach ($lines as $i => $line) {
        if ($line !== '' || ($i === 2 && $blocks)) {
            $body[] = $line;
        }
    }

    return ['subject' => atw_subject($names, $tickets), 'body' => implode("\n", $body)];
}

/* ---------- Database ---------- */

/**
 * Service template texts (admin-editable on Email Templates).
 */
function service_settings(): array
{
    $row = db()->query('SELECT * FROM email_request_settings WHERE id = 1')->fetch();
    return array_intersect_key(($row ?: []) + SERVICE_DEFAULTS, SERVICE_DEFAULTS);
}

/**
 * Request types in display order.
 */
function service_request_types(bool $activeOnly = true): array
{
    return db()->query(
        'SELECT id, label, phrase, text_mode, text_hint, sort_order, is_active FROM email_request_types'
        . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, id'
    )->fetchAll();
}

/**
 * Turn posted checkbox ids and texts into actions (in display order).
 * Returns [actions, errors].
 */
function service_resolve_actions(array $types, array $checkedIds, array $texts): array
{
    $checkedIds = array_map('intval', $checkedIds);
    $actions = [];
    $errors  = [];
    foreach ($types as $type) {
        $id = (int) $type['id'];
        if (!in_array($id, $checkedIds, true)) {
            continue;
        }
        $text = trim((string) ($texts[$id] ?? ''));
        if ($type['text_mode'] !== 'none' && $text === '') {
            $errors[] = "Type the details for \"{$type['label']}\".";
        } elseif (mb_strlen($text) > 100) {
            $errors[] = "The details for \"{$type['label']}\" must be at most 100 characters.";
        }
        $actions[] = service_action($type, $text);
    }
    if (!$actions) {
        $errors[] = 'Choose at least one request type.';
    }
    return [$actions, $errors];
}

/**
 * email_history_suggestions() for one user, from their last 300 requests of each kind.
 */
function email_history_suggestions_for(int $userId): array
{
    $stmt = db()->prepare('SELECT sn, tid, merchant FROM service_requests WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 300');
    $stmt->execute([$userId]);
    $service = $stmt->fetchAll();
    $stmt = db()->prepare('SELECT merchants FROM atw_requests WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 300');
    $stmt->execute([$userId]);
    $atw = array_map(fn($json) => json_decode($json, true) ?: [], $stmt->fetchAll(PDO::FETCH_COLUMN));
    return email_history_suggestions($service, $atw);
}

/**
 * Recipients for a generator ('ATW' or 'SERVICE'), plus those tagged ALL:
 * active ones first in list order, then inactive ones.
 */
function email_recipients_for(string $group): array
{
    $stmt = db()->prepare(
        "SELECT id, display_name, email, group_tag, is_active, notes FROM email_recipients
         WHERE group_tag IN (?, 'ALL') ORDER BY is_active DESC, sort_order, id"
    );
    $stmt->execute([$group]);
    return $stmt->fetchAll();
}

/**
 * Check a posted recipients list (JSON from the recipients box) and return
 * [recipients string, errors]. Each entry: {name, email, checked}. An empty list is
 * allowed (the user may add recipients in Gmail).
 */
function email_recipients_from_post(string $json): array
{
    $list = json_decode($json, true);
    if (!is_array($list)) {
        return ['', ['The recipients list could not be read. Please reload the page.']];
    }
    $errors = [];
    $clean  = [];
    foreach (array_slice($list, 0, 100) as $r) {
        if (!is_array($r)) {
            continue;
        }
        $entry = [
            'name'    => mb_substr(trim((string) ($r['name'] ?? '')), 0, 100),
            'email'   => trim((string) ($r['email'] ?? '')),
            'checked' => !empty($r['checked']),
        ];
        if ($entry['checked'] && !filter_var($entry['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = "\"{$entry['email']}\" is not a valid email address.";
        }
        $clean[] = $entry;
    }
    return [email_recipients_string($clean), $errors];
}
