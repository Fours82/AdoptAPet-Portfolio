<?php
declare(strict_types=1);

const PAYMENT_VERIFICATION_TTL_SECONDS = 600;
const PAYMENT_VERIFICATION_RESEND_SECONDS = 60;
const PAYMENT_VERIFICATION_MAX_ATTEMPTS = 5;
const BANK_PROVIDER_AUTH_TTL_SECONDS = 600;
const BANK_PROVIDER_LOGIN_MAX_ATTEMPTS = 5;
const BANK_PROVIDER_LOGIN_COOLDOWN_SECONDS = 30;

function paymentVerificationContext(?string $context): ?string
{
    return in_array($context, ['online_banking', 'e_wallet'], true) ? $context : null;
}

function bankProviderCredentialsMatch(string $storedUsername, string $storedPasswordHash, string $username, string $password): bool
{
    $usernameMatches = hash_equals(strtolower($storedUsername), strtolower($username));
    $passwordMatches = password_verify($password, $storedPasswordHash);

    return $usernameMatches && $passwordMatches;
}

function paymentVerificationRequestKeyIsValid(string $requestKey): bool
{
    return (bool) preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $requestKey);
}

function paymentVerificationState(): ?array
{
    startAuthenticationSession();
    $state = $_SESSION['donation_payment_verification'] ?? null;

    return is_array($state) ? $state : null;
}

function paymentVerificationCooldownSeconds(): int
{
    startAuthenticationSession();
    $lastSentAt = (int) ($_SESSION['donation_payment_verification_last_sent_at'] ?? 0);

    return max(0, PAYMENT_VERIFICATION_RESEND_SECONDS - (time() - $lastSentAt));
}

function bankProviderAuthenticationState(): ?array
{
    startAuthenticationSession();
    $state = $_SESSION['donation_bank_provider_auth'] ?? null;
    if (!is_array($state)) {
        return null;
    }
    if ((int) ($state['authorised_until'] ?? 0) < time()) {
        unset($_SESSION['donation_bank_provider_auth']);
        return null;
    }

    return $state;
}

function bankProviderAuthenticationIsAuthorised(int $userId, string $requestKey): bool
{
    $state = bankProviderAuthenticationState();

    return $state !== null
        && (int) ($state['user_id'] ?? 0) === $userId
        && hash_equals((string) ($state['context'] ?? ''), 'online_banking')
        && hash_equals((string) ($state['request_key'] ?? ''), $requestKey);
}

function authoriseBankProviderAuthentication(int $userId, string $requestKey): void
{
    startAuthenticationSession();
    $now = time();
    $_SESSION['donation_bank_provider_auth'] = [
        'user_id' => $userId,
        'context' => 'online_banking',
        'request_key' => $requestKey,
        'authenticated_at' => $now,
        'authorised_until' => $now + BANK_PROVIDER_AUTH_TTL_SECONDS,
    ];
    clearBankProviderLoginAttempts($userId, $requestKey);
}

function clearBankProviderAuthentication(?int $userId = null, ?string $requestKey = null): void
{
    startAuthenticationSession();
    $state = $_SESSION['donation_bank_provider_auth'] ?? null;
    if (!is_array($state)) {
        return;
    }
    if ($userId !== null && (int) ($state['user_id'] ?? 0) !== $userId) {
        return;
    }
    if ($requestKey !== null && !hash_equals((string) ($state['request_key'] ?? ''), $requestKey)) {
        return;
    }
    unset($_SESSION['donation_bank_provider_auth']);
}

function bankProviderLoginAttemptState(int $userId, string $requestKey): ?array
{
    startAuthenticationSession();
    $state = $_SESSION['donation_bank_provider_login_attempts'] ?? null;
    if (
        !is_array($state)
        || (int) ($state['user_id'] ?? 0) !== $userId
        || !hash_equals((string) ($state['request_key'] ?? ''), $requestKey)
    ) {
        return null;
    }

    return $state;
}

function bankProviderLoginCooldownSeconds(int $userId, string $requestKey): int
{
    $state = bankProviderLoginAttemptState($userId, $requestKey);
    if ($state === null) {
        return 0;
    }
    $remaining = max(0, (int) ($state['cooldown_until'] ?? 0) - time());
    if ($remaining === 0 && (int) ($state['cooldown_until'] ?? 0) > 0) {
        clearBankProviderLoginAttempts($userId, $requestKey);
    }

    return $remaining;
}

function recordBankProviderLoginFailure(int $userId, string $requestKey): int
{
    startAuthenticationSession();
    $state = bankProviderLoginAttemptState($userId, $requestKey) ?? [
        'user_id' => $userId,
        'request_key' => $requestKey,
        'attempts' => 0,
        'cooldown_until' => 0,
    ];
    $state['attempts'] = (int) ($state['attempts'] ?? 0) + 1;
    if ($state['attempts'] >= BANK_PROVIDER_LOGIN_MAX_ATTEMPTS) {
        $state['attempts'] = 0;
        $state['cooldown_until'] = time() + BANK_PROVIDER_LOGIN_COOLDOWN_SECONDS;
    }
    $_SESSION['donation_bank_provider_login_attempts'] = $state;

    return max(0, (int) $state['cooldown_until'] - time());
}

function clearBankProviderLoginAttempts(?int $userId = null, ?string $requestKey = null): void
{
    startAuthenticationSession();
    $state = $_SESSION['donation_bank_provider_login_attempts'] ?? null;
    if (!is_array($state)) {
        return;
    }
    if ($userId !== null && (int) ($state['user_id'] ?? 0) !== $userId) {
        return;
    }
    if ($requestKey !== null && !hash_equals((string) ($state['request_key'] ?? ''), $requestKey)) {
        return;
    }
    unset($_SESSION['donation_bank_provider_login_attempts']);
}

function generatePaymentVerificationCode(): string
{
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function storePaymentVerificationCode(int $userId, string $context, string $requestKey, string $code): void
{
    startAuthenticationSession();
    $now = time();
    $_SESSION['donation_payment_verification'] = [
        'user_id' => $userId,
        'context' => $context,
        'request_key' => $requestKey,
        'code_hash' => password_hash($code, PASSWORD_DEFAULT),
        'expires_at' => $now + PAYMENT_VERIFICATION_TTL_SECONDS,
        'attempts' => 0,
        'verified_at' => null,
        'authorised_until' => null,
    ];
    $_SESSION['donation_payment_verification_last_sent_at'] = $now;
}

function clearPaymentVerification(?int $userId = null, ?string $requestKey = null): void
{
    startAuthenticationSession();
    $state = paymentVerificationState();
    if ($state === null) {
        return;
    }
    if ($userId !== null && (int) ($state['user_id'] ?? 0) !== $userId) {
        return;
    }
    if ($requestKey !== null && !hash_equals((string) ($state['request_key'] ?? ''), $requestKey)) {
        return;
    }
    unset($_SESSION['donation_payment_verification']);
}

function verifyPaymentVerificationCode(int $userId, string $context, string $requestKey, string $code): bool
{
    startAuthenticationSession();
    $state = paymentVerificationState();
    if (
        $state === null
        || (int) ($state['user_id'] ?? 0) !== $userId
        || !hash_equals((string) ($state['context'] ?? ''), $context)
        || !hash_equals((string) ($state['request_key'] ?? ''), $requestKey)
        || !is_string($state['code_hash'] ?? null)
        || (int) ($state['verified_at'] ?? 0) > 0
    ) {
        return false;
    }

    if ((int) ($state['expires_at'] ?? 0) < time() || (int) ($state['attempts'] ?? 0) >= PAYMENT_VERIFICATION_MAX_ATTEMPTS) {
        clearPaymentVerification($userId, $requestKey);
        return false;
    }

    if (!password_verify($code, $state['code_hash'])) {
        $state['attempts'] = (int) ($state['attempts'] ?? 0) + 1;
        if ($state['attempts'] >= PAYMENT_VERIFICATION_MAX_ATTEMPTS) {
            clearPaymentVerification($userId, $requestKey);
        } else {
            $_SESSION['donation_payment_verification'] = $state;
        }
        return false;
    }

    unset($state['code_hash']);
    $state['verified_at'] = time();
    $state['authorised_until'] = time() + PAYMENT_VERIFICATION_TTL_SECONDS;
    $_SESSION['donation_payment_verification'] = $state;

    return true;
}

function paymentVerificationIsAuthorised(int $userId, string $context, string $requestKey): bool
{
    $state = paymentVerificationState();

    return $state !== null
        && (int) ($state['user_id'] ?? 0) === $userId
        && hash_equals((string) ($state['context'] ?? ''), $context)
        && hash_equals((string) ($state['request_key'] ?? ''), $requestKey)
        && (int) ($state['verified_at'] ?? 0) > 0
        && (int) ($state['authorised_until'] ?? 0) >= time();
}

function consumePaymentVerification(int $userId, string $context, string $requestKey): void
{
    if (paymentVerificationIsAuthorised($userId, $context, $requestKey)) {
        clearPaymentVerification($userId, $requestKey);
        if ($context === 'online_banking') {
            clearBankProviderAuthentication($userId, $requestKey);
            clearBankProviderLoginAttempts($userId, $requestKey);
        }
    }
}

function maskPaymentEmail(string $email): string
{
    [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
    if ($domain === '') {
        return '••••';
    }
    $visible = function_exists('mb_substr') ? mb_substr($local, 0, 2, 'UTF-8') : substr($local, 0, 2);

    return $visible . '****@' . $domain;
}
