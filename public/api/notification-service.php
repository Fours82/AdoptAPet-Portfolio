<?php
declare(strict_types=1);

/** Inserts one in-platform notification using the caller's existing transaction. */
function createNotificationRecord(
    PDO $pdo,
    int $recipientUserId,
    string $notificationType,
    string $message,
    ?string $linkUrl = null
): int {
    $statement = $pdo->prepare(
        'INSERT INTO notifications
         (recipient_user_id, notification_type, message, link_url, is_read)
         VALUES (:recipient_user_id, :notification_type, :message, :link_url, :is_read)'
    );
    $statement->execute([
        'recipient_user_id' => $recipientUserId,
        'notification_type' => $notificationType,
        'message' => $message,
        'link_url' => $linkUrl,
        'is_read' => 0,
    ]);

    return (int) $pdo->lastInsertId();
}

/** Fans out one operational alert per active privileged account in the caller's transaction. */
function notifyOperationalAdministrators(PDO $pdo, string $type, string $message, string $linkUrl): void
{
    if (!in_array($type, ['admin_application_submitted', 'admin_stray_report_submitted'], true)) {
        throw new InvalidArgumentException('Unsupported operational notification type.');
    }

    $statement = $pdo->prepare(
        'SELECT id FROM users
         WHERE role IN (:administrator_role, :super_administrator_role)
           AND account_status = :active_status AND deleted_at IS NULL
         ORDER BY id'
    );
    $statement->execute([
        'administrator_role' => 'administrator',
        'super_administrator_role' => 'super_administrator',
        'active_status' => 'active',
    ]);
    foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $recipientId) {
        createNotificationRecord($pdo, (int) $recipientId, $type, $message, $linkUrl);
    }
}

/** Hide operational alerts from accounts that are no longer privileged. */
function notificationVisibilitySql(bool $isOperationalAdmin): string
{
    return $isOperationalAdmin ? ''
        : " AND notification_type NOT IN ('admin_application_submitted', 'admin_stray_report_submitted', 'chat_message_admin')";
}
