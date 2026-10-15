<?php
declare(strict_types=1);

/** Returns true when a configured filesystem path is absolute. */
function adoptAPetPathIsAbsolute(string $path): bool
{
    return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
}

/** Creates a private runtime directory and returns its canonical path. */
function adoptAPetEnsurePrivateDirectory(string $directory): string
{
    if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('Private application storage is unavailable.');
    }

    $resolved = realpath($directory);
    if ($resolved === false || !is_dir($resolved) || !is_writable($resolved)) {
        throw new RuntimeException('Private application storage is not writable.');
    }

    return $resolved;
}

/**
 * Resolves private runtime storage outside the project web tree.
 *
 * ADOPTAPET_PRIVATE_STORAGE_PATH may override the default. When the derived
 * outside-webroot location cannot be created, the protected project storage
 * directory remains a safe compatibility fallback.
 */
function adoptAPetPrivateStorageDirectory(string $childDirectory): string
{
    if (!preg_match('/^[a-z0-9-]+$/', $childDirectory)) {
        throw new InvalidArgumentException('Invalid private storage directory.');
    }

    $projectRoot = dirname(__DIR__);
    $configuredRoot = trim((string) (getenv('ADOPTAPET_PRIVATE_STORAGE_PATH') ?: ''));
    if ($configuredRoot !== '') {
        if (!adoptAPetPathIsAbsolute($configuredRoot)) {
            throw new RuntimeException('ADOPTAPET_PRIVATE_STORAGE_PATH must be an absolute path.');
        }

        $normalisedConfigured = strtolower(rtrim(str_replace('\\', '/', $configuredRoot), '/'));
        $normalisedProject = strtolower(rtrim(str_replace('\\', '/', $projectRoot), '/'));
        if ($normalisedConfigured === $normalisedProject || str_starts_with($normalisedConfigured, $normalisedProject . '/')) {
            throw new RuntimeException('Configured private storage must be outside the project directory.');
        }

        return adoptAPetEnsurePrivateDirectory($configuredRoot . DIRECTORY_SEPARATOR . $childDirectory);
    }

    $outsideWebRoot = dirname($projectRoot, 2) . DIRECTORY_SEPARATOR . 'adoptapet-private';
    try {
        return adoptAPetEnsurePrivateDirectory($outsideWebRoot . DIRECTORY_SEPARATOR . $childDirectory);
    } catch (RuntimeException $exception) {
        return adoptAPetEnsurePrivateDirectory(
            $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . $childDirectory
        );
    }
}

function adoptAPetSessionStorageDirectory(): string
{
    return adoptAPetPrivateStorageDirectory('sessions');
}

function adoptAPetGeneratedDocumentStorageDirectory(): string
{
    return adoptAPetPrivateStorageDirectory('generated-documents');
}
