<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['success' => false, 'message' => 'Only GET requests are allowed.']);
    exit;
}

function petAgeLabel(?string $birthDate, ?int $estimatedAgeMonths): ?string
{
    if ($birthDate !== null) {
        $birth = new DateTimeImmutable($birthDate);
        $today = new DateTimeImmutable('today');
        $months = max(0, ((int) $today->format('Y') - (int) $birth->format('Y')) * 12 + (int) $today->format('n') - (int) $birth->format('n'));
        $estimatedAgeMonths = $months;
    }

    if ($estimatedAgeMonths === null) {
        return null;
    }

    if ($estimatedAgeMonths < 12) {
        return $estimatedAgeMonths . ' month' . ($estimatedAgeMonths === 1 ? '' : 's');
    }

    $years = intdiv($estimatedAgeMonths, 12);
    return $years . ' year' . ($years === 1 ? '' : 's');
}

function formatPet(array $pet): array
{
    $pet['id'] = (int) $pet['id'];
    $pet['estimated_age_months'] = $pet['estimated_age_months'] === null ? null : (int) $pet['estimated_age_months'];
    $pet['age_label'] = petAgeLabel($pet['birth_date'], $pet['estimated_age_months']);
    return $pet;
}

function petCatalogError(string $message): never
{
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

function petCatalogInteger(string $key, int $default, int $minimum, int $maximum): int
{
    if (!array_key_exists($key, $_GET)) {
        return $default;
    }

    $value = $_GET[$key];
    if (!is_string($value) || $value === '' || !ctype_digit($value)) {
        petCatalogError('The ' . str_replace('_', ' ', $key) . ' value must be a whole number.');
    }

    $integer = (int) $value;
    if ($integer < $minimum || $integer > $maximum) {
        petCatalogError('The ' . str_replace('_', ' ', $key) . ' value is outside the allowed range.');
    }

    return $integer;
}

function petCatalogString(string $key, string $default = ''): string
{
    if (!array_key_exists($key, $_GET)) {
        return $default;
    }
    if (!is_string($_GET[$key])) {
        petCatalogError('The ' . str_replace('_', ' ', $key) . ' filter must be text.');
    }

    return trim($_GET[$key]);
}

try {
    $pdo = getDatabaseConnection();
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

    if (isset($_GET['id']) && $_GET['id'] !== '' && ($id === false || $id === null)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'A valid pet listing ID is required.']);
        exit;
    }

    $select = 'SELECT p.id, p.name, p.species, p.breed, p.sex, p.birth_date, p.estimated_age_months, p.location, p.intake_date, p.adoption_status, p.story,
        (SELECT pi.file_path FROM pet_images pi WHERE pi.pet_id = p.id ORDER BY pi.is_primary DESC, pi.sort_order ASC, pi.id ASC LIMIT 1) AS image_path
        FROM pets p';

    if ($id !== false && $id !== null) {
        if (array_diff(array_keys($_GET), ['id']) !== []) {
            petCatalogError('Pet detail requests only accept a pet listing ID.');
        }

        $statement = $pdo->prepare($select . ' WHERE p.id = :id AND p.adoption_status <> :inactive_status LIMIT 1');
        $statement->execute(['id' => $id, 'inactive_status' => 'inactive']);
        $pet = $statement->fetch();

        if (!$pet) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Pet listing not found.']);
            exit;
        }

        $medical = $pdo->prepare('SELECT record_type, title, details, record_date, next_due_date FROM medical_records WHERE pet_id = :pet_id ORDER BY record_date DESC, id DESC');
        $medical->execute(['pet_id' => $id]);
        $images = $pdo->prepare(
            'SELECT file_path, original_filename, is_primary, sort_order
             FROM pet_images WHERE pet_id = :pet_id
             ORDER BY is_primary DESC, sort_order ASC, id ASC'
        );
        $images->execute(['pet_id' => $id]);
        $pet = formatPet($pet);
        $pet['medical_records'] = $medical->fetchAll();
        $pet['images'] = array_map(
            static fn (array $image): array => [
                'file_path' => $image['file_path'],
                'original_filename' => $image['original_filename'],
                'is_primary' => (bool) $image['is_primary'],
                'sort_order' => (int) $image['sort_order'],
            ],
            $images->fetchAll()
        );
        echo json_encode(['success' => true, 'pet' => $pet]);
        exit;
    }

    $supportedParameters = [
        'q', 'species', 'breed', 'sex', 'location', 'min_age_months', 'max_age_months',
        'status', 'page', 'limit',
    ];
    if (array_diff(array_keys($_GET), $supportedParameters) !== []) {
        petCatalogError('Unsupported Pet Listing filter.');
    }

    $page = petCatalogInteger('page', 1, 1, 1000000);
    $pageSize = petCatalogInteger('limit', 6, 1, 24);
    $filters = ['p.adoption_status <> :inactive_status'];
    $parameters = ['inactive_status' => 'inactive'];

    $status = strtolower(petCatalogString('status', 'available'));
    if ($status !== '') {
        if (!in_array($status, ['available', 'pending', 'adopted'], true)) {
            petCatalogError('Select a valid public adoption status.');
        }
        $filters[] = 'p.adoption_status = :adoption_status';
        $parameters['adoption_status'] = $status;
    }

    $allowed = [
        'species' => ['dog', 'cat', 'other'],
        'sex' => ['male', 'female', 'unknown'],
    ];

    foreach ($allowed as $field => $values) {
        $value = strtolower(petCatalogString($field));
        if ($value === '') {
            continue;
        }
        if (!in_array($value, $values, true)) {
            petCatalogError('Select a valid ' . $field . ' filter.');
        }
        $filters[] = 'p.' . $field . ' = :' . $field;
        $parameters[$field] = $value;
    }

    foreach (['breed', 'location'] as $field) {
        $value = petCatalogString($field);
        if ($value !== '') {
            if (strlen($value) > 150) {
                petCatalogError('The ' . $field . ' filter is too long.');
            }
            $filters[] = 'p.' . $field . ' LIKE :' . $field;
            $parameters[$field] = '%' . $value . '%';
        }
    }

    foreach (['min_age_months' => '>=', 'max_age_months' => '<='] as $field => $operator) {
        if (!isset($_GET[$field]) || $_GET[$field] === '') {
            continue;
        }

        $age = petCatalogInteger($field, 0, 0, 65535);
        $filters[] = 'COALESCE(p.estimated_age_months, TIMESTAMPDIFF(MONTH, p.birth_date, CURDATE())) ' . $operator . ' :' . $field;
        $parameters[$field] = $age;
    }

    if (isset($parameters['min_age_months'], $parameters['max_age_months'])
        && $parameters['min_age_months'] > $parameters['max_age_months']) {
        petCatalogError('Minimum age cannot be greater than maximum age.');
    }

    $query = petCatalogString('q');
    if (strlen($query) > 100) {
        petCatalogError('The keyword search is too long.');
    }
    if ($query !== '') {
        $filters[] = '(p.name LIKE :q_name OR p.breed LIKE :q_breed)';
        $parameters['q_name'] = '%' . $query . '%';
        $parameters['q_breed'] = '%' . $query . '%';
    }

    $where = ' WHERE ' . implode(' AND ', $filters);
    $countStatement = $pdo->prepare('SELECT COUNT(*) FROM pets p' . $where);
    $countStatement->execute($parameters);
    $totalRecords = (int) $countStatement->fetchColumn();
    $totalPages = $totalRecords === 0 ? 0 : (int) ceil($totalRecords / $pageSize);
    $offset = ($page - 1) * $pageSize;

    $statement = $pdo->prepare($select . $where . ' ORDER BY p.created_at DESC, p.id DESC LIMIT :page_size OFFSET :offset');
    foreach ($parameters as $key => $value) {
        $statement->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $statement->bindValue(':page_size', $pageSize, PDO::PARAM_INT);
    $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
    $statement->execute();
    $pets = array_map('formatPet', $statement->fetchAll());

    $breedOptions = $pdo->prepare(
        'SELECT DISTINCT breed FROM pets
         WHERE adoption_status <> :inactive_status AND breed IS NOT NULL AND breed <> \'\'
         ORDER BY breed'
    );
    $breedOptions->execute(['inactive_status' => 'inactive']);
    $locationOptions = $pdo->prepare(
        'SELECT DISTINCT location FROM pets
         WHERE adoption_status <> :inactive_status AND location IS NOT NULL AND location <> \'\'
         ORDER BY location'
    );
    $locationOptions->execute(['inactive_status' => 'inactive']);

    echo json_encode([
        'success' => true,
        'pets' => $pets,
        'pagination' => [
            'current_page' => $page,
            'total_records' => $totalRecords,
            'total_pages' => $totalPages,
            'page_size' => $pageSize,
            'has_previous' => $page > 1 && $totalRecords > 0,
            'has_next' => $totalPages > 0 && $page < $totalPages,
        ],
        'filter_options' => [
            'breeds' => array_column($breedOptions->fetchAll(), 'breed'),
            'locations' => array_column($locationOptions->fetchAll(), 'location'),
        ],
    ]);
} catch (PDOException $exception) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to load pet listings. Please try again later.']);
}
