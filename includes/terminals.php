<?php
/**
 * Terminal registration helpers: labels (terminal_labels table), bank types,
 * field checks and terminal images.
 * Include after includes/config.php (which loads profile_picture.php, used
 * here for the shared image checks). terminal_validate() and
 * terminal_label_name_error() use no database (tests/terminals_test.php).
 */

const TERMINAL_LABEL_MAX = 50;   // characters in a label name
const TERMINAL_BANKS  = [
    'bdo'     => 'BDO',
    'non_bdo' => 'Non-BDO',
];

const TERMINAL_IMAGE_DIR     = __DIR__ . '/../uploads/terminals';
const TERMINAL_IMAGE_URL     = 'uploads/terminals';
const TERMINAL_IMAGE_DEFAULT = 'assets/img/terminal-default.svg';
const TERMINAL_IMAGE_MAX     = 1000;   // longest side in pixels after saving
const TERMINAL_MAX_PHOTOS    = 3;      // photo slots per terminal

const TERMINAL_REMARKS_MAX      = 2000;   // characters
const TERMINAL_FAQ_MAX          = 30;     // FAQs per terminal
const TERMINAL_FAQ_QUESTION_MAX = 255;
const TERMINAL_FAQ_ANSWER_MAX   = 2000;

/**
 * Photos for the given terminals: [terminal_id => [slot => ['photo_id', 'file', 'url']]].
 */
function terminal_photos(array $terminalIds): array
{
    $terminalIds = array_values(array_filter(array_map('intval', $terminalIds)));
    if (!$terminalIds) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($terminalIds), '?'));
    $stmt = db()->prepare("SELECT photo_id, terminal_id, slot, file FROM terminal_photos WHERE terminal_id IN ($placeholders) ORDER BY slot");
    $stmt->execute($terminalIds);

    $photos = [];
    foreach ($stmt->fetchAll() as $row) {
        $photos[(int) $row['terminal_id']][(int) $row['slot']] = [
            'photo_id' => (int) $row['photo_id'],
            'file'     => $row['file'],
            'url'      => terminal_image_url($row['file']),
        ];
    }
    return $photos;
}

/**
 * Check an uploaded terminal image (same rules as profile pictures:
 * JPG/PNG/WEBP/GIF, up to 5 MB). Returns [error or null, has file].
 */
function terminal_image_check(?array $file): array
{
    [$error, $hasFile] = profile_picture_check($file);
    return [$error ? str_replace(['Profile picture', 'profile picture'], ['Terminal image', 'terminal image'], $error) : null, $hasFile];
}

/**
 * Store a checked upload and return its file name. With GD the image is fixed
 * for phone rotation, shrunk to fit 1000 x 1000 (not cropped) and saved as JPEG.
 * Without GD the checked original is stored as-is.
 */
function terminal_image_store(array $file): string
{
    if (!is_dir(TERMINAL_IMAGE_DIR)) {
        mkdir(TERMINAL_IMAGE_DIR, 0755, true);
    }
    $name = bin2hex(random_bytes(16));

    if (!function_exists('imagecreatefromstring')) {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $name .= '.' . PROFILE_PICTURE_TYPES[$mime];
        if (!move_uploaded_file($file['tmp_name'], TERMINAL_IMAGE_DIR . '/' . $name)) {
            throw new RuntimeException('Could not save the terminal image.');
        }
        return $name;
    }

    $source = imagecreatefromstring(file_get_contents($file['tmp_name']));
    if ($source === false) {
        throw new RuntimeException('Could not read the terminal image.');
    }
    if (function_exists('exif_read_data')) {
        $exif = @exif_read_data($file['tmp_name']);
        $rotate = [3 => 180, 6 => -90, 8 => 90][$exif['Orientation'] ?? 1] ?? 0;
        if ($rotate) {
            $source = imagerotate($source, $rotate, 0);
        }
    }

    $width  = imagesx($source);
    $height = imagesy($source);
    $scale  = min(1, TERMINAL_IMAGE_MAX / max($width, $height));
    $newW   = max(1, (int) round($width * $scale));
    $newH   = max(1, (int) round($height * $scale));

    $canvas = imagecreatetruecolor($newW, $newH);
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
    imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newW, $newH, $width, $height);

    $name .= '.jpg';
    $saved = imagejpeg($canvas, TERMINAL_IMAGE_DIR . '/' . $name, 85);
    imagedestroy($source);
    imagedestroy($canvas);
    if (!$saved) {
        throw new RuntimeException('Could not save the terminal image.');
    }
    return $name;
}

function terminal_image_delete(?string $name): void
{
    if ($name && preg_match('/^[a-f0-9]{32}\.(jpg|png|webp|gif)$/', $name)) {
        @unlink(TERMINAL_IMAGE_DIR . '/' . $name);
    }
}

function terminal_image_url(?string $name): string
{
    if ($name && preg_match('/^[a-f0-9]{32}\.(jpg|png|webp|gif)$/', $name) && is_file(TERMINAL_IMAGE_DIR . '/' . $name)) {
        return TERMINAL_IMAGE_URL . '/' . $name;
    }
    return TERMINAL_IMAGE_DEFAULT;
}

/* ---------- Labels ---------- */

// Badge colours, picked by label id so each label keeps its colour (CBG-MP, CBG-AB, TBG = the first three).
const TERMINAL_LABEL_BADGES = ['bg-primary', 'bg-info text-dark', 'bg-secondary', 'bg-success', 'bg-warning text-dark', 'bg-dark', 'bg-danger'];

function terminal_label_badge_class(int $labelId): string
{
    return TERMINAL_LABEL_BADGES[max(0, $labelId - 1) % count(TERMINAL_LABEL_BADGES)];
}

function terminal_label_badge(int $labelId, string $name): string
{
    return '<span class="badge ' . terminal_label_badge_class($labelId) . '">' . e($name) . '</span>';
}

/**
 * A label name's problem (empty / too long), or null when it is fine.
 * Duplicates are checked against the database separately.
 */
function terminal_label_name_error(string $name): ?string
{
    if ($name === '') {
        return 'Label name is required.';
    }
    if (mb_strlen($name) > TERMINAL_LABEL_MAX) {
        return 'Label name must be at most ' . TERMINAL_LABEL_MAX . ' characters.';
    }
    return null;
}

function terminal_label_clean(string $name): string
{
    return trim(preg_replace('/\s+/', ' ', $name));
}

/**
 * Labels in list order, keyed by id: [id => ['id', 'name', 'is_active', 'sort_order', 'terminals', 'serials']]
 * (terminals / serials = how many terminals and Serial Inventory serials use the label).
 */
function terminal_labels(bool $activeOnly = false): array
{
    $rows = db()->query(
        'SELECT l.id, l.name, l.is_active, l.sort_order,
                (SELECT COUNT(*) FROM terminals t WHERE t.label_id = l.id) AS terminals,
                (SELECT COUNT(*) FROM serial_inventory s WHERE s.label_id = l.id) AS serials
         FROM terminal_labels l'
        . ($activeOnly ? ' WHERE l.is_active = 1' : '') .
        ' ORDER BY l.sort_order, l.name'
    )->fetchAll();
    $labels = [];
    foreach ($rows as $row) {
        $row['id']        = (int) $row['id'];
        $row['is_active'] = (int) $row['is_active'];
        $row['terminals'] = (int) $row['terminals'];
        $row['serials']   = (int) $row['serials'];
        $labels[$row['id']] = $row;
    }
    return $labels;
}

/**
 * Add a label at the end of the list. Returns [new id or null, error or null].
 */
function terminal_label_add(string $name): array
{
    $name = terminal_label_clean($name);
    if ($error = terminal_label_name_error($name)) {
        return [null, $error];
    }
    $stmt = db()->prepare('SELECT 1 FROM terminal_labels WHERE name = ?');
    $stmt->execute([$name]);
    if ($stmt->fetchColumn()) {
        return [null, "The label \"$name\" already exists."];
    }
    $next = (int) db()->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM terminal_labels')->fetchColumn();
    try {
        db()->prepare('INSERT INTO terminal_labels (name, sort_order) VALUES (?, ?)')->execute([$name, min(32000, $next)]);
    } catch (PDOException $ex) {
        if ($ex->getCode() === '23000') {
            return [null, "The label \"$name\" already exists."];
        }
        throw $ex;
    }
    return [(int) db()->lastInsertId(), null];
}

/* ---------- Terminal fields ---------- */

/**
 * Check the posted terminal fields.
 *   $in: terminal_name, brand, model, current_version, label_id, bank_type, remarks
 *   $labels: terminal_labels() rows keyed by id
 *   $currentLabelId: the label the terminal already has (it may stay even if now inactive)
 * Returns [clean values, errors]. The unique name check needs the database and is done by the page.
 */
function terminal_validate(array $in, array $labels, ?int $currentLabelId = null): array
{
    $clean = static fn(string $key): string => trim(preg_replace('/\s+/', ' ', (string) ($in[$key] ?? '')));
    $values = [
        'terminal_name'   => $clean('terminal_name'),
        'brand'           => $clean('brand'),
        'model'           => $clean('model'),
        'current_version' => $clean('current_version'),
        'label_id'        => (int) ($in['label_id'] ?? 0),
        'bank_type'       => (string) ($in['bank_type'] ?? ''),
        'remarks'         => terminal_text_clean((string) ($in['remarks'] ?? '')),   // keeps line breaks
    ];
    $errors = [];

    if ($values['terminal_name'] === '' || mb_strlen($values['terminal_name']) > 100) {
        $errors[] = 'Terminal name is required (up to 100 characters).';
    }
    if ($values['brand'] === '' || mb_strlen($values['brand']) > 100) {
        $errors[] = 'Brand is required (up to 100 characters).';
    }
    if (mb_strlen($values['model']) > 50) {
        $errors[] = 'Model must be at most 50 characters.';
    }
    if (mb_strlen($values['current_version']) > 30) {
        $errors[] = 'Current version must be at most 30 characters.';
    }

    $label = $labels[$values['label_id']] ?? null;
    if ($label === null) {
        $errors[] = 'Please choose a label from the list.';
    } elseif (!$label['is_active'] && $values['label_id'] !== $currentLabelId) {
        $errors[] = "The label \"{$label['name']}\" is no longer in use. Please choose another label.";
    }

    if (!array_key_exists($values['bank_type'], TERMINAL_BANKS)) {
        $errors[] = 'Please choose BDO or Non-BDO.';
    }
    if (mb_strlen($values['remarks']) > TERMINAL_REMARKS_MAX) {
        $errors[] = 'Remarks must be at most ' . TERMINAL_REMARKS_MAX . ' characters.';
    }
    return [$values, $errors];
}

/** Multi-line text: Windows line breaks unified, spaces trimmed at the ends. */
function terminal_text_clean(string $text): string
{
    return trim(str_replace("\r\n", "\n", $text));
}

/* ---------- FAQs ---------- */

/**
 * Check the posted FAQ rows (faq_id[], faq_question[], faq_answer[], in form order).
 * Rows with both question and answer empty are skipped. Returns
 * [[['faq_id' => int (0 = new), 'question', 'answer'], …], errors].
 */
function terminal_faq_validate(array $in): array
{
    $ids       = array_values((array) ($in['faq_id'] ?? []));
    $questions = array_values((array) ($in['faq_question'] ?? []));
    $answers   = array_values((array) ($in['faq_answer'] ?? []));

    $rows = $errors = [];
    foreach ($questions as $i => $question) {
        $question = trim(preg_replace('/\s+/', ' ', (string) $question));
        $answer   = terminal_text_clean((string) ($answers[$i] ?? ''));
        if ($question === '' && $answer === '') {
            continue;
        }
        $n = count($rows) + 1;
        if ($question === '') {
            $errors[] = "FAQ $n: the question is required.";
        } elseif (mb_strlen($question) > TERMINAL_FAQ_QUESTION_MAX) {
            $errors[] = "FAQ $n: the question must be at most " . TERMINAL_FAQ_QUESTION_MAX . ' characters.';
        }
        if ($answer === '') {
            $errors[] = "FAQ $n: the answer is required.";
        } elseif (mb_strlen($answer) > TERMINAL_FAQ_ANSWER_MAX) {
            $errors[] = "FAQ $n: the answer must be at most " . TERMINAL_FAQ_ANSWER_MAX . ' characters.';
        }
        $rows[] = ['faq_id' => max(0, (int) ($ids[$i] ?? 0)), 'question' => $question, 'answer' => $answer];
    }
    if (count($rows) > TERMINAL_FAQ_MAX) {
        $errors[] = 'A terminal can have at most ' . TERMINAL_FAQ_MAX . ' FAQs.';
    }
    return [$rows, $errors];
}

/**
 * FAQs for the given terminals in display order: [terminal_id => [['faq_id', 'question', 'answer'], …]].
 */
function terminal_faqs(array $terminalIds): array
{
    $terminalIds = array_values(array_filter(array_map('intval', $terminalIds)));
    if (!$terminalIds) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($terminalIds), '?'));
    $stmt = db()->prepare("SELECT faq_id, terminal_id, question, answer FROM terminal_faqs WHERE terminal_id IN ($placeholders) ORDER BY sort_order, faq_id");
    $stmt->execute($terminalIds);

    $faqs = [];
    foreach ($stmt->fetchAll() as $row) {
        $faqs[(int) $row['terminal_id']][] = ['faq_id' => (int) $row['faq_id'], 'question' => $row['question'], 'answer' => $row['answer']];
    }
    return $faqs;
}

/**
 * Save a terminal's FAQs as posted: known rows are updated, new rows added,
 * rows left out of the form removed. Run inside the terminal's transaction.
 */
function terminal_faqs_save(PDO $pdo, int $terminalId, array $rows): void
{
    $stmt = $pdo->prepare('SELECT faq_id FROM terminal_faqs WHERE terminal_id = ?');
    $stmt->execute([$terminalId]);
    $existing = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    $update = $pdo->prepare('UPDATE terminal_faqs SET question = ?, answer = ?, sort_order = ? WHERE faq_id = ? AND terminal_id = ?');
    $insert = $pdo->prepare('INSERT INTO terminal_faqs (terminal_id, question, answer, sort_order) VALUES (?, ?, ?, ?)');
    $keep   = [];
    foreach ($rows as $i => $row) {
        $order = ($i + 1) * 10;
        if ($row['faq_id'] && in_array($row['faq_id'], $existing, true)) {
            $update->execute([$row['question'], $row['answer'], $order, $row['faq_id'], $terminalId]);
            $keep[] = $row['faq_id'];
        } else {
            $insert->execute([$terminalId, $row['question'], $row['answer'], $order]);
        }
    }
    $delete = $pdo->prepare('DELETE FROM terminal_faqs WHERE faq_id = ?');
    foreach (array_diff($existing, $keep) as $faqId) {
        $delete->execute([$faqId]);
    }
}

function terminal_bank_badge(string $bank): string
{
    $class = $bank === 'bdo' ? 'bg-success' : 'bg-warning text-dark';
    return '<span class="badge ' . $class . '">' . e(TERMINAL_BANKS[$bank] ?? $bank) . '</span>';
}
