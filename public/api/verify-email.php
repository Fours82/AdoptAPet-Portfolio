<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/email-verification-service.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['success' => false, 'message' => 'Only POST requests are allowed.']);
    exit;
}


$input = json_decode(file_get_contents('php://input'), true);
$email = is_array($input) ? strtolower(trim((string) ($input['email'] ?? ''))) : '';
$code = is_array($input) ? trim((string) ($input['code'] ?? '')) : '';

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^\d{6}$/', $code)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Enter a valid email address and 6-digit verification code.']);
    exit;
}

function verifyEmailFailure(PDO $pdo, int $status, string $message): never
{
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code($status);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    $pdo->beginTransaction();
    $findUser = $pdo->prepare(
        'SELECT id, role, account_status, deleted_at FROM users WHERE email = :email LIMIT 1 FOR UPDATE'
    );
    $findUser->execute(['email' => $email]);
    $user = $findUser->fetch();

    if (!$user || !emailVerificationPendingRegistrationIsEligible($pdo, $user)) {
        verifyEmailFailure($pdo, 400, 'Unable to verify this email address.');
    }

    $findVerification = $pdo->prepare(
        'SELECT id, verification_code_hash, attempts, verified_at, expires_at <= NOW() AS is_expired
         FROM email_verifications
         WHERE user_id = :user_id
         ORDER BY id DESC
         LIMIT 1 FOR UPDATE'
    );
    $findVerification->execute(['user_id' => $user['id']]);
    $verification = $findVerification->fetch();

    if (!$verification) {
        verifyEmailFailure($pdo, 400, 'Unable to verify this email address.');
    }

    if ($verification['verified_at'] !== null) {
        verifyEmailFailure($pdo, 409, 'This email address has already been verified.');
    }

    if ((int) $verification['is_expired'] === 1) {
        verifyEmailFailure($pdo, 410, 'This verification code has expired. Request a new code.');
    }

    if ((int) $verification['attempts'] >= 5) {
        verifyEmailFailure($pdo, 429, 'Too many incorrect attempts. Request a new verification code.');
    }

    if (!password_verify($code, $verification['verification_code_hash'])) {
        $incrementAttempts = $pdo->prepare(
            'UPDATE email_verifications SET attempts = attempts + 1
             WHERE id = :id AND verified_at IS NULL AND attempts < 5 AND expires_at > NOW()'
        );
        $incrementAttempts->execute(['id' => $verification['id']]);
        if ($incrementAttempts->rowCount() !== 1) {
            verifyEmailFailure($pdo, 409, 'This verification request changed. Request a new code.');
        }
        $pdo->commit();
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'The verification code is incorrect.']);
        exit;
    }

    $markVerified = $pdo->prepare(
        'UPDATE email_verifications SET verified_at = NOW()
         WHERE id = :id AND verified_at IS NULL AND attempts < 5 AND expires_at > NOW()'
    );
    $markVerified->execute(['id' => $verification['id']]);
    if ($markVerified->rowCount() !== 1) {
        verifyEmailFailure($pdo, 409, 'This verification request changed. Request a new code.');
    }
    $activateUser = $pdo->prepare(
        'UPDATE users SET account_status = :account_status
         WHERE id = :id AND role = :role AND account_status = :pending_status AND deleted_at IS NULL'
    );
    $activateUser->execute([
        'account_status' => 'active', 'id' => $user['id'],
        'role' => 'registered_user', 'pending_status' => 'deactivated',
    ]);
    if ($activateUser->rowCount() !== 1) {
        verifyEmailFailure($pdo, 409, 'This account changed. Request help before trying again.');
    }
    $pdo->commit();

    echo json_encode(['success' => true, 'message' => 'Email verified successfully. You can now log in.']);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to verify your email. Please try again later.']);
}
