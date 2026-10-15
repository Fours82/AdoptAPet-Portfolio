<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/admin-audit-service.php';

const MEDICAL_RECORD_TYPES = ['vaccination', 'treatment', 'sterilization', 'checkup', 'other'];

function requireMedicalAdministrator(PDO $pdo): int
{
    $userId = authenticatedUserId();
    if ($userId === null) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Authentication is required.']);
        exit;
    }

    $statement = $pdo->prepare(
        'SELECT role FROM users
         WHERE id = :id AND account_status = :status AND deleted_at IS NULL
         LIMIT 1'
    );
    $statement->execute(['id' => $userId, 'status' => 'active']);
    $user = $statement->fetch();

    if (!isOperationalAdministratorRole($user['role'] ?? null)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Administrator access is required.']);
        exit;
    }

    return $userId;
}

function medicalQueryId(string $key): int
{
    $value = $_GET[$key] ?? null;
    if (!is_string($value) || !ctype_digit($value) || (int) $value < 1) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'A valid ' . str_replace('_', ' ', $key) . ' is required.']);
        exit;
    }

    return (int) $value;
}

function medicalTextLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function validMedicalDate(mixed $value, string $label, bool $required): ?string
{
    if ($value === null || $value === '') {
        if ($required) {
            throw new InvalidArgumentException($label . ' is required.');
        }
        return null;
    }
    if (!is_string($value)) {
        throw new InvalidArgumentException($label . ' must be a valid date.');
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (
        $date === false
        || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $date->format('Y-m-d') !== $value
    ) {
        throw new InvalidArgumentException($label . ' must be a valid date in YYYY-MM-DD format.');
    }

    return $date->format('Y-m-d');
}

function readMedicalPayload(): array
{
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON request body.']);
        exit;
    }

    return $input;
}

function validateMedicalPayload(array $input): array
{
    $allowed = ['record_type', 'title', 'details', 'record_date', 'next_due_date'];
    if (array_diff(array_keys($input), $allowed) !== []) {
        throw new InvalidArgumentException('Only supported medical-record fields may be changed.');
    }

    foreach ($allowed as $field) {
        if (array_key_exists($field, $input) && $input[$field] !== null && !is_string($input[$field])) {
            throw new InvalidArgumentException('One or more medical-record fields contain an invalid value.');
        }
    }

    $recordType = strtolower(trim((string) ($input['record_type'] ?? '')));
    $title = trim((string) ($input['title'] ?? ''));
    $details = trim((string) ($input['details'] ?? ''));
    if (!in_array($recordType, MEDICAL_RECORD_TYPES, true)) {
        throw new InvalidArgumentException('Select a valid medical record type.');
    }
    if ($title === '' || medicalTextLength($title) > 200) {
        throw new InvalidArgumentException('Medical record title is required and must be 200 characters or fewer.');
    }
    if (medicalTextLength($details) > 65535) {
        throw new InvalidArgumentException('Medical record details are too long.');
    }

    $recordDate = validMedicalDate($input['record_date'] ?? null, 'Record date', true);
    $nextDueDate = validMedicalDate($input['next_due_date'] ?? null, 'Next due date', false);
    if ($nextDueDate !== null && $nextDueDate < $recordDate) {
        throw new InvalidArgumentException('Next due date cannot be earlier than the record date.');
    }

    return [
        'record_type' => $recordType,
        'title' => $title,
        'details' => $details === '' ? null : $details,
        'record_date' => $recordDate,
        'next_due_date' => $nextDueDate,
    ];
}

function requireMedicalPet(PDO $pdo, int $petId): array
{
    $statement = $pdo->prepare('SELECT id, name FROM pets WHERE id = :id LIMIT 1');
    $statement->execute(['id' => $petId]);
    $pet = $statement->fetch();
    if (!$pet) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Pet listing not found.']);
        exit;
    }

    return ['id' => (int) $pet['id'], 'name' => $pet['name']];
}

function requireOwnedMedicalRecord(PDO $pdo, int $petId, int $recordId, bool $forUpdate = false): array
{
    $statement = $pdo->prepare(
        'SELECT id, pet_id, record_type, title, details, record_date, next_due_date, recorded_by_user_id, created_at
         FROM medical_records
         WHERE id = :id AND pet_id = :pet_id
         LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '')
    );
    $statement->execute(['id' => $recordId, 'pet_id' => $petId]);
    $record = $statement->fetch();
    if (!$record) {
        if ($forUpdate && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Medical record was not found for this pet.']);
        exit;
    }

    $record['id'] = (int) $record['id'];
    $record['pet_id'] = (int) $record['pet_id'];
    return $record;
}

$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'POST', 'PATCH', 'DELETE'], true)) {
    http_response_code(405);
    header('Allow: GET, POST, PATCH, DELETE');
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    $administratorId = requireMedicalAdministrator($pdo);

    if ($method !== 'GET') {
        requireValidCsrfToken();
    }

    $allowedQuery = $method === 'GET' || $method === 'POST' ? ['pet_id'] : ['pet_id', 'id'];
    if (array_diff(array_keys($_GET), $allowedQuery) !== []) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Unsupported medical-record query parameter.']);
        exit;
    }

    $petId = medicalQueryId('pet_id');
    $pet = requireMedicalPet($pdo, $petId);

    if ($method === 'GET') {
        $statement = $pdo->prepare(
            'SELECT id, pet_id, record_type, title, details, record_date, next_due_date, created_at
             FROM medical_records
             WHERE pet_id = :pet_id
             ORDER BY record_date DESC, id DESC'
        );
        $statement->execute(['pet_id' => $petId]);
        $records = array_map(
            static function (array $record): array {
                $record['id'] = (int) $record['id'];
                $record['pet_id'] = (int) $record['pet_id'];
                return $record;
            },
            $statement->fetchAll()
        );
        echo json_encode(['success' => true, 'pet' => $pet, 'medical_records' => $records]);
        exit;
    }

    if ($method === 'POST') {
        $record = validateMedicalPayload(readMedicalPayload());
        $pdo->beginTransaction();
        $statement = $pdo->prepare(
            'INSERT INTO medical_records
             (pet_id, record_type, title, details, record_date, next_due_date, recorded_by_user_id)
             VALUES (:pet_id, :record_type, :title, :details, :record_date, :next_due_date, :recorded_by_user_id)'
        );
        $statement->execute($record + ['pet_id' => $petId, 'recorded_by_user_id' => $administratorId]);
        $recordId = (int) $pdo->lastInsertId();
        adminAuditWrite($pdo, 'medical_record_created', $recordId, null,
            ['pet_id' => $petId, 'record_type' => $record['record_type']]);
        $pdo->commit();
        http_response_code(201);
        echo json_encode(['success' => true, 'message' => 'Medical record created successfully.', 'id' => $recordId]);
        exit;
    }

    $recordId = medicalQueryId('id');
    if ($method === 'PATCH') {
        $record = validateMedicalPayload(readMedicalPayload());
        $pdo->beginTransaction();
        $oldRecord = requireOwnedMedicalRecord($pdo, $petId, $recordId, true);
        $statement = $pdo->prepare(
            'UPDATE medical_records
             SET record_type = :record_type, title = :title, details = :details,
                 record_date = :record_date, next_due_date = :next_due_date
             WHERE id = :id AND pet_id = :pet_id'
        );
        $statement->execute($record + ['id' => $recordId, 'pet_id' => $petId]);
        $changed = false;
        foreach ($record as $field => $value) {
            if ($oldRecord[$field] !== $value) {
                $changed = true;
                break;
            }
        }
        if ($changed) {
            adminAuditWrite($pdo, 'medical_record_updated', $recordId,
                ['pet_id' => $petId, 'record_type' => $oldRecord['record_type']],
                ['pet_id' => $petId, 'record_type' => $record['record_type']]);
        }
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Medical record updated successfully.']);
        exit;
    }

    $pdo->beginTransaction();
    $oldRecord = requireOwnedMedicalRecord($pdo, $petId, $recordId, true);
    $statement = $pdo->prepare('DELETE FROM medical_records WHERE id = :id AND pet_id = :pet_id');
    $statement->execute(['id' => $recordId, 'pet_id' => $petId]);
    adminAuditWrite($pdo, 'medical_record_deleted', $recordId,
        ['pet_id' => $petId, 'record_type' => $oldRecord['record_type']]);
    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Medical record removed successfully.']);
} catch (InvalidArgumentException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $exception->getMessage()]);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to manage medical records. Please try again later.']);
}
