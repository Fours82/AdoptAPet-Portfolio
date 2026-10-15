<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/db.php';

function changePasswordError(int $status, string $message): never
{
    http_response_code($status);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    changePasswordError(405, 'Method not allowed.');
}

$userId = authenticatedUserId();
if ($userId === null) {
    changePasswordError(401, 'Authentication is required.');
}
requireValidCsrfToken();

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    changePasswordError(400, 'Invalid JSON request body.');
}

$unexpectedFields = array_diff(array_keys($input), ['current_password', 'new_password', 'confirm_password']);
if ($unexpectedFields !== []) {
    changePasswordError(422, 'Only current password, new password, and password confirmation are accepted.');
}

$currentPassword = (string) ($input['current_password'] ?? '');
$newPassword = (string) ($input['new_password'] ?? '');
$confirmPassword = (string) ($input['confirm_password'] ?? '');

if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
    changePasswordError(422, 'Complete all password fields.');
}

if ($newPassword !== $confirmPassword) {
    changePasswordError(422, 'Password confirmation does not match.');
}

if (
    strlen($newPassword) < 8
    || !preg_match('/[A-Z]/', $newPassword)
    || !preg_match('/[a-z]/', $newPassword)
    || !preg_match('/\d/', $newPassword)
    || !preg_match('/[^a-zA-Z\d]/', $newPassword)
) {
    changePasswordError(422, 'Password must have at least 8 characters, including uppercase, lowercase, number, and special character.');
}

if (hash_equals($currentPassword, $newPassword)) {
    changePasswordError(422, 'Choose a new password that is different from your current password.');
}

try {
    $pdo = getDatabaseConnection();
    $pdo->beginTransaction();

    $statement = $pdo->prepare(
        'SELECT u.password_hash, u.role,
                EXISTS (
                    SELECT 1 FROM email_verifications ev
                    WHERE ev.user_id = u.id AND ev.verified_at IS NOT NULL
                ) AS email_verified
         FROM users u
         WHERE u.id = :id
           AND u.account_status = :account_status
           AND u.deleted_at IS NULL
         FOR UPDATE'
    );
    $statement->execute(['id' => $userId, 'account_status' => 'active']);
    $user = $statement->fetch();

    $isSupportedRole = $user && isKnownAuthenticatedRole($user['role']);
    $isVerifiedRegisteredUser = $user && $user['role'] === 'registered_user' && (bool) $user['email_verified'];
    if (!$user || !$isSupportedRole || ($user['role'] === 'registered_user' && !$isVerifiedRegisteredUser)) {
        $pdo->rollBack();
        changePasswordError(403, 'An active verified account is required.');
    }

    if (!password_verify($currentPassword, $user['password_hash'])) {
        $pdo->rollBack();
        changePasswordError(401, 'The current password is incorrect.');
    }

    $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $update = $pdo->prepare(
        'UPDATE users
         SET password_hash = :password_hash, failed_login_count = 0, locked_until = NULL
         WHERE id = :id'
    );
    $update->execute([
        'password_hash' => $newPasswordHash,
        'id' => $userId,
    ]);

    $invalidateResetCodes = $pdo->prepare(
        'UPDATE password_reset_codes
         SET used_at = NOW()
         WHERE user_id = :user_id AND used_at IS NULL'
    );
    $invalidateResetCodes->execute(['user_id' => $userId]);
    securityRevokeOtherSessions($pdo, $userId, securityCurrentRegistryId(), 'password_changed');
    securityRecordEvent($pdo, $userId, 'password_changed');
    $pdo->commit();

    startAuthenticationSession();
    session_regenerate_id(true);
    refreshAuthenticationSessionCredential($newPasswordHash);
    $csrfToken = rotateCsrfToken();

    echo json_encode([
        'success' => true,
        'csrf_token' => $csrfToken,
        'message' => 'Password updated successfully.',
    ]);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    changePasswordError(500, 'Unable to update your password. Please try again later.');
}
