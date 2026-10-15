<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/private-storage.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/roles.php';
require_once __DIR__ . '/security-session-service.php';

const ADOPTAPET_SESSION_NAME = 'ADOPTAPETSESSID';
const ADOPTAPET_SESSION_IDLE_TIMEOUT = 1800;
const ADOPTAPET_SESSION_ABSOLUTE_TIMEOUT = 28800;
const ADOPTAPET_SESSION_GC_LIFETIME = 43200;
const ADOPTAPET_SESSION_FINGERPRINT_KEY = 'credential_fingerprint';
const ADOPTAPET_SESSION_EXPIRED_MESSAGE = 'Your session has expired. Please sign in again.';

/** Request-local authentication state that is never written to the PHP session. */
function &authenticationSessionRequestState(): array
{
    static $state = [
        'configured' => false,
        'destroyed' => false,
        'credential_validated' => false,
        'registry_validated' => false,
        'registry_id' => null,
        'invalidation_reason' => null,
    ];
    return $state;
}

/** Trusts only the web server's direct HTTPS state, never forwarding headers. */
function authenticationRequestIsHttps(): bool
{
    $https = strtolower(trim((string) ($_SERVER['HTTPS'] ?? '')));
    return $https !== '' && !in_array($https, ['off', '0', 'false'], true);
}

/** Configures the application session before session_start(). */
function configureAuthenticationSession(): void
{
    $state = &authenticationSessionRequestState();
    if ($state['configured'] || session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_save_path(adoptAPetSessionStorageDirectory());
    // Keep the application's private/no-store response policy; PHP's default
    // session cache limiter otherwise replaces it when session_start() runs.
    session_cache_limiter('');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_secure', authenticationRequestIsHttps() ? '1' : '0');
    ini_set('session.cookie_lifetime', '0');
    ini_set('session.cookie_path', '/');
    ini_set('session.cookie_domain', '');
    ini_set('session.gc_maxlifetime', (string) ADOPTAPET_SESSION_GC_LIFETIME);
    session_name(ADOPTAPET_SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => authenticationRequestIsHttps(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $state['configured'] = true;
}

function authenticationSessionTimestamp(mixed $value): ?int
{
    if (is_int($value) && $value > 0) {
        return $value;
    }
    if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
        return (int) $value;
    }
    return null;
}

/** Returns the authenticated-session expiry reason without changing state. */
function authenticationSessionExpiryReason(array $session, ?int $now = null): ?string
{
    if (!isset($session['user_id'])) {
        return null;
    }

    $now ??= time();
    $authenticatedAt = authenticationSessionTimestamp($session['authenticated_at'] ?? null);
    $lastActivityAt = authenticationSessionTimestamp($session['last_activity_at'] ?? null);
    if ($authenticatedAt === null || $lastActivityAt === null || $authenticatedAt > $now || $lastActivityAt > $now) {
        return 'invalid_timestamps';
    }
    if ($now - $authenticatedAt > ADOPTAPET_SESSION_ABSOLUTE_TIMEOUT) {
        return 'absolute_timeout';
    }
    if ($now - $lastActivityAt > ADOPTAPET_SESSION_IDLE_TIMEOUT) {
        return 'idle_timeout';
    }
    return null;
}

function authenticationSessionCookieOptions(int $expires = 0): array
{
    configureAuthenticationSession();
    return [
        'expires' => $expires,
        'path' => '/',
        'secure' => authenticationRequestIsHttps(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function expireAuthenticationSessionCookie(): void
{
    configureAuthenticationSession();
    if ((bool) ini_get('session.use_cookies')) {
        setcookie(session_name(), '', authenticationSessionCookieOptions(time() - 42000));
    }
    unset($_COOKIE[session_name()]);
}

/** Clears identity, expires the matching cookie, and removes the session file. */
function destroyAuthenticationSession(?string $reason = null): void
{
    configureAuthenticationSession();
    $state = &authenticationSessionRequestState();
    if ($reason !== null) {
        $state['invalidation_reason'] = $reason;
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        expireAuthenticationSessionCookie();
        session_destroy();
        session_write_close();
        session_id('');
    } else {
        expireAuthenticationSessionCookie();
    }

    $state['destroyed'] = true;
    $state['credential_validated'] = false;
    $state['registry_validated'] = false;
    $state['registry_id'] = null;
}

/** Enforces inactivity and absolute lifetime only for authenticated sessions. */
function enforceAuthenticationSessionLifetime(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE || !isset($_SESSION['user_id'])) {
        return;
    }

    $reason = authenticationSessionExpiryReason($_SESSION);
    if ($reason !== null) {
        destroyAuthenticationSession($reason);
        return;
    }
    $_SESSION['last_activity_at'] = time();
}

/** Starts the application session used to identify an authenticated user. */
function startAuthenticationSession(bool $allowRestart = false): void
{
    configureAuthenticationSession();
    $state = &authenticationSessionRequestState();
    if ($state['destroyed']) {
        if (!$allowRestart) {
            return;
        }
        $state['destroyed'] = false;
        $state['invalidation_reason'] = null;
        $state['credential_validated'] = false;
        $state['registry_validated'] = false;
        $state['registry_id'] = null;
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    enforceAuthenticationSessionLifetime();
    if ($allowRestart && $state['destroyed']) {
        $state['destroyed'] = false;
        $state['invalidation_reason'] = null;
        $state['credential_validated'] = false;
        $state['registry_validated'] = false;
        $state['registry_id'] = null;
        session_start();
    }
}

/** Derives a non-secret session value without storing the raw password hash. */
function authenticationCredentialFingerprint(string $passwordHash): string
{
    return hash('sha256', "AdoptAPet\0session-credential-v1\0" . $passwordHash);
}

function authenticationSessionCredentialMatches(string $sessionFingerprint, string $passwordHash): bool
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $sessionFingerprint)) {
        return false;
    }
    return hash_equals(authenticationCredentialFingerprint($passwordHash), $sessionFingerprint);
}

/** Checks whether this session was established using the current password. */
function validateAuthenticationSessionCredential(int $userId): bool
{
    $state = &authenticationSessionRequestState();
    if ($state['credential_validated']) {
        return true;
    }

    $sessionFingerprint = $_SESSION[ADOPTAPET_SESSION_FINGERPRINT_KEY] ?? '';
    if (!is_string($sessionFingerprint)) {
        return false;
    }

    try {
        $pdo = getDatabaseConnection();
        $statement = $pdo->prepare(
            'SELECT u.password_hash, u.role, u.account_status, u.deleted_at, m.totp_secret_encrypted
             FROM users u
             LEFT JOIN administrator_mfa_settings m ON m.user_id = u.id
             WHERE u.id = :id LIMIT 1'
        );
        $statement->execute(['id' => $userId]);
        $user = $statement->fetch();
    } catch (PDOException $exception) {
        // No authenticated role may continue when its live role cannot be checked.
        return false;
    }

    if (!$user || !authenticationSessionCredentialMatches($sessionFingerprint, (string) $user['password_hash'])) {
        return false;
    }
    if ($user['account_status'] !== 'active' || $user['deleted_at'] !== null
        || !isKnownAuthenticatedRole($user['role']) || ($_SESSION['role'] ?? null) !== $user['role']) {
        return false;
    }
    if (isOperationalAdministratorRole($user['role'])) {
        $mfaFingerprint = $_SESSION['admin_mfa_fingerprint'] ?? '';
        if (!is_string($user['totp_secret_encrypted']) || !is_string($mfaFingerprint)
            || !hash_equals(hash('sha256', $user['totp_secret_encrypted']), $mfaFingerprint)) {
            return false;
        }
    }
    $state['credential_validated'] = true;
    return true;
}

function authenticationSessionInvalidationReason(): ?string
{
    $state = &authenticationSessionRequestState();
    return is_string($state['invalidation_reason']) ? $state['invalidation_reason'] : null;
}

function authenticationSessionFailure(): never
{
    $reason = authenticationSessionInvalidationReason();
    $revoked = $reason === 'session_revoked';
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'code' => $revoked ? 'session_revoked' : 'session_expired',
        'message' => $revoked ? 'This session was signed out. Please sign in again.' : ADOPTAPET_SESSION_EXPIRED_MESSAGE,
    ]);
    exit;
}

/** Returns the authenticated user's ID, or null when no user is signed in. */
function authenticatedUserId(bool $required = true): ?int
{
    startAuthenticationSession();
    $reason = authenticationSessionInvalidationReason();
    if ($reason !== null) {
        if ($required) {
            authenticationSessionFailure();
        }
        return null;
    }

    $userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    if ($userId === null || $userId < 1) {
        return null;
    }
    if (!validateAuthenticationSessionCredential($userId)) {
        destroyAuthenticationSession('credential_changed');
        if ($required) {
            authenticationSessionFailure();
        }
        return null;
    }
    $registryReason = securityValidateAuthenticatedSession($userId);
    if ($registryReason !== null) {
        destroyAuthenticationSession($registryReason);
        if ($required) {
            authenticationSessionFailure();
        }
        return null;
    }
    return $userId;
}

/** Stores the minimum identity and revocation data needed by the session. */
function signInUser(int $userId, string $role, string $passwordHash): void
{
    if (!isKnownAuthenticatedRole($role)) {
        throw new InvalidArgumentException('Unsupported authenticated role.');
    }
    startAuthenticationSession(true);
    if (!session_regenerate_id(true)) {
        throw new RuntimeException('Unable to rotate the authentication session.');
    }
    $now = time();
    $_SESSION['user_id'] = $userId;
    $_SESSION['role'] = $role;
    $_SESSION['authenticated_at'] = $now;
    $_SESSION['last_activity_at'] = $now;
    $_SESSION[ADOPTAPET_SESSION_FINGERPRINT_KEY] = authenticationCredentialFingerprint($passwordHash);
    unset($_SESSION['admin_mfa_fingerprint'], $_SESSION['pending_admin_mfa']);
    unset($_SESSION[SECURITY_SESSION_HANDLE_KEY], $_SESSION[SECURITY_SESSION_TOUCH_KEY]);
    $state = &authenticationSessionRequestState();
    $state['credential_validated'] = true;
    $state['registry_validated'] = false;
    $state['registry_id'] = null;
    $state['invalidation_reason'] = null;
}

/** Live database role and active status remain authoritative for operational Admin APIs. */
function authenticatedOperationalAdministratorId(PDO $pdo): ?int
{
    $userId = authenticatedUserId(false);
    if ($userId === null) {
        return null;
    }
    $statement = $pdo->prepare(
        'SELECT role FROM users WHERE id = :id AND account_status = :status AND deleted_at IS NULL LIMIT 1'
    );
    $statement->execute(['id' => $userId, 'status' => 'active']);
    return isOperationalAdministratorRole($statement->fetchColumn()) ? $userId : null;
}

function requireOperationalAdministrator(PDO $pdo): int
{
    $userId = authenticatedUserId();
    if ($userId === null) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Authentication is required.']);
        exit;
    }
    if (authenticatedOperationalAdministratorId($pdo) === null) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Administrator access is required.']);
        exit;
    }
    return $userId;
}

/** Keeps the current browser signed in after its own password change. */
function refreshAuthenticationSessionCredential(string $passwordHash): void
{
    startAuthenticationSession();
    if (!isset($_SESSION['user_id'])) {
        authenticationSessionFailure();
    }
    $_SESSION[ADOPTAPET_SESSION_FINGERPRINT_KEY] = authenticationCredentialFingerprint($passwordHash);
    $_SESSION['last_activity_at'] = time();
    $state = &authenticationSessionRequestState();
    $state['credential_validated'] = true;
    $state['invalidation_reason'] = null;
}
