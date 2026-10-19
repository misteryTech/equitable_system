<?php
/**
 * Serial Inventory: a register of serial numbers read from QR codes / barcodes on
 * terminal and device labels. Each serial is stored once, with the date it was first
 * registered; nothing else is kept.
 *   clean_serial()    scanned or typed text -> serial (no database)
 *   register_serial() save a new serial, or report that it already exists
 *   check_serial()    look a serial up without saving
 * The database functions take the PDO connection, so tests can use SQLite
 * (tests/serial_inventory_test.php).
 */

const SERIAL_MAX_LENGTH = 100;

// Letters, digits and - / . _ ; 3 to 100 characters, starting with a letter or digit.
const SERIAL_PATTERN = '/^[A-Z0-9][A-Z0-9\-\/._]{2,99}$/';

// Values with these labels are never the serial (e.g. "TID: 22019744" on the same QR).
const SERIAL_OTHER_LABELS = ['TID', 'MID', 'MODEL', 'MODEL NO', 'TN', 'PN', 'P/N', 'PART NO', 'DATE', 'MFG', 'MFD', 'REV', 'VER', 'VERSION', 'MERCHANT'];

function serial_valid(string $serial): bool
{
    return (bool) preg_match(SERIAL_PATTERN, $serial);
}

/**
 * Turn scanned or typed text into a serial number, or null when none is found.
 *   "  2367kd8e6059 "                      -> 2367KD8E6059
 *   "SN:2367KD8E6059", "S/N 2367KD8E6059"  -> 2367KD8E6059
 *   "MODEL: DX8000\nSN: 2367KD8E6059\nTID: 22019744" -> 2367KD8E6059
 *   "https://example.com/d?sn=2367KD8E6059" -> 2367KD8E6059
 * Without an SN label, the longest serial-like word with a digit is used
 * (values labelled TID, MODEL… are skipped).
 */
function clean_serial(string $raw): ?string
{
    // One line per row; control characters (e.g. GS1 separators) split rows too.
    $text = str_replace(["\r\n", "\r"], "\n", $raw);
    $text = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]+/', "\n", $text);
    $text = strtoupper(trim($text));
    if ($text === '') {
        return null;
    }

    // 1. Whole text is already a serial.
    if (serial_valid($text)) {
        return $text;
    }

    // 2. A labelled value: SN, S/N, SERIAL, SERIAL NO., SERIAL NUMBER, SERIAL#, sn= in a URL.
    $label = '(?<![A-Z0-9])(?:S\/N|SN|SERIAL(?:\s*(?:NO|NUMBER|NUM)\.?|\s*#)?)';
    if (preg_match_all('/' . $label . '(?:\s*[:#=]\s*|\s+)([A-Z0-9][A-Z0-9\-\/._]*)/', $text, $matches)) {
        foreach ($matches[1] as $value) {
            $value = rtrim($value, '.-/_');
            if (serial_valid($value)) {
                return $value;
            }
        }
    }

    // 3. Longest serial-like word that contains a digit, skipping values with other labels.
    $best = null;
    foreach (explode("\n", $text) as $line) {
        $line = trim($line);
        if (preg_match('/^([A-Z][A-Z \/._]*?)\s*[:=]\s*(.*)$/', $line, $kv)) {
            if (in_array(trim($kv[1]), SERIAL_OTHER_LABELS, true)) {
                continue;
            }
            $line = $kv[2];
        }
        foreach (preg_split('/[\s,;|]+/', $line, -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $word = trim($word, '.-/_:');
            if (serial_valid($word) && preg_match('/\d/', $word) && ($best === null || strlen($word) > strlen($best))) {
                $best = $word;
            }
        }
    }
    return $best;
}

/**
 * A registered date: valid Y-m-d, not before 2000 and not after $today.
 */
function serial_date_valid(string $ymd, string $today): bool
{
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $ymd);
    return $d !== false && $d->format('Y-m-d') === $ymd && $ymd >= '2000-01-01' && $ymd <= $today;
}

/* ---------- Database ---------- */

/**
 * Look a serial up without saving.
 * Returns ['status' => 'exists', 'serial', 'date', 'id'] or ['status' => 'not_found', 'serial', 'date' => null, 'id' => null].
 */
function check_serial(PDO $pdo, string $serial): array
{
    $stmt = $pdo->prepare('SELECT id, registered_date FROM serial_inventory WHERE serial = ?');
    $stmt->execute([$serial]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row
        ? ['status' => 'exists', 'serial' => $serial, 'date' => $row['registered_date'], 'id' => (int) $row['id']]
        : ['status' => 'not_found', 'serial' => $serial, 'date' => null, 'id' => null];
}

/**
 * Register a serial with $date (Y-m-d). An existing serial is not saved again.
 * Returns ['status' => 'registered' | 'exists', 'serial', 'date', 'id'];
 * for 'exists', date is the date it was first registered.
 */
function register_serial(PDO $pdo, string $serial, string $date): array
{
    $found = check_serial($pdo, $serial);
    if ($found['status'] === 'exists') {
        return $found;
    }
    try {
        $pdo->prepare('INSERT INTO serial_inventory (serial, registered_date) VALUES (?, ?)')->execute([$serial, $date]);
    } catch (PDOException $ex) {
        // Someone registered it a moment ago (UNIQUE on serial).
        if ($ex->getCode() !== '23000') {
            throw $ex;
        }
        return check_serial($pdo, $serial);
    }
    return ['status' => 'registered', 'serial' => $serial, 'date' => $date, 'id' => (int) $pdo->lastInsertId()];
}

/**
 * Registered dates for many serials at once: [serial => date]. Missing serials are left out.
 */
function serial_dates(PDO $pdo, array $serials): array
{
    $dates = [];
    foreach (array_chunk(array_values(array_unique($serials)), 500) as $chunk) {
        $stmt = $pdo->prepare('SELECT serial, registered_date FROM serial_inventory WHERE serial IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')');
        $stmt->execute($chunk);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $dates[strtoupper($row['serial'])] = $row['registered_date'];
        }
    }
    return $dates;
}
