<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/public/api/admin-mfa-service.php';
require_once dirname(__DIR__) . '/public/api/csrf.php';

function checkMfa(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException('MFA test failed: ' . $label);
    }
}

// Published RFC 6238 Appendix B SHA-1 test vectors (not account credentials).
$secret = '12345678901234567890';
$vectors = [
    59 => '94287082',
    1111111109 => '07081804',
    1111111111 => '14050471',
    1234567890 => '89005924',
    2000000000 => '69279037',
    20000000000 => '65353130',
];
foreach ($vectors as $timestamp => $expected) {
    checkMfa(administratorMfaTotpAtStep($secret, intdiv($timestamp, 30), 8) === $expected, 'RFC vector');
}
checkMfa(administratorMfaBase32($secret) === 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 'base32');
checkMfa(administratorMfaVerifyTotp($secret, '287082', 59) === 1, 'six-digit current step');
checkMfa(administratorMfaVerifyTotp($secret, '287082', 89) === 1, 'one-step tolerance');
checkMfa(administratorMfaVerifyTotp($secret, '287082', 120) === null, 'bounded tolerance');
checkMfa(administratorMfaVerifyTotp($secret, 'abc', 59) === null, 'invalid format');

if (function_exists('sodium_crypto_secretbox')) {
    $key = random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $sample = random_bytes(20);
    $encrypted = administratorMfaEncrypt($sample, $key);
    checkMfa(administratorMfaDecrypt($encrypted, $key) === $sample, 'authenticated encryption');
    $encrypted[40] = chr(ord($encrypted[40]) ^ 1);
    checkMfa(administratorMfaDecrypt($encrypted, $key) === null, 'tamper rejection');
}

$codes = administratorMfaRecoveryCodes();
checkMfa(count($codes) === 8 && count(array_unique($codes)) === 8, 'recovery count and uniqueness');
foreach ($codes as $code) {
    $normalized = administratorMfaNormalizeRecovery($code);
    checkMfa((bool) preg_match('/^[A-F0-9]{16}$/D', $normalized), 'recovery format');
    checkMfa(password_verify($normalized, password_hash($normalized, PASSWORD_DEFAULT)), 'recovery hash');
}

startAuthenticationSession();
$beforePendingId = session_id();
administratorMfaBeginPending(42, 'test-only-password-hash', 'setup');
checkMfa(!hash_equals($beforePendingId, session_id()), 'password-stage session rotation');
checkMfa(!isset($_SESSION['user_id']) && authenticatedUserId(false) === null, 'pending has no authenticated identity');
checkMfa(administratorMfaPending()['mode'] === 'setup', 'pending setup state');
for ($i = 0; $i < ADMIN_MFA_MAX_ATTEMPTS - 1; $i++) {
    checkMfa(!administratorMfaFailedAttempt(), 'pending attempt remains');
}
checkMfa(administratorMfaFailedAttempt() && administratorMfaPending() === null, 'fifth failure clears pending');
administratorMfaBeginPending(42, 'test-only-password-hash', 'verify');
$_SESSION['pending_admin_mfa']['started_at'] = time() - ADMIN_MFA_PENDING_SECONDS - 1;
checkMfa(administratorMfaPending() === null, 'pending expiry');
administratorMfaBeginPending(42, 'test-only-password-hash', 'verify');
$beforePromotionId = session_id();
administratorMfaPromote(['id' => 42, 'role' => 'administrator', 'password_hash' => 'test-only-password-hash'], administratorMfaEncrypt(random_bytes(20), random_bytes(32)));
checkMfa(!hash_equals($beforePromotionId, session_id()), 'full-auth session rotation');
checkMfa(isset($_SESSION['user_id'], $_SESSION['authenticated_at'], $_SESSION['last_activity_at'], $_SESSION['admin_mfa_fingerprint'])
    && !isset($_SESSION['pending_admin_mfa']), 'promotion state');
checkMfa((bool) preg_match('/^[a-f0-9]{64}$/D', rotateCsrfToken()), 'post-promotion CSRF token');
administratorMfaBeginPending(43, 'test-only-super-password-hash', 'verify', 'super_administrator');
checkMfa(administratorMfaPending()['role'] === 'super_administrator'
    && !isset($_SESSION['user_id'], $_SESSION[SECURITY_SESSION_HANDLE_KEY]),
    'Super Administrator password stage remains pending only');
administratorMfaPromote(['id' => 43, 'role' => 'super_administrator',
    'password_hash' => 'test-only-super-password-hash'],
    administratorMfaEncrypt(random_bytes(20), random_bytes(32)));
checkMfa(($_SESSION['role'] ?? null) === 'super_administrator'
    && isset($_SESSION['admin_mfa_fingerprint']) && !isset($_SESSION['pending_admin_mfa']),
    'Super Administrator MFA promotion preserves privileged role');
destroyAuthenticationSession();

echo "MFA known-vector and cryptographic helper tests passed.\n";
