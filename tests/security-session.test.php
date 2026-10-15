<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/public/api/auth.php';
require_once dirname(__DIR__) . '/public/api/admin-mfa-service.php';

function checkSession(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException('Security session test failed: ' . $label);
    }
}

// Isolated in-memory registry: this does not touch the AdoptAPet database.
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->sqliteCreateFunction('UTC_TIMESTAMP', static fn (): string => gmdate('Y-m-d H:i:s'));
$pdo->exec('CREATE TABLE user_auth_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
    session_handle_hash BLOB NOT NULL UNIQUE, created_at TEXT NOT NULL, last_active_at TEXT NOT NULL,
    absolute_expires_at TEXT NOT NULL, revoked_at TEXT, revoked_reason TEXT, user_agent TEXT, ip_address TEXT)');
$pdo->exec('CREATE TABLE user_security_events (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL,
    event_type TEXT NOT NULL, occurred_at TEXT NOT NULL, device_summary TEXT, ip_address TEXT)');
$pdo->exec('CREATE TABLE touch_audit (id INTEGER PRIMARY KEY AUTOINCREMENT)');
$pdo->exec('CREATE TRIGGER audit_touch AFTER UPDATE OF last_active_at ON user_auth_sessions
    BEGIN INSERT INTO touch_audit (id) VALUES (NULL); END');

$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/120.0 Safari/537.36';
$_SERVER['REMOTE_ADDR'] = '192.0.2.42';
checkSession(securityDeviceSummary(securityRequestUserAgent()) === 'Chrome on Windows', 'device summary');
checkSession(securityDeviceSummary('Mozilla/5.0 (iPhone) AppleWebKit/605.1.15 Mobile Safari/604.1') === 'Safari on iPhone', 'iPhone summary');
checkSession(securityDeviceSummary('Mozilla/5.0 (X11; Linux x86_64) Gecko/20100101 Firefox/120.0') === 'Firefox on Linux', 'Linux summary');
checkSession(securityApproximateIp('192.0.2.42') === '192.0.2.x', 'IPv4 privacy');
checkSession(str_ends_with((string) securityApproximateIp('2001:db8::1'), '::/64'), 'IPv6 privacy');
checkSession(strlen(securitySessionDigest(random_bytes(32))) === 32, 'raw BINARY(32) digest');

signInUser(101, 'registered_user', 'synthetic-test-password-hash');
securityRegisterAuthenticatedSession($pdo, 101);
$firstId = securityCurrentRegistryId();
$firstHandle = $_SESSION[SECURITY_SESSION_HANDLE_KEY];
$previousPhpSessionId = session_id();
checkSession(session_regenerate_id(true), 'PHP session ID regeneration');
checkSession(session_id() !== $previousPhpSessionId && $_SESSION[SECURITY_SESSION_HANDLE_KEY] === $firstHandle,
    'registry handle survives PHP session ID regeneration');
$stored = $pdo->query('SELECT session_handle_hash FROM user_auth_sessions WHERE id = ' . $firstId)->fetchColumn();
checkSession(is_string($stored) && strlen($stored) === 32, 'stored digest length');
checkSession(hash_equals($stored, securitySessionDigest($firstHandle)) && !hash_equals($stored, $firstHandle), 'digest not bearer handle');
checkSession((int) $pdo->query('SELECT COUNT(*) FROM user_security_events')->fetchColumn() === 1, 'login event');

$state = &authenticationSessionRequestState();
$state['registry_validated'] = false;
checkSession(securityValidateAuthenticatedSession(101, $pdo) === null, 'active registry validation');
checkSession((int) $pdo->query('SELECT COUNT(*) FROM touch_audit')->fetchColumn() === 0, 'no DB touch on every request');
$state['registry_validated'] = false;
$_SESSION[SECURITY_SESSION_TOUCH_KEY] = time() - SECURITY_SESSION_TOUCH_SECONDS - 1;
checkSession(securityValidateAuthenticatedSession(101, $pdo) === null, 'throttled touch succeeds');
checkSession((int) $pdo->query('SELECT COUNT(*) FROM touch_audit')->fetchColumn() === 1, 'five-minute DB touch');
$state['registry_validated'] = false;
checkSession(securityValidateAuthenticatedSession(202, $pdo) === 'session_revoked', 'owner mismatch denied');

signInUser(101, 'registered_user', 'synthetic-test-password-hash');
securityRegisterAuthenticatedSession($pdo, 101);
$secondId = securityCurrentRegistryId();
$secondHandle = $_SESSION[SECURITY_SESSION_HANDLE_KEY];
checkSession($firstId !== $secondId, 'separate login rows');
checkSession(!securityRevokeOneOtherSession($pdo, 101, $secondId, $secondId), 'current session protected');
checkSession(!securityRevokeOneOtherSession($pdo, 202, $secondId, $firstId), 'other user cannot revoke');
checkSession(securityRevokeOneOtherSession($pdo, 101, $secondId, $firstId), 'owner revokes another session');
$_SESSION[SECURITY_SESSION_HANDLE_KEY] = $firstHandle;
$state['registry_validated'] = false;
checkSession(securityValidateAuthenticatedSession(101, $pdo) === 'session_revoked', 'revoked session fails next request');
$_SESSION[SECURITY_SESSION_HANDLE_KEY] = $secondHandle;
$state['registry_validated'] = false;
checkSession(securityValidateAuthenticatedSession(101, $pdo) === null, 'current session survives revoke');

signInUser(101, 'registered_user', 'synthetic-test-password-hash');
securityRegisterAuthenticatedSession($pdo, 101);
$thirdId = securityCurrentRegistryId();
checkSession(securityRevokeOtherSessions($pdo, 101, $thirdId, 'owner_revoked_others') === 1, 'sign out all other active sessions');
checkSession((int) $pdo->query('SELECT COUNT(*) FROM user_auth_sessions WHERE user_id = 101 AND revoked_at IS NULL')->fetchColumn() === 1, 'current remains active');
signInUser(101, 'registered_user', 'synthetic-test-password-hash');
securityRegisterAuthenticatedSession($pdo, 101);
securityEndCurrentSession($pdo, 101, 'logout');
checkSession((int) $pdo->query('SELECT COUNT(*) FROM user_auth_sessions WHERE user_id = 101 AND revoked_at IS NULL')->fetchColumn() === 1, 'logout leaves other session valid');
checkSession(securityRevokeAllSessions($pdo, 101, 'password_reset') === 1, 'password reset revokes remaining session');

$invalidEventRejected = false;
try {
    securityRecordEvent($pdo, 101, 'frontend_supplied_event');
} catch (InvalidArgumentException $exception) {
    $invalidEventRejected = true;
}
checkSession($invalidEventRejected, 'server event whitelist');

for ($i = 0; $i < 23; $i++) {
    securityRecordEvent($pdo, 101, 'session_revoked');
}
$pageTwo = $pdo->prepare('SELECT id FROM user_security_events WHERE user_id = :user_id
    ORDER BY occurred_at DESC, id DESC LIMIT 10 OFFSET :offset');
$pageTwo->bindValue(':user_id', 101, PDO::PARAM_INT);
$pageTwo->bindValue(':offset', 10, PDO::PARAM_INT);
$pageTwo->execute();
checkSession(count($pageTwo->fetchAll()) === 10, 'ten-event activity page');

unset($_SESSION[SECURITY_SESSION_HANDLE_KEY]);
$state['registry_validated'] = false;
checkSession(securityValidateAuthenticatedSession(101, $pdo) === 'registry_missing', 'pre-rollout session requires login');
destroyAuthenticationSession();

$priorRows = (int) $pdo->query('SELECT COUNT(*) FROM user_auth_sessions')->fetchColumn();
administratorMfaBeginPending(303, 'synthetic-admin-password-hash', 'verify');
checkSession(!isset($_SESSION['user_id'], $_SESSION[SECURITY_SESSION_HANDLE_KEY]), 'pending MFA has no full identity or registry handle');
checkSession((int) $pdo->query('SELECT COUNT(*) FROM user_auth_sessions')->fetchColumn() === $priorRows, 'pending MFA creates no registry row');
administratorMfaPromote(['id' => 303, 'role' => 'administrator', 'password_hash' => 'synthetic-admin-password-hash'], random_bytes(64));
securityRegisterAuthenticatedSession($pdo, 303);
checkSession((int) $pdo->query('SELECT COUNT(*) FROM user_auth_sessions WHERE user_id = 303')->fetchColumn() === 1,
    'promoted Administrator receives registry row');
destroyAuthenticationSession();

administratorMfaBeginPending(404, 'synthetic-super-password-hash', 'verify', 'super_administrator');
checkSession(administratorMfaPending()['role'] === 'super_administrator'
    && !isset($_SESSION['user_id'], $_SESSION[SECURITY_SESSION_HANDLE_KEY]), 'Super Administrator pending MFA has no registry identity');
administratorMfaPromote(['id' => 404, 'role' => 'super_administrator', 'password_hash' => 'synthetic-super-password-hash'], random_bytes(64));
securityRegisterAuthenticatedSession($pdo, 404);
checkSession($_SESSION['role'] === 'super_administrator'
    && (int) $pdo->query('SELECT COUNT(*) FROM user_auth_sessions WHERE user_id = 404')->fetchColumn() === 1,
    'Super Administrator receives registry row only after MFA');
destroyAuthenticationSession();

echo "Security session digest, registration, ownership, revocation, and rollout tests passed.\n";
