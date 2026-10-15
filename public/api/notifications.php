<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notification-service.php';

function requireNotificationRecipient(PDO $pdo): array
{
    $userId = authenticatedUserId();

    if ($userId === null) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Authentication is required.']);
        exit;
    }

    $statement = $pdo->prepare(
        'SELECT u.role, u.account_status, u.deleted_at,
                EXISTS (
                    SELECT 1 FROM email_verifications ev
                    WHERE ev.user_id = u.id AND ev.verified_at IS NOT NULL
                ) AS email_verified
         FROM users u
         WHERE u.id = :id
         LIMIT 1'
    );
    $statement->execute(['id' => $userId]);
    $user = $statement->fetch();

    $isRegisteredUser = $user && $user['role'] === 'registered_user' && (bool) $user['email_verified'];
    $isAdministrator = $user && isOperationalAdministratorRole($user['role']);

    if (!$user || $user['account_status'] !== 'active' || $user['deleted_at'] !== null || (!$isRegisteredUser && !$isAdministrator)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'An active account with Notifications access is required.']);
        exit;
    }

    return ['id' => $userId, 'operational_admin' => (bool) $isAdministrator];
}

function notificationUnreadCount(PDO $pdo, int $userId, bool $isOperationalAdmin): int
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM notifications WHERE recipient_user_id = :recipient_user_id AND is_read = :is_read'
        . notificationVisibilitySql($isOperationalAdmin)
    );
    $statement->execute(['recipient_user_id' => $userId, 'is_read' => 0]);

    return (int) $statement->fetchColumn();
}

$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'PATCH'], true)) {
    http_response_code(405);
    header('Allow: GET, PATCH');
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    $recipient = requireNotificationRecipient($pdo);
    $userId = $recipient['id'];
    $isOperationalAdmin = $recipient['operational_admin'];

    if ($method !== 'GET') {
        requireValidCsrfToken();
    }

    if ($method === 'GET') {
        if (array_diff(array_keys($_GET), ['summary']) !== []) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Unsupported Notifications query parameter.']);
            exit;
        }

        if (isset($_GET['summary']) && $_GET['summary'] !== '1') {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'The Notifications summary value must be 1.']);
            exit;
        }

        $unreadCount = notificationUnreadCount($pdo, $userId, $isOperationalAdmin);
        if (isset($_GET['summary'])) {
            echo json_encode(['success' => true, 'unread_count' => $unreadCount]);
            exit;
        }

        $statement = $pdo->prepare(
            'SELECT id, notification_type, message, link_url, is_read, read_at, created_at
             FROM notifications
             WHERE recipient_user_id = :recipient_user_id
             ' . notificationVisibilitySql($isOperationalAdmin) . '
             ORDER BY created_at DESC, id DESC'
        );
        $statement->execute(['recipient_user_id' => $userId]);
        $notifications = array_map(
            static fn (array $notification): array => [
                'id' => (int) $notification['id'],
                'notification_type' => $notification['notification_type'],
                'message' => $notification['message'],
                'link_url' => $notification['link_url'],
                'is_read' => (bool) $notification['is_read'],
                'read_at' => $notification['read_at'],
                'created_at' => $notification['created_at'],
            ],
            $statement->fetchAll()
        );

        echo json_encode([
            'success' => true,
            'unread_count' => $unreadCount,
            'notifications' => $notifications,
        ]);
        exit;
    }

    if ($_GET !== []) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Notifications updates do not accept query parameters.']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON request body.']);
        exit;
    }

    $unexpectedFields = array_diff(array_keys($input), ['id', 'mark_all']);
    if ($unexpectedFields !== []) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Only a Notification ID or mark-all instruction is accepted.']);
        exit;
    }

    $hasId = array_key_exists('id', $input);
    $markAll = ($input['mark_all'] ?? null) === true;
    if ($hasId === $markAll) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Select one Notification or request that all Notifications be marked as read.']);
        exit;
    }

    if ($markAll) {
        $statement = $pdo->prepare(
            'UPDATE notifications
             SET is_read = :read_status, read_at = NOW()
             WHERE recipient_user_id = :recipient_user_id AND is_read = :unread_status'
            . notificationVisibilitySql($isOperationalAdmin)
        );
        $statement->execute([
            'read_status' => 1,
            'recipient_user_id' => $userId,
            'unread_status' => 0,
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'All Notifications marked as read.',
            'updated_count' => $statement->rowCount(),
            'unread_count' => notificationUnreadCount($pdo, $userId, $isOperationalAdmin),
        ]);
        exit;
    }

    $notificationId = filter_var($input['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($notificationId === false) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'A valid Notification ID is required.']);
        exit;
    }

    $statement = $pdo->prepare(
        'SELECT id FROM notifications WHERE id = :id AND recipient_user_id = :recipient_user_id'
        . notificationVisibilitySql($isOperationalAdmin) . ' LIMIT 1'
    );
    $statement->execute(['id' => $notificationId, 'recipient_user_id' => $userId]);
    if (!$statement->fetch()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Notification not found.']);
        exit;
    }

    $statement = $pdo->prepare(
        'UPDATE notifications
         SET is_read = :is_read, read_at = COALESCE(read_at, NOW())
         WHERE id = :id AND recipient_user_id = :recipient_user_id'
        . notificationVisibilitySql($isOperationalAdmin)
    );
    $statement->execute(['is_read' => 1, 'id' => $notificationId, 'recipient_user_id' => $userId]);

    echo json_encode([
        'success' => true,
        'message' => 'Notification marked as read.',
        'unread_count' => notificationUnreadCount($pdo, $userId, $isOperationalAdmin),
    ]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to process the Notifications request.']);
}
