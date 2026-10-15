<?php
declare(strict_types=1);

function administratorMfaEncryptionKey(): ?string
{
    if (!function_exists('sodium_crypto_secretbox')) {
        return null;
    }

    $configured = getenv('MFA_ENCRYPTION_KEY');
    if ($configured === false || $configured === '') {
        $file = __DIR__ . '/mfa.local.php';
        $local = is_file($file) ? require $file : [];
        $configured = is_array($local) ? ($local['encryption_key'] ?? '') : '';
    }

    $key = is_string($configured) ? base64_decode($configured, true) : false;
    return is_string($key) && strlen($key) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES ? $key : null;
}
