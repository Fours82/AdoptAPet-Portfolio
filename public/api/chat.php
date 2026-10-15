<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notification-service.php';

function requireVerifiedChatUser(PDO $pdo): array
{
    $userId = authenticatedUserId();
    if ($userId === null) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Authentication is required.']);
        exit;
    }

    $statement = $pdo->prepare(
        'SELECT u.id, u.name, u.role, u.account_status, u.deleted_at,
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

    if (
        !$user
        || $user['role'] !== 'registered_user'
        || $user['account_status'] !== 'active'
        || $user['deleted_at'] !== null
        || !(bool) $user['email_verified']
    ) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'An active, verified registered-user account is required.']);
        exit;
    }

    return ['id' => (int) $user['id'], 'name' => $user['name']];
}

function chatTicketId(int $conversationId): string
{
    return 'CHAT-' . str_pad((string) $conversationId, 6, '0', STR_PAD_LEFT);
}

function chatJsonInput(): array
{
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON request body.']);
        exit;
    }
    return $input;
}

function chatConversationIdValue(mixed $value): int
{
    if (is_int($value) && $value > 0) {
        return $value;
    }
    if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
        return (int) $value;
    }
    throw new InvalidArgumentException('A valid Live Chat conversation ID is required.');
}

function validateChatMessage(array $input): string
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

function userConversationRows(PDO $pdo, int $userId): array
{
    $statement = $pdo->prepare(
        'SELECT cc.id, cc.subject, cc.conversation_status, cc.last_message_at,
                cc.created_at, cc.closed_at,
                (SELECT cm.message_body FROM chat_messages cm
                 WHERE cm.conversation_id = cc.id
                 ORDER BY cm.created_at DESC, cm.id DESC LIMIT 1) AS last_message,
                (SELECT GROUP_CONCAT(cm.message_body ORDER BY cm.created_at ASC, cm.id ASC SEPARATOR " ")
                 FROM chat_messages cm WHERE cm.conversation_id = cc.id) AS message_search,
                (SELECT COUNT(*) FROM chat_messages cm
                 JOIN users sender ON sender.id = cm.sender_user_id
                 WHERE cm.conversation_id = cc.id
                   AND sender.role IN (\'administrator\', \'super_administrator\')
                   AND cm.read_at IS NULL) AS unread_count
         FROM chat_conversations cc
         WHERE cc.user_id = :user_id
         ORDER BY cc.conversation_status = :open_status DESC,
                  COALESCE(cc.last_message_at, cc.created_at) DESC,
                  cc.id DESC'
    );
    $statement->execute([
        'user_id' => $userId,
        'open_status' => 'open',
    ]);

    return array_map(
        static fn (array $conversation): array => [
            'id' => (int) $conversation['id'],
            'ticket_id' => chatTicketId((int) $conversation['id']),
            'subject' => $conversation['subject'] ?: 'Shelter Support',
            'status' => $conversation['conversation_status'],
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

function ownedConversation(PDO $pdo, int $conversationId, int $userId, bool $forUpdate = false): array|false
{
    $statement = $pdo->prepare(
        'SELECT id, user_id, assigned_admin_user_id, subject, conversation_status,
                last_message_at, created_at, closed_at
         FROM chat_conversations
         WHERE id = :id AND user_id = :user_id
         LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '')
    );
    $statement->execute(['id' => $conversationId, 'user_id' => $userId]);
    return $statement->fetch();
}

function formattedOwnedConversation(array $conversation): array
{
    return [
        'id' => (int) $conversation['id'],
        'ticket_id' => chatTicketId((int) $conversation['id']),
        'subject' => $conversation['subject'] ?: 'Shelter Support',
        'status' => $conversation['conversation_status'],
        'last_message_at' => $conversation['last_message_at'],
        'created_at' => $conversation['created_at'],
        'closed_at' => $conversation['closed_at'],
    ];
}

function latestOpenConversation(PDO $pdo, int $userId, bool $forUpdate = false): array|false
{
    $statement = $pdo->prepare(
        'SELECT id, user_id, assigned_admin_user_id, subject, conversation_status,
                last_message_at, created_at, closed_at
         FROM chat_conversations
         WHERE user_id = :user_id AND conversation_status = :status
         ORDER BY id DESC LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '')
    );
    $statement->execute(['user_id' => $userId, 'status' => 'open']);
    return $statement->fetch();
}

function chatMessages(PDO $pdo, int $conversationId, int $currentUserId): array
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
            'is_current_user' => (int) $message['sender_user_id'] === $currentUserId,
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
    $user = requireVerifiedChatUser($pdo);

    if ($method !== 'GET') {
        requireValidCsrfToken();
    }

    if ($method === 'GET') {
        if (array_diff(array_keys($_GET), ['conversation_id']) !== []) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Unsupported Live Chat query parameter.']);
            exit;
        }
        if (!isset($_GET['conversation_id'])) {
            echo json_encode(['success' => true, 'conversations' => userConversationRows($pdo, $user['id'])]);
            exit;
        }

        $conversationId = chatConversationIdValue($_GET['conversation_id']);
        requireValidCsrfToken();
        $pdo->beginTransaction();
        $conversation = ownedConversation($pdo, $conversationId, $user['id'], true);
        if (!$conversation) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Live Chat conversation not found.']);
            exit;
        }
        $statement = $pdo->prepare(
            'UPDATE chat_messages SET read_at = NOW()
             WHERE conversation_id = :conversation_id
               AND sender_user_id <> :current_user_id
               AND read_at IS NULL'
        );
        $statement->execute(['conversation_id' => $conversationId, 'current_user_id' => $user['id']]);
        $messages = chatMessages($pdo, $conversationId, $user['id']);
        $pdo->commit();
        echo json_encode([
            'success' => true,
            'conversation' => formattedOwnedConversation($conversation),
            'messages' => $messages,
        ]);
        exit;
    }

    if ($_GET !== []) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Unsupported Live Chat query parameter.']);
        exit;
    }

    $input = chatJsonInput();
    $action = $input['action'] ?? ($method === 'POST' ? 'message' : 'close');
    if (!is_string($action)) {
        throw new InvalidArgumentException('Invalid Live Chat action.');
    }

    if ($method === 'POST' && $action === 'create') {
        if (array_diff(array_keys($input), ['action', 'subject']) !== []) {
            throw new InvalidArgumentException('Unsupported Live Chat field.');
        }
        if (isset($input['subject']) && !is_string($input['subject'])) {
            throw new InvalidArgumentException('The conversation subject must be text.');
        }
        $subject = trim((string) ($input['subject'] ?? 'Shelter Support'));
        $subjectLength = function_exists('mb_strlen') ? mb_strlen($subject) : strlen($subject);
        if ($subject === '' || $subjectLength > 150 || preg_match('/[\x00-\x1F\x7F]/u', $subject)) {
            throw new InvalidArgumentException('Enter a conversation subject of 150 characters or fewer.');
        }
        $pdo->beginTransaction();
        $existing = latestOpenConversation($pdo, $user['id'], true);
        if ($existing) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'message' => 'Close your current open ticket before starting another conversation.',
                'conversation' => formattedOwnedConversation($existing),
            ]);
            exit;
        }
        $statement = $pdo->prepare(
            'INSERT INTO chat_conversations (user_id, conversation_status, subject)
             VALUES (:user_id, :status, :subject)'
        );
        $statement->execute(['user_id' => $user['id'], 'status' => 'open', 'subject' => $subject]);
        $conversationId = (int) $pdo->lastInsertId();
        $conversation = ownedConversation($pdo, $conversationId, $user['id']);
        $pdo->commit();
        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'New support ticket created.',
            'conversation' => formattedOwnedConversation($conversation),
        ]);
        exit;
    }

    if ($method === 'POST' && $action === 'message') {
        if (array_diff(array_keys($input), ['action', 'conversation_id', 'message']) !== []) {
            throw new InvalidArgumentException('Unsupported Live Chat field.');
        }
        $message = validateChatMessage(['message' => $input['message'] ?? null]);
        $pdo->beginTransaction();
        if (array_key_exists('conversation_id', $input)) {
            $conversationId = chatConversationIdValue($input['conversation_id']);
            $conversation = ownedConversation($pdo, $conversationId, $user['id'], true);
        } else {
            $conversation = latestOpenConversation($pdo, $user['id'], true);
            $conversationId = $conversation ? (int) $conversation['id'] : 0;
        }
        if (!$conversation) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Live Chat conversation not found.']);
            exit;
        }
        if ($conversation['conversation_status'] !== 'open') {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'This support ticket is closed. Start a new conversation to send another message.']);
            exit;
        }
        $statement = $pdo->prepare(
            'INSERT INTO chat_messages (conversation_id, sender_user_id, message_body)
             VALUES (:conversation_id, :sender_user_id, :message_body)'
        );
        $statement->execute([
            'conversation_id' => $conversationId,
            'sender_user_id' => $user['id'],
            'message_body' => $message,
        ]);
        $messageId = (int) $pdo->lastInsertId();
        $statement = $pdo->prepare('UPDATE chat_conversations SET last_message_at = NOW() WHERE id = :id');
        $statement->execute(['id' => $conversationId]);
        $statement = $pdo->prepare(
            'SELECT id FROM users
             WHERE role IN (\'administrator\', \'super_administrator\') AND account_status = :account_status AND deleted_at IS NULL'
        );
        $statement->execute(['account_status' => 'active']);
        foreach ($statement->fetchAll() as $administrator) {
            createNotificationRecord(
                $pdo,
                (int) $administrator['id'],
                'chat_message_admin',
                'New message in ' . chatTicketId($conversationId) . ' from ' . $user['name'] . '.',
                '#/admin/chat/' . $conversationId
            );
        }
        $pdo->commit();
        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'Live Chat message sent.',
            'conversation_id' => $conversationId,
            'ticket_id' => chatTicketId($conversationId),
            'message_id' => $messageId,
        ]);
        exit;
    }

    if ($method === 'PATCH' && $action === 'close') {
        if (array_diff(array_keys($input), ['action', 'conversation_id']) !== []) {
            throw new InvalidArgumentException('Unsupported Live Chat field.');
        }
        $conversationId = chatConversationIdValue($input['conversation_id'] ?? null);
        $pdo->beginTransaction();
        $conversation = ownedConversation($pdo, $conversationId, $user['id'], true);
        if (!$conversation) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Live Chat conversation not found.']);
            exit;
        }
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
        $pdo->commit();
        echo json_encode([
            'success' => true,
            'message' => chatTicketId($conversationId) . ' was closed. Its history remains available.',
            'conversation_id' => $conversationId,
            'ticket_id' => chatTicketId($conversationId),
        ]);
        exit;
    }

    throw new InvalidArgumentException('Unsupported Live Chat action.');
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
    echo json_encode(['success' => false, 'message' => 'Unable to process the Live Chat request.']);
}
