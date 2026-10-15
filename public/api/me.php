<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/password-recovery-session.php';
require_once __DIR__ . '/admin-mfa-service.php';

$userId = authenticatedUserId();
$passwordRecovery = publicPasswordRecoverySessionState();

if ($userId === null) {
    $pendingMfa = administratorMfaPending();
    echo json_encode([
        'success' => true,
        'authenticated' => false,
        'pending_mfa' => $pendingMfa ? ['mode' => $pendingMfa['mode']] : null,
        'csrf_token' => $pendingMfa ? csrfToken() : null,
        'password_recovery' => $passwordRecovery,
    ]);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    $findUser = $pdo->prepare(
        'SELECT u.id, u.username, u.name, u.email, u.role, u.account_status, p.profile_image_path
         FROM users u
         LEFT JOIN user_profiles p ON p.user_id = u.id
         WHERE u.id = :id AND u.deleted_at IS NULL
         LIMIT 1'
    );
    $findUser->execute(['id' => $userId]);
    $user = $findUser->fetch();

    if (!$user || $user['account_status'] !== 'active' || !isKnownAuthenticatedRole($user['role'])) {
        destroyAuthenticationSession('account_unavailable');
        echo json_encode(['success' => true, 'authenticated' => false]);
        exit;
    }

    echo json_encode([
        'success' => true,
        'authenticated' => true,
        'csrf_token' => csrfToken(),
        'password_recovery' => $passwordRecovery,
        'user' => [
            'id' => (int) $user['id'],
            'username' => $user['username'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
            'account_status' => $user['account_status'],
            'profile_image_path' => $user['profile_image_path'],
        ],
    ]);
} catch (PDOException $exception) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to check authentication status.']);
}
