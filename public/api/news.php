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

if (array_diff(array_keys($_GET), ['id']) !== []) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Unsupported News query parameter.']);
    exit;
}

$newsId = null;
if (array_key_exists('id', $_GET)) {
    if (!is_string($_GET['id']) || !ctype_digit($_GET['id']) || (int) $_GET['id'] < 1) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'A valid News announcement ID is required.']);
        exit;
    }
    $newsId = (int) $_GET['id'];
}

try {
    $pdo = getDatabaseConnection();
    $select =
        'SELECT np.id, np.title, np.body, np.cover_image_path, np.published_at, np.created_at, np.updated_at,
                u.name AS author_name
         FROM news_posts np
         JOIN users u ON u.id = np.author_user_id
         WHERE np.publication_status = :publication_status
           AND np.published_at IS NOT NULL
           AND np.published_at <= NOW()';
    $parameters = ['publication_status' => 'published'];
    if ($newsId !== null) {
        $select .= ' AND np.id = :id LIMIT 1';
        $parameters['id'] = $newsId;
    } else {
        $select .= ' ORDER BY np.published_at DESC, np.id DESC';
    }
    $statement = $pdo->prepare($select);
    $statement->execute($parameters);

    if ($newsId !== null) {
        $post = $statement->fetch();
        if (!$post) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'News announcement not found.']);
            exit;
        }
        echo json_encode([
            'success' => true,
            'news_item' => [
                'id' => (int) $post['id'],
                'title' => $post['title'],
                'body' => $post['body'],
                'cover_image_path' => $post['cover_image_path'],
                'author_name' => $post['author_name'],
                'published_at' => $post['published_at'],
                'created_at' => $post['created_at'],
                'updated_at' => $post['updated_at'],
            ],
        ]);
        exit;
    }

    $news = array_map(
        static fn (array $post): array => [
            'id' => (int) $post['id'],
            'title' => $post['title'],
            'body' => $post['body'],
            'cover_image_path' => $post['cover_image_path'],
            'author_name' => $post['author_name'],
            'published_at' => $post['published_at'],
            'created_at' => $post['created_at'],
            'updated_at' => $post['updated_at'],
        ],
        $statement->fetchAll()
    );

    echo json_encode(['success' => true, 'news' => $news]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to load News announcements.']);
}
