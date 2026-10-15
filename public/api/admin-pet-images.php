<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/admin-audit-service.php';
require_once __DIR__ . '/image-upload.php';

const MAX_PET_IMAGES = 5;

function requirePetImageAdministrator(PDO $pdo): int
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

function petImagePositiveId(string $name): int
{
    $value = $_GET[$name] ?? null;
    if (!is_string($value) || !ctype_digit($value) || (int) $value < 1) {
        throw new InvalidArgumentException('A valid ' . str_replace('_', ' ', $name) . ' is required.');
    }
    return (int) $value;
}

function lockManagedPet(PDO $pdo, int $petId): void
{
    $statement = $pdo->prepare('SELECT id FROM pets WHERE id = :id LIMIT 1 FOR UPDATE');
    $statement->execute(['id' => $petId]);
    if (!$statement->fetch()) {
        throw new OutOfBoundsException('Pet listing not found.');
    }
}

function petImageRows(PDO $pdo, int $petId): array
{
    $statement = $pdo->prepare(
        'SELECT id, pet_id, file_path, original_filename, mime_type, file_size_bytes,
                is_primary, sort_order, created_at
         FROM pet_images
         WHERE pet_id = :pet_id
         ORDER BY is_primary DESC, sort_order ASC, id ASC'
    );
    $statement->execute(['pet_id' => $petId]);
    return array_map(
        static fn (array $image): array => [
            'id' => (int) $image['id'],
            'pet_id' => (int) $image['pet_id'],
            'file_path' => $image['file_path'],
            'original_filename' => $image['original_filename'],
            'mime_type' => $image['mime_type'],
            'file_size_bytes' => (int) $image['file_size_bytes'],
            'is_primary' => (bool) $image['is_primary'],
            'sort_order' => (int) $image['sort_order'],
            'created_at' => $image['created_at'],
        ],
        $statement->fetchAll()
    );
}

$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'POST', 'PATCH', 'DELETE'], true)) {
    http_response_code(405);
    header('Allow: GET, POST, PATCH, DELETE');
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$storedImages = [];
try {
    $pdo = getDatabaseConnection();
    $administratorId = requirePetImageAdministrator($pdo);
    if ($method !== 'GET') {
        requireValidCsrfToken();
    }
    $allowedQuery = $method === 'GET' || $method === 'POST' ? ['pet_id'] : ['pet_id', 'id'];
    if (array_diff(array_keys($_GET), $allowedQuery) !== []) {
        throw new InvalidArgumentException('Unsupported pet image query parameter.');
    }
    $petId = petImagePositiveId('pet_id');

    if ($method === 'GET') {
        $statement = $pdo->prepare('SELECT id FROM pets WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $petId]);
        if (!$statement->fetch()) {
            throw new OutOfBoundsException('Pet listing not found.');
        }
        $images = petImageRows($pdo, $petId);
        echo json_encode(['success' => true, 'images' => $images, 'count' => count($images), 'maximum' => MAX_PET_IMAGES]);
        exit;
    }

    if ($method === 'POST') {
        $pdo->beginTransaction();
        lockManagedPet($pdo, $petId);
        $countStatement = $pdo->prepare('SELECT COUNT(*) FROM pet_images WHERE pet_id = :pet_id');
        $countStatement->execute(['pet_id' => $petId]);
        $count = (int) $countStatement->fetchColumn();
        if ($count >= MAX_PET_IMAGES) {
            throw new InvalidArgumentException('A pet can have a maximum of 5 images.');
        }
        $storedImages = storeRequestedImages('pets', MAX_PET_IMAGES - $count);
        if ($storedImages === []) {
            throw new ImageUploadException('Select at least one image to upload.');
        }
        $orderStatement = $pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM pet_images WHERE pet_id = :pet_id');
        $orderStatement->execute(['pet_id' => $petId]);
        $nextSortOrder = (int) $orderStatement->fetchColumn();
        $statement = $pdo->prepare(
            'INSERT INTO pet_images
                (pet_id, file_path, original_filename, mime_type, file_size_bytes, is_primary, sort_order, uploaded_by_user_id)
             VALUES
                (:pet_id, :file_path, :original_filename, :mime_type, :file_size_bytes, :is_primary, :sort_order, :uploaded_by_user_id)'
        );
        $imageIds = [];
        foreach ($storedImages as $index => $storedImage) {
            $statement->execute([
                'pet_id' => $petId,
                'file_path' => $storedImage['file_path'],
                'original_filename' => $storedImage['original_filename'],
                'mime_type' => $storedImage['mime_type'],
                'file_size_bytes' => $storedImage['file_size_bytes'],
                'is_primary' => $count === 0 && $index === 0 ? 1 : 0,
                'sort_order' => $nextSortOrder + $index,
                'uploaded_by_user_id' => $administratorId,
            ]);
            $imageIds[] = (int) $pdo->lastInsertId();
        }
        adminAuditWrite($pdo, 'pet_image_added', $petId, null, ['count' => count($imageIds)]);
        $pdo->commit();
        $storedImages = [];
        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => count($imageIds) === 1 ? 'Pet image added successfully.' : 'Pet images added successfully.',
            'ids' => $imageIds,
            'images' => petImageRows($pdo, $petId),
        ]);
        exit;
    }

    $imageId = petImagePositiveId('id');
    $pdo->beginTransaction();
    lockManagedPet($pdo, $petId);
    $statement = $pdo->prepare(
        'SELECT id, file_path, is_primary FROM pet_images
         WHERE id = :id AND pet_id = :pet_id
         LIMIT 1 FOR UPDATE'
    );
    $statement->execute(['id' => $imageId, 'pet_id' => $petId]);
    $image = $statement->fetch();
    if (!$image) {
        throw new OutOfBoundsException('Pet image not found.');
    }

    if ($method === 'PATCH') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input) || array_keys($input) !== ['action'] || $input['action'] !== 'set_primary') {
            throw new InvalidArgumentException('Unsupported pet image action.');
        }
        $statement = $pdo->prepare('UPDATE pet_images SET is_primary = 0 WHERE pet_id = :pet_id');
        $statement->execute(['pet_id' => $petId]);
        $statement = $pdo->prepare('UPDATE pet_images SET is_primary = 1 WHERE id = :id AND pet_id = :pet_id');
        $statement->execute(['id' => $imageId, 'pet_id' => $petId]);
        if (!(bool) $image['is_primary']) {
            adminAuditWrite($pdo, 'pet_primary_image_changed', $petId, null, ['image_id' => $imageId]);
        }
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Primary pet image updated.', 'images' => petImageRows($pdo, $petId)]);
        exit;
    }

    $wasPrimary = (bool) $image['is_primary'];
    $filePath = $image['file_path'];
    $statement = $pdo->prepare('DELETE FROM pet_images WHERE id = :id AND pet_id = :pet_id');
    $statement->execute(['id' => $imageId, 'pet_id' => $petId]);
    if ($wasPrimary) {
        $statement = $pdo->prepare(
            'UPDATE pet_images SET is_primary = 1
             WHERE id = (
                SELECT id FROM (
                    SELECT id FROM pet_images WHERE pet_id = :pet_id ORDER BY sort_order ASC, id ASC LIMIT 1
                ) remaining
             )'
        );
        $statement->execute(['pet_id' => $petId]);
    }
    adminAuditWrite($pdo, 'pet_image_removed', $petId, ['image_id' => $imageId]);
    $removedFromDisk = removeStoredImagePath(is_string($filePath) ? $filePath : null);
    if (!$removedFromDisk) {
        throw new RuntimeException('The stored pet image could not be removed.');
    }
    $pdo->commit();
    echo json_encode([
        'success' => true,
        'message' => 'Pet image removed successfully.',
        'file_removed' => $removedFromDisk,
        'images' => petImageRows($pdo, $petId),
    ]);
} catch (OutOfBoundsException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    removeStoredImages($storedImages);
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => $exception->getMessage()]);
} catch (ImageUploadException | InvalidArgumentException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    removeStoredImages($storedImages);
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $exception->getMessage()]);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    removeStoredImages($storedImages);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to manage pet images. Please try again later.']);
}
