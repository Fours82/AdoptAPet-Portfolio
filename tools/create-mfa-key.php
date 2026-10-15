<?php
declare(strict_types=1);

function adoptAPetCreateFreshMfaConfiguration(string $directory): array
{
    if (PHP_VERSION_ID < 80100 || !extension_loaded('sodium')) {
        return ['created' => false, 'message' => 'PHP 8.1+ with Sodium is required; no file was written.'];
    }
    if (!is_dir($directory)) {
        return ['created' => false, 'message' => 'The config directory is missing; no file was written.'];
    }

    $target = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'mfa.local.php';
    try {
        $key = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $contents = "<?php\ndeclare(strict_types=1);\n\nreturn ['encryption_key' => " . var_export($key, true) . "];\n";
    } catch (Throwable) {
        return ['created' => false, 'message' => 'A secure MFA key could not be generated; no file was written.'];
    }

    $handle = @fopen($target, 'x');
    if (!is_resource($handle)) {
        return ['created' => false, 'message' => is_file($target)
            ? 'config/mfa.local.php already exists. It was not changed. Do not replace a key used by an installation.'
            : 'config/mfa.local.php could not be created. Check project-folder permissions; no existing file was changed.'];
    }

    $written = 0;
    $length = strlen($contents);
    while ($written < $length) {
        $count = fwrite($handle, substr($contents, $written));
        if (!is_int($count) || $count < 1) {
            break;
        }
        $written += $count;
    }
    $flushed = fflush($handle);
    fclose($handle);
    if ($written !== $length || !$flushed) {
        @unlink($target);
        return ['created' => false, 'message' => 'The new MFA configuration could not be written completely; its incomplete file was removed.'];
    }

    return ['created' => true, 'message' => 'Created config/mfa.local.php with a fresh random key. The key was not printed. Keep this file private and preserve it with this installation.'];
}

function adoptAPetMfaKeyGeneratorMain(?string $directory = null): int
{
    if (PHP_SAPI !== 'cli') {
        if (!headers_sent()) {
            http_response_code(404);
        }
        return 1;
    }

    $result = adoptAPetCreateFreshMfaConfiguration(
        $directory ?? dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config'
    );
    fwrite($result['created'] ? STDOUT : STDERR, $result['message'] . PHP_EOL);
    return $result['created'] ? 0 : 1;
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(adoptAPetMfaKeyGeneratorMain());
}
