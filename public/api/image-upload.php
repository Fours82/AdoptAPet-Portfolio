<?php
declare(strict_types=1);

final class ImageUploadException extends RuntimeException
{
}

const IMAGE_UPLOAD_MAX_BYTES = 5 * 1024 * 1024;
const IMAGE_UPLOAD_MAX_DIMENSION = 8000;
const IMAGE_UPLOAD_MAX_PIXELS = 25000000;

function imageRequestPayload(): array
{
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    $raw = str_contains($contentType, 'multipart/form-data')
        ? ($_POST['payload'] ?? '')
        : file_get_contents('php://input');

    if (!is_string($raw) || $raw === '') {
        throw new ImageUploadException('Invalid JSON request body.');
    }

    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        throw new ImageUploadException('Invalid JSON request body.');
    }

    return $payload;
}

function imageUploadWasProvided(string $field = 'image'): bool
{
    if (!isset($_FILES[$field])) {
        return false;
    }

    if (!is_array($_FILES[$field]) || !isset($_FILES[$field]['error']) || is_array($_FILES[$field]['error'])) {
        throw new ImageUploadException('Malformed image upload.');
    }

    return (int) $_FILES[$field]['error'] !== UPLOAD_ERR_NO_FILE;
}

function safeOriginalImageName(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    $name = preg_replace('/[^A-Za-z0-9._ -]/', '_', $name) ?? 'image';
    $name = trim($name, " .\t\n\r\0\x0B");
    if ($name === '') {
        $name = 'image';
    }

    return substr($name, 0, 255);
}

/**
 * Normalises a PHP single or multiple file input into individual file records.
 * Inputs containing only UPLOAD_ERR_NO_FILE are treated as an empty selection.
 */
function uploadedImageFiles(string $field): array
{
    if (!isset($_FILES[$field])) {
        return [];
    }

    $upload = $_FILES[$field];
    if (!is_array($upload) || !array_key_exists('error', $upload)) {
        throw new ImageUploadException('Malformed image upload.');
    }

    if (!is_array($upload['error'])) {
        return (int) $upload['error'] === UPLOAD_ERR_NO_FILE ? [] : [$upload];
    }

    foreach (['name', 'type', 'tmp_name', 'error', 'size'] as $key) {
        if (!isset($upload[$key]) || !is_array($upload[$key]) || count($upload[$key]) !== count($upload['error'])) {
            throw new ImageUploadException('Malformed image upload.');
        }
    }

    $files = [];
    foreach (array_keys($upload['error']) as $index) {
        $error = (int) $upload['error'][$index];
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $files[] = [
            'name' => $upload['name'][$index],
            'type' => $upload['type'][$index],
            'tmp_name' => $upload['tmp_name'][$index],
            'error' => $error,
            'size' => $upload['size'][$index],
        ];
    }

    return $files;
}

function storeUploadedImageFile(string $category, array $file): array
{
    $categories = ['pets', 'strays', 'community', 'profiles', 'news'];
    if (!in_array($category, $categories, true)) {
        throw new LogicException('Unsupported image category.');
    }

    if (!isset($file['error']) || is_array($file['error'])) {
        throw new ImageUploadException('Malformed image upload.');
    }

    $error = (int) $file['error'];
    if ($error !== UPLOAD_ERR_OK) {
        $message = match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The image exceeds the upload size limit.',
            UPLOAD_ERR_PARTIAL => 'The image upload was incomplete.',
            UPLOAD_ERR_NO_FILE => 'No image was uploaded.',
            default => 'The image could not be uploaded.',
        };
        throw new ImageUploadException($message);
    }

    $temporaryPath = $file['tmp_name'] ?? null;
    if (!is_string($temporaryPath) || $temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
        throw new ImageUploadException('Malformed image upload.');
    }

    $actualSize = filesize($temporaryPath);
    if ($actualSize === false || $actualSize < 1) {
        throw new ImageUploadException('The uploaded image is empty.');
    }
    if ($actualSize > IMAGE_UPLOAD_MAX_BYTES) {
        throw new ImageUploadException('Images must be no larger than 5 MB.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($temporaryPath);
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!is_string($mimeType) || !isset($extensions[$mimeType])) {
        throw new ImageUploadException('Only JPEG, PNG, and WebP images are allowed.');
    }

    $dimensions = @getimagesize($temporaryPath);
    if (!is_array($dimensions) || !isset($dimensions[0], $dimensions[1], $dimensions['mime'])) {
        throw new ImageUploadException('The uploaded file is not a valid image.');
    }
    $width = (int) $dimensions[0];
    $height = (int) $dimensions[1];
    if ($dimensions['mime'] !== $mimeType || $width < 1 || $height < 1) {
        throw new ImageUploadException('The image content does not match its detected format.');
    }
    if ($width > IMAGE_UPLOAD_MAX_DIMENSION || $height > IMAGE_UPLOAD_MAX_DIMENSION || ($width * $height) > IMAGE_UPLOAD_MAX_PIXELS) {
        throw new ImageUploadException('The image dimensions are too large.');
    }

    $contents = file_get_contents($temporaryPath);
    if (!is_string($contents)) {
        throw new ImageUploadException('The uploaded image could not be inspected.');
    }
    $lowerContents = strtolower($contents);
    foreach (['<?php', '<?=', '<script'] as $signature) {
        if (str_contains($lowerContents, $signature)) {
            throw new ImageUploadException('Executable or scripted image content is not allowed.');
        }
    }

    $uploadRoot = dirname(__DIR__) . '/uploads';
    $targetDirectory = $uploadRoot . '/' . $category;
    if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0755, true) && !is_dir($targetDirectory)) {
        throw new RuntimeException('The image storage directory is unavailable.');
    }

    $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mimeType];
    $absolutePath = $targetDirectory . '/' . $filename;
    if (!move_uploaded_file($temporaryPath, $absolutePath)) {
        throw new RuntimeException('The image could not be stored.');
    }
    @chmod($absolutePath, 0644);

    return [
        'file_path' => 'uploads/' . $category . '/' . $filename,
        'absolute_path' => $absolutePath,
        'original_filename' => safeOriginalImageName((string) ($file['name'] ?? 'image')),
        'mime_type' => $mimeType,
        'file_size_bytes' => (int) $actualSize,
        'width' => $width,
        'height' => $height,
    ];
}

function storeUploadedImage(string $category, string $field = 'image'): array
{
    $files = uploadedImageFiles($field);
    if (count($files) !== 1) {
        throw new ImageUploadException($files === [] ? 'No image was uploaded.' : 'Please upload one image.');
    }

    return storeUploadedImageFile($category, $files[0]);
}

/**
 * Stores a batch uploaded as images[] while retaining the legacy image field.
 * All files written before a validation/storage failure are removed immediately.
 */
function storeRequestedImages(
    string $category,
    int $maximum,
    string $batchField = 'images',
    string $singleField = 'image'
): array {
    if ($maximum < 1) {
        throw new ImageUploadException('No image slots are available.');
    }

    $batchFiles = uploadedImageFiles($batchField);
    $singleFiles = uploadedImageFiles($singleField);
    if ($batchFiles !== [] && $singleFiles !== []) {
        throw new ImageUploadException('Use only one image upload field.');
    }

    $files = $batchFiles !== [] ? $batchFiles : $singleFiles;
    if (count($files) > $maximum) {
        throw new ImageUploadException('You can upload no more than ' . $maximum . ' image' . ($maximum === 1 ? '' : 's') . '.');
    }

    $storedImages = [];
    try {
        foreach ($files as $file) {
            $storedImages[] = storeUploadedImageFile($category, $file);
        }
    } catch (Throwable $error) {
        removeStoredImages($storedImages);
        throw $error;
    }

    return $storedImages;
}

function removeStoredImage(?array $storedImage): void
{
    if (!is_array($storedImage) || !isset($storedImage['absolute_path']) || !is_string($storedImage['absolute_path'])) {
        return;
    }

    $uploadRoot = realpath(dirname(__DIR__) . '/uploads');
    $filePath = realpath($storedImage['absolute_path']);
    if ($uploadRoot !== false && $filePath !== false && str_starts_with($filePath, $uploadRoot . DIRECTORY_SEPARATOR) && is_file($filePath)) {
        @unlink($filePath);
    }
}

function removeStoredImages(array $storedImages): void
{
    foreach ($storedImages as $storedImage) {
        removeStoredImage(is_array($storedImage) ? $storedImage : null);
    }
}

/** Safely removes a previously stored public upload path. */
function removeStoredImagePath(?string $relativePath): bool
{
    if (!is_string($relativePath) || !preg_match('#^uploads/(pets|strays|community|profiles|news)/[A-Za-z0-9._-]+$#', $relativePath)) {
        return false;
    }

    $publicRoot = realpath(dirname(__DIR__));
    $uploadRoot = realpath(dirname(__DIR__) . '/uploads');
    if ($publicRoot === false || $uploadRoot === false) {
        return false;
    }

    $candidate = $publicRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    $filePath = realpath($candidate);
    if ($filePath === false) {
        return !file_exists($candidate);
    }
    if (!str_starts_with($filePath, $uploadRoot . DIRECTORY_SEPARATOR) || !is_file($filePath)) {
        return false;
    }

    return @unlink($filePath);
}
