<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/admin-audit-service.php';
require_once __DIR__ . '/notification-service.php';

function requireChatAdministrator(PDO $pdo): int
{
    $userId = authenticatedUserId();
    if ($userId === null) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Authentication is required.']);
        exit;
    }

    $statement = $pdo->prepare(
        'SELECT role FROM users
         WHERE id = :id AND account_status = :account_status AND deleted_at IS NULL
         LIMIT 1'
    );
    $statement->execute(['id' => $userId, 'account_status' => 'active']);
    $user = $statement->fetch();
    if (!isOperationalAdministratorRole($user['role'] ?? null)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Administrator access is required.']);
        exit;
    }

    return $userId;
}

function adminChatTicketId(int $conversationId): string
{
    return 'CHAT-' . str_pad((string) $conversationId, 6, '0', STR_PAD_LEFT);
}

function adminConversationId(): int
{
    $value = $_GET['conversation_id'] ?? null;
    if (!is_string($value) || !ctype_digit($value) || (int) $value < 1) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'A valid Live Chat conversation ID is required.']);
        exit;
    }

    return (int) $value;
}

function validateAdminChatMessage(array $input): string
{
    if (array_diff(array_keys($input), ['message']) !== []) {
        throw new InvalidArgumentException('Unsupported Live Chat field.');
    }
    if (!isset($input['message']) || !is_string($input['message'])) {
        throw new InvalidArgumentException('The Live Chat message must be text.');
    }

    $message = trim($input['message']);
    $length = function_exists('mb_strlen') ? mb_strlen($message) : strlen($message);
    if ($message === '' || $length > 2000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $message)) {
        throw new InvalidArgumentException('Enter a Live Chat message of 2,000 characters or fewer.');
    }

    return $message;
}

function administratorConversationRows(PDO $pdo): array
{
    $statement = $pdo->prepare(
        'SELECT cc.id, cc.subject, cc.conversation_status, cc.last_message_at, cc.created_at, cc.closed_at,
                u.name AS user_name, u.email AS user_email,
                au.name AS assigned_admin_name,
                (SELECT cm.message_body FROM chat_messages cm
                 WHERE cm.conversation_id = cc.id
                 ORDER BY cm.created_at DESC, cm.id DESC LIMIT 1) AS last_message,
                (SELECT GROUP_CONCAT(cm.message_body ORDER BY cm.created_at ASC, cm.id ASC SEPARATOR " ")
                 FROM chat_messages cm WHERE cm.conversation_id = cc.id) AS message_search,
                (SELECT COUNT(*) FROM chat_messages cm
                 WHERE cm.conversation_id = cc.id
                   AND cm.sender_user_id = cc.user_id
                   AND cm.read_at IS NULL) AS unread_count
         FROM chat_conversations cc
         JOIN users u ON u.id = cc.user_id
         LEFT JOIN users au ON au.id = cc.assigned_admin_user_id
         ORDER BY cc.conversation_status = :open_status DESC,
                  COALESCE(cc.last_message_at, cc.created_at) DESC,
                  cc.id DESC'
    );
    $statement->execute(['open_status' => 'open']);

    return array_map(
        static fn (array $conversation): array => [
            'id' => (int) $conversation['id'],
            'ticket_id' => adminChatTicketId((int) $conversation['id']),
            'subject' => $conversation['subject'],
            'status' => $conversation['conversation_status'],
            'user_name' => $conversation['user_name'],
            'user_email' => $conversation['user_email'],
            'assigned_admin_name' => $conversation['assigned_admin_name'],
            'last_message' => $conversation['last_message'],
            'message_search' => $conversation['message_search'] ?: '',
            'last_message_at' => $conversation['last_message_at'],
            'created_at' => $conversation['created_at'],
            'closed_at' => $conversation['closed_at'],
            'unread_count' => (int) $conversation['unread_count'],
        ],
        $statement->fetchAll()
    );
}

function administratorMessages(PDO $pdo, int $conversationId, int $administratorId): array
{
    $statement = $pdo->prepare(
        'SELECT cm.id, cm.sender_user_id, cm.message_body, cm.read_at, cm.created_at,
                u.name AS sender_name, u.role AS sender_role
         FROM chat_messages cm
         JOIN users u ON u.id = cm.sender_user_id
         WHERE cm.conversation_id = :conversation_id
         ORDER BY cm.created_at ASC, cm.id ASC'
    );
    $statement->execute(['conversation_id' => $conversationId]);

    return array_map(
        static fn (array $message): array => [
            'id' => (int) $message['id'],
            'sender_name' => $message['sender_name'],
            'sender_role' => $message['sender_role'],
            'message' => $message['message_body'],
            'read_at' => $message['read_at'],
            'created_at' => $message['created_at'],
            'is_current_user' => (int) $message['sender_user_id'] === $administratorId,
        ],
        $statement->fetchAll()
    );
}

$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'POST', 'PATCH'], true)) {
    http_response_code(405);
    header('Allow: GET, POST, PATCH');
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    $administratorId = requireChatAdministrator($pdo);

    if ($method !== 'GET') {
        requireValidCsrfToken();
    }

    if ($method === 'GET' && $_GET === []) {
        echo json_encode(['success' => true, 'conversations' => administratorConversationRows($pdo)]);
        exit;
    }

    if (array_diff(array_keys($_GET), ['conversation_id']) !== []) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Unsupported Live Chat query parameter.']);
        exit;
    }
    $conversationId = adminConversationId();

    if ($method === 'GET') {
        requireValidCsrfToken();
        $pdo->beginTransaction();
        $statement = $pdo->prepare(
            'SELECT cc.id, cc.subject, cc.conversation_status, cc.assigned_admin_user_id,
                    cc.last_message_at, cc.created_at, cc.closed_at,
                    u.id AS user_id, u.name AS user_name, u.email AS user_email
             FROM chat_conversations cc
             JOIN users u ON u.id = cc.user_id
             WHERE cc.id = :id
             LIMIT 1 FOR UPDATE'
        );
        $statement->execute(['id' => $conversationId]);
        $conversation = $statement->fetch();
        if (!$conversation) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Live Chat conversation not found.']);
            exit;
        }

        $statement = $pdo->prepare(
            'UPDATE chat_messages
             SET read_at = NOW()
             WHERE conversation_id = :conversation_id
               AND sender_user_id = :user_id
               AND read_at IS NULL'
        );
        $statement->execute(['conversation_id' => $conversationId, 'user_id' => $conversation['user_id']]);
        $messages = administratorMessages($pdo, $conversationId, $administratorId);
        $pdo->commit();

        echo json_encode([
            'success' => true,
            'conversation' => [
                'id' => (int) $conversation['id'],
                'ticket_id' => adminChatTicketId((int) $conversation['id']),
                'subject' => $conversation['subject'],
                'status' => $conversation['conversation_status'],
                'last_message_at' => $conversation['last_message_at'],
                'created_at' => $conversation['created_at'],
                'closed_at' => $conversation['closed_at'],
                'user_name' => $conversation['user_name'],
                'user_email' => $conversation['user_email'],
                'assigned_admin_user_id' => $conversation['assigned_admin_user_id'] === null
                    ? null
                    : (int) $conversation['assigned_admin_user_id'],
            ],
            'messages' => $messages,
        ]);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON request body.']);
        exit;
    }
    if ($method === 'PATCH') {
        if (array_keys($input) !== ['action'] || $input['action'] !== 'close') {
            throw new InvalidArgumentException('Unsupported Live Chat action.');
        }
    } else {
        $message = validateAdminChatMessage($input);
    }

    $pdo->beginTransaction();
    $statement = $pdo->prepare(
        'SELECT cc.id, cc.user_id, cc.assigned_admin_user_id, cc.conversation_status
         FROM chat_conversations cc
         JOIN users u ON u.id = cc.user_id
         WHERE cc.id = :id
           AND u.role = :user_role
           AND u.account_status = :account_status
           AND u.deleted_at IS NULL
         LIMIT 1 FOR UPDATE'
    );
    $statement->execute([
        'id' => $conversationId,
        'user_role' => 'registered_user',
        'account_status' => 'active',
    ]);
    $conversation = $statement->fetch();
    if (!$conversation) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Live Chat conversation not found.']);
        exit;
    }
    if ($method === 'PATCH') {
        if ($conversation['conversation_status'] !== 'open') {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'This support ticket is already closed.']);
            exit;
        }
        $statement = $pdo->prepare(
            'UPDATE chat_conversations SET conversation_status = :status, closed_at = NOW()
             WHERE id = :id'
        );
        $statement->execute(['status' => 'closed', 'id' => $conversationId]);
        adminAuditWrite($pdo, 'support_ticket_closed', $conversationId,
            ['status' => 'open'], ['status' => 'closed']);
        $pdo->commit();
        echo json_encode([
            'success' => true,
            'message' => adminChatTicketId($conversationId) . ' was closed. Its history remains available.',
            'conversation_id' => $conversationId,
            'ticket_id' => adminChatTicketId($conversationId),
        ]);
        exit;
    }
    if ($conversation['conversation_status'] !== 'open') {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This Live Chat conversation is closed.']);
        exit;
    }
    if (
        $conversation['assigned_admin_user_id'] !== null
        && (int) $conversation['assigned_admin_user_id'] !== $administratorId
    ) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This Live Chat conversation is assigned to another administrator.']);
        exit;
    }

    $statement = $pdo->prepare(
        'INSERT INTO chat_messages (conversation_id, sender_user_id, message_body)
         VALUES (:conversation_id, :sender_user_id, :message_body)'
    );
    $statement->execute([
        'conversation_id' => $conversationId,
        'sender_user_id' => $administratorId,
        'message_body' => $message,
    ]);
    $messageId = (int) $pdo->lastInsertId();
    $statement = $pdo->prepare(
        'UPDATE chat_conversations
         SET assigned_admin_user_id = :assigned_admin_user_id, last_message_at = NOW()
         WHERE id = :id'
    );
    $statement->execute(['assigned_admin_user_id' => $administratorId, 'id' => $conversationId]);
    createNotificationRecord(
        $pdo,
        (int) $conversation['user_id'],
        'chat_message',
        'Shelter Support replied to ' . adminChatTicketId($conversationId) . '.',
        '#/chat/' . $conversationId
    );
    $pdo->commit();

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Live Chat reply sent.',
        'conversation_id' => $conversationId,
        'ticket_id' => adminChatTicketId($conversationId),
        'message_id' => $messageId,
    ]);
} catch (InvalidArgumentException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $exception->getMessage()]);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to process the administrator Live Chat request.']);
}
