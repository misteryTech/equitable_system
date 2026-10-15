<?php
/**
 * Profile pictures: validate an uploaded image, store it in uploads/profile/,
 * and build the URL to show it (or a default avatar).
 * Include after includes/config.php.
 */

const PROFILE_PICTURE_DIR       = __DIR__ . '/../uploads/profile';
const PROFILE_PICTURE_URL       = 'uploads/profile';
const PROFILE_PICTURE_DEFAULT   = 'assets/img/avatar-default.svg';
const PROFILE_PICTURE_MAX_BYTES = 5 * 1024 * 1024;   // 5 MB
const PROFILE_PICTURE_SIZE      = 400;               // saved as a 400 x 400 square
const PROFILE_PICTURE_TYPES     = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
];

/**
 * Check an entry from $_FILES. Returns [error message or null, has file].
 * No file chosen is fine (the picture is optional).
 */
function profile_picture_check(?array $file): array
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [null, false];
    }
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE
        || $file['size'] > PROFILE_PICTURE_MAX_BYTES) {
        return ['Profile picture must be 5 MB or smaller.', true];
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        return ['The profile picture could not be uploaded. Please try again.', true];
    }

    // Trust the file contents, not the name or the browser's type.
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $info = @getimagesize($file['tmp_name']);
    if (!isset(PROFILE_PICTURE_TYPES[$mime]) || $info === false) {
        return ['Profile picture must be a JPG, PNG, WEBP or GIF image.', true];
    }
    if ($info[0] > 8000 || $info[1] > 8000) {
        return ['Profile picture is too large (max 8000 x 8000 pixels).', true];
    }
    return [null, true];
}

/**
 * Store a checked upload and return its file name. With GD the image is fixed
 * for phone rotation, center-cropped to a square, resized and saved as JPEG
 * (which also strips metadata such as GPS location). Without GD the checked
 * original is stored as-is.
 */
function profile_picture_store(array $file): string
{
    if (!is_dir(PROFILE_PICTURE_DIR)) {
        mkdir(PROFILE_PICTURE_DIR, 0755, true);
    }
    $name = bin2hex(random_bytes(16));

    if (!function_exists('imagecreatefromstring')) {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $name .= '.' . PROFILE_PICTURE_TYPES[$mime];
        if (!move_uploaded_file($file['tmp_name'], PROFILE_PICTURE_DIR . '/' . $name)) {
            throw new RuntimeException('Could not save the profile picture.');
        }
        return $name;
    }

    $source = imagecreatefromstring(file_get_contents($file['tmp_name']));
    if ($source === false) {
        throw new RuntimeException('Could not read the profile picture.');
    }

    // Phone photos are often stored sideways with an EXIF "orientation" flag.
    if (function_exists('exif_read_data')) {
        $exif = @exif_read_data($file['tmp_name']);
        $rotate = [3 => 180, 6 => -90, 8 => 90][$exif['Orientation'] ?? 1] ?? 0;
        if ($rotate) {
            $source = imagerotate($source, $rotate, 0);
        }
    }

    $width  = imagesx($source);
    $height = imagesy($source);
    $side   = min($width, $height);
    $size   = PROFILE_PICTURE_SIZE;

    $square = imagecreatetruecolor($size, $size);
    imagefill($square, 0, 0, imagecolorallocate($square, 255, 255, 255));   // white behind transparent PNGs
    imagecopyresampled($square, $source, 0, 0, intdiv($width - $side, 2), intdiv($height - $side, 2), $size, $size, $side, $side);

    $name .= '.jpg';
    $saved = imagejpeg($square, PROFILE_PICTURE_DIR . '/' . $name, 85);
    imagedestroy($source);
    imagedestroy($square);
    if (!$saved) {
        throw new RuntimeException('Could not save the profile picture.');
    }
    return $name;
}

/**
 * Delete a stored picture (e.g. when saving the user failed).
 */
function profile_picture_delete(?string $name): void
{
    if ($name && preg_match('/^[a-f0-9]{32}\.(jpg|png|webp|gif)$/', $name)) {
        @unlink(PROFILE_PICTURE_DIR . '/' . $name);
    }
}

/**
 * URL of a user's picture, or the default avatar.
 */
function profile_picture_url(?array $user): string
{
    $name = $user['profile_picture'] ?? null;
    if ($name && preg_match('/^[a-f0-9]{32}\.(jpg|png|webp|gif)$/', $name) && is_file(PROFILE_PICTURE_DIR . '/' . $name)) {
        return PROFILE_PICTURE_URL . '/' . $name;
    }
    return PROFILE_PICTURE_DEFAULT;
}
