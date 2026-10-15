<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/payment-verification-service.php';

function requirePaymentVerificationUser(PDO $pdo): array
{
    $userId = authenticatedUserId();
    if ($userId === null) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Authentication is required.']);
        exit;
    }

    $statement = $pdo->prepare(
        'SELECT u.id, u.username, u.name, u.email, u.password_hash, p.phone,
                EXISTS (
                    SELECT 1 FROM email_verifications ev
                    WHERE ev.user_id = u.id AND ev.verified_at IS NOT NULL
                ) AS email_verified
         FROM users u
         LEFT JOIN user_profiles p ON p.user_id = u.id
         WHERE u.id = :id
           AND u.role = :role
           AND u.account_status = :status
           AND u.deleted_at IS NULL
         LIMIT 1'
    );
    $statement->execute([
        'id' => $userId,
        'role' => 'registered_user',
        'status' => 'active',
    ]);
    $user = $statement->fetch();
    if (!$user || !(bool) $user['email_verified']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'An active, verified registered-user account is required.']);
        exit;
    }

    return [
        'id' => (int) $user['id'],
        'username' => (string) $user['username'],
        'name' => (string) $user['name'],
        'email' => (string) $user['email'],
        'password_hash' => (string) $user['password_hash'],
        'phone' => is_string($user['phone']) ? $user['phone'] : '',
    ];
}

function paymentVerificationIdentity(array $user): array
{
    return [
        'name' => $user['name'],
        'email' => $user['email'],
        'masked_email' => maskPaymentEmail($user['email']),
        'phone' => $user['phone'],
    ];
}

try {
    $pdo = getDatabaseConnection();
    $user = requirePaymentVerificationUser($pdo);
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        if ($_GET !== []) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Payment identity requests do not accept query parameters.']);
            exit;
        }
        echo json_encode(['success' => true, 'identity' => paymentVerificationIdentity($user)]);
        exit;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        header('Allow: GET, POST');
        echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
        exit;
    }

    requireValidCsrfToken();

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON request body.']);
        exit;
    }

    $action = strtolower(trim((string) ($input['action'] ?? '')));
    $context = paymentVerificationContext(strtolower(trim((string) ($input['context'] ?? ''))));
    $requestKey = strtolower(trim((string) ($input['idempotency_key'] ?? '')));
    $allowedFields = $action === 'verify'
        ? ['action', 'context', 'idempotency_key', 'code']
        : ($action === 'bank_login'
            ? ['action', 'context', 'idempotency_key', 'username', 'password']
            : ['action', 'context', 'idempotency_key']);

    if (array_diff(array_keys($input), $allowedFields) !== []) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Unsupported payment verification information was provided.']);
        exit;
    }
    if ($context === null || !paymentVerificationRequestKeyIsValid($requestKey)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'The payment verification request is invalid.']);
        exit;
    }

    if ($action === 'cancel') {
        clearPaymentVerification($user['id'], $requestKey);
        if ($context === 'online_banking') {
            clearBankProviderAuthentication($user['id'], $requestKey);
            clearBankProviderLoginAttempts($user['id'], $requestKey);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'bank_login') {
        if ($context !== 'online_banking') {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'The payment verification request is invalid.']);
            exit;
        }

        $cooldown = bankProviderLoginCooldownSeconds($user['id'], $requestKey);
        if ($cooldown > 0) {
            http_response_code(429);
            echo json_encode([
                'success' => false,
                'message' => "Too many attempts. Please wait {$cooldown} seconds and try again.",
                'cooldown_seconds' => $cooldown,
            ]);
            exit;
        }

        $username = trim((string) ($input['username'] ?? ''));
        $password = (string) ($input['password'] ?? '');
        if ($username === '' || $password === '') {
            unset($password);
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Enter your username and password.']);
            exit;
        }

        $credentialsMatch = bankProviderCredentialsMatch($user['username'], $user['password_hash'], $username, $password);
        unset($password);

        if (!$credentialsMatch) {
            $cooldown = recordBankProviderLoginFailure($user['id'], $requestKey);
            http_response_code($cooldown > 0 ? 429 : 401);
            $message = $cooldown > 0
                ? "Too many attempts. Please wait {$cooldown} seconds and try again."
                : 'Incorrect username or password.';
            echo json_encode([
                'success' => false,
                'message' => $message,
                'cooldown_seconds' => $cooldown,
            ]);
            exit;
        }

        authoriseBankProviderAuthentication($user['id'], $requestKey);
        echo json_encode([
            'success' => true,
            'message' => 'Online Banking login successful.',
            'full_name' => $user['name'],
            'expires_in_seconds' => BANK_PROVIDER_AUTH_TTL_SECONDS,
        ]);
        exit;
    }

    if ($action === 'send') {
        if (
            $context === 'online_banking'
            && !bankProviderAuthenticationIsAuthorised($user['id'], $requestKey)
        ) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Sign in to Online Banking before approving this payment.']);
            exit;
        }
        $cooldown = paymentVerificationCooldownSeconds();
        if ($cooldown > 0) {
            http_response_code(429);
            echo json_encode([
                'success' => false,
                'message' => "Please wait {$cooldown} seconds before requesting another code.",
                'cooldown_seconds' => $cooldown,
            ]);
            exit;
        }

        $code = generatePaymentVerificationCode();
        $purpose = $context === 'e_wallet' ? 'PawPay access' : 'online banking payment authorisation';
        sendPaymentVerificationEmail($user['email'], $user['name'], $code, $purpose);
        storePaymentVerificationCode($user['id'], $context, $requestKey, $code);
        unset($code);

        echo json_encode([
            'success' => true,
            'message' => 'A verification code was sent to your registered email address.',
            'masked_email' => maskPaymentEmail($user['email']),
            'expires_in_seconds' => PAYMENT_VERIFICATION_TTL_SECONDS,
            'resend_after_seconds' => PAYMENT_VERIFICATION_RESEND_SECONDS,
        ]);
        exit;
    }

    if ($action === 'verify') {
        if (
            $context === 'online_banking'
            && !bankProviderAuthenticationIsAuthorised($user['id'], $requestKey)
        ) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Your Online Banking session expired. Please sign in again.']);
            exit;
        }
        $code = trim((string) ($input['code'] ?? ''));
        if (!preg_match('/^\d{6}$/', $code) || !verifyPaymentVerificationCode($user['id'], $context, $requestKey, $code)) {
            unset($code);
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Incorrect or expired verification code.']);
            exit;
        }
        unset($code);
        echo json_encode(['success' => true, 'message' => 'Payment verification successful.']);
        exit;
    }

    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Select a valid payment verification action.']);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => ($action ?? '') === 'bank_login'
            ? 'Unable to sign in. Please try again.'
            : 'The verification email could not be sent. Please try again.',
    ]);
}
