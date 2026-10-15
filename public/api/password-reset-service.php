<?php
declare(strict_types=1);

const ADOPTAPET_PASSWORD_RESET_TOKEN_BYTES = 32;
const ADOPTAPET_PASSWORD_RESET_TOKEN_PATTERN = '/^[A-Za-z0-9_-]{43}$/';
const ADOPTAPET_PASSWORD_RESET_INVALID_MESSAGE = 'This password reset link is invalid or has expired.';

function generatePasswordResetToken(): string
{
    return rtrim(strtr(base64_encode(random_bytes(ADOPTAPET_PASSWORD_RESET_TOKEN_BYTES)), '+/', '-_'), '=');
}

function passwordResetTokenIsWellFormed(string $token): bool
{
    return preg_match(ADOPTAPET_PASSWORD_RESET_TOKEN_PATTERN, $token) === 1;
}

function hashPasswordResetToken(string $token): string
{
    return hash('sha256', $token);
}

function findPasswordResetRequest(PDO $pdo, string $tokenHash, bool $forUpdate = false): array|false
{
    $statement = $pdo->prepare(
        'SELECT pr.id, pr.user_id, pr.reset_code_hash, pr.used_at,
                pr.expires_at <= NOW() AS is_expired,
                u.account_status, u.deleted_at
         FROM password_reset_codes pr
         INNER JOIN users u ON u.id = pr.user_id
         WHERE pr.reset_code_hash = :token_hash
         ORDER BY pr.id DESC
         LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '')
    );
    $statement->execute(['token_hash' => $tokenHash]);
    return $statement->fetch();
}

function passwordResetRequestIsUsable(array|false $request, string $tokenHash): bool
{
    return is_array($request)
        && is_string($request['reset_code_hash'] ?? null)
        && hash_equals($request['reset_code_hash'], $tokenHash)
        && ($request['used_at'] ?? null) === null
        && (int) ($request['is_expired'] ?? 1) === 0
        && ($request['account_status'] ?? null) === 'active'
        && ($request['deleted_at'] ?? null) === null;
}
