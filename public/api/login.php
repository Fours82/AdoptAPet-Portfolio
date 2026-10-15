<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['success' => false, 'message' => 'Only POST requests are allowed.']);
    exit;
}

require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/request-abuse.php';
require_once __DIR__ . '/db.php';
require_once dirname(__DIR__, 2) . '/config/security.php';
require_once __DIR__ . '/admin-mfa-service.php';

function recordLoginAttempt(PDO $pdo, ?int $userId, string $email, bool $wasSuccessful, ?string $failureReason): void
{
    $statement = $pdo->prepare(
        'INSERT INTO login_attempts (user_id, email_attempted, was_successful, failure_reason, ip_address, user_agent)
         VALUES (:user_id, :email_attempted, :was_successful, :failure_reason, :ip_address, :user_agent)'
    );
    $statement->execute([
        'user_id' => $userId,
        'email_attempted' => $email,
        'was_successful' => $wasSuccessful ? 1 : 0,
        'failure_reason' => $failureReason,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 500) : null,
    ]);
}

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON request body.']);
    exit;
}

$email = strtolower(trim((string) ($input['email'] ?? '')));
$password = (string) ($input['password'] ?? '');
$captchaToken = isset($input['hcaptcha_token']) ? (string) $input['hcaptcha_token'] : null;

if ($email === '' || strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL)
    || $password === '' || strlen($password) > 1024) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address and password.']);
    exit;
}

if (requestAbuseIncrement('login_requests', 60) > 30) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Too many sign-in requests. Please wait a minute and try again.']);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    $findUser = $pdo->prepare(
        'SELECT id, username, name, email, password_hash, role, account_status, failed_login_count, locked_until
         FROM users
         WHERE email = :email AND deleted_at IS NULL
         LIMIT 1'
    );
    $findUser->execute(['email' => $email]);
    $user = $findUser->fetch();

    if ($user && $user['locked_until'] !== null && strtotime($user['locked_until']) > time()) {
        recordLoginAttempt($pdo, (int) $user['id'], $email, false, 'account_locked');
        if (password_verify($password, $user['password_hash'])) {
            http_response_code(423);
            echo json_encode(['success' => false, 'message' => 'This account is temporarily locked after repeated failed sign-in attempts. Please try again after ' . date('g:i A', strtotime($user['locked_until'])) . '.']);
            exit;
        }
        $browserFailures = requestAbuseIncrement('login_failures', 300);
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid email or password.',
            'captcha_required' => hcaptchaConfiguration()['enabled'] && $browserFailures >= 2,
        ]);
        exit;
    }

    if ($user && $user['locked_until'] !== null) {
        $clearExpiredLock = $pdo->prepare('UPDATE users SET failed_login_count = 0, locked_until = NULL WHERE id = :id');
        $clearExpiredLock->execute(['id' => $user['id']]);
        $user['failed_login_count'] = 0;
        $user['locked_until'] = null;
    }

    $captchaRequired = hcaptchaConfiguration()['enabled']
        && (($user && (int) $user['failed_login_count'] >= 2)
            || requestAbuseCount('login_failures', 300) >= 2);
    if ($captchaRequired) {
        $captcha = verifyHcaptchaToken($captchaToken, $_SERVER['REMOTE_ADDR'] ?? null);
        if (!$captcha['success']) {
            http_response_code($captcha['unavailable'] ? 503 : 422);
            echo json_encode([
                'success' => false,
                'captcha_required' => true,
                'message' => $captcha['unavailable']
                    ? 'The security check is temporarily unavailable. Please try again later.'
                    : 'Complete the security check before signing in.',
            ]);
            exit;
        }
    }

    if (!$user || !password_verify($password, $user['password_hash'])) {
        $browserFailures = requestAbuseIncrement('login_failures', 300);
        if ($user) {
            $registerFailure = $pdo->prepare(
                'UPDATE users
                 SET failed_login_count = failed_login_count + 1,
                     locked_until = CASE WHEN failed_login_count + 1 >= 5 THEN DATE_ADD(NOW(), INTERVAL 15 MINUTE) ELSE locked_until END
                 WHERE id = :id'
            );
            $registerFailure->execute(['id' => $user['id']]);
            if ((int) $user['failed_login_count'] + 1 >= 5) {
                recordLoginAttempt($pdo, (int) $user['id'], $email, false, 'account_locked');
            } else {
                recordLoginAttempt($pdo, (int) $user['id'], $email, false, 'invalid_credentials');
            }
        } else {
            recordLoginAttempt($pdo, null, $email, false, 'invalid_credentials');
        }
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid email or password.',
            'captcha_required' => hcaptchaConfiguration()['enabled'] && $browserFailures >= 2,
        ]);
        exit;
    }

    $findVerification = $pdo->prepare('SELECT verified_at FROM email_verifications WHERE user_id = :user_id ORDER BY id DESC LIMIT 1');
    $findVerification->execute(['user_id' => $user['id']]);
    $verification = $findVerification->fetch();

    if ($user['account_status'] === 'deactivated' && $verification && $verification['verified_at'] === null) {
        recordLoginAttempt($pdo, (int) $user['id'], $email, false, 'email_not_verified');
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Please verify your email address before logging in.']);
        exit;
    }

    if ($user['account_status'] !== 'active') {
        recordLoginAttempt($pdo, (int) $user['id'], $email, false, 'account_not_active');
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'This account is not active.']);
        exit;
    }

    if (!isKnownAuthenticatedRole($user['role'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'This account cannot sign in.']);
        exit;
    }

    if (isOperationalAdministratorRole($user['role'])) {
        if (administratorMfaEncryptionKey() === null) {
            http_response_code(503);
            echo json_encode(['success' => false, 'message' => 'Administrator sign-in is temporarily unavailable.']);
            exit;
        }
        $mfaStatement = $pdo->prepare('SELECT user_id FROM administrator_mfa_settings WHERE user_id = :user_id LIMIT 1');
        $mfaStatement->execute(['user_id' => $user['id']]);
        $mode = $mfaStatement->fetchColumn() === false ? 'setup' : 'verify';
    }

    if (!isOperationalAdministratorRole($user['role'])) {
        recordLoginAttempt($pdo, (int) $user['id'], $email, true, null);
    }
    $resetFailures = $pdo->prepare(
        'UPDATE users SET failed_login_count = 0, locked_until = NULL'
        . (isOperationalAdministratorRole($user['role']) ? '' : ', last_login_at = NOW()') . ' WHERE id = :id'
    );
    $resetFailures->execute(['id' => $user['id']]);
    $previousUserId = authenticatedUserId(false);
    if ($previousUserId !== null) {
        $pdo->beginTransaction();
        securityEndCurrentSession($pdo, $previousUserId, 'replaced_by_login');
        securityRecordEvent($pdo, $previousUserId, 'logout');
        $pdo->commit();
    }
    if (isOperationalAdministratorRole($user['role'])) {
        requestAbuseClear('login_failures');
        requestAbuseClear('login_requests');
        administratorMfaBeginPending((int) $user['id'], $user['password_hash'], $mode, $user['role']);
        echo json_encode([
            'success' => true,
            'mfa_required' => true,
            'mfa_mode' => $mode,
            'csrf_token' => rotateCsrfToken(),
        ]);
        exit;
    }
    requestAbuseClear('login_failures');
    requestAbuseClear('login_requests');
    signInUser((int) $user['id'], $user['role'], $user['password_hash']);
    securityRegisterAuthenticatedSession($pdo, (int) $user['id']);
    $csrfToken = rotateCsrfToken();

    echo json_encode([
        'success' => true,
        'csrf_token' => $csrfToken,
        'message' => 'Login successful.',
        'user' => [
            'id' => (int) $user['id'],
            'username' => $user['username'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
            'account_status' => $user['account_status'],
        ],
    ]);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to sign in. Please try again later.']);
}
