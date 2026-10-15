<?php
declare(strict_types=1);

const EMAIL_CHANGE_CODE_EXPIRY_SECONDS = 600;
const EMAIL_CHANGE_RESEND_COOLDOWN_SECONDS = 60;
const EMAIL_CHANGE_MAX_ATTEMPTS = 5;

function normalizeEmailChangeAddress(string $email): string
{
    return strtolower(trim($email));
}

function isValidEmailChangeAddress(string $email): bool
{
    return $email !== ''
        && strlen($email) <= 255
        && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function generateEmailChangeCode(): string
{
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function isValidEmailChangeCodeFormat(string $code): bool
{
    return preg_match('/^[0-9]{6}$/D', $code) === 1;
}

function emailChangeInvalidCodeMessage(int $attempts): string
{
    $remaining = EMAIL_CHANGE_MAX_ATTEMPTS - $attempts;
    if ($remaining <= 0) {
        return 'The verification code is invalid or has expired.';
    }

    return sprintf('Invalid verification code. %d attempt%s remaining.',
        $remaining,
        $remaining === 1 ? '' : 's');
}

function maskEmailChangeAddress(string $email): string
{
    [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
    if ($local === '' || $domain === '') {
        return 'the new email address';
    }

    $visibleLength = strlen($local) > 2 ? 2 : 1;
    return substr($local, 0, $visibleLength) . '***@' . $domain;
}

function emailChangePendingResponse(array $request): array
{
    return [
        'destination' => maskEmailChangeAddress((string) $request['new_email']),
        'expires_in' => max(0, (int) ($request['expires_in'] ?? 0)),
        'resend_after' => max(0, (int) ($request['resend_after'] ?? 0)),
        'attempts_remaining' => max(0, EMAIL_CHANGE_MAX_ATTEMPTS - (int) ($request['attempts'] ?? 0)),
    ];
}
