<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 2) . '/config/mfa.php';

const ADMIN_MFA_PENDING_SECONDS = 600;
const ADMIN_MFA_MAX_ATTEMPTS = 5;

function administratorMfaBase32(string $bytes): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $result = '';
    $buffer = 0;
    $bits = 0;
    foreach (unpack('C*', $bytes) as $byte) {
        $buffer = ($buffer << 8) | $byte;
        $bits += 8;
        while ($bits >= 5) {
            $bits -= 5;
            $result .= $alphabet[($buffer >> $bits) & 31];
        }
        $buffer &= (1 << $bits) - 1;
    }
    if ($bits > 0) {
        $result .= $alphabet[($buffer << (5 - $bits)) & 31];
    }
    return $result;
}

function administratorMfaTotpAtStep(string $secret, int $step, int $digits = 6): string
{
    if ($step < 0 || !in_array($digits, [6, 8], true)) {
        throw new InvalidArgumentException('Invalid TOTP parameters.');
    }
    $counter = pack('N2', ($step >> 32) & 0xffffffff, $step & 0xffffffff);
    $digest = hash_hmac('sha1', $counter, $secret, true);
    $offset = ord($digest[19]) & 15;
    $number = unpack('N', substr($digest, $offset, 4))[1] & 0x7fffffff;
    return str_pad((string) ($number % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
}

/** Returns the accepted 30-second step, or null. The caller enforces replay protection. */
function administratorMfaVerifyTotp(string $secret, string $code, ?int $now = null): ?int
{
    if (!preg_match('/^[0-9]{6}$/D', $code)) {
        return null;
    }
    $step = intdiv($now ?? time(), 30);
    for ($candidate = $step - 1; $candidate <= $step + 1; $candidate++) {
        if ($candidate >= 0 && hash_equals(administratorMfaTotpAtStep($secret, $candidate), $code)) {
            return $candidate;
        }
    }
    return null;
}

function administratorMfaEncrypt(string $secret, string $key): string
{
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return "\x01" . $nonce . sodium_crypto_secretbox($secret, $nonce, $key);
}

function administratorMfaDecrypt(string $payload, string $key): ?string
{
    if (strlen($payload) < 1 + SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES
        || $payload[0] !== "\x01") {
        return null;
    }
    $nonce = substr($payload, 1, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plaintext = sodium_crypto_secretbox_open(substr($payload, 1 + SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $key);
    return is_string($plaintext) && strlen($plaintext) === 20 ? $plaintext : null;
}

function administratorMfaPending(): ?array
{
    startAuthenticationSession();
    $pending = $_SESSION['pending_admin_mfa'] ?? null;
    if (!is_array($pending)) {
        return null;
    }
    $started = authenticationSessionTimestamp($pending['started_at'] ?? null);
    if ($started === null || $started > time() || time() - $started > ADMIN_MFA_PENDING_SECONDS
        || !in_array($pending['mode'] ?? null, ['setup', 'verify'], true)
        || !isOperationalAdministratorRole($pending['role'] ?? null)
        || (int) ($pending['user_id'] ?? 0) < 1) {
        unset($_SESSION['pending_admin_mfa']);
        return null;
    }
    return $pending;
}

function administratorMfaBeginPending(int $userId, string $passwordHash, string $mode, string $role = ADOPTAPET_ADMIN_ROLE): void
{
    if (!isOperationalAdministratorRole($role)) {
        throw new InvalidArgumentException('A privileged role is required for pending MFA.');
    }
    startAuthenticationSession(true);
    if (!session_regenerate_id(true)) {
        throw new RuntimeException('Unable to rotate the pending authentication session.');
    }
    $_SESSION = [];
    $_SESSION['pending_admin_mfa'] = [
        'user_id' => $userId,
        'credential_fingerprint' => authenticationCredentialFingerprint($passwordHash),
        'mode' => $mode,
        'role' => $role,
        'started_at' => time(),
        'attempts' => 0,
    ];
}

function administratorMfaFailedAttempt(): bool
{
    $_SESSION['pending_admin_mfa']['attempts'] = (int) ($_SESSION['pending_admin_mfa']['attempts'] ?? 0) + 1;
    if ($_SESSION['pending_admin_mfa']['attempts'] >= ADMIN_MFA_MAX_ATTEMPTS) {
        unset($_SESSION['pending_admin_mfa']);
        return true;
    }
    return false;
}

function administratorMfaPromote(array $user, string $encryptedSecret): void
{
    $_SESSION = [];
    if (!isOperationalAdministratorRole($user['role'] ?? null)) {
        throw new InvalidArgumentException('Administrator role is required for MFA promotion.');
    }
    signInUser((int) $user['id'], (string) $user['role'], (string) $user['password_hash']);
    $_SESSION['admin_mfa_fingerprint'] = hash('sha256', $encryptedSecret);
}

function administratorMfaRecoveryCodes(): array
{
    $codes = [];
    for ($i = 0; $i < 8; $i++) {
        $raw = strtoupper(bin2hex(random_bytes(8)));
        $codes[] = substr($raw, 0, 4) . '-' . substr($raw, 4, 4) . '-' . substr($raw, 8, 4) . '-' . substr($raw, 12, 4);
    }
    return $codes;
}

function administratorMfaNormalizeRecovery(string $value): string
{
    return strtoupper(str_replace('-', '', trim($value)));
}
