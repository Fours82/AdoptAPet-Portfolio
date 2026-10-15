<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['success' => false, 'message' => 'Only POST requests are allowed.']);
    exit;
}

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/email-verification-service.php';
require_once __DIR__ . '/request-abuse.php';

$input = json_decode(file_get_contents('php://input'), true);
$email = is_array($input) ? strtolower(trim((string) ($input['email'] ?? ''))) : '';

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}

if (requestAbuseIncrement('verification_resends', 600) > 5) {
    echo json_encode(['success' => true, 'message' => 'If this account needs verification, a new code will be sent shortly.']);
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
        $pdo->rollBack();
        echo json_encode(['success' => true, 'message' => 'If this account needs verification, a new code will be sent shortly.']);
        exit;
    }

    $recentCode = $pdo->prepare(
        'SELECT id
         FROM email_verifications
         WHERE user_id = :user_id AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)
         ORDER BY id DESC
         LIMIT 1'
    );
    $recentCode->execute(['user_id' => $user['id']]);

    if ($recentCode->fetch()) {
        $pdo->rollBack();
        echo json_encode(['success' => true, 'message' => 'If this account needs verification, a new code will be sent shortly.']);
        exit;
    }

    $code = (string) random_int(100000, 999999);
    $createVerification = $pdo->prepare(
        'INSERT INTO email_verifications (user_id, verification_code_hash, expires_at)
         VALUES (:user_id, :verification_code_hash, DATE_ADD(NOW(), INTERVAL 10 MINUTE))'
    );
    $createVerification->execute([
        'user_id' => $user['id'],
        'verification_code_hash' => password_hash($code, PASSWORD_DEFAULT),
    ]);
    $verificationId = (int) $pdo->lastInsertId();
    $pdo->commit();

    sendMailWithFailureCleanup(
        'verification_resend',
        static fn () => sendVerificationEmail($email, $code),
        static function () use ($pdo, $verificationId, $user): void {
            $discardVerification = $pdo->prepare(
                'DELETE FROM email_verifications
                 WHERE id = :id AND user_id = :user_id AND verified_at IS NULL'
            );
            $discardVerification->execute(['id' => $verificationId, 'user_id' => $user['id']]);
        }
    );
    echo json_encode(['success' => true, 'message' => 'If this account needs verification, a new code will be sent shortly.']);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('AdoptAPet verification resend failed.');
    echo json_encode(['success' => true, 'message' => 'If this account needs verification, a new code will be sent shortly.']);
}
