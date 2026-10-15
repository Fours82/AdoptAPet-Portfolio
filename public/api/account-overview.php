<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}
if ($_GET !== []) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Unsupported Account request parameter.']);
    exit;
}

$userId = authenticatedUserId();
if ($userId === null) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication is required.']);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    $statement = $pdo->prepare(
        'SELECT u.id, u.username, u.name, u.email, u.role, u.account_status,
                u.created_at, p.profile_image_path
         FROM users u
         LEFT JOIN user_profiles p ON p.user_id = u.id
         WHERE u.id = :id AND u.account_status = :status AND u.deleted_at IS NULL
         LIMIT 1'
    );
    $statement->execute(['id' => $userId, 'status' => 'active']);
    $user = $statement->fetch();
    if (!$user || !isKnownAuthenticatedRole($user['role'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'An active account is required.']);
        exit;
    }

    $overview = [
        'id' => (int) $user['id'],
        'username' => $user['username'],
        'name' => $user['name'],
        'email' => $user['email'],
        'role' => $user['role'],
        'account_status' => $user['account_status'],
        'created_at' => $user['created_at'],
        'profile_image_path' => $user['profile_image_path'],
    ];

    if ($user['role'] === 'registered_user') {
        $counts = $pdo->prepare(
            'SELECT
                (SELECT COUNT(*) FROM adoption_applications WHERE applicant_user_id = :applications_user_id) AS applications,
                (SELECT COUNT(*) FROM stray_reports WHERE reporter_user_id = :strays_user_id) AS stray_reports,
                (SELECT COUNT(*) FROM donations WHERE user_id = :donations_user_id AND donation_status = :completed) AS completed_donations
             FROM users
             WHERE id = :owner_id AND role = :registered_role
               AND account_status = :active_status AND deleted_at IS NULL'
        );
        $counts->execute([
            'applications_user_id' => $userId,
            'strays_user_id' => $userId,
            'donations_user_id' => $userId,
            'completed' => 'completed',
            'owner_id' => $userId,
            'registered_role' => 'registered_user',
            'active_status' => 'active',
        ]);
        $row = $counts->fetch();
        if (!$row) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Account access changed. Please sign in again.']);
            exit;
        }
        $overview['summary'] = [
            'applications' => (int) $row['applications'],
            'stray_reports' => (int) $row['stray_reports'],
            'completed_donations' => (int) $row['completed_donations'],
        ];
    }

    echo json_encode(['success' => true, 'overview' => $overview]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to load account information.']);
}
