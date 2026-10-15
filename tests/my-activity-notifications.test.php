<?php
declare(strict_types=1);

function connectedAreaCheck(bool $condition, string $description): void
{
    if (!$condition) {
        throw new RuntimeException('Connected account-area check failed: ' . $description);
    }
}

$root = dirname(__DIR__) . '/public/api/';
$applications = file_get_contents($root . 'adoption-applications.php');
$profile = file_get_contents($root . 'profile.php');
$notifications = file_get_contents($root . 'notifications.php');
$documents = file_get_contents($root . 'documents.php');
$approval = file_get_contents($root . 'adoption-approval-service.php');
$news = file_get_contents($root . 'admin-news.php');
foreach ([$applications, $profile, $notifications, $documents, $approval, $news] as $source) {
    connectedAreaCheck(is_string($source), 'required endpoint source is readable');
}

connectedAreaCheck(str_contains($applications, 'a.applicant_user_id = :applicant_user_id')
    && str_contains($applications, 'application_status_history')
    && str_contains($applications, 'ORDER BY a.submitted_at DESC, a.id DESC')
    && str_contains($applications, "'role' => 'registered_user'")
    && str_contains($applications, 'requireValidCsrfToken()'),
    'own applications, status history, order, role and mutation CSRF');
connectedAreaCheck(str_contains($documents, 'a.applicant_user_id = :user_id')
    && str_contains($documents, "'approved'")
    && str_contains($documents, 'requireVerifiedDocumentUser('),
    'approved certificate remains applicant-owned');
connectedAreaCheck(str_contains($approval, 'Another application for this pet has been approved.')
    && str_contains($approval, "'notes' => \$reason")
    && str_contains($approval, "'#/application/' . \$applicationId"),
    'competing rejection reason remains in history and owned notification');
connectedAreaCheck(str_contains($profile, 'reporter_user_id = :user_id')
    && str_contains($profile, 'd.user_id = :user_id')
    && str_contains($profile, 'a.applicant_user_id = :user_id')
    && str_contains($profile, "'role' => 'registered_user'"),
    'My Activity uses owned real data for Registered Users only');
connectedAreaCheck(str_contains($notifications, 'recipient_user_id = :recipient_user_id')
    && str_contains($notifications, 'ORDER BY created_at DESC, id DESC')
    && str_contains($notifications, "\$user['role'] === 'registered_user'")
    && str_contains($notifications, 'isOperationalAdministratorRole(')
    && str_contains($notifications, "\$user['account_status'] !== 'active'")
    && str_contains($notifications, 'requireValidCsrfToken()')
    && str_contains($notifications, "'unread_count' => notificationUnreadCount(\$pdo, \$userId, \$isOperationalAdmin)")
    && str_contains($notifications, 'AND is_read = :unread_status'),
    'recipient ownership, ordering, CSRF, authoritative counts and idempotent mark-all');
connectedAreaCheck(str_contains($news, "'#/news/' . \$newsId")
    && substr_count($news, "notifyNewsPublication(\$pdo, \$newsId, \$news['title'])") === 2,
    'new publication notices lead to the actual article on create or publish');

echo "My Applications, My Activity and Notifications PHP checks passed.\n";
