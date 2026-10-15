<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/tools/create-mfa-key.php';

function mfaKeyGeneratorCheck(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException('MFA key generator test failed: ' . $label);
    }
}

$directory = dirname(__DIR__) . '/tmp/mfa-key-generator-' . bin2hex(random_bytes(8));
if (!mkdir($directory, 0700)) {
    throw new RuntimeException('Could not create an isolated MFA key generator test directory.');
}

$target = $directory . DIRECTORY_SEPARATOR . 'mfa.local.php';
try {
    $created = adoptAPetCreateFreshMfaConfiguration($directory);
    mfaKeyGeneratorCheck($created['created'] === true, 'a new local key file is created');

    $contents = file_get_contents($target);
    $configuration = require $target;
    $decodedKey = is_array($configuration) ? base64_decode((string) ($configuration['encryption_key'] ?? ''), true) : false;
    mfaKeyGeneratorCheck(is_string($contents), 'the generated file is readable');
    mfaKeyGeneratorCheck(is_string($decodedKey) && strlen($decodedKey) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'the generated key has the required length');

    $secondAttempt = adoptAPetCreateFreshMfaConfiguration($directory);
    mfaKeyGeneratorCheck($secondAttempt['created'] === false, 'an existing key is not replaced');
    mfaKeyGeneratorCheck(file_get_contents($target) === $contents, 'existing key bytes remain unchanged');
    mfaKeyGeneratorCheck(!str_contains($created['message'], $configuration['encryption_key']), 'the key is not printed in the helper result');
} finally {
    if (is_file($target)) {
        unlink($target);
    }
    rmdir($directory);
}

echo "MFA key generator tests passed.\n";
