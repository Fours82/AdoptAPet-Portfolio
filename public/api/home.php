<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

if ($_GET !== []) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Unsupported homepage query parameter.']);
    exit;
}

try {
    $pdo = getDatabaseConnection();

    $pets = $pdo->prepare(
        'SELECT p.id, p.name, p.species, p.breed, p.sex, p.birth_date,
                p.estimated_age_months, p.location, p.adoption_status,
                (SELECT pi.file_path FROM pet_images pi WHERE pi.pet_id = p.id
                 ORDER BY pi.is_primary DESC, pi.sort_order ASC, pi.id ASC LIMIT 1) AS image_path
         FROM pets p
         WHERE p.adoption_status = :status
         ORDER BY p.created_at DESC, p.id DESC
         LIMIT 4'
    );
    $pets->execute(['status' => 'available']);

    $petCounts = $pdo->prepare(
        'SELECT
            SUM(CASE WHEN adoption_status = :available THEN 1 ELSE 0 END) AS available_count,
            SUM(CASE WHEN adoption_status = :adopted THEN 1 ELSE 0 END) AS adopted_count
         FROM pets'
    );
    $petCounts->execute(['available' => 'available', 'adopted' => 'adopted']);
    $counts = $petCounts->fetch();

    $donationSummary = $pdo->prepare(
        'SELECT COUNT(*) AS contribution_count, COALESCE(SUM(amount), 0) AS donation_total
         FROM donations WHERE donation_status = :status'
    );
    $donationSummary->execute(['status' => 'completed']);
    $donations = $donationSummary->fetch();

    $news = $pdo->prepare(
        'SELECT id, title, body, cover_image_path, published_at
         FROM news_posts
         WHERE publication_status = :status AND published_at IS NOT NULL AND published_at <= NOW()
         ORDER BY published_at DESC, id DESC LIMIT 2'
    );
    $news->execute(['status' => 'published']);

    $community = $pdo->prepare(
        'SELECT cp.id, cp.content, cp.created_at, u.username AS author_name
         FROM community_posts cp
         JOIN users u ON u.id = cp.author_user_id
         WHERE cp.post_status = :status
         ORDER BY cp.created_at DESC, cp.id DESC LIMIT 2'
    );
    $community->execute(['status' => 'published']);

    echo json_encode([
        'success' => true,
        'featured_pets' => $pets->fetchAll(),
        'statistics' => [
            'available_pets' => (int) ($counts['available_count'] ?? 0),
            'adopted_pets' => (int) ($counts['adopted_count'] ?? 0),
            'donation_total' => (float) ($donations['donation_total'] ?? 0),
            'contribution_count' => (int) ($donations['contribution_count'] ?? 0),
        ],
        'news' => $news->fetchAll(),
        'community_posts' => $community->fetchAll(),
    ]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to load the homepage. Please try again later.']);
}
