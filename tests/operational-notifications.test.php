<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/public/api/notification-service.php';

function operationalCheck(bool $ok, string $description): void
{
    if (!$ok) {
        throw new RuntimeException('Operational notification check failed: ' . $description);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT, account_status TEXT, deleted_at TEXT)');
$pdo->exec('CREATE TABLE notifications (id INTEGER PRIMARY KEY AUTOINCREMENT, recipient_user_id INTEGER,
    notification_type TEXT, message TEXT, link_url TEXT, is_read INTEGER)');
$users = $pdo->prepare('INSERT INTO users (id, role, account_status, deleted_at) VALUES (?, ?, ?, ?)');
foreach ([
    [1, 'administrator', 'active', null],
    [2, 'super_administrator', 'active', null],
    [3, 'registered_user', 'active', null],
    [4, 'administrator', 'deactivated', null],
    [5, 'super_administrator', 'active', '2026-09-14'],
    [6, 'unknown_role', 'active', null],
] as $row) {
    $users->execute($row);
}

$pdo->beginTransaction();
notifyOperationalAdministrators($pdo, 'admin_application_submitted', 'Application #7 needs review.', '#/admin/applications');
operationalCheck((int) $pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn() === 2, 'one notice per eligible privileged account');
$pdo->rollBack();
operationalCheck((int) $pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn() === 0, 'failed submission rolls back its notices');

$pdo->beginTransaction();
notifyOperationalAdministrators($pdo, 'admin_application_submitted', 'Application #7 needs review.', '#/admin/applications');
notifyOperationalAdministrators($pdo, 'admin_stray_report_submitted', 'Stray Report #8 needs review.', '#/admin/strays');
$pdo->commit();
$rows = $pdo->query('SELECT recipient_user_id, notification_type, link_url FROM notifications ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
operationalCheck(count($rows) === 4, 'each successful event produces exactly two notices');
operationalCheck(array_column($rows, 'recipient_user_id') === [1, 2, 1, 2], 'Admin and Super Admin receive events, others do not');
operationalCheck(array_column($rows, 'link_url') === [
    '#/admin/applications', '#/admin/applications', '#/admin/strays', '#/admin/strays',
], 'notifications use existing Admin routes');
try {
    notifyOperationalAdministrators($pdo, 'news_published', 'Not allowed', '#/admin/news');
    throw new RuntimeException('Noisy event was accepted.');
} catch (InvalidArgumentException $exception) {
    operationalCheck((int) $pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn() === 4, 'unapproved event did not write');
}

$root = dirname(__DIR__) . '/public/api/';
$applications = file_get_contents($root . 'adoption-applications.php');
$strays = file_get_contents($root . 'stray-reports.php');
$inbox = file_get_contents($root . 'notifications.php');
operationalCheck(is_string($applications) && is_string($strays) && is_string($inbox), 'endpoint sources readable');
foreach ([[$applications, 'admin_application_submitted'], [$strays, 'admin_stray_report_submitted']] as [$source, $type]) {
    operationalCheck(substr_count($source, "'" . $type . "'") === 1
        && str_contains($source, 'notifyOperationalAdministrators(')
        && strpos($source, 'notifyOperationalAdministrators(') < strrpos($source, '$pdo->commit();')
        && str_contains($source, 'requireValidCsrfToken()'), 'single in-transaction, CSRF-protected ' . $type . ' write');
}
operationalCheck(str_contains($applications, 'SELECT id FROM adoption_applications WHERE pet_id = :pet_id AND applicant_user_id = :applicant_user_id')
    && str_contains($applications, 'FOR UPDATE'), 'duplicate application prevented under pet lock');
operationalCheck(str_contains(notificationVisibilitySql(false), 'admin_application_submitted')
    && str_contains(notificationVisibilitySql(false), 'admin_stray_report_submitted')
    && str_contains(notificationVisibilitySql(false), 'chat_message_admin')
    && notificationVisibilitySql(true) === ''
    && substr_count($inbox, 'notificationVisibilitySql($isOperationalAdmin)') >= 5,
    'demoted accounts cannot list, count or update operational notices');
$visibleUserRows = $pdo->query('SELECT recipient_user_id FROM notifications WHERE recipient_user_id = 1'
    . notificationVisibilitySql(false))->fetchAll(PDO::FETCH_COLUMN);
operationalCheck($visibleUserRows === [], 'demoted recipient cannot read previously delivered Admin alerts');

echo "Operational notification fan-out, rollback, role and destination checks passed.\n";
