<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/admin-audit-service.php';
require_once __DIR__ . '/image-upload.php';

function requireActiveAdministrator(PDO $pdo): int
{
    $userId = authenticatedUserId();

    if ($userId === null) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Authentication is required.']);
        exit;
    }

    $statement = $pdo->prepare('SELECT role FROM users WHERE id = :id AND account_status = :status AND deleted_at IS NULL LIMIT 1');
    $statement->execute(['id' => $userId, 'status' => 'active']);
    $user = $statement->fetch();

    if (!isOperationalAdministratorRole($user['role'] ?? null)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Administrator access is required.']);
        exit;
    }

    return $userId;
}

function validatePetPayload(array $input): array
{
    $allowedFields = [
        'name', 'species', 'breed', 'sex', 'estimated_age_months',
        'location', 'intake_date', 'adoption_status', 'story',
    ];
    if (array_diff(array_keys($input), $allowedFields) !== []) {
        throw new InvalidArgumentException('Only supported pet listing fields may be changed.');
    }

    foreach (['name', 'species', 'breed', 'sex', 'location', 'intake_date', 'adoption_status', 'story'] as $field) {
        if (array_key_exists($field, $input) && $input[$field] !== null && !is_string($input[$field])) {
            throw new InvalidArgumentException('One or more pet listing fields contain an invalid value.');
        }
    }

    $name = trim((string) ($input['name'] ?? ''));
    $species = strtolower(trim((string) ($input['species'] ?? '')));
    $breed = trim((string) ($input['breed'] ?? ''));
    $sex = strtolower(trim((string) ($input['sex'] ?? 'unknown')));
    $location = trim((string) ($input['location'] ?? ''));
    $status = strtolower(trim((string) ($input['adoption_status'] ?? 'available')));
    $story = trim((string) ($input['story'] ?? ''));
    $age = $input['estimated_age_months'] ?? null;
    $intakeDateValue = $input['intake_date'] ?? null;

    if ($name === '' || strlen($name) > 100 || !in_array($species, ['dog', 'cat', 'other'], true) || !in_array($sex, ['male', 'female', 'unknown'], true) || !in_array($status, ['available', 'pending', 'adopted', 'inactive'], true)) {
        throw new InvalidArgumentException('Enter a name and valid species, sex, and adoption status.');
    }

    if (strlen($breed) > 150 || strlen($location) > 150 || strlen($story) > 65535) {
        throw new InvalidArgumentException('One or more pet listing fields are too long.');
    }

    if ($age === '' || $age === null) {
        $age = null;
    } elseif (filter_var($age, FILTER_VALIDATE_INT) === false || (int) $age < 0 || (int) $age > 65535) {
        throw new InvalidArgumentException('Estimated age must be a valid number of months.');
    } else {
        $age = (int) $age;
    }

    $intakeDate = null;
    if ($intakeDateValue !== null && $intakeDateValue !== '') {
        if (!is_string($intakeDateValue)) {
            throw new InvalidArgumentException('Intake date must be a valid date.');
        }
        $intakeDate = DateTimeImmutable::createFromFormat('!Y-m-d', $intakeDateValue);
        $dateErrors = DateTimeImmutable::getLastErrors();
        if (
            $intakeDate === false
            || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
            || $intakeDate->format('Y-m-d') !== $intakeDateValue
        ) {
            throw new InvalidArgumentException('Intake date must be a valid date in YYYY-MM-DD format.');
        }
        $intakeDate = $intakeDate->format('Y-m-d');
    }

    return [
        'name' => $name,
        'species' => $species,
        'breed' => $breed === '' ? null : $breed,
        'sex' => $sex,
        'estimated_age_months' => $age,
        'location' => $location === '' ? null : $location,
        'intake_date' => $intakeDate,
        'adoption_status' => $status,
        'story' => $story === '' ? null : $story,
    ];
}

function validAdminPetId(): int
{
    $raw = $_GET['id'] ?? null;
    if (!is_string($raw) || !ctype_digit($raw) || (int) $raw < 1) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'A valid pet listing ID is required.']);
        exit;
    }

    return (int) $raw;
}

function insertPrimaryPetImage(PDO $pdo, int $petId, int $administratorId, array $image): void
{
    $countStatement = $pdo->prepare('SELECT COUNT(*) FROM pet_images WHERE pet_id = :pet_id');
    $countStatement->execute(['pet_id' => $petId]);
    if ((int) $countStatement->fetchColumn() >= 5) {
        throw new ImageUploadException('A pet can have a maximum of 5 images.');
    }

    $orderStatement = $pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM pet_images WHERE pet_id = :pet_id');
    $orderStatement->execute(['pet_id' => $petId]);
    $sortOrder = (int) $orderStatement->fetchColumn();

    $clearPrimary = $pdo->prepare('UPDATE pet_images SET is_primary = 0 WHERE pet_id = :pet_id AND is_primary = 1');
    $clearPrimary->execute(['pet_id' => $petId]);

    $statement = $pdo->prepare(
        'INSERT INTO pet_images
            (pet_id, file_path, original_filename, mime_type, file_size_bytes, is_primary, sort_order, uploaded_by_user_id)
         VALUES
            (:pet_id, :file_path, :original_filename, :mime_type, :file_size_bytes, 1, :sort_order, :uploaded_by_user_id)'
    );
    $statement->execute([
        'pet_id' => $petId,
        'file_path' => $image['file_path'],
        'original_filename' => $image['original_filename'],
        'mime_type' => $image['mime_type'],
        'file_size_bytes' => $image['file_size_bytes'],
        'sort_order' => $sortOrder,
        'uploaded_by_user_id' => $administratorId,
    ]);
}

$storedImage = null;
try {
    $pdo = getDatabaseConnection();
    $administratorId = requireActiveAdministrator($pdo);
    $method = $_SERVER['REQUEST_METHOD'];

    if (!in_array($method, ['GET', 'POST', 'PATCH', 'DELETE'], true)) {
        http_response_code(405);
        header('Allow: GET, POST, PATCH, DELETE');
        echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
        exit;
    }

    if ($method !== 'GET') {
        requireValidCsrfToken();
    }

    if ($method === 'GET') {
        $statement = $pdo->query(
            'SELECT p.id, p.name, p.species, p.breed, p.sex, p.birth_date, p.estimated_age_months, p.location, p.intake_date, p.adoption_status, p.story, p.deactivated_at,
             (SELECT pi.file_path FROM pet_images pi WHERE pi.pet_id = p.id ORDER BY pi.is_primary DESC, pi.sort_order ASC, pi.id ASC LIMIT 1) AS image_path,
             (SELECT COUNT(*) FROM pet_images pi WHERE pi.pet_id = p.id) AS image_count
             FROM pets p ORDER BY p.created_at DESC, p.id DESC'
        );
        echo json_encode(['success' => true, 'pets' => $statement->fetchAll()]);
        exit;
    }

    if ($method === 'DELETE') {
        $id = validAdminPetId();
        $pdo->beginTransaction();
        $previous = $pdo->prepare('SELECT adoption_status FROM pets WHERE id = :id LIMIT 1 FOR UPDATE');
        $previous->execute(['id' => $id]);
        $oldStatus = $previous->fetchColumn();
        if ($oldStatus === false) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Pet listing not found.']);
            exit;
        }
        $statement = $pdo->prepare('UPDATE pets SET adoption_status = :status, deactivated_at = NOW() WHERE id = :id');
        $statement->execute(['status' => 'inactive', 'id' => $id]);
        if ($oldStatus !== 'inactive') {
            adminAuditWrite($pdo, 'pet_deactivated', $id,
                ['status' => (string) $oldStatus], ['status' => 'inactive']);
        }
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Pet listing deactivated successfully.']);
        exit;
    }

    $effectiveMethod = $method;
    if ($method === 'POST' && isset($_POST['_method'])) {
        if ($_POST['_method'] !== 'PATCH') {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Unsupported method override.']);
            exit;
        }
        $effectiveMethod = 'PATCH';
    }

    $input = imageRequestPayload();

    if ($effectiveMethod === 'POST') {
        $pet = validatePetPayload($input);
        $pdo->beginTransaction();
        $statement = $pdo->prepare(
            'INSERT INTO pets (name, species, breed, sex, estimated_age_months, location, intake_date, adoption_status, story, created_by_user_id)
             VALUES (:name, :species, :breed, :sex, :estimated_age_months, :location, :intake_date, :adoption_status, :story, :created_by_user_id)'
        );
        $statement->execute($pet + ['created_by_user_id' => $administratorId]);
        $petId = (int) $pdo->lastInsertId();
        if (imageUploadWasProvided()) {
            $storedImage = storeUploadedImage('pets');
            insertPrimaryPetImage($pdo, $petId, $administratorId, $storedImage);
        }
        adminAuditWrite($pdo, 'pet_created', $petId, null, ['status' => $pet['adoption_status']]);
        $pdo->commit();
        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'Pet listing created successfully.',
            'id' => $petId,
            'image_path' => $storedImage['file_path'] ?? null,
        ]);
        exit;
    }

    $id = validAdminPetId();

    if ($effectiveMethod === 'PATCH') {
        $pet = validatePetPayload($input);
        $pdo->beginTransaction();
        $previous = $pdo->prepare('SELECT adoption_status FROM pets WHERE id = :id LIMIT 1 FOR UPDATE');
        $previous->execute(['id' => $id]);
        $oldStatus = $previous->fetchColumn();
        if ($oldStatus === false) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Pet listing not found.']);
            exit;
        }
        if (in_array($pet['adoption_status'], ['available', 'pending'], true)) {
            $approved = $pdo->prepare(
                'SELECT id FROM adoption_applications
                 WHERE pet_id = :pet_id AND application_status = :approved_status
                 LIMIT 1'
            );
            $approved->execute(['pet_id' => $id, 'approved_status' => 'approved']);
            if ($approved->fetch()) {
                $pdo->rollBack();
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => 'A pet with an approved Adoption Application cannot be made available or pending.']);
                exit;
            }
        }
        $statement = $pdo->prepare(
            'UPDATE pets SET name = :name, species = :species, breed = :breed, sex = :sex, estimated_age_months = :estimated_age_months,
             location = :location, intake_date = :intake_date, adoption_status = :adoption_status, story = :story,
             deactivated_at = CASE WHEN adoption_status = :inactive_value THEN COALESCE(deactivated_at, NOW()) ELSE NULL END
             WHERE id = :id'
        );
        $statement->execute($pet + ['inactive_value' => 'inactive', 'id' => $id]);
        $petChanged = $statement->rowCount() > 0;
        if (imageUploadWasProvided()) {
            $storedImage = storeUploadedImage('pets');
            insertPrimaryPetImage($pdo, $id, $administratorId, $storedImage);
        }
        if ($oldStatus !== $pet['adoption_status']) {
            $action = $pet['adoption_status'] === 'inactive' ? 'pet_deactivated'
                : ($oldStatus === 'inactive' ? 'pet_reactivated' : 'pet_status_updated');
            adminAuditWrite($pdo, $action, $id,
                ['status' => (string) $oldStatus], ['status' => $pet['adoption_status']]);
        } elseif ($petChanged || $storedImage !== null) {
            adminAuditWrite($pdo, 'pet_updated', $id);
        }
        $pdo->commit();
        echo json_encode([
            'success' => true,
            'message' => 'Pet listing updated successfully.',
            'image_path' => $storedImage['file_path'] ?? null,
        ]);
        exit;
    }

    http_response_code(405);
    header('Allow: GET, POST, PATCH, DELETE');
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
} catch (ImageUploadException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    removeStoredImage($storedImage);
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $exception->getMessage()]);
} catch (InvalidArgumentException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    removeStoredImage($storedImage);
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $exception->getMessage()]);
} catch (PDOException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    removeStoredImage($storedImage);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to manage pet listings. Please try again later.']);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    removeStoredImage($storedImage);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to manage pet listings. Please try again later.']);
}
