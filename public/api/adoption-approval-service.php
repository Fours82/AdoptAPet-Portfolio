<?php
declare(strict_types=1);

require_once __DIR__ . '/notification-service.php';

/** Applies the already-locked competing rows inside the caller's approval transaction. */
function rejectCompetingPendingApplications(
    PDO $pdo,
    array $competingApplications,
    int $petId,
    int $administratorId,
    string $petName
): int {
    if (!$pdo->inTransaction()) {
        throw new LogicException('Competing applications require the approval transaction.');
    }

    $reason = 'Another application for this pet has been approved.';
    $reject = $pdo->prepare(
        'UPDATE adoption_applications SET application_status = :rejected_status
         WHERE id = :id AND pet_id = :pet_id AND application_status = :pending_status'
    );
    $history = $pdo->prepare(
        'INSERT INTO application_status_history
         (application_id, old_status, new_status, changed_by_user_id, notes)
         VALUES (:application_id, :old_status, :new_status, :changed_by_user_id, :notes)'
    );
    $notifiedUsers = [];
    $rejectedCount = 0;

    foreach ($competingApplications as $application) {
        $applicationId = (int) $application['id'];
        $userId = (int) $application['applicant_user_id'];
        $reject->execute([
            'rejected_status' => 'rejected',
            'id' => $applicationId,
            'pet_id' => $petId,
            'pending_status' => 'pending',
        ]);
        if ($reject->rowCount() !== 1) {
            throw new RuntimeException('A competing application changed during approval.');
        }

        $history->execute([
            'application_id' => $applicationId,
            'old_status' => 'pending',
            'new_status' => 'rejected',
            'changed_by_user_id' => $administratorId,
            'notes' => $reason,
        ]);
        if (!isset($notifiedUsers[$userId])) {
            createNotificationRecord(
                $pdo,
                $userId,
                'application_status',
                'Your Adoption Application for ' . $petName . ' was rejected. ' . $reason,
                '#/application/' . $applicationId
            );
            $notifiedUsers[$userId] = true;
        }
        $rejectedCount++;
    }

    return $rejectedCount;
}
