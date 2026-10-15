<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/admin-audit-service.php';

function requireCommunityAdministrator(PDO $pdo): int
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

function moderatedPostId(): int
{
    $value = $_GET['id'] ?? null;

    if (!is_string($value) || !ctype_digit($value) || (int) $value < 1) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'A valid Community Post ID is required.']);
        exit;
    }

    return (int) $value;
}

$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'DELETE'], true)) {
    http_response_code(405);
    header('Allow: GET, DELETE');
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    $administratorId = requireCommunityAdministrator($pdo);

    if ($method !== 'GET') {
        requireValidCsrfToken();
    }

    if ($method === 'GET') {
        if ($_GET !== []) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Unsupported moderation query parameter.']);
            exit;
        }

        $statement = $pdo->prepare(
            'SELECT cp.id, cp.content, cp.created_at, u.name AS author_name,
                    (SELECT COUNT(*) FROM post_likes pl WHERE pl.post_id = cp.id) AS like_count,
                    (SELECT COUNT(*) FROM post_comments pc
                     WHERE pc.post_id = cp.id AND pc.comment_status = :comment_status) AS comment_count
             FROM community_posts cp
             JOIN users u ON u.id = cp.author_user_id
             WHERE cp.post_status = :post_status
             ORDER BY cp.created_at DESC, cp.id DESC'
        );
        $statement->execute(['comment_status' => 'published', 'post_status' => 'published']);

        $postRows = $statement->fetchAll();
        $imagesByPost = [];
        if ($postRows !== []) {
            $imageStatement = $pdo->prepare(
                'SELECT pi.id, pi.post_id, pi.file_path, pi.sort_order
                 FROM post_images pi
                 JOIN community_posts cp ON cp.id = pi.post_id
                 WHERE cp.post_status = :post_status
                 ORDER BY pi.post_id ASC, pi.sort_order ASC, pi.id ASC'
            );
            $imageStatement->execute(['post_status' => 'published']);
            foreach ($imageStatement->fetchAll() as $image) {
                $imagesByPost[(int) $image['post_id']][] = [
                    'id' => (int) $image['id'],
                    'file_path' => $image['file_path'],
                    'sort_order' => (int) $image['sort_order'],
                ];
            }
        }

        $posts = array_map(
            static fn (array $post): array => [
                'id' => (int) $post['id'],
                'author_name' => $post['author_name'],
                'content' => $post['content'],
                'created_at' => $post['created_at'],
                'like_count' => (int) $post['like_count'],
                'comment_count' => (int) $post['comment_count'],
                'images' => $imagesByPost[(int) $post['id']] ?? [],
            ],
            $postRows
        );

        echo json_encode(['success' => true, 'posts' => $posts]);
        exit;
    }

    if (array_diff(array_keys($_GET), ['id']) !== []) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Unsupported moderation query parameter.']);
        exit;
    }

    $postId = moderatedPostId();
    $pdo->beginTransaction();

    $statement = $pdo->prepare(
        'SELECT id, author_user_id, content, post_status
         FROM community_posts
         WHERE id = :id AND post_status = :status
         LIMIT 1 FOR UPDATE'
    );
    $statement->execute(['id' => $postId, 'status' => 'published']);
    $post = $statement->fetch();

    if (!$post) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Published Community Post not found.']);
        exit;
    }

    $statement = $pdo->prepare('DELETE FROM post_comments WHERE post_id = :post_id');
    $statement->execute(['post_id' => $postId]);
    $statement = $pdo->prepare('DELETE FROM post_likes WHERE post_id = :post_id');
    $statement->execute(['post_id' => $postId]);
    $statement = $pdo->prepare(
        'UPDATE community_posts
         SET post_status = :post_status, removed_by_user_id = :removed_by_user_id, removed_at = NOW()
         WHERE id = :id'
    );
    $statement->execute([
        'post_status' => 'removed',
        'removed_by_user_id' => $administratorId,
        'id' => $postId,
    ]);

    adminAuditWrite($pdo, 'community_post_removed', $postId,
        ['status' => (string) $post['post_status']], ['status' => 'removed']);

    $pdo->commit();

    echo json_encode(['success' => true, 'message' => 'Community Post removed.']);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to process the Community moderation request.']);
}
