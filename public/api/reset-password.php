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

require_once __DIR__ . '/password-reset-service.php';
require_once __DIR__ . '/password-recovery-session.php';

function passwordResetFailure(int $status, string $message, ?PDO $pdo = null): never
{
    if ($pdo !== null && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code($status);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$token = is_array($input) ? trim((string) ($input['token'] ?? '')) : '';
$password = is_array($input) ? (string) ($input['password'] ?? '') : '';
$confirmPassword = is_array($input) ? (string) ($input['confirm_password'] ?? '') : '';

if (!passwordResetTokenIsWellFormed($token)) {
    passwordResetFailure(400, ADOPTAPET_PASSWORD_RESET_INVALID_MESSAGE);
}

try {
    $pdo = getDatabaseConnection();
    $pdo->beginTransaction();

    $tokenHash = hashPasswordResetToken($token);
    $resetRequest = findPasswordResetRequest($pdo, $tokenHash, true);
    if (!passwordResetRequestIsUsable($resetRequest, $tokenHash)) {
        passwordResetFailure(400, ADOPTAPET_PASSWORD_RESET_INVALID_MESSAGE, $pdo);
    }

    if ($password !== $confirmPassword) {
        passwordResetFailure(422, 'Password confirmation does not match.', $pdo);
    }

    if (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/\d/', $password) || !preg_match('/[^a-zA-Z\d]/', $password)) {
        passwordResetFailure(422, 'Password must have at least 8 characters, including uppercase, lowercase, number, and special character.', $pdo);
    }

    $newPasswordHash = password_hash($password, PASSWORD_DEFAULT);
    $updatePassword = $pdo->prepare(
        'UPDATE users
         SET password_hash = :password_hash
         WHERE id = :id AND account_status = :account_status AND deleted_at IS NULL'
    );
    $updatePassword->execute([
        'password_hash' => $newPasswordHash,
        'id' => $resetRequest['user_id'],
        'account_status' => 'active',
    ]);
    if ($updatePassword->rowCount() !== 1) {
        passwordResetFailure(400, ADOPTAPET_PASSWORD_RESET_INVALID_MESSAGE, $pdo);
    }

    $consumeRequests = $pdo->prepare(
        'UPDATE password_reset_codes
         SET used_at = NOW()
         WHERE user_id = :user_id AND used_at IS NULL'
    );
    $consumeRequests->execute(['user_id' => $resetRequest['user_id']]);
    securityRevokeAllSessions($pdo, (int) $resetRequest['user_id'], 'password_reset');
    securityRecordEvent($pdo, (int) $resetRequest['user_id'], 'password_reset');
    $pdo->commit();

    clearPasswordRecoverySessionState();
    destroyAuthenticationSession('password_reset');
    echo json_encode(['success' => true, 'message' => 'Password reset successfully. You can now log in.']);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to reset your password. Please try again later.']);
}
