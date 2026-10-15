<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

const ADOPTAPET_PASSWORD_RECOVERY_SESSION_KEY = 'password_recovery_ux';
const ADOPTAPET_PASSWORD_RECOVERY_CAPTCHA_WINDOW = 300;

function clearPasswordRecoverySessionState(): void
{
    startAuthenticationSession();
    unset($_SESSION[ADOPTAPET_PASSWORD_RECOVERY_SESSION_KEY]);
}

function passwordRecoverySessionState(?int $now = null): ?array
{
    startAuthenticationSession();
    $state = $_SESSION[ADOPTAPET_PASSWORD_RECOVERY_SESSION_KEY] ?? null;
    if (!is_array($state)) {
        return null;
    }

    $email = strtolower(trim((string) ($state['email'] ?? '')));
    $verifiedAt = authenticationSessionTimestamp($state['captcha_verified_at'] ?? null);
    $currentTime = $now ?? time();
    $age = $verifiedAt === null ? -1 : $currentTime - $verifiedAt;
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $age < 0 || $age >= ADOPTAPET_PASSWORD_RECOVERY_CAPTCHA_WINDOW) {
        unset($_SESSION[ADOPTAPET_PASSWORD_RECOVERY_SESSION_KEY]);
        return null;
    }

    return [
        'email' => $email,
        'captcha_verified_at' => $verifiedAt,
    ];
}

function rememberPasswordRecoverySession(string $email, ?int $now = null): void
{
    $normalizedEmail = strtolower(trim($email));
    if (!filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)) {
        return;
    }

    startAuthenticationSession();
    $_SESSION[ADOPTAPET_PASSWORD_RECOVERY_SESSION_KEY] = [
        'email' => $normalizedEmail,
        'captcha_verified_at' => $now ?? time(),
    ];
}

function passwordRecoveryCaptchaMayBeReused(string $email, ?int $now = null): bool
{
    $state = passwordRecoverySessionState($now);
    return $state !== null && $state['email'] === strtolower(trim($email));
}

function publicPasswordRecoverySessionState(): array
{
    $state = passwordRecoverySessionState();
    $expiresIn = $state === null
        ? 0
        : max(0, ADOPTAPET_PASSWORD_RECOVERY_CAPTCHA_WINDOW - (time() - $state['captcha_verified_at']));
    return [
        'email' => $state['email'] ?? '',
        'captcha_reusable' => $state !== null,
        'expires_in' => $expiresIn,
    ];
}
