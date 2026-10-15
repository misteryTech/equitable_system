<?php
/**
 * Terminal registration helpers: labels, bank types and terminal images.
 * Include after includes/config.php (which loads profile_picture.php, used
 * here for the shared image checks).
 */

const TERMINAL_LABELS = ['CBG-MP', 'CBG-AB', 'TBG'];
const TERMINAL_BANKS  = [
    'bdo'     => 'BDO',
    'non_bdo' => 'Non-BDO',
];

const TERMINAL_IMAGE_DIR     = __DIR__ . '/../uploads/terminals';
const TERMINAL_IMAGE_URL     = 'uploads/terminals';
const TERMINAL_IMAGE_DEFAULT = 'assets/img/terminal-default.svg';
const TERMINAL_IMAGE_MAX     = 1000;   // longest side in pixels after saving
const TERMINAL_MAX_PHOTOS    = 3;      // photo slots per terminal

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

/**
 * Badge colour per label.
 */
function terminal_label_badge(string $label): string
{
    $class = ['CBG-MP' => 'bg-primary', 'CBG-AB' => 'bg-info text-dark', 'TBG' => 'bg-secondary'][$label] ?? 'bg-light text-dark';
    return '<span class="badge ' . $class . '">' . e($label) . '</span>';
}

function terminal_bank_badge(string $bank): string
{
    $class = $bank === 'bdo' ? 'bg-success' : 'bg-warning text-dark';
    return '<span class="badge ' . $class . '">' . e(TERMINAL_BANKS[$bank] ?? $bank) . '</span>';
}
