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
require_once __DIR__ . '/password-reset-service.php';
require_once __DIR__ . '/password-recovery-session.php';
require_once __DIR__ . '/request-abuse.php';
require_once dirname(__DIR__, 2) . '/config/application.php';
require_once dirname(__DIR__, 2) . '/config/security.php';

$input = json_decode(file_get_contents('php://input'), true);
$email = is_array($input) ? strtolower(trim((string) ($input['email'] ?? ''))) : '';
$captchaToken = is_array($input) && isset($input['hcaptcha_token']) ? (string) $input['hcaptcha_token'] : null;
$message = 'If an account exists for that email, a password reset link has been sent.';

if (requestAbuseIncrement('password_reset_requests', 600) > 5) {
    echo json_encode(['success' => true, 'message' => $message]);
    exit;
}

$captchaMayBeReused = filter_var($email, FILTER_VALIDATE_EMAIL)
    && passwordRecoveryCaptchaMayBeReused($email);
if (!$captchaMayBeReused) {
    $captcha = verifyHcaptchaToken($captchaToken, $_SERVER['REMOTE_ADDR'] ?? null);
    if (!$captcha['success']) {
        http_response_code($captcha['unavailable'] ? 503 : 422);
        echo json_encode([
            'success' => false,
            'captcha_required' => true,
            'message' => $captcha['unavailable']
                ? 'The security check is temporarily unavailable. Please try again later.'
                : 'Complete the security check before requesting a password reset.',
        ]);
        exit;
    }
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => true, 'message' => $message]);
    exit;
}

if (!$captchaMayBeReused) {
    rememberPasswordRecoverySession($email);
}

try {
    $pdo = getDatabaseConnection();
    $pdo->beginTransaction();
    $findUser = $pdo->prepare(
        'SELECT id FROM users
         WHERE email = :email AND account_status = :account_status AND deleted_at IS NULL
         LIMIT 1 FOR UPDATE'
    );
    $findUser->execute(['email' => $email, 'account_status' => 'active']);
    $user = $findUser->fetch();

    if (!$user) {
        $pdo->rollBack();
        echo json_encode(['success' => true, 'message' => $message]);
        exit;
    }

    $recentRequest = $pdo->prepare(
        'SELECT id FROM password_reset_codes
         WHERE user_id = :user_id AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)
         ORDER BY id DESC LIMIT 1'
    );
    $recentRequest->execute(['user_id' => $user['id']]);

    if ($recentRequest->fetch()) {
        $pdo->rollBack();
        echo json_encode(['success' => true, 'message' => $message]);
        exit;
    }

    $token = generatePasswordResetToken();
    $tokenHash = hashPasswordResetToken($token);
    $resetUrl = adoptAPetPasswordResetUrl($token);
    $createRequest = $pdo->prepare(
        'INSERT INTO password_reset_codes (user_id, reset_code_hash, expires_at)
         VALUES (:user_id, :reset_code_hash, DATE_ADD(NOW(), INTERVAL 15 MINUTE))'
    );
    $createRequest->execute([
        'user_id' => $user['id'],
        'reset_code_hash' => $tokenHash,
    ]);
    $resetRequestId = (int) $pdo->lastInsertId();
    $pdo->commit();

    sendMailWithFailureCleanup(
        'password_reset',
        static fn () => sendPasswordResetEmail($email, $resetUrl),
        static function () use ($pdo, $resetRequestId, $user): void {
            $discardResetRequest = $pdo->prepare(
                'DELETE FROM password_reset_codes
                 WHERE id = :id AND user_id = :user_id AND used_at IS NULL'
            );
            $discardResetRequest->execute(['id' => $resetRequestId, 'user_id' => $user['id']]);
        }
    );
    echo json_encode(['success' => true, 'message' => $message]);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('AdoptAPet password-reset request failure exception=' . get_class($exception));
    echo json_encode(['success' => true, 'message' => $message]);
}
