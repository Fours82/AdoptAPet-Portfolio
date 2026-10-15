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
require_once __DIR__ . '/usernames.php';
require_once dirname(__DIR__, 2) . '/config/security.php';

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON request body.']);
    exit;
}

$username = trim((string) ($input['username'] ?? ''));
$name = trim((string) ($input['name'] ?? ''));
$email = trim((string) ($input['email'] ?? ''));
$password = (string) ($input['password'] ?? '');
$captchaToken = isset($input['hcaptcha_token']) ? (string) $input['hcaptcha_token'] : null;

$captcha = verifyHcaptchaToken($captchaToken, $_SERVER['REMOTE_ADDR'] ?? null);
if (!$captcha['success']) {
    http_response_code($captcha['unavailable'] ? 503 : 422);
    echo json_encode([
        'success' => false,
        'message' => $captcha['unavailable']
            ? 'The security check is temporarily unavailable. Please try again later.'
            : 'Complete the security check before creating an account.',
    ]);
    exit;
}

if (!isValidUsername($username)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => usernameValidationMessage()]);
    exit;
}

if ($name === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Full name is required.']);
    exit;
}

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}

if (
    strlen($password) < 8
    || !preg_match('/[A-Z]/', $password)
    || !preg_match('/[a-z]/', $password)
    || !preg_match('/\d/', $password)
    || !preg_match('/[^a-zA-Z\d]/', $password)
) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Password must have at least 8 characters, including uppercase, lowercase, number, and special character.']);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    $email = strtolower($email);

    $existingUser = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $existingUser->execute(['email' => $email]);

    if ($existingUser->fetch()) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'That email address is already registered.']);
        exit;
    }

    if (usernameIsTaken($pdo, $username)) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This username is already taken.']);
        exit;
    }

    $code = (string) random_int(100000, 999999);
    $pdo->beginTransaction();

    $createUser = $pdo->prepare(
        'INSERT INTO users (username, name, email, password_hash, role, account_status)
         VALUES (:username, :name, :email, :password_hash, :role, :account_status)'
    );
    $createUser->execute([
        'username' => $username,
        'name' => $name,
        'email' => $email,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'role' => 'registered_user',
        'account_status' => 'deactivated',
    ]);

    $userId = (int) $pdo->lastInsertId();
    $createProfile = $pdo->prepare('INSERT INTO user_profiles (user_id) VALUES (:user_id)');
    $createProfile->execute(['user_id' => $userId]);

    $createVerification = $pdo->prepare(
        'INSERT INTO email_verifications (user_id, verification_code_hash, expires_at)
         VALUES (:user_id, :verification_code_hash, DATE_ADD(NOW(), INTERVAL 10 MINUTE))'
    );
    $createVerification->execute([
        'user_id' => $userId,
        'verification_code_hash' => password_hash($code, PASSWORD_DEFAULT),
    ]);
    $verificationId = (int) $pdo->lastInsertId();

    $pdo->commit();

    try {
        sendMailWithFailureCleanup(
            'registration_verification',
            static fn () => sendVerificationEmail($email, $code),
            static function () use ($pdo, $verificationId, $userId): void {
                $discardVerification = $pdo->prepare(
                    'DELETE FROM email_verifications
                     WHERE id = :id AND user_id = :user_id AND verified_at IS NULL'
                );
                $discardVerification->execute(['id' => $verificationId, 'user_id' => $userId]);
            }
        );
    } catch (Throwable $exception) {
        http_response_code(503);
        echo json_encode([
            'success' => false,
            'verification_required' => true,
            'message' => 'Account created, but the verification email could not be sent. Please request a new code.',
        ]);
        exit;
    }

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Registration successful. Check your email for the verification code.',
        'user' => [
            'id' => $userId,
            'username' => $username,
            'name' => $name,
            'email' => $email,
            'role' => 'registered_user',
            'account_status' => 'deactivated',
        ],
    ]);
} catch (PDOException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if (isUsernameUniqueConstraintViolation($exception)) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This username is already taken.']);
        exit;
    }

    if ($exception->getCode() === '23000') {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'That email address is already registered.']);
        exit;
    }

    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to create the account. Please try again later.']);
}
