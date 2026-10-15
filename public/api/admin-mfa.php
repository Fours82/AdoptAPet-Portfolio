<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/admin-mfa-service.php';
require_once __DIR__ . '/admin-mfa-qr.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/db.php';

function adminMfaReply(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body);
    exit;
}

function adminMfaInvalidCode(): never
{
    $restart = administratorMfaFailedAttempt();
    adminMfaReply(422, [
        'success' => false,
        'restart_login' => $restart,
        'message' => $restart
            ? 'Too many unsuccessful attempts. Sign in with your password again.'
            : 'Invalid authentication code. Please try again.',
    ]);
}

function adminMfaInvalidReset(): never
{
    $_SESSION['admin_mfa_reset_failures'] = (int) ($_SESSION['admin_mfa_reset_failures'] ?? 0) + 1;
    if ($_SESSION['admin_mfa_reset_failures'] >= 5) {
        destroyAuthenticationSession('mfa_reset_attempts');
        adminMfaReply(401, [
            'success' => false, 'code' => 'session_expired',
            'message' => 'Sign in again before changing your authenticator.',
        ]);
    }
    adminMfaReply(422, ['success' => false, 'message' => 'Current password and authentication code could not be verified.']);
}

function adminMfaActiveUser(PDO $pdo, int $userId, string $fingerprint, string $expectedRole): ?array
{
    $statement = $pdo->prepare(
        'SELECT id, email, password_hash, role, account_status
         FROM users WHERE id = :id AND deleted_at IS NULL FOR UPDATE'
    );
    $statement->execute(['id' => $userId]);
    $user = $statement->fetch();
    return $user && isOperationalAdministratorRole($user['role']) && $user['role'] === $expectedRole
        && $user['account_status'] === 'active'
        && authenticationSessionCredentialMatches($fingerprint, (string) $user['password_hash'])
        ? $user : null;
}

function adminMfaConsumeRecovery(PDO $pdo, int $userId, string $code): bool
{
    $normalized = administratorMfaNormalizeRecovery($code);
    if (!preg_match('/^[A-F0-9]{16}$/D', $normalized)) {
        return false;
    }
    $statement = $pdo->prepare(
        'SELECT id, code_hash FROM administrator_mfa_recovery_codes
         WHERE user_id = :user_id AND used_at IS NULL FOR UPDATE'
    );
    $statement->execute(['user_id' => $userId]);
    foreach ($statement->fetchAll() as $row) {
        if (password_verify($normalized, (string) $row['code_hash'])) {
            $consume = $pdo->prepare(
                'UPDATE administrator_mfa_recovery_codes SET used_at = NOW()
                 WHERE id = :id AND user_id = :user_id AND used_at IS NULL'
            );
            $consume->execute(['id' => $row['id'], 'user_id' => $userId]);
            return $consume->rowCount() === 1;
        }
    }
    return false;
}

function adminMfaRecordCompletedLogin(PDO $pdo, array $user): void
{
    $update = $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id');
    $update->execute(['id' => $user['id']]);
    $log = $pdo->prepare(
        'INSERT INTO login_attempts (user_id, email_attempted, was_successful, failure_reason, ip_address, user_agent)
         VALUES (:user_id, :email, 1, NULL, :ip, :agent)'
    );
    $log->execute([
        'user_id' => $user['id'],
        'email' => $user['email'],
        'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        'agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 500) : null,
    ]);
}

function adminMfaNotice(string $email, string $event): void
{
    try {
        require_once __DIR__ . '/mailer.php';
        $mail = createMailer();
        $mail->addAddress($email);
        $mail->Subject = 'AdoptAPet Administrator security notice';
        $message = match ($event) {
            'reset' => 'Your Administrator authenticator was reset. Complete the new setup before accessing the Administrator workspace.',
            'recovery' => 'A recovery code was used to sign in to your Administrator account.',
            default => 'Two-factor authentication was enabled for your Administrator account.',
        };
        $mail->Body = '<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p><p>If this was not you, contact the site administrator.</p>';
        $mail->AltBody = $message . ' If this was not you, contact the site administrator.';
        $mail->send();
    } catch (Throwable $exception) {
        // Notification failure cannot undo an already committed security change.
        error_log('AdoptAPet Administrator MFA notification delivery failed.');
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    adminMfaReply(405, ['success' => false, 'message' => 'Only POST requests are allowed.']);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    adminMfaReply(400, ['success' => false, 'message' => 'Invalid request.']);
}
$action = (string) ($input['action'] ?? '');
if (!in_array($action, ['setup', 'enroll', 'verify', 'cancel', 'status', 'reset'], true)) {
    adminMfaReply(400, ['success' => false, 'message' => 'Invalid request.']);
}
requireValidCsrfToken();

if ($action === 'cancel') {
    if (administratorMfaPending() === null) {
        adminMfaReply(401, ['success' => false, 'message' => 'Sign in again to continue.']);
    }
    destroyAuthenticationSession();
    adminMfaReply(200, ['success' => true]);
}

$key = administratorMfaEncryptionKey();
if ($key === null) {
    adminMfaReply(503, ['success' => false, 'message' => 'Administrator authentication is temporarily unavailable.']);
}

try {
    $pdo = getDatabaseConnection();

    if ($action === 'status' || $action === 'reset') {
        $userId = authenticatedUserId();
        if ($userId === null) {
            adminMfaReply(401, ['success' => false, 'message' => 'Authentication is required.']);
        }
        $pdo->beginTransaction();
        $userStatement = $pdo->prepare(
            'SELECT id, email, password_hash, role, account_status FROM users
             WHERE id = :id AND deleted_at IS NULL FOR UPDATE'
        );
        $userStatement->execute(['id' => $userId]);
        $user = $userStatement->fetch();
        if (!$user || !isOperationalAdministratorRole($user['role']) || $user['account_status'] !== 'active') {
            $pdo->rollBack();
            adminMfaReply(403, ['success' => false, 'message' => 'Administrator access is required.']);
        }
        $mfaStatement = $pdo->prepare(
            'SELECT totp_secret_encrypted, last_accepted_step FROM administrator_mfa_settings
             WHERE user_id = :user_id FOR UPDATE'
        );
        $mfaStatement->execute(['user_id' => $userId]);
        $settings = $mfaStatement->fetch();
        if (!$settings) {
            $pdo->rollBack();
            adminMfaReply(403, ['success' => false, 'message' => 'Two-factor authentication is not enabled.']);
        }
        if ($action === 'status') {
            $pdo->commit();
            adminMfaReply(200, ['success' => true, 'enabled' => true]);
        }
        $password = (string) ($input['current_password'] ?? '');
        $code = (string) ($input['code'] ?? '');
        $method = (string) ($input['method'] ?? 'totp');
        $secret = administratorMfaDecrypt($settings['totp_secret_encrypted'], $key);
        $step = $secret !== null && $method === 'totp' ? administratorMfaVerifyTotp($secret, $code) : null;
        $lastStep = $settings['last_accepted_step'];
        $totpValid = $step !== null && ($lastStep === null || $step > (int) $lastStep);
        $passwordValid = $password !== '' && password_verify($password, $user['password_hash']);
        if (!$passwordValid || (!$totpValid && $method !== 'recovery')) {
            $pdo->rollBack();
            adminMfaInvalidReset();
        }
        if ($method === 'recovery' && !adminMfaConsumeRecovery($pdo, $userId, $code)) {
            $pdo->rollBack();
            adminMfaInvalidReset();
        }
        if ($method === 'recovery') {
            securityRecordEvent($pdo, $userId, 'recovery_code_used');
        }
        $delete = $pdo->prepare('DELETE FROM administrator_mfa_settings WHERE user_id = :user_id');
        $delete->execute(['user_id' => $userId]);
        securityRevokeAllSessions($pdo, $userId, 'mfa_reset');
        securityRecordEvent($pdo, $userId, 'mfa_reset');
        $pdo->commit();
        administratorMfaBeginPending($userId, $user['password_hash'], 'setup', $user['role']);
        $_SESSION['pending_admin_mfa']['reenrollment'] = true;
        $token = rotateCsrfToken();
        adminMfaNotice($user['email'], 'reset');
        adminMfaReply(200, ['success' => true, 'mfa_mode' => 'setup', 'csrf_token' => $token]);
    }

    $pending = administratorMfaPending();
    if ($pending === null) {
        adminMfaReply(401, ['success' => false, 'restart_login' => true, 'message' => 'Sign in again to continue.']);
    }
    $userId = (int) $pending['user_id'];
    if ($action === 'setup') {
        if ($pending['mode'] !== 'setup') {
            adminMfaReply(403, ['success' => false, 'message' => 'MFA setup is not available.']);
        }
        $candidate = isset($pending['candidate']) && is_string($pending['candidate'])
            ? administratorMfaDecrypt($pending['candidate'], $key) : null;
        if ($candidate === null) {
            $candidate = random_bytes(20);
            $_SESSION['pending_admin_mfa']['candidate'] = administratorMfaEncrypt($candidate, $key);
        }
        $statement = $pdo->prepare(
            'SELECT email, role FROM users WHERE id = :id AND account_status = :status AND deleted_at IS NULL LIMIT 1'
        );
        $statement->execute(['id' => $userId, 'status' => 'active']);
        $account = $statement->fetch();
        if (!$account || $account['role'] !== $pending['role'] || !isOperationalAdministratorRole($account['role'])) {
            unset($_SESSION['pending_admin_mfa']);
            adminMfaReply(401, ['success' => false, 'restart_login' => true, 'message' => 'Sign in again to continue.']);
        }
        $email = $account['email'];
        $base32 = administratorMfaBase32($candidate);
        $qrImage = null;
        try {
            $qrImage = administratorMfaQrSvgDataUri(administratorMfaOtpAuthUri((string) $email, $base32));
        } catch (Throwable $exception) {
            // Local rendering failure must not remove the manual setup path.
            error_log('AdoptAPet Administrator MFA QR rendering failed.');
        }
        adminMfaReply(200, ['success' => true, 'setup_key' => $base32, 'qr_image' => $qrImage]);
    }

    if (($action === 'enroll' && $pending['mode'] !== 'setup')
        || ($action === 'verify' && $pending['mode'] !== 'verify')) {
        adminMfaReply(403, ['success' => false, 'message' => 'This MFA step is not available.']);
    }
    $code = trim((string) ($input['code'] ?? ''));
    $method = (string) ($input['method'] ?? 'totp');
    $pdo->beginTransaction();
    $user = adminMfaActiveUser($pdo, $userId, (string) $pending['credential_fingerprint'], (string) $pending['role']);
    if (!$user) {
        $pdo->rollBack();
        unset($_SESSION['pending_admin_mfa']);
        adminMfaReply(401, ['success' => false, 'restart_login' => true, 'message' => 'Sign in again to continue.']);
    }

    if ($action === 'enroll') {
        $candidate = isset($pending['candidate']) && is_string($pending['candidate'])
            ? administratorMfaDecrypt($pending['candidate'], $key) : null;
        if ($candidate === null) {
            $pdo->rollBack();
            adminMfaReply(422, ['success' => false, 'message' => 'Start authenticator setup again.']);
        }
        $existing = $pdo->prepare('SELECT user_id FROM administrator_mfa_settings WHERE user_id = :user_id');
        $existing->execute(['user_id' => $userId]);
        if ($existing->fetchColumn() !== false) {
            $pdo->rollBack();
            unset($_SESSION['pending_admin_mfa']);
            adminMfaReply(401, ['success' => false, 'restart_login' => true, 'message' => 'Sign in again to continue.']);
        }
        $step = administratorMfaVerifyTotp($candidate, $code);
        if ($step === null) {
            $pdo->rollBack();
            adminMfaInvalidCode();
        }
        $encrypted = $pending['candidate'];
        $insert = $pdo->prepare(
            'INSERT INTO administrator_mfa_settings
             (user_id, totp_secret_encrypted, last_accepted_step, enabled_at)
             VALUES (:user_id, :secret, :step, NOW())'
        );
        $insert->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $insert->bindValue(':secret', $encrypted, PDO::PARAM_LOB);
        $insert->bindValue(':step', $step, PDO::PARAM_INT);
        $insert->execute();
        $codes = administratorMfaRecoveryCodes();
        $insertCode = $pdo->prepare(
            'INSERT INTO administrator_mfa_recovery_codes (user_id, code_hash) VALUES (:user_id, :code_hash)'
        );
        foreach ($codes as $recoveryCode) {
            $insertCode->execute([
                'user_id' => $userId,
                'code_hash' => password_hash(administratorMfaNormalizeRecovery($recoveryCode), PASSWORD_DEFAULT),
            ]);
        }
        adminMfaRecordCompletedLogin($pdo, $user);
        securityRecordEvent($pdo, $userId, !empty($pending['reenrollment']) ? 'mfa_reenrolled' : 'mfa_enabled');
        $pdo->commit();
        administratorMfaPromote($user, $encrypted);
        securityRegisterAuthenticatedSession($pdo, $userId);
        $token = rotateCsrfToken();
        adminMfaNotice($user['email'], 'enabled');
        adminMfaReply(200, ['success' => true, 'role' => $user['role'], 'csrf_token' => $token, 'recovery_codes' => $codes]);
    }

    $settingsStatement = $pdo->prepare(
        'SELECT totp_secret_encrypted, last_accepted_step FROM administrator_mfa_settings
         WHERE user_id = :user_id FOR UPDATE'
    );
    $settingsStatement->execute(['user_id' => $userId]);
    $settings = $settingsStatement->fetch();
    if (!$settings) {
        $pdo->rollBack();
        unset($_SESSION['pending_admin_mfa']);
        adminMfaReply(401, ['success' => false, 'restart_login' => true, 'message' => 'Sign in again to continue.']);
    }
    $valid = false;
    if ($method === 'totp') {
        $secret = administratorMfaDecrypt($settings['totp_secret_encrypted'], $key);
        if ($secret === null) {
            $pdo->rollBack();
            adminMfaReply(503, ['success' => false, 'message' => 'Administrator authentication is temporarily unavailable.']);
        }
        $step = administratorMfaVerifyTotp($secret, $code);
        $lastStep = $settings['last_accepted_step'];
        if ($step !== null && ($lastStep === null || $step > (int) $lastStep)) {
            $update = $pdo->prepare(
                'UPDATE administrator_mfa_settings SET last_accepted_step = :step WHERE user_id = :user_id'
            );
            $update->execute(['step' => $step, 'user_id' => $userId]);
            $valid = true;
        }
    } elseif ($method === 'recovery') {
        $valid = adminMfaConsumeRecovery($pdo, $userId, $code);
    }
    if (!$valid) {
        $pdo->rollBack();
        adminMfaInvalidCode();
    }
    adminMfaRecordCompletedLogin($pdo, $user);
    if ($method === 'recovery') {
        securityRecordEvent($pdo, $userId, 'recovery_code_used');
    }
    $pdo->commit();
    administratorMfaPromote($user, $settings['totp_secret_encrypted']);
    securityRegisterAuthenticatedSession($pdo, $userId);
    $token = rotateCsrfToken();
    if ($method === 'recovery') {
        adminMfaNotice($user['email'], 'recovery');
    }
    adminMfaReply(200, ['success' => true, 'role' => $user['role'], 'csrf_token' => $token, 'recovery_used' => $method === 'recovery']);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    adminMfaReply(500, ['success' => false, 'message' => 'Unable to complete Administrator authentication. Please try again later.']);
}
