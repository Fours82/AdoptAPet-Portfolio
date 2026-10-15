<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/public/api/adoption-approval-service.php';

function autoRejectCheck(bool $condition, string $description): void
{
    if (!$condition) {
        throw new RuntimeException('Automatic rejection check failed: ' . $description);
    }
}

function adoptionFixture(bool $failingRecipient = false): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('CREATE TABLE pets (id INTEGER PRIMARY KEY, adoption_status TEXT NOT NULL)');
    $pdo->exec('CREATE TABLE adoption_applications
        (id INTEGER PRIMARY KEY, pet_id INTEGER NOT NULL, applicant_user_id INTEGER NOT NULL,
         application_status TEXT NOT NULL, admin_notes TEXT)');
    $pdo->exec('CREATE TABLE application_status_history
        (id INTEGER PRIMARY KEY, application_id INTEGER NOT NULL, old_status TEXT,
         new_status TEXT NOT NULL, changed_by_user_id INTEGER, notes TEXT)');
    $pdo->exec('CREATE TABLE notifications
        (id INTEGER PRIMARY KEY, recipient_user_id INTEGER NOT NULL CHECK (recipient_user_id > 0),
         notification_type TEXT, message TEXT, link_url TEXT, is_read INTEGER)');
    $pdo->exec("INSERT INTO pets VALUES (1, 'available')");
    $thirdUser = $failingRecipient ? 0 : 103;
    $insert = $pdo->prepare('INSERT INTO adoption_applications VALUES (?, 1, ?, ?, ?)');
    foreach ([
        [1, 101, 'pending', null],
        [2, 102, 'pending', 'Existing applicant-facing note'],
        [3, $thirdUser, 'pending', null],
        [4, 104, 'rejected', 'Prior rejection note'],
        [5, 105, 'cancelled', null],
    ] as $row) {
        $insert->execute($row);
    }
    return $pdo;
}

function approveFixtureApplication(PDO $pdo): void
{
    $pdo->beginTransaction();
    $pet = $pdo->prepare("UPDATE pets SET adoption_status = 'adopted' WHERE id = 1 AND adoption_status = 'available'");
    $pet->execute();
    autoRejectCheck($pet->rowCount() === 1, 'selected pet was available');
    $pdo->exec("UPDATE adoption_applications SET application_status = 'approved' WHERE id = 1 AND application_status = 'pending'");
    $pdo->exec("INSERT INTO application_status_history
        (application_id, old_status, new_status, changed_by_user_id)
        VALUES (1, 'pending', 'approved', 900)");
    $rows = $pdo->query("SELECT id, applicant_user_id FROM adoption_applications
        WHERE pet_id = 1 AND id <> 1 AND application_status = 'pending' ORDER BY id")->fetchAll();
    autoRejectCheck(rejectCompetingPendingApplications($pdo, $rows, 1, 900, 'Milo') === 2,
        'exactly two competing pending applications rejected');
    createNotificationRecord($pdo, 101, 'application_status', 'Approved', '#/application/1');
}

$pdo = adoptionFixture();
approveFixtureApplication($pdo);
$pdo->commit();
$statuses = $pdo->query('SELECT id, application_status FROM adoption_applications ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
autoRejectCheck($statuses === [1 => 'approved', 2 => 'rejected', 3 => 'rejected', 4 => 'rejected', 5 => 'cancelled'],
    'approved, competing, and unrelated historical statuses');
autoRejectCheck($pdo->query('SELECT adoption_status FROM pets WHERE id = 1')->fetchColumn() === 'adopted',
    'pet adopted with selected application');
autoRejectCheck((int) $pdo->query('SELECT COUNT(*) FROM application_status_history')->fetchColumn() === 3,
    'selected approval and each automatic rejection have one history row');
autoRejectCheck((int) $pdo->query("SELECT COUNT(*) FROM application_status_history
    WHERE old_status = 'pending' AND new_status = 'rejected'
      AND notes = 'Another application for this pet has been approved.'")->fetchColumn() === 2,
    'system-generated reason recorded for both applications');
autoRejectCheck((int) $pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn() === 3,
    'approved applicant and two affected applicants each notified once');
autoRejectCheck((int) $pdo->query('SELECT COUNT(DISTINCT recipient_user_id) FROM notifications')->fetchColumn() === 3,
    'no duplicate applicant notifications');
autoRejectCheck($pdo->query('SELECT admin_notes FROM adoption_applications WHERE id = 2')->fetchColumn()
    === 'Existing applicant-facing note', 'automatic rejection preserves existing notes');
autoRejectCheck($pdo->query('SELECT admin_notes FROM adoption_applications WHERE id = 4')->fetchColumn()
    === 'Prior rejection note', 'earlier rejection remains unchanged');

$legacy = adoptionFixture();
$legacy->exec("INSERT INTO adoption_applications VALUES (6, 1, 102, 'pending', NULL)");
$legacy->beginTransaction();
$legacyRows = $legacy->query("SELECT id, applicant_user_id FROM adoption_applications
    WHERE pet_id = 1 AND id <> 1 AND application_status = 'pending' ORDER BY id")->fetchAll();
autoRejectCheck(rejectCompetingPendingApplications($legacy, $legacyRows, 1, 900, 'Milo') === 3,
    'legacy duplicate pending rows are all rejected');
$legacy->commit();
autoRejectCheck((int) $legacy->query('SELECT COUNT(*) FROM notifications')->fetchColumn() === 2,
    'legacy duplicate rows notify each affected user only once');

$pdo->beginTransaction();
$secondApproval = $pdo->exec("UPDATE pets SET adoption_status = 'adopted'
    WHERE id = 1 AND adoption_status = 'available'");
autoRejectCheck($secondApproval === 0, 'conflicting second approval cannot claim adopted pet');
$pdo->rollBack();

$failed = adoptionFixture(true);
try {
    approveFixtureApplication($failed);
    throw new RuntimeException('Expected the notification constraint to fail.');
} catch (PDOException $exception) {
    $failed->rollBack();
}
autoRejectCheck($failed->query('SELECT adoption_status FROM pets WHERE id = 1')->fetchColumn() === 'available',
    'failed approval rolls pet back');
autoRejectCheck((int) $failed->query("SELECT COUNT(*) FROM adoption_applications WHERE application_status = 'pending'")->fetchColumn() === 3,
    'failed approval rolls all application statuses back');
autoRejectCheck((int) $failed->query('SELECT COUNT(*) FROM application_status_history')->fetchColumn() === 0
    && (int) $failed->query('SELECT COUNT(*) FROM notifications')->fetchColumn() === 0,
    'failed approval rolls history and notifications back');

echo "Automatic competing-application rejection tests passed.\n";
