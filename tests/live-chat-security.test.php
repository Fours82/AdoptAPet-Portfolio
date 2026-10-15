<?php
declare(strict_types=1);

function liveChatCheck(bool $condition, string $label): void
{
    if (!$condition) {
        fwrite(STDERR, "Live Chat regression failed: {$label}\n");
        exit(1);
    }
}

$userApi = file_get_contents(dirname(__DIR__) . '/public/api/chat.php');
$adminApi = file_get_contents(dirname(__DIR__) . '/public/api/admin-chat.php');
$client = file_get_contents(dirname(__DIR__) . '/public/assets/js/chat-tickets.js');

liveChatCheck(is_string($userApi) && is_string($adminApi) && is_string($client), 'required sources are readable');

liveChatCheck(
    str_contains($userApi, "\$user['role'] !== 'registered_user'")
    && str_contains($userApi, "\$user['account_status'] !== 'active'")
    && str_contains($userApi, "!(bool) \$user['email_verified']"),
    'active verified Registered User boundary remains enforced'
);
liveChatCheck(
    str_contains($userApi, 'WHERE id = :id AND user_id = :user_id'),
    'conversation access remains scoped to its owner'
);
liveChatCheck(
    str_contains($userApi, "if (\$method !== 'GET')")
    && substr_count($userApi, 'requireValidCsrfToken();') >= 2,
    'mutations and message-detail reads retain CSRF checks'
);
liveChatCheck(
    str_contains($userApi, "latestOpenConversation(\$pdo, \$user['id'], true)")
    && str_contains($userApi, 'Close your current open ticket before starting another conversation.')
    && str_contains($userApi, 'http_response_code(409);'),
    'transactional one-open-ticket rule remains enforced'
);
liveChatCheck(
    substr_count($userApi, "\$conversation['conversation_status'] !== 'open'") >= 2
    && str_contains($userApi, 'This support ticket is closed. Start a new conversation to send another message.'),
    'closed tickets cannot receive messages'
);
liveChatCheck(
    str_contains($userApi, 'UPDATE chat_conversations SET conversation_status = :status, closed_at = NOW()')
    && !str_contains($userApi, 'DELETE FROM chat_messages'),
    'closing retains conversation history'
);
liveChatCheck(
    str_contains($adminApi, 'isOperationalAdministratorRole')
    && str_contains($adminApi, 'This Live Chat conversation is assigned to another administrator.'),
    'administrator access and assignment safeguards remain enforced'
);
liveChatCheck(
    str_contains($userApi, "'chat_message_admin'")
    && str_contains($adminApi, "'chat_message'"),
    'chat notification integration remains present'
);
liveChatCheck(
    preg_match('/loadUserTickets\(true\);\s*}\s*,\s*5000\)/', $client) === 1
    && !preg_match('/WebSocket|EventSource|socket\.io/i', $client),
    'five-second polling remains in place without a realtime backend replacement'
);

echo "Live Chat ownership, CSRF, open/closed rules, history retention, notifications, and polling checks passed.\n";
