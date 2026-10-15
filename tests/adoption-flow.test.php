<?php
declare(strict_types=1);

function adoptionCheck(bool $condition, string $description): void
{
    if (!$condition) {
        throw new RuntimeException('Adoption flow check failed: ' . $description);
    }
}

$root = dirname(__DIR__);
$own = file_get_contents($root . '/public/api/adoption-applications.php');
$admin = file_get_contents($root . '/public/api/admin-adoption-applications.php');
$approval = file_get_contents($root . '/public/api/adoption-approval-service.php');
$pets = file_get_contents($root . '/public/api/pets.php');
$adminPets = file_get_contents($root . '/public/api/admin-pets.php');
$documents = file_get_contents($root . '/public/api/documents.php');
$pdf = file_get_contents($root . '/public/api/pdf-document-service.php');

foreach ([$own, $admin, $approval, $pets, $adminPets, $documents, $pdf] as $source) {
    adoptionCheck(is_string($source), 'source files are readable');
}

adoptionCheck(str_contains($pets, "p.adoption_status <> :inactive_status")
    && str_contains($pets, "['available', 'pending', 'adopted']")
    && str_contains($pets, "'medical_records'")
    && str_contains($pets, "'images'"), 'public detail/status, medical and gallery reads');
adoptionCheck(str_contains($own, "'registered_user'")
    && str_contains($own, 'account_status = :status')
    && str_contains($own, 'ev.verified_at IS NOT NULL')
    && str_contains($own, 'FOR UPDATE'), 'registered-user eligibility and locked submission');
adoptionCheck(str_contains($own, "application_status IN (\\'pending\\', \\'approved\\')")
    && str_contains($own, "\$petRow['adoption_status'] !== 'available'")
    && str_contains($own, 'requireValidCsrfToken()'), 'active duplicate, pet availability and CSRF guards');
adoptionCheck(str_contains($own, 'a.applicant_user_id = :applicant_user_id')
    && str_contains($own, 'a.admin_notes')
    && str_contains($own, 'SELECT old_status, new_status, notes, changed_at'), 'ownership and applicant-facing review-note history');
adoptionCheck(str_contains($admin, 'requireOperationalAdministrator($pdo)')
    && str_contains($admin, "\$applicationRow['application_status'] !== 'pending'")
    && str_contains($admin, 'applicant_email_verified')
    && str_contains($admin, "'registered_user'"), 'privileged review, stale-state and applicant eligibility guards');
adoptionCheck(str_contains($admin, "'adoption_status' => 'adopted'")
    && str_contains($admin, "'available_status' => 'available'")
    && str_contains($admin, '$pet->rowCount() !== 1')
    && str_contains($admin, 'application_status_history')
    && str_contains($admin, 'adminAuditWrite(')
    && str_contains($admin, 'createNotificationRecord('), 'single approval, history, audit and notification');
adoptionCheck(str_contains($admin, 'id <> :approved_id AND application_status = :pending_status')
    && str_contains($admin, 'ORDER BY id FOR UPDATE')
    && str_contains($admin, 'rejectCompetingPendingApplications(')
    && substr_count($admin, 'adminAuditWrite(') === 1
    && str_contains($approval, 'application_status = :pending_status')
    && str_contains($approval, 'Another application for this pet has been approved.')
    && str_contains($approval, 'createNotificationRecord('),
    'locked pending-only automatic rejections, one primary audit event, history and notifications');
adoptionCheck(str_contains($adminPets, "in_array(\$pet['adoption_status'], ['available', 'pending'], true)")
    && str_contains($adminPets, "application_status = :approved_status"), 'approved pet cannot reopen as available or pending');
adoptionCheck(str_contains($documents, 'a.applicant_user_id = :user_id')
    && str_contains($documents, "\$source['application_status'] !== 'approved'")
    && str_contains($documents, 'Cache-Control: private, no-store')
    && str_contains($pdf, 'adoptAPetGeneratedDocumentStorageDirectory()')
    && str_contains($pdf, 'FOR UPDATE'), 'owned approved-only private certificate');

echo "Adoption flow PHP boundary checks passed.\n";
