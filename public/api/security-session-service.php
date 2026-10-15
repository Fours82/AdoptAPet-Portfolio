<?php
declare(strict_types=1);

const SECURITY_SESSION_HANDLE_KEY = 'security_session_handle';
const SECURITY_SESSION_TOUCH_KEY = 'security_session_last_db_touch';
const SECURITY_SESSION_TOUCH_SECONDS = 300;
const SECURITY_EVENT_TYPES = [
    'login_success', 'logout', 'password_changed', 'password_reset',
    'email_changed', 'mfa_enabled', 'mfa_reset', 'mfa_reenrolled',
    'recovery_code_used', 'session_revoked', 'other_sessions_revoked',
    'account_access_changed',
    'promoted_to_administrator', 'demoted_to_registered_user',
    'promoted_to_super_administrator', 'demoted_to_administrator',
    'administrator_account_deactivated', 'administrator_account_reactivated',
];
const SECURITY_REVOCATION_REASONS = [
    'logout', 'password_changed', 'password_reset', 'email_changed',
    'mfa_reset', 'owner_revoked', 'owner_revoked_others', 'replaced_by_login',
    'account_status_changed',
    'role_changed',
];

function securitySessionDigest(string $handle): string
{
    if (strlen($handle) !== 32) {
        throw new InvalidArgumentException('Invalid internal session handle.');
    }
    return hash('sha256', "AdoptAPet\0auth-session-registry-v1\0" . $handle, true);
}

function securityRequestUserAgent(): ?string
{
    $agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (!is_string($agent)) {
        return null;
    }
    $agent = substr((string) preg_replace('/[^\x20-\x7e]/', '', $agent), 0, 500);
    return $agent !== '' ? $agent : null;
}

function securityRequestIpAddress(): ?string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
}

/** User-Agent is display-only and never an authentication factor. */
function securityDeviceSummary(?string $agent): string
{
    if ($agent === null || $agent === '') {
        return 'Unknown device';
    }
    $os = match (true) {
        str_contains($agent, 'iPhone') => 'iPhone',
        str_contains($agent, 'iPad') => 'iPad',
        str_contains($agent, 'Android') => 'Android',
        str_contains($agent, 'Windows') => 'Windows',
        str_contains($agent, 'Mac OS X'), str_contains($agent, 'Macintosh') => 'macOS',
        str_contains($agent, 'Linux') => 'Linux',
        default => 'Unknown device',
    };
    $browser = match (true) {
        str_contains($agent, 'Edg/') => 'Edge',
        str_contains($agent, 'OPR/'), str_contains($agent, 'Opera') => 'Opera',
        str_contains($agent, 'Firefox/'), str_contains($agent, 'FxiOS/') => 'Firefox',
        str_contains($agent, 'Chrome/'), str_contains($agent, 'CriOS/') => 'Chrome',
        str_contains($agent, 'Safari/') => 'Safari',
        default => 'Browser',
    };
    return $browser . ' on ' . $os;
}

function securityApproximateIp(?string $ip): ?string
{
    if ($ip === null || !filter_var($ip, FILTER_VALIDATE_IP)) {
        return null;
    }
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $parts = explode('.', $ip);
        return implode('.', array_slice($parts, 0, 3)) . '.x';
    }
    $packed = inet_pton($ip);
    if ($packed === false) {
        return null;
    }
    return implode(':', array_map(
        static fn (int $part): string => dechex($part),
        array_values(unpack('n4', substr($packed, 0, 8)))
    )) . '::/64';
}

function securityRecordEvent(PDO $pdo, int $userId, string $eventType, bool $includeRequestDevice = true): void
{
    if (!in_array($eventType, SECURITY_EVENT_TYPES, true)) {
        throw new InvalidArgumentException('Unsupported security event type.');
    }
    $statement = $pdo->prepare(
        'INSERT INTO user_security_events (user_id, event_type, occurred_at, device_summary, ip_address)
         VALUES (:user_id, :event_type, UTC_TIMESTAMP(), :device_summary, :ip_address)'
    );
    $statement->execute([
        'user_id' => $userId,
        'event_type' => $eventType,
        'device_summary' => $includeRequestDevice ? securityDeviceSummary(securityRequestUserAgent()) : 'Account administration',
        'ip_address' => $includeRequestDevice ? securityRequestIpAddress() : null,
    ]);
}

/** Called once, after full password or Administrator MFA authentication. */
function securityRegisterAuthenticatedSession(PDO $pdo, int $userId): void
{
    if ($pdo->inTransaction()) {
        throw new LogicException('Session registration requires a completed authentication transaction.');
    }
    $handle = random_bytes(32);
    $digest = securitySessionDigest($handle);
    $authenticatedAt = authenticationSessionTimestamp($_SESSION['authenticated_at'] ?? null);
    if ($authenticatedAt === null || (int) ($_SESSION['user_id'] ?? 0) !== $userId) {
        destroyAuthenticationSession('registry_unavailable');
        throw new LogicException('Full authentication is required before session registration.');
    }
    $role = $_SESSION['role'] ?? null;
    $adminMarker = $_SESSION['admin_mfa_fingerprint'] ?? null;
    if (!isKnownAuthenticatedRole($role)
        || (isOperationalAdministratorRole($role) && (!is_string($adminMarker) || !preg_match('/^[a-f0-9]{64}$/D', $adminMarker)))) {
        destroyAuthenticationSession('registry_unavailable');
        throw new LogicException('Completed account authentication is required before session registration.');
    }
    try {
        $pdo->beginTransaction();
        $statement = $pdo->prepare(
            'INSERT INTO user_auth_sessions
             (user_id, session_handle_hash, created_at, last_active_at, absolute_expires_at, user_agent, ip_address)
             VALUES (:user_id, :handle_hash, UTC_TIMESTAMP(), UTC_TIMESTAMP(), :expires_at, :user_agent, :ip_address)'
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':handle_hash', $digest, PDO::PARAM_LOB);
        $statement->bindValue(':expires_at', gmdate('Y-m-d H:i:s', $authenticatedAt + ADOPTAPET_SESSION_ABSOLUTE_TIMEOUT));
        $statement->bindValue(':user_agent', securityRequestUserAgent());
        $statement->bindValue(':ip_address', securityRequestIpAddress());
        $statement->execute();
        $registryId = (int) $pdo->lastInsertId();
        securityRecordEvent($pdo, $userId, 'login_success');
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        destroyAuthenticationSession('registry_unavailable');
        throw $exception;
    }
    $_SESSION[SECURITY_SESSION_HANDLE_KEY] = $handle;
    $_SESSION[SECURITY_SESSION_TOUCH_KEY] = time();
    $state = &authenticationSessionRequestState();
    $state['registry_validated'] = true;
    $state['registry_id'] = $registryId;
}

/** Returns a reason on failure; successful checks run on each protected request. */
function securityValidateAuthenticatedSession(int $userId, ?PDO $connection = null): ?string
{
    $state = &authenticationSessionRequestState();
    if ($state['registry_validated']) {
        return null;
    }
    $handle = $_SESSION[SECURITY_SESSION_HANDLE_KEY] ?? null;
    if (!is_string($handle) || strlen($handle) !== 32) {
        return 'registry_missing';
    }
    try {
        $pdo = $connection ?? getDatabaseConnection();
        $statement = $pdo->prepare(
            'SELECT id FROM user_auth_sessions
             WHERE user_id = :user_id AND session_handle_hash = :handle_hash
               AND revoked_at IS NULL AND absolute_expires_at > UTC_TIMESTAMP()
             LIMIT 1'
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':handle_hash', securitySessionDigest($handle), PDO::PARAM_LOB);
        $statement->execute();
        $id = $statement->fetchColumn();
        if ($id === false) {
            return 'session_revoked';
        }
        $lastTouch = authenticationSessionTimestamp($_SESSION[SECURITY_SESSION_TOUCH_KEY] ?? null);
        if ($lastTouch === null || time() - $lastTouch >= SECURITY_SESSION_TOUCH_SECONDS) {
            $touch = $pdo->prepare(
                'UPDATE user_auth_sessions SET last_active_at = UTC_TIMESTAMP()
                 WHERE id = :id AND user_id = :user_id AND revoked_at IS NULL
                   AND absolute_expires_at > UTC_TIMESTAMP()'
            );
            $touch->execute(['id' => $id, 'user_id' => $userId]);
            if ($touch->rowCount() !== 1) {
                return 'session_revoked';
            }
            $_SESSION[SECURITY_SESSION_TOUCH_KEY] = time();
        }
        $state['registry_validated'] = true;
        $state['registry_id'] = (int) $id;
        return null;
    } catch (Throwable $exception) {
        return 'registry_unavailable';
    }
}

function securityCurrentRegistryId(): int
{
    $state = &authenticationSessionRequestState();
    $id = (int) ($state['registry_id'] ?? 0);
    if ($id < 1) {
        throw new RuntimeException('Current session registry entry is unavailable.');
    }
    return $id;
}

function securityRevokeOtherSessions(PDO $pdo, int $userId, int $currentId, string $reason): int
{
    if (!in_array($reason, SECURITY_REVOCATION_REASONS, true)) {
        throw new InvalidArgumentException('Unsupported session revocation reason.');
    }
    $statement = $pdo->prepare(
        'UPDATE user_auth_sessions SET revoked_at = UTC_TIMESTAMP(), revoked_reason = :reason
         WHERE user_id = :user_id AND id <> :current_id AND revoked_at IS NULL'
    );
    $statement->execute(['reason' => $reason, 'user_id' => $userId, 'current_id' => $currentId]);
    return $statement->rowCount();
}

function securityRevokeAllSessions(PDO $pdo, int $userId, string $reason): int
{
    if (!in_array($reason, SECURITY_REVOCATION_REASONS, true)) {
        throw new InvalidArgumentException('Unsupported session revocation reason.');
    }
    $statement = $pdo->prepare(
        'UPDATE user_auth_sessions SET revoked_at = UTC_TIMESTAMP(), revoked_reason = :reason
         WHERE user_id = :user_id AND revoked_at IS NULL'
    );
    $statement->execute(['reason' => $reason, 'user_id' => $userId]);
    return $statement->rowCount();
}

function securityEndCurrentSession(PDO $pdo, int $userId, string $reason): void
{
    if (!in_array($reason, SECURITY_REVOCATION_REASONS, true)) {
        throw new InvalidArgumentException('Unsupported session revocation reason.');
    }
    $statement = $pdo->prepare(
        'UPDATE user_auth_sessions SET revoked_at = UTC_TIMESTAMP(), revoked_reason = :reason
         WHERE id = :id AND user_id = :user_id AND revoked_at IS NULL'
    );
    $statement->execute(['reason' => $reason, 'id' => securityCurrentRegistryId(), 'user_id' => $userId]);
}

function securityRevokeOneOtherSession(PDO $pdo, int $userId, int $currentId, int $targetId): bool
{
    if ($targetId < 1 || $targetId === $currentId) {
        return false;
    }
    $statement = $pdo->prepare(
        'UPDATE user_auth_sessions SET revoked_at = UTC_TIMESTAMP(), revoked_reason = :reason
         WHERE id = :id AND user_id = :user_id AND id <> :current_id AND revoked_at IS NULL
           AND absolute_expires_at > UTC_TIMESTAMP()'
    );
    $statement->execute([
        'reason' => 'owner_revoked', 'id' => $targetId,
        'user_id' => $userId, 'current_id' => $currentId,
    ]);
    return $statement->rowCount() === 1;
}
