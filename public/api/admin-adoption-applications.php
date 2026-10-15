<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/admin-audit-service.php';
require_once __DIR__ . '/notification-service.php';
require_once __DIR__ . '/adoption-approval-service.php';
require_once __DIR__ . '/pdf-document-service.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/document-email-service.php';

function activeApplicationAdministrator(PDO $pdo): int
{
    return requireOperationalAdministrator($pdo);
}

function adminApplicationSelect(): string
{
    return 'SELECT a.id, a.pet_id, a.applicant_user_id, a.reason, a.application_status, a.submitted_at, a.reviewed_at, a.admin_notes,
        u.name AS applicant_name, u.email AS applicant_email,
        p.name AS pet_name, p.species AS pet_species, p.breed AS pet_breed, p.location AS pet_location, p.adoption_status AS pet_adoption_status
        FROM adoption_applications a
        INNER JOIN users u ON u.id = a.applicant_user_id
        INNER JOIN pets p ON p.id = a.pet_id';
}

try {
    $pdo = getDatabaseConnection();
    $administratorId = activeApplicationAdministrator($pdo);
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method !== 'GET') {
        requireValidCsrfToken();
    }

    if ($method === 'GET') {
        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        if (isset($_GET['id']) && $_GET['id'] !== '' && ($id === false || $id === null)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'A valid application ID is required.']);
            exit;
        }

        if ($id !== false && $id !== null) {
            $statement = $pdo->prepare(adminApplicationSelect() . ' WHERE a.id = :id LIMIT 1');
            $statement->execute(['id' => $id]);
            $application = $statement->fetch();
            if (!$application) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Adoption application not found.']);
                exit;
            }
            echo json_encode(['success' => true, 'application' => $application]);
            exit;
        }

        $status = strtolower(trim((string) ($_GET['status'] ?? '')));
        $query = adminApplicationSelect();
        $parameters = [];
        if ($status !== '') {
            if (!in_array($status, ['pending', 'approved', 'rejected', 'withdrawn', 'cancelled'], true)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Invalid application status.']);
                exit;
            }
            $query .= ' WHERE a.application_status = :status';
            $parameters['status'] = $status;
        }
        $statement = $pdo->prepare($query . ' ORDER BY a.submitted_at DESC, a.id DESC');
        $statement->execute($parameters);
        echo json_encode(['success' => true, 'applications' => $statement->fetchAll()]);
        exit;
    }

    if ($method !== 'PATCH') {
        http_response_code(405);
        header('Allow: GET, PATCH');
        echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
        exit;
    }

    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    $input = json_decode(file_get_contents('php://input'), true);
    $status = is_array($input) ? strtolower(trim((string) ($input['application_status'] ?? ''))) : '';
    $notes = is_array($input) ? trim((string) ($input['admin_notes'] ?? '')) : '';
    if ($id === false || $id === null || !in_array($status, ['approved', 'rejected'], true) || strlen($notes) > 65535) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Provide a valid application ID and review decision.']);
        exit;
    }

    $pdo->beginTransaction();
    $application = $pdo->prepare(
        'SELECT a.id, a.pet_id, a.applicant_user_id, a.application_status,
                p.name AS pet_name, u.name AS applicant_name, u.email AS applicant_email,
                u.role AS applicant_role, u.account_status AS applicant_account_status,
                u.deleted_at AS applicant_deleted_at,
                EXISTS (SELECT 1 FROM email_verifications ev
                        WHERE ev.user_id = u.id AND ev.verified_at IS NOT NULL) AS applicant_email_verified
         FROM adoption_applications a
         INNER JOIN pets p ON p.id = a.pet_id
         INNER JOIN users u ON u.id = a.applicant_user_id
         WHERE a.id = :id
         FOR UPDATE'
    );
    $application->execute(['id' => $id]);
    $applicationRow = $application->fetch();
    if (!$applicationRow) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Adoption application not found.']);
        exit;
    }
    if ($applicationRow['application_status'] !== 'pending') {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Only pending applications can be reviewed.']);
        exit;
    }

    if ($status === 'approved') {
        if ($applicationRow['applicant_role'] !== 'registered_user'
            || $applicationRow['applicant_account_status'] !== 'active'
            || $applicationRow['applicant_deleted_at'] !== null
            || (int) $applicationRow['applicant_email_verified'] !== 1) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'The applicant account is no longer eligible for approval.']);
            exit;
        }
        $pet = $pdo->prepare('UPDATE pets SET adoption_status = :adoption_status WHERE id = :id AND adoption_status = :available_status');
        $pet->execute(['adoption_status' => 'adopted', 'id' => $applicationRow['pet_id'], 'available_status' => 'available']);
        if ($pet->rowCount() !== 1) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'This pet is no longer available for approval.']);
            exit;
        }
    }

    $review = $pdo->prepare('UPDATE adoption_applications SET application_status = :application_status, reviewed_by_user_id = :reviewed_by_user_id, reviewed_at = NOW(), admin_notes = :admin_notes WHERE id = :id');
    $review->execute(['application_status' => $status, 'reviewed_by_user_id' => $administratorId, 'admin_notes' => $notes === '' ? null : $notes, 'id' => $id]);
    $history = $pdo->prepare('INSERT INTO application_status_history (application_id, old_status, new_status, changed_by_user_id, notes) VALUES (:application_id, :old_status, :new_status, :changed_by_user_id, :notes)');
    $history->execute(['application_id' => $id, 'old_status' => 'pending', 'new_status' => $status, 'changed_by_user_id' => $administratorId, 'notes' => $notes === '' ? null : $notes]);
    $autoRejectedCount = 0;
    if ($status === 'approved') {
        $competing = $pdo->prepare(
            'SELECT id, applicant_user_id FROM adoption_applications
             WHERE pet_id = :pet_id AND id <> :approved_id AND application_status = :pending_status
             ORDER BY id FOR UPDATE'
        );
        $competing->execute([
            'pet_id' => $applicationRow['pet_id'],
            'approved_id' => $id,
            'pending_status' => 'pending',
        ]);
        $autoRejectedCount = rejectCompetingPendingApplications(
            $pdo,
            $competing->fetchAll(),
            (int) $applicationRow['pet_id'],
            $administratorId,
            (string) $applicationRow['pet_name']
        );
    }
    adminAuditWrite($pdo, $status === 'approved' ? 'application_approved' : 'application_rejected', $id,
        ['status' => 'pending'], ['status' => $status]);
    createNotificationRecord(
        $pdo,
        (int) $applicationRow['applicant_user_id'],
        'application_status',
        'Your Adoption Application for ' . $applicationRow['pet_name'] . ' was ' . $status . '.',
        '#/application/' . $id
    );
    $pdo->commit();

    $certificateAvailable = false;
    $certificateEmailSent = false;
    if ($status === 'approved') {
        try {
            $approvalDate = $pdo->prepare('SELECT reviewed_at FROM adoption_applications WHERE id = :id LIMIT 1');
            $approvalDate->execute(['id' => $id]);
            $reviewedAt = (string) $approvalDate->fetchColumn();
            $document = createOrReusePdfDocument(
                $pdo,
                (int) $applicationRow['applicant_user_id'],
                'adoption_certificate',
                (int) $id,
                [
                    'application_id' => (int) $id,
                    'application_status' => 'approved',
                    'adopter_name' => $applicationRow['applicant_name'],
                    'pet_name' => $applicationRow['pet_name'],
                    'approval_date' => $reviewedAt,
                ]
            );
            $certificateAvailable = isRealPdfFile($document['file_path']);
        } catch (Throwable $documentException) {
            $certificateAvailable = false;
        }

        if ($certificateAvailable) {
            try {
                $delivery = deliverGeneratedDocumentEmail(
                    $pdo,
                    $document,
                    static function () use ($applicationRow, $document): void {
                        sendAdoptionCertificateEmail(
                            $applicationRow['applicant_email'],
                            $applicationRow['applicant_name'],
                            $applicationRow['pet_name'],
                            $document['document_number'],
                            $document['file_path']
                        );
                    }
                );
                $certificateEmailSent = $delivery['sent'];
            } catch (Throwable $mailException) {
                $certificateEmailSent = false;
            }
        }
    }

    $message = 'Application ' . $status . ' successfully.';
    if ($status === 'approved' && $autoRejectedCount > 0) {
        $message .= ' ' . $autoRejectedCount . ' other pending application' . ($autoRejectedCount === 1 ? ' was' : 's were') . ' automatically rejected.';
    }
    if ($status === 'approved' && $certificateEmailSent) {
        $message .= ' The Adoption Certificate was emailed to the applicant.';
    } elseif ($status === 'approved' && $certificateAvailable) {
        $message .= ' The certificate is available for download, but the email could not be sent.';
    } elseif ($status === 'approved') {
        $message .= ' The approval remains valid, but the certificate could not be prepared automatically.';
    }

    echo json_encode([
        'success' => true,
        'message' => $message,
        'certificate_available' => $certificateAvailable,
        'certificate_email_sent' => $certificateEmailSent,
        'automatically_rejected_count' => $autoRejectedCount,
    ]);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to manage adoption applications. Please try again later.']);
}
