<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/image-upload.php';
require_once __DIR__ . '/notification-service.php';

const STRAY_ANIMAL_TYPES = ['dog', 'cat', 'other'];
const STRAY_URGENCIES = ['low', 'medium', 'high', 'critical'];
const STRAY_STATUSES = ['submitted', 'under_review', 'assigned', 'resolved', 'closed'];
const STRAY_SIZES = ['small', 'medium', 'large', 'unknown'];
const STRAY_SEXES = ['male', 'female', 'unknown'];
const STRAY_CONDITIONS = ['healthy', 'injured', 'sick', 'trapped', 'aggressive', 'mother_with_litter', 'other'];
const MAX_STRAY_REPORT_IMAGES = 3;

function strayTextLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function strayJsonBody(): array
{
    return imageRequestPayload();
}

function requireVerifiedStrayReporter(PDO $pdo): int
{
    $userId = authenticatedUserId();
    if ($userId === null) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Authentication is required to submit a Stray Report.']);
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
               SELECT 1 FROM email_verifications ev
               WHERE ev.user_id = u.id AND ev.verified_at IS NOT NULL
           )
         LIMIT 1'
    );
    $statement->execute([
        'id' => $userId,
        'role' => 'registered_user',
        'status' => 'active',
    ]);

    if (!$statement->fetch()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'An active, verified registered-user account is required.']);
        exit;
    }

    return $userId;
}

function publicStraySelect(): string
{
    return 'SELECT sr.id, sr.reporter_user_id, sr.animal_type, sr.breed, sr.colour,
                   sr.size, sr.sex, sr.condition_category, sr.description, sr.condition_notes,
                   sr.admin_notes,
                   sr.location_text, sr.latitude, sr.longitude, sr.urgency,
                   sr.report_status, sr.submitted_at, sr.resolved_at, sr.updated_at,
                   (SELECT sri.file_path
                    FROM stray_report_images sri
                    WHERE sri.stray_report_id = sr.id
                    ORDER BY sri.sort_order ASC, sri.id ASC
                    LIMIT 1) AS image_path
            FROM stray_reports sr';
}

function formatPublicStrayReport(array $report, bool $includePrivate = false): array
{
    $report['id'] = (int) $report['id'];
    $report['latitude'] = $report['latitude'] === null ? null : (float) $report['latitude'];
    $report['longitude'] = $report['longitude'] === null ? null : (float) $report['longitude'];
    $report['reference'] = 'STR-' . str_pad((string) $report['id'], 6, '0', STR_PAD_LEFT);
    if (!$includePrivate) {
        unset($report['admin_notes']);
    }
    unset($report['reporter_user_id']);
    return $report;
}

function canViewPrivateStrayReport(PDO $pdo, array $report): bool
{
    $viewerId = authenticatedUserId(false);
    if ($viewerId === null) {
        return false;
    }
    if ($viewerId === (int) $report['reporter_user_id']) {
        return true;
    }
    $statement = $pdo->prepare(
        'SELECT role FROM users
         WHERE id = :id AND account_status = :status AND deleted_at IS NULL
         LIMIT 1'
    );
    $statement->execute(['id' => $viewerId, 'status' => 'active']);
    return isOperationalAdministratorRole($statement->fetchColumn());
}

function validStrayId(): ?int
{
    if (!array_key_exists('id', $_GET)) {
        return null;
    }

    $raw = $_GET['id'];
    if (!is_string($raw) || !ctype_digit($raw) || (int) $raw < 1) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'A valid Stray Report ID is required.']);
        exit;
    }

    return (int) $raw;
}

function strayQueryString(string $key): string
{
    if (!array_key_exists($key, $_GET)) {
        return '';
    }

    if (!is_string($_GET[$key])) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Invalid Stray Report filter value.']);
        exit;
    }

    return trim($_GET[$key]);
}

$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$storedImages = [];
try {
    $pdo = getDatabaseConnection();

    if ($method === 'GET') {
        $allowedParameters = ['id', 'q', 'animal_type', 'location', 'urgency', 'status'];
        foreach (array_keys($_GET) as $parameter) {
            if (!in_array($parameter, $allowedParameters, true)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Unsupported Stray Report query parameter.']);
                exit;
            }
        }

        $id = validStrayId();
        if ($id !== null) {
            if (count($_GET) !== 1) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Report detail cannot be combined with listing filters.']);
                exit;
            }

            $statement = $pdo->prepare(publicStraySelect() . ' WHERE sr.id = :id LIMIT 1');
            $statement->execute(['id' => $id]);
            $report = $statement->fetch();

            if (!$report) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Stray Report not found.']);
                exit;
            }

            $images = $pdo->prepare(
                'SELECT file_path, sort_order
                 FROM stray_report_images
                 WHERE stray_report_id = :id
                 ORDER BY sort_order ASC, id ASC'
            );
            $images->execute(['id' => $id]);

            $report = formatPublicStrayReport($report, canViewPrivateStrayReport($pdo, $report));
            $report['images'] = array_map(
                static fn (array $image): array => [
                    'file_path' => $image['file_path'],
                    'sort_order' => (int) $image['sort_order'],
                ],
                $images->fetchAll()
            );

            echo json_encode(['success' => true, 'report' => $report]);
            exit;
        }

        $filters = [];
        $parameters = [];

        $animalType = strtolower(strayQueryString('animal_type'));
        if ($animalType !== '') {
            if (!in_array($animalType, STRAY_ANIMAL_TYPES, true)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Invalid animal type filter.']);
                exit;
            }
            $filters[] = 'sr.animal_type = :animal_type';
            $parameters['animal_type'] = $animalType;
        }

        $urgency = strtolower(strayQueryString('urgency'));
        if ($urgency !== '') {
            if (!in_array($urgency, array_merge(STRAY_URGENCIES, ['normal', 'urgent']), true)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Invalid urgency filter.']);
                exit;
            }
            if ($urgency === 'normal') {
                $filters[] = "sr.urgency IN ('low', 'medium')";
            } elseif ($urgency === 'urgent') {
                $filters[] = "sr.urgency IN ('high', 'critical')";
            } else {
                $filters[] = 'sr.urgency = :urgency';
                $parameters['urgency'] = $urgency;
            }
        }

        $status = strtolower(strayQueryString('status'));
        if ($status !== '') {
            if (!in_array($status, STRAY_STATUSES, true)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Invalid report status filter.']);
                exit;
            }
            $filters[] = 'sr.report_status = :report_status';
            $parameters['report_status'] = $status;
        }

        $location = strayQueryString('location');
        if (strayTextLength($location) > 100) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Location search must be 100 characters or fewer.']);
            exit;
        }
        if ($location !== '') {
            $filters[] = 'sr.location_text LIKE :location';
            $parameters['location'] = '%' . $location . '%';
        }

        $query = strayQueryString('q');
        if (strayTextLength($query) > 100) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Search text must be 100 characters or fewer.']);
            exit;
        }
        if ($query !== '') {
            $filters[] = '(sr.description LIKE :q_description OR sr.condition_notes LIKE :q_condition
                           OR sr.location_text LIKE :q_location OR sr.breed LIKE :q_breed
                           OR sr.colour LIKE :q_colour OR sr.condition_category LIKE :q_category)';
            $parameters['q_description'] = '%' . $query . '%';
            $parameters['q_condition'] = '%' . $query . '%';
            $parameters['q_location'] = '%' . $query . '%';
            $parameters['q_breed'] = '%' . $query . '%';
            $parameters['q_colour'] = '%' . $query . '%';
            $parameters['q_category'] = '%' . $query . '%';
        }

        $sql = publicStraySelect();
        if ($filters !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $filters);
        }
        $sql .= " ORDER BY FIELD(sr.urgency, 'critical', 'high', 'medium', 'low'), sr.submitted_at DESC, sr.id DESC";

        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);
        $reports = array_map('formatPublicStrayReport', $statement->fetchAll());

        echo json_encode(['success' => true, 'reports' => $reports, 'count' => count($reports)]);
        exit;
    }

    $userId = requireVerifiedStrayReporter($pdo);
    requireValidCsrfToken();
    $body = strayJsonBody();
    $allowedFields = ['animal_type', 'breed', 'colour', 'size', 'sex', 'condition_category',
        'description', 'condition_notes', 'location_text', 'latitude', 'longitude', 'urgency'];
    foreach (array_keys($body) as $field) {
        if (!in_array($field, $allowedFields, true)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Unsupported Stray Report field: ' . $field . '.']);
            exit;
        }
    }

    foreach (['animal_type', 'breed', 'colour', 'size', 'sex', 'condition_category',
        'description', 'condition_notes', 'location_text', 'urgency'] as $field) {
        if (array_key_exists($field, $body) && !is_string($body[$field])) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Invalid value supplied for ' . $field . '.']);
            exit;
        }
    }

    $animalType = strtolower(trim((string) ($body['animal_type'] ?? '')));
    $breed = trim((string) ($body['breed'] ?? ''));
    $colour = trim((string) ($body['colour'] ?? ''));
    $size = strtolower(trim((string) ($body['size'] ?? '')));
    $sex = strtolower(trim((string) ($body['sex'] ?? 'unknown')));
    $conditionCategory = strtolower(trim((string) ($body['condition_category'] ?? '')));
    $description = trim((string) ($body['description'] ?? ''));
    $conditionNotes = trim((string) ($body['condition_notes'] ?? ''));
    $locationText = trim((string) ($body['location_text'] ?? ''));
    $urgency = strtolower(trim((string) ($body['urgency'] ?? 'medium')));

    if (!in_array($animalType, STRAY_ANIMAL_TYPES, true)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Select a valid animal type.']);
        exit;
    }
    if (strayTextLength($breed) > 100) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Breed must be 100 characters or fewer.']);
        exit;
    }
    if ($colour === '' || strayTextLength($colour) > 100) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Colour is required and must be 100 characters or fewer.']);
        exit;
    }
    if (!in_array($size, STRAY_SIZES, true)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Select a valid animal size.']);
        exit;
    }
    if (!in_array($sex, STRAY_SEXES, true)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Select a valid sex.']);
        exit;
    }
    if (!in_array($conditionCategory, STRAY_CONDITIONS, true)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Select a valid condition.']);
        exit;
    }
    if ($description === '' || strayTextLength($description) < 10 || strayTextLength($description) > 5000) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Description must be between 10 and 5,000 characters.']);
        exit;
    }
    if (strayTextLength($conditionNotes) > 3000) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Condition notes must be 3,000 characters or fewer.']);
        exit;
    }
    if ($locationText === '' || strayTextLength($locationText) > 255) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Location is required and must be 255 characters or fewer.']);
        exit;
    }
    if (!in_array($urgency, STRAY_URGENCIES, true)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Select a valid urgency level.']);
        exit;
    }

    $latitudeRaw = $body['latitude'] ?? null;
    $longitudeRaw = $body['longitude'] ?? null;
    $latitudeEmpty = $latitudeRaw === null || $latitudeRaw === '';
    $longitudeEmpty = $longitudeRaw === null || $longitudeRaw === '';

    if ($latitudeEmpty !== $longitudeEmpty) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Enter both latitude and longitude, or leave both blank.']);
        exit;
    }

    $latitude = null;
    $longitude = null;
    if (!$latitudeEmpty) {
        if (!is_numeric($latitudeRaw) || !is_numeric($longitudeRaw)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Latitude and longitude must be valid numbers.']);
            exit;
        }
        $latitude = (float) $latitudeRaw;
        $longitude = (float) $longitudeRaw;
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Latitude or longitude is outside the valid range.']);
            exit;
        }
    }

    $pdo->beginTransaction();
    $reporter = $pdo->prepare(
        'SELECT u.id FROM users u
         WHERE u.id = :id AND u.role = :role AND u.account_status = :status
           AND u.deleted_at IS NULL
           AND EXISTS (SELECT 1 FROM email_verifications ev
                       WHERE ev.user_id = u.id AND ev.verified_at IS NOT NULL)
         FOR UPDATE'
    );
    $reporter->execute(['id' => $userId, 'role' => 'registered_user', 'status' => 'active']);
    if (!$reporter->fetch()) {
        $pdo->rollBack();
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'An active, verified registered-user account is required.']);
        exit;
    }
    $statement = $pdo->prepare(
        'INSERT INTO stray_reports
            (reporter_user_id, animal_type, breed, colour, size, sex, condition_category,
             description, condition_notes, location_text,
             latitude, longitude, urgency, report_status)
         VALUES
            (:reporter_user_id, :animal_type, :breed, :colour, :size, :sex, :condition_category,
             :description, :condition_notes, :location_text,
             :latitude, :longitude, :urgency, :report_status)'
    );
    $statement->execute([
        'reporter_user_id' => $userId,
        'animal_type' => $animalType,
        'breed' => $breed === '' ? null : $breed,
        'colour' => $colour,
        'size' => $size,
        'sex' => $sex,
        'condition_category' => $conditionCategory,
        'description' => $description,
        'condition_notes' => $conditionNotes === '' ? null : $conditionNotes,
        'location_text' => $locationText,
        'latitude' => $latitude,
        'longitude' => $longitude,
        'urgency' => $urgency,
        'report_status' => 'submitted',
    ]);

    $reportId = (int) $pdo->lastInsertId();
    $storedImages = storeRequestedImages('strays', MAX_STRAY_REPORT_IMAGES);
    if ($storedImages !== []) {
        $imageStatement = $pdo->prepare(
            'INSERT INTO stray_report_images
                (stray_report_id, file_path, original_filename, mime_type, file_size_bytes, sort_order)
             VALUES
                (:stray_report_id, :file_path, :original_filename, :mime_type, :file_size_bytes, :sort_order)'
        );
        foreach ($storedImages as $sortOrder => $storedImage) {
            $imageStatement->execute([
                'stray_report_id' => $reportId,
                'file_path' => $storedImage['file_path'],
                'original_filename' => $storedImage['original_filename'],
                'mime_type' => $storedImage['mime_type'],
                'file_size_bytes' => $storedImage['file_size_bytes'],
                'sort_order' => $sortOrder,
            ]);
        }
    }
    $created = $pdo->prepare(publicStraySelect() . ' WHERE sr.id = :id LIMIT 1');
    $created->execute(['id' => $reportId]);
    $createdReport = $created->fetch();
    if (!$createdReport) {
        throw new RuntimeException('Submitted Stray Report could not be read.');
    }
    createNotificationRecord(
        $pdo,
        $userId,
        'stray_report_status',
        'Your Stray Report ' . 'STR-' . str_pad((string) $reportId, 6, '0', STR_PAD_LEFT) . ' was received.',
        '#/stray/' . $reportId
    );
    notifyOperationalAdministrators(
        $pdo,
        'admin_stray_report_submitted',
        'New Stray Report STR-' . str_pad((string) $reportId, 6, '0', STR_PAD_LEFT) . ' needs review.',
        '#/admin/strays'
    );
    $pdo->commit();
    $storedImages = [];

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Stray Report submitted successfully.',
        'report' => formatPublicStrayReport($createdReport),
    ]);
} catch (ImageUploadException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    removeStoredImages($storedImages);
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $exception->getMessage()]);
} catch (PDOException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    removeStoredImages($storedImages);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to process Stray Reports. Please try again later.']);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    removeStoredImages($storedImages);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to process Stray Reports. Please try again later.']);
}
