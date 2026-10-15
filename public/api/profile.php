<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/image-upload.php';
require_once __DIR__ . '/usernames.php';

function requireActiveRegisteredProfileUser(PDO $pdo): int
{
    $userId = authenticatedUserId();
    if ($userId === null) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Authentication is required.']);
        exit;
    }

    $statement = $pdo->prepare(
        'SELECT id
         FROM users
         WHERE id = :id
           AND role = :role
           AND account_status = :status
           AND deleted_at IS NULL
         LIMIT 1'
    );
    $statement->execute([
        'id' => $userId,
        'role' => 'registered_user',
        'status' => 'active',
    ]);

    if (!$statement->fetch()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'An active registered-user account is required.']);
        exit;
    }

    return $userId;
}

function profileTextLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function removeReplacedProfileImage(?string $path): void
{
    if (!is_string($path) || !str_starts_with($path, 'uploads/profiles/')) {
        return;
    }

    removeStoredImage(['absolute_path' => dirname(__DIR__) . '/' . $path]);
}

function profileHistory(PDO $pdo, int $userId): array
{
    $applications = $pdo->prepare(
        'SELECT a.id, a.pet_id, p.name AS pet_name, p.species AS pet_species,
                p.breed AS pet_breed, a.application_status, a.submitted_at, a.reviewed_at,
                COALESCE(
                    (SELECT MAX(h.changed_at) FROM application_status_history h WHERE h.application_id = a.id),
                    a.reviewed_at,
                    a.submitted_at
                ) AS last_updated_at
         FROM adoption_applications a
         INNER JOIN pets p ON p.id = a.pet_id
         WHERE a.applicant_user_id = :user_id
         ORDER BY a.submitted_at DESC, a.id DESC'
    );
    $applications->execute(['user_id' => $userId]);
    $applicationRows = array_map(
        static fn (array $row): array => [
            'id' => (int) $row['id'],
            'pet_id' => (int) $row['pet_id'],
            'pet_name' => $row['pet_name'],
            'pet_species' => $row['pet_species'],
            'pet_breed' => $row['pet_breed'],
            'application_status' => $row['application_status'],
            'submitted_at' => $row['submitted_at'],
            'reviewed_at' => $row['reviewed_at'],
            'last_updated_at' => $row['last_updated_at'],
        ],
        $applications->fetchAll()
    );

    $donations = $pdo->prepare(
        'SELECT d.id, d.amount, d.currency, d.donation_status,
                COALESCE(d.donated_at, d.created_at) AS donation_date,
                (
                    SELECT pt.transaction_reference
                    FROM payment_transactions pt
                    WHERE pt.donation_id = d.id
                    ORDER BY pt.id DESC
                    LIMIT 1
                ) AS transaction_reference
         FROM donations d
         WHERE d.user_id = :user_id
         ORDER BY COALESCE(d.donated_at, d.created_at) DESC, d.id DESC'
    );
    $donations->execute(['user_id' => $userId]);
    $donationRows = array_map(
        static fn (array $row): array => [
            'id' => (int) $row['id'],
            'amount' => (float) $row['amount'],
            'currency' => $row['currency'],
            'donation_status' => $row['donation_status'],
            'transaction_reference' => $row['transaction_reference'],
            'donation_date' => $row['donation_date'],
        ],
        $donations->fetchAll()
    );

    $strayReports = $pdo->prepare(
        'SELECT id, animal_type, location_text, urgency, report_status,
                submitted_at, resolved_at, updated_at
         FROM stray_reports
         WHERE reporter_user_id = :user_id
         ORDER BY submitted_at DESC, id DESC'
    );
    $strayReports->execute(['user_id' => $userId]);
    $strayRows = array_map(
        static fn (array $row): array => [
            'id' => (int) $row['id'],
            'animal_type' => $row['animal_type'],
            'location_text' => $row['location_text'],
            'urgency' => $row['urgency'],
            'report_status' => $row['report_status'],
            'submitted_at' => $row['submitted_at'],
            'resolved_at' => $row['resolved_at'],
            'updated_at' => $row['updated_at'],
        ],
        $strayReports->fetchAll()
    );

    return [
        'applications' => $applicationRows,
        'donations' => $donationRows,
        'stray_reports' => $strayRows,
    ];
}

function profileResponse(PDO $pdo, int $userId): array
{
    $statement = $pdo->prepare(
        'SELECT u.id, u.username, u.name, u.email, u.account_status, u.created_at,
                p.phone, p.location, p.profile_image_path, p.preferences_json, p.updated_at,
                EXISTS (SELECT 1 FROM email_verifications ev WHERE ev.user_id = u.id AND ev.verified_at IS NOT NULL) AS email_verified,
                (SELECT COUNT(*) FROM adoption_applications a WHERE a.applicant_user_id = u.id) AS application_count,
                (SELECT COUNT(*) FROM donations d WHERE d.user_id = u.id AND d.donation_status = :donation_status) AS donation_count,
                (SELECT COUNT(*) FROM stray_reports sr WHERE sr.reporter_user_id = u.id) AS stray_report_count,
                (SELECT COUNT(*) FROM community_posts cp WHERE cp.author_user_id = u.id AND cp.post_status = :post_status) AS community_post_count
         FROM users u
         LEFT JOIN user_profiles p ON p.user_id = u.id
         WHERE u.id = :id
           AND u.role = :role
           AND u.account_status = :status
           AND u.deleted_at IS NULL
         LIMIT 1'
    );
    $statement->execute([
        'id' => $userId,
        'role' => 'registered_user',
        'status' => 'active',
        'donation_status' => 'completed',
        'post_status' => 'published',
    ]);
    $profile = $statement->fetch();

    if (!$profile) {
        throw new RuntimeException('Profile user is no longer available.');
    }

    $preferences = [];
    if (is_string($profile['preferences_json']) && $profile['preferences_json'] !== '') {
        $decoded = json_decode($profile['preferences_json'], true);
        $preferences = is_array($decoded) ? $decoded : [];
    }

    return [
        'id' => (int) $profile['id'],
        'username' => $profile['username'],
        'name' => $profile['name'],
        'email' => $profile['email'],
        'phone' => $profile['phone'],
        'location' => $profile['location'],
        'profile_image_path' => $profile['profile_image_path'],
        'preferred_pet_type' => is_string($preferences['preferred_pet_type'] ?? null) ? $preferences['preferred_pet_type'] : '',
        'adoption_preferences' => is_string($preferences['adoption_preferences'] ?? null) ? $preferences['adoption_preferences'] : '',
        'account_status' => $profile['account_status'],
        'email_verified' => (bool) $profile['email_verified'],
        'created_at' => $profile['created_at'],
        'activity' => [
            'application_count' => (int) $profile['application_count'],
            'donation_count' => (int) $profile['donation_count'],
            'stray_report_count' => (int) $profile['stray_report_count'],
            'community_post_count' => (int) $profile['community_post_count'],
        ],
        'history' => profileHistory($pdo, $userId),
        'updated_at' => $profile['updated_at'],
    ];
}

$storedImage = null;
try {
    $pdo = getDatabaseConnection();
    $userId = requireActiveRegisteredProfileUser($pdo);
    $method = $_SERVER['REQUEST_METHOD'];
    $effectiveMethod = $method;
    if ($method === 'POST' && ($_POST['_method'] ?? null) === 'PATCH') {
        $effectiveMethod = 'PATCH';
    }

    if ($method !== 'GET') {
        requireValidCsrfToken();
    }

    if ($_GET !== []) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Profile requests do not accept user IDs or other query parameters.']);
        exit;
    }

    if ($effectiveMethod === 'GET') {
        echo json_encode(['success' => true, 'profile' => profileResponse($pdo, $userId)]);
        exit;
    }

    if ($effectiveMethod !== 'PATCH') {
        http_response_code(405);
        header('Allow: GET, PATCH, POST');
        echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
        exit;
    }

    $input = imageRequestPayload();

    $allowedFields = ['username', 'name', 'phone', 'location', 'preferred_pet_type', 'adoption_preferences', 'remove_profile_image'];
    $unexpectedFields = array_diff(array_keys($input), $allowedFields);
    if ($unexpectedFields !== []) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Only editable profile and adoption-preference fields can be updated.']);
        exit;
    }

    $username = trim((string) ($input['username'] ?? ''));
    $name = trim((string) ($input['name'] ?? ''));
    $phone = trim((string) ($input['phone'] ?? ''));
    $location = trim((string) ($input['location'] ?? ''));
    $preferredPetType = strtolower(trim((string) ($input['preferred_pet_type'] ?? '')));
    $adoptionPreferences = trim((string) ($input['adoption_preferences'] ?? ''));
    $removeProfileImageValue = $input['remove_profile_image'] ?? false;
    if (!is_bool($removeProfileImageValue)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Invalid profile-image removal request.']);
        exit;
    }
    $removeProfileImage = $removeProfileImageValue;

    if (!isValidUsername($username)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => usernameValidationMessage()]);
        exit;
    }

    if (usernameIsTaken($pdo, $username, $userId)) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This username is already taken.']);
        exit;
    }

    if ($name === '' || profileTextLength($name) > 150 || preg_match('/[\x00-\x1F\x7F]/u', $name)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Enter a valid full name of up to 150 characters.']);
        exit;
    }

    if (profileTextLength($phone) > 30 || ($phone !== '' && !preg_match('/^[0-9+().\-\s]+$/', $phone))) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Enter a valid phone number of up to 30 characters.']);
        exit;
    }

    if (profileTextLength($location) > 150 || preg_match('/[\x00-\x1F\x7F]/u', $location)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Enter a valid location of up to 150 characters.']);
        exit;
    }

    if (!in_array($preferredPetType, ['', 'dog', 'cat', 'other', 'any'], true)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Select a valid preferred pet type.']);
        exit;
    }

    if (profileTextLength($adoptionPreferences) > 1000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $adoptionPreferences)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Adoption preferences must be 1,000 characters or fewer.']);
        exit;
    }

    if (imageUploadWasProvided()) {
        $storedImage = storeUploadedImage('profiles');
    }

    $pdo->beginTransaction();
    $liveUser = $pdo->prepare(
        'SELECT u.id, u.username, p.preferences_json, p.profile_image_path
         FROM users u
         LEFT JOIN user_profiles p ON p.user_id = u.id
         WHERE u.id = :id
           AND u.role = :role
           AND u.account_status = :status
           AND u.deleted_at IS NULL
         FOR UPDATE'
    );
    $liveUser->execute([
        'id' => $userId,
        'role' => 'registered_user',
        'status' => 'active',
    ]);

    $liveProfile = $liveUser->fetch();
    if (!$liveProfile) {
        $pdo->rollBack();
        removeStoredImage($storedImage);
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'An active registered-user account is required.']);
        exit;
    }

    $preferences = [];
    if (is_string($liveProfile['preferences_json']) && $liveProfile['preferences_json'] !== '') {
        $decodedPreferences = json_decode($liveProfile['preferences_json'], true);
        $preferences = is_array($decodedPreferences) ? $decodedPreferences : [];
    }
    $preferences['preferred_pet_type'] = $preferredPetType;
    $preferences['adoption_preferences'] = $adoptionPreferences;
    $preferencesJson = json_encode($preferences, JSON_THROW_ON_ERROR);
    $oldProfileImagePath = is_string($liveProfile['profile_image_path']) ? $liveProfile['profile_image_path'] : null;
    $profileImagePath = $storedImage['file_path'] ?? ($removeProfileImage ? null : $oldProfileImagePath);

    $updateUser = $pdo->prepare('UPDATE users SET username = :username, name = :name WHERE id = :id');
    $updateUser->execute(['username' => $username, 'name' => $name, 'id' => $userId]);

    $updateProfile = $pdo->prepare(
        'INSERT INTO user_profiles (user_id, phone, location, profile_image_path, preferences_json)
         VALUES (:user_id, :phone, :location, :profile_image_path, :preferences_json)
         ON DUPLICATE KEY UPDATE phone = VALUES(phone), location = VALUES(location),
            profile_image_path = VALUES(profile_image_path), preferences_json = VALUES(preferences_json)'
    );
    $updateProfile->execute([
        'user_id' => $userId,
        'phone' => $phone === '' ? null : $phone,
        'location' => $location === '' ? null : $location,
        'profile_image_path' => $profileImagePath,
        'preferences_json' => $preferencesJson,
    ]);
    $pdo->commit();

    if ($oldProfileImagePath !== $profileImagePath) {
        removeReplacedProfileImage($oldProfileImagePath);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Profile updated successfully.',
        'profile' => profileResponse($pdo, $userId),
    ]);
} catch (ImageUploadException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    removeStoredImage($storedImage);
    http_response_code($exception->getMessage() === 'Invalid JSON request body.' ? 400 : 422);
    echo json_encode(['success' => false, 'message' => $exception->getMessage()]);
} catch (RuntimeException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    removeStoredImage($storedImage);
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'An active registered-user account is required.']);
} catch (PDOException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    removeStoredImage($storedImage);
    if (isUsernameUniqueConstraintViolation($exception)) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This username is already taken.']);
        exit;
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to manage the profile. Please try again later.']);
}
