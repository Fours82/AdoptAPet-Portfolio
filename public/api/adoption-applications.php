<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notification-service.php';

function activeRegisteredApplicant(PDO $pdo): int
{
    $userId = authenticatedUserId();
    if ($userId === null) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Authentication is required.']);
        exit;
    }

    $statement = $pdo->prepare(
        'SELECT u.id
         FROM users u
         WHERE u.id = :id
           AND u.role = :role
           AND u.account_status = :status
           AND u.deleted_at IS NULL
           AND EXISTS (
               SELECT 1
               FROM email_verifications ev
               WHERE ev.user_id = u.id AND ev.verified_at IS NOT NULL
           )
         LIMIT 1'
    );
    $statement->execute(['id' => $userId, 'role' => 'registered_user', 'status' => 'active']);
    if (!$statement->fetch()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'An active, verified registered-user account is required.']);
        exit;
    }

    return $userId;
}

function ownApplicationSelect(): string
{
    return 'SELECT a.id, a.pet_id, a.reason, a.application_status, a.submitted_at, a.reviewed_at, a.admin_notes,
        p.name AS pet_name, p.species AS pet_species, p.breed AS pet_breed, p.sex AS pet_sex,
        p.location AS pet_location, p.adoption_status AS pet_adoption_status,
        u.name AS applicant_name, u.email AS applicant_email,
        COALESCE((SELECT MAX(h.changed_at) FROM application_status_history h WHERE h.application_id = a.id), a.reviewed_at, a.submitted_at) AS last_updated_at,
        (SELECT pi.file_path FROM pet_images pi WHERE pi.pet_id = p.id ORDER BY pi.is_primary DESC, pi.sort_order ASC, pi.id ASC LIMIT 1) AS pet_image_path
        FROM adoption_applications a
        INNER JOIN pets p ON p.id = a.pet_id
        INNER JOIN users u ON u.id = a.applicant_user_id';
}

try {
    $pdo = getDatabaseConnection();
    $applicantId = activeRegisteredApplicant($pdo);
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method !== 'GET') {
        requireValidCsrfToken();
    }

    if ($method === 'GET') {
        if (array_diff(array_keys($_GET), ['id']) !== []) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Unsupported application query parameter.']);
            exit;
        }
        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        if (isset($_GET['id']) && $_GET['id'] !== '' && ($id === false || $id === null)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'A valid application ID is required.']);
            exit;
        }

        if ($id !== false && $id !== null) {
            $statement = $pdo->prepare(ownApplicationSelect() . ' WHERE a.id = :id AND a.applicant_user_id = :applicant_user_id LIMIT 1');
            $statement->execute(['id' => $id, 'applicant_user_id' => $applicantId]);
            $application = $statement->fetch();
            if (!$application) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Adoption application not found.']);
                exit;
            }
            $history = $pdo->prepare(
                'SELECT old_status, new_status, notes, changed_at
                 FROM application_status_history
                 WHERE application_id = :application_id
                 ORDER BY changed_at ASC, id ASC'
            );
            $history->execute(['application_id' => $id]);
            $application['status_history'] = $history->fetchAll();
            echo json_encode(['success' => true, 'application' => $application]);
            exit;
        }

        $statement = $pdo->prepare(ownApplicationSelect() . ' WHERE a.applicant_user_id = :applicant_user_id ORDER BY a.submitted_at DESC, a.id DESC');
        $statement->execute(['applicant_user_id' => $applicantId]);
        echo json_encode(['success' => true, 'applications' => $statement->fetchAll()]);
        exit;
    }

    if ($method === 'PATCH') {
        if (array_diff(array_keys($_GET), ['id']) !== []) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Unsupported application query parameter.']);
            exit;
        }

        $applicationId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        $input = json_decode(file_get_contents('php://input'), true);
        if ($applicationId === false || $applicationId === null || !is_array($input) || $input !== ['action' => 'cancel']) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'A valid pending-application cancellation request is required.']);
            exit;
        }

        $pdo->beginTransaction();
        $applicationStatement = $pdo->prepare(
            'SELECT id, application_status FROM adoption_applications
             WHERE id = :id AND applicant_user_id = :applicant_user_id FOR UPDATE'
        );
        $applicationStatement->execute(['id' => $applicationId, 'applicant_user_id' => $applicantId]);
        $application = $applicationStatement->fetch();
        if (!$application) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Adoption application not found.']);
            exit;
        }
        if ($application['application_status'] !== 'pending') {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Only a pending Adoption Application can be cancelled.']);
            exit;
        }

        $update = $pdo->prepare('UPDATE adoption_applications SET application_status = :status WHERE id = :id AND applicant_user_id = :applicant_user_id');
        $update->execute(['status' => 'cancelled', 'id' => $applicationId, 'applicant_user_id' => $applicantId]);
        $history = $pdo->prepare(
            'INSERT INTO application_status_history (application_id, old_status, new_status, changed_by_user_id, notes)
             VALUES (:application_id, :old_status, :new_status, :changed_by_user_id, :notes)'
        );
        $history->execute([
            'application_id' => $applicationId,
            'old_status' => 'pending',
            'new_status' => 'cancelled',
            'changed_by_user_id' => $applicantId,
            'notes' => 'Cancelled by applicant.',
        ]);
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Adoption Application cancelled.']);
        exit;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        header('Allow: GET, POST, PATCH');
        echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $petId = is_array($input) ? filter_var($input['pet_id'] ?? null, FILTER_VALIDATE_INT) : false;
    $reason = is_array($input) ? trim((string) ($input['reason'] ?? '')) : '';

    if ($petId === false || $petId === null || strlen($reason) < 15 || strlen($reason) > 65535) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Provide a valid pet listing and a reason of at least 15 characters.']);
        exit;
    }

    $pdo->beginTransaction();
    $applicant = $pdo->prepare(
        'SELECT u.id FROM users u
         WHERE u.id = :id AND u.role = :role AND u.account_status = :status
           AND u.deleted_at IS NULL
           AND EXISTS (SELECT 1 FROM email_verifications ev
                       WHERE ev.user_id = u.id AND ev.verified_at IS NOT NULL)
         FOR UPDATE'
    );
    $applicant->execute(['id' => $applicantId, 'role' => 'registered_user', 'status' => 'active']);
    if (!$applicant->fetch()) {
        $pdo->rollBack();
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'An active, verified registered-user account is required.']);
        exit;
    }
    $pet = $pdo->prepare('SELECT id, name, adoption_status FROM pets WHERE id = :id FOR UPDATE');
    $pet->execute(['id' => $petId]);
    $petRow = $pet->fetch();

    if (!$petRow) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Pet listing not found.']);
        exit;
    }

    if ($petRow['adoption_status'] !== 'available') {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This pet is no longer available for adoption applications.']);
        exit;
    }

    $duplicate = $pdo->prepare('SELECT id FROM adoption_applications WHERE pet_id = :pet_id AND applicant_user_id = :applicant_user_id AND application_status IN (\'pending\', \'approved\') LIMIT 1');
    $duplicate->execute(['pet_id' => $petId, 'applicant_user_id' => $applicantId]);
    if ($duplicate->fetch()) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'You already have an active application for this pet.']);
        exit;
    }

    $create = $pdo->prepare('INSERT INTO adoption_applications (pet_id, applicant_user_id, reason, application_status) VALUES (:pet_id, :applicant_user_id, :reason, :application_status)');
    $create->execute(['pet_id' => $petId, 'applicant_user_id' => $applicantId, 'reason' => $reason, 'application_status' => 'pending']);
    $applicationId = (int) $pdo->lastInsertId();
    $history = $pdo->prepare('INSERT INTO application_status_history (application_id, old_status, new_status, changed_by_user_id) VALUES (:application_id, :old_status, :new_status, :changed_by_user_id)');
    $history->execute(['application_id' => $applicationId, 'old_status' => null, 'new_status' => 'pending', 'changed_by_user_id' => $applicantId]);
    createNotificationRecord(
        $pdo,
        $applicantId,
        'application_submitted',
        'Your Adoption Application for ' . $petRow['name'] . ' was submitted and is pending review.',
        '#/application/' . $applicationId
    );
    notifyOperationalAdministrators(
        $pdo,
        'admin_application_submitted',
        'New Adoption Application #' . $applicationId . ' needs review.',
        '#/admin/applications'
    );
    $pdo->commit();

    http_response_code(201);
    echo json_encode(['success' => true, 'message' => 'Adoption application submitted successfully.', 'id' => $applicationId]);
} catch (PDOException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to process the adoption application. Please try again later.']);
}
