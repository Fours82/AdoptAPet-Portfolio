<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/image-upload.php';

const MAX_COMMUNITY_POST_IMAGES = 4;
require_once __DIR__ . '/notification-service.php';

function communityTextLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}

function requireVerifiedCommunityUser(PDO $pdo): array
{
    $userId = authenticatedUserId();

    if ($userId === null) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Authentication is required.']);
        exit;
    }

    $statement = $pdo->prepare(
        'SELECT u.username, u.role, u.account_status, u.deleted_at,
                EXISTS (
                    SELECT 1 FROM email_verifications ev
                    WHERE ev.user_id = u.id AND ev.verified_at IS NOT NULL
                ) AS email_verified
         FROM users u
         WHERE u.id = :id
         LIMIT 1'
    );
    $statement->execute(['id' => $userId]);
    $user = $statement->fetch();

    if (
        !$user
        || $user['role'] !== 'registered_user'
        || $user['account_status'] !== 'active'
        || $user['deleted_at'] !== null
        || !(bool) $user['email_verified']
    ) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'An active, verified registered-user account is required.']);
        exit;
    }

    return ['id' => $userId, 'username' => $user['username']];
}

function communityPostId(): int
{
    $value = $_GET['post_id'] ?? null;

    if (!is_string($value) || !ctype_digit($value) || (int) $value < 1) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'A valid Community Post ID is required.']);
        exit;
    }

    return (int) $value;
}

function communityCommentId(): int
{
    $value = $_GET['comment_id'] ?? null;

    if (!is_string($value) || !ctype_digit($value) || (int) $value < 1) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'A valid Community comment ID is required.']);
        exit;
    }

    return (int) $value;
}

function requirePublishedCommunityPost(PDO $pdo, int $postId, bool $lock = false): int
{
    $statement = $pdo->prepare(
        'SELECT author_user_id FROM community_posts WHERE id = :id AND post_status = :status LIMIT 1'
        . ($lock ? ' FOR UPDATE' : '')
    );
    $statement->execute(['id' => $postId, 'status' => 'published']);

    $post = $statement->fetch();
    if (!$post) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Community Post not found.']);
        exit;
    }

    return (int) $post['author_user_id'];
}

function readCommunityJson(): array
{
    return imageRequestPayload();
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
    $communityUser = requireVerifiedCommunityUser($pdo);
    $userId = $communityUser['id'];

    if ($method !== 'GET') {
        requireValidCsrfToken();
    }

    if ($method === 'GET') {
        if (array_diff(array_keys($_GET), ['post_id']) !== []) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Unsupported Community query parameter.']);
            exit;
        }
        $requestedPostId = array_key_exists('post_id', $_GET) ? communityPostId() : null;

        $postSql =
            'SELECT cp.id, cp.content, cp.created_at, cp.updated_at, cp.author_user_id, u.username AS author_name,
                    (SELECT pi.file_path FROM post_images pi WHERE pi.post_id = cp.id ORDER BY pi.sort_order ASC, pi.id ASC LIMIT 1) AS image_path,
                    (SELECT COUNT(*) FROM post_likes pl WHERE pl.post_id = cp.id) AS like_count,
                    (SELECT COUNT(*) FROM post_comments pc
                     WHERE pc.post_id = cp.id AND pc.comment_status = :comment_status) AS comment_count,
                    EXISTS (
                        SELECT 1 FROM post_likes current_like
                        WHERE current_like.post_id = cp.id AND current_like.user_id = :current_user_id
                    ) AS liked_by_current_user
             FROM community_posts cp
             JOIN users u ON u.id = cp.author_user_id
             WHERE cp.post_status = :post_status';
        $postParameters = [
            'comment_status' => 'published',
            'current_user_id' => $userId,
            'post_status' => 'published',
        ];
        if ($requestedPostId !== null) {
            $postSql .= ' AND cp.id = :post_id';
            $postParameters['post_id'] = $requestedPostId;
        }
        $postSql .= ' ORDER BY cp.created_at DESC, cp.id DESC';
        $statement = $pdo->prepare($postSql);
        $statement->execute($postParameters);
        $posts = $statement->fetchAll();
        if ($requestedPostId !== null && $posts === []) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Community Post not found.']);
            exit;
        }

        $commentsByPost = [];
        $imagesByPost = [];
        if ($posts !== []) {
            $commentStatement = $pdo->prepare(
                'SELECT pc.id, pc.post_id, pc.author_user_id, pc.content, pc.created_at, u.username AS author_name
                 FROM post_comments pc
                 JOIN community_posts cp ON cp.id = pc.post_id
                 JOIN users u ON u.id = pc.author_user_id
                 WHERE pc.comment_status = :comment_status
                   AND cp.post_status = :post_status' . ($requestedPostId !== null ? ' AND pc.post_id = :post_id' : '') . '
                 ORDER BY pc.created_at ASC, pc.id ASC'
            );
            $commentParameters = [
                'comment_status' => 'published',
                'post_status' => 'published',
            ];
            if ($requestedPostId !== null) {
                $commentParameters['post_id'] = $requestedPostId;
            }
            $commentStatement->execute($commentParameters);

            foreach ($commentStatement->fetchAll() as $comment) {
                $postId = (int) $comment['post_id'];
                $commentsByPost[$postId][] = [
                    'id' => (int) $comment['id'],
                    'author_name' => $comment['author_name'],
                    'content' => $comment['content'],
                    'created_at' => $comment['created_at'],
                    'is_owned_by_current_user' => (int) $comment['author_user_id'] === $userId,
                ];
            }

            $imageStatement = $pdo->prepare(
                'SELECT pi.id, pi.post_id, pi.file_path, pi.sort_order
                 FROM post_images pi
                 JOIN community_posts cp ON cp.id = pi.post_id
                 WHERE cp.post_status = :post_status' . ($requestedPostId !== null ? ' AND pi.post_id = :post_id' : '') . '
                 ORDER BY pi.post_id ASC, pi.sort_order ASC, pi.id ASC'
            );
            $imageParameters = ['post_status' => 'published'];
            if ($requestedPostId !== null) {
                $imageParameters['post_id'] = $requestedPostId;
            }
            $imageStatement->execute($imageParameters);
            foreach ($imageStatement->fetchAll() as $image) {
                $imagesByPost[(int) $image['post_id']][] = [
                    'id' => (int) $image['id'],
                    'file_path' => $image['file_path'],
                    'sort_order' => (int) $image['sort_order'],
                ];
            }
        }

        $formattedPosts = array_map(
            static fn (array $post): array => [
                'id' => (int) $post['id'],
                'author_name' => $post['author_name'],
                'content' => $post['content'],
                'created_at' => $post['created_at'],
                'like_count' => (int) $post['like_count'],
                'comment_count' => (int) $post['comment_count'],
                'liked_by_current_user' => (bool) $post['liked_by_current_user'],
                'is_owned_by_current_user' => (int) $post['author_user_id'] === $userId,
                'updated_at' => $post['updated_at'],
                'image_path' => $post['image_path'],
                'images' => $imagesByPost[(int) $post['id']] ?? [],
                'comments' => $commentsByPost[(int) $post['id']] ?? [],
            ],
            $posts
        );

        echo json_encode($requestedPostId !== null
            ? ['success' => true, 'post' => $formattedPosts[0]]
            : ['success' => true, 'posts' => $formattedPosts]);
        exit;
    }

    $actionValue = $_GET['action'] ?? '';
    $action = is_string($actionValue) ? strtolower(trim($actionValue)) : '';

    if ($method === 'DELETE' && $action === 'post') {
        if (array_diff(array_keys($_GET), ['action', 'post_id']) !== []) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'A valid Community Post removal request is required.']);
            exit;
        }
        $postId = communityPostId();
        $statement = $pdo->prepare(
            'UPDATE community_posts
             SET post_status = :removed_status, removed_by_user_id = :removed_by_user_id, removed_at = NOW()
             WHERE id = :id AND author_user_id = :author_user_id AND post_status = :published_status'
        );
        $statement->execute([
            'removed_status' => 'removed',
            'removed_by_user_id' => $userId,
            'author_user_id' => $userId,
            'id' => $postId,
            'published_status' => 'published',
        ]);
        if ($statement->rowCount() !== 1) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Your Community Post was not found.']);
            exit;
        }
        echo json_encode(['success' => true, 'message' => 'Community Post deleted.']);
        exit;
    }

    if ($method === 'DELETE' && $action === 'comment') {
        if (array_diff(array_keys($_GET), ['action', 'comment_id']) !== []) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'A valid Community comment removal request is required.']);
            exit;
        }

        $commentId = communityCommentId();
        $pdo->beginTransaction();
        $statement = $pdo->prepare(
            'SELECT pc.id, pc.post_id, pc.author_user_id
             FROM post_comments pc
             JOIN community_posts cp ON cp.id = pc.post_id
             WHERE pc.id = :id
               AND pc.comment_status = :comment_status
               AND cp.post_status = :post_status
             LIMIT 1
             FOR UPDATE'
        );
        $statement->execute([
            'id' => $commentId,
            'comment_status' => 'published',
            'post_status' => 'published',
        ]);
        $comment = $statement->fetch();

        if (!$comment) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Community comment not found.']);
            exit;
        }
        if ((int) $comment['author_user_id'] !== $userId) {
            $pdo->rollBack();
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'You can only delete your own comments.']);
            exit;
        }

        $postId = (int) $comment['post_id'];
        $deleteStatement = $pdo->prepare(
            'DELETE FROM post_comments WHERE id = :id AND author_user_id = :author_user_id'
        );
        $deleteStatement->execute(['id' => $commentId, 'author_user_id' => $userId]);
        $countStatement = $pdo->prepare(
            'SELECT COUNT(*) FROM post_comments WHERE post_id = :post_id AND comment_status = :comment_status'
        );
        $countStatement->execute(['post_id' => $postId, 'comment_status' => 'published']);
        $commentCount = (int) $countStatement->fetchColumn();
        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Comment deleted.',
            'post_id' => $postId,
            'comment_id' => $commentId,
            'comment_count' => $commentCount,
        ]);
        exit;
    }

    if ($method === 'DELETE') {
        if ($action !== 'like' || array_diff(array_keys($_GET), ['action', 'post_id']) !== []) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'A valid unlike request is required.']);
            exit;
        }

        $postId = communityPostId();
        $pdo->beginTransaction();
        requirePublishedCommunityPost($pdo, $postId, true);

        $statement = $pdo->prepare('DELETE FROM post_likes WHERE post_id = :post_id AND user_id = :user_id');
        $statement->execute(['post_id' => $postId, 'user_id' => $userId]);

        $countStatement = $pdo->prepare('SELECT COUNT(*) FROM post_likes WHERE post_id = :post_id');
        $countStatement->execute(['post_id' => $postId]);
        $likeCount = (int) $countStatement->fetchColumn();
        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Community Post unliked.',
            'liked' => false,
            'like_count' => $likeCount,
        ]);
        exit;
    }

    $input = readCommunityJson();

    if ($action !== '' && (imageUploadWasProvided() || uploadedImageFiles('images') !== [])) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Images may only be attached to new Community Posts.']);
        exit;
    }

    if ($method === 'PATCH') {
        if ($action !== 'post' || array_diff(array_keys($_GET), ['action', 'post_id']) !== [] || array_diff(array_keys($input), ['content']) !== []) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'A valid Community Post update is required.']);
            exit;
        }
        $postId = communityPostId();
        $contentValue = $input['content'] ?? null;
        $content = is_string($contentValue) ? trim($contentValue) : '';
        if (!is_string($contentValue) || $content === '' || communityTextLength($content) > 5000) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Community Post content is required and must be 5,000 characters or fewer.']);
            exit;
        }
        $statement = $pdo->prepare(
            'UPDATE community_posts SET content = :content
             WHERE id = :id AND author_user_id = :user_id AND post_status = :status'
        );
        $statement->execute(['content' => $content, 'id' => $postId, 'user_id' => $userId, 'status' => 'published']);
        if ($statement->rowCount() !== 1) {
            $ownership = $pdo->prepare('SELECT id FROM community_posts WHERE id = :id AND author_user_id = :user_id AND post_status = :status');
            $ownership->execute(['id' => $postId, 'user_id' => $userId, 'status' => 'published']);
            if (!$ownership->fetch()) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Your Community Post was not found.']);
                exit;
            }
        }
        echo json_encode(['success' => true, 'message' => 'Community Post updated.']);
        exit;
    }

    if ($action === '') {
        if ($_GET !== [] || array_diff(array_keys($input), ['content']) !== []) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Only Community Post content is accepted.']);
            exit;
        }

        $contentValue = $input['content'] ?? null;
        $content = is_string($contentValue) ? trim($contentValue) : '';
        if (!is_string($contentValue) || $content === '' || communityTextLength($content) > 5000) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Community Post content is required and must be 5,000 characters or fewer.']);
            exit;
        }

        $pdo->beginTransaction();
        $statement = $pdo->prepare(
            'INSERT INTO community_posts (author_user_id, content, post_status)
             VALUES (:author_user_id, :content, :post_status)'
        );
        $statement->execute([
            'author_user_id' => $userId,
            'content' => $content,
            'post_status' => 'published',
        ]);
        $postId = (int) $pdo->lastInsertId();

        $storedImages = storeRequestedImages('community', MAX_COMMUNITY_POST_IMAGES);
        if ($storedImages !== []) {
            $imageStatement = $pdo->prepare(
                'INSERT INTO post_images (post_id, file_path, original_filename, mime_type, file_size_bytes, sort_order)
                 VALUES (:post_id, :file_path, :original_filename, :mime_type, :file_size_bytes, :sort_order)'
            );
            foreach ($storedImages as $sortOrder => $storedImage) {
                $imageStatement->execute([
                    'post_id' => $postId,
                    'file_path' => $storedImage['file_path'],
                    'original_filename' => $storedImage['original_filename'],
                    'mime_type' => $storedImage['mime_type'],
                    'file_size_bytes' => $storedImage['file_size_bytes'],
                    'sort_order' => $sortOrder,
                ]);
            }
        }

        $pdo->commit();
        $responseImages = array_map(static fn (array $image): string => $image['file_path'], $storedImages);
        $storedImages = [];

        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'Community Post published.',
            'post' => ['id' => $postId, 'image_path' => $responseImages[0] ?? null, 'images' => $responseImages],
        ]);
        exit;
    }

    if ($action === 'comment') {
        if (
            array_diff(array_keys($_GET), ['action', 'post_id']) !== []
            || array_diff(array_keys($input), ['content']) !== []
        ) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Only comment content is accepted.']);
            exit;
        }

        $postId = communityPostId();
        $contentValue = $input['content'] ?? null;
        $content = is_string($contentValue) ? trim($contentValue) : '';

        if (!is_string($contentValue) || $content === '' || communityTextLength($content) > 2000) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Comment content is required and must be 2,000 characters or fewer.']);
            exit;
        }

        $pdo->beginTransaction();
        $postAuthorId = requirePublishedCommunityPost($pdo, $postId, true);
        $statement = $pdo->prepare(
            'INSERT INTO post_comments (post_id, author_user_id, content, comment_status)
             VALUES (:post_id, :author_user_id, :content, :comment_status)'
        );
        $statement->execute([
            'post_id' => $postId,
            'author_user_id' => $userId,
            'content' => $content,
            'comment_status' => 'published',
        ]);
        $commentId = (int) $pdo->lastInsertId();

        if ($postAuthorId !== $userId) {
            createNotificationRecord(
                $pdo,
                $postAuthorId,
                'community_comment',
                $communityUser['username'] . ' commented on your Community Post.',
                '#/community/' . $postId
            );
        }

        $createdStatement = $pdo->prepare(
            'SELECT created_at FROM post_comments WHERE id = :id AND author_user_id = :author_user_id LIMIT 1'
        );
        $createdStatement->execute(['id' => $commentId, 'author_user_id' => $userId]);
        $createdAt = $createdStatement->fetchColumn();
        $countStatement = $pdo->prepare(
            'SELECT COUNT(*) FROM post_comments WHERE post_id = :post_id AND comment_status = :comment_status'
        );
        $countStatement->execute(['post_id' => $postId, 'comment_status' => 'published']);
        $commentCount = (int) $countStatement->fetchColumn();
        $pdo->commit();

        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'Comment posted.',
            'comment' => [
                'id' => $commentId,
                'author_name' => $communityUser['username'],
                'content' => $content,
                'created_at' => $createdAt,
                'is_owned_by_current_user' => true,
            ],
            'comment_count' => $commentCount,
        ]);
        exit;
    }

    if ($action === 'like') {
        if (
            array_diff(array_keys($_GET), ['action', 'post_id']) !== []
            || $input !== []
        ) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'A valid like request is required.']);
            exit;
        }

        $postId = communityPostId();
        $pdo->beginTransaction();
        $postAuthorId = requirePublishedCommunityPost($pdo, $postId, true);
        $existingLike = $pdo->prepare('SELECT 1 FROM post_likes WHERE post_id = :post_id AND user_id = :user_id LIMIT 1');
        $existingLike->execute(['post_id' => $postId, 'user_id' => $userId]);
        $alreadyLiked = (bool) $existingLike->fetchColumn();
        $statement = $pdo->prepare(
            'INSERT IGNORE INTO post_likes (post_id, user_id) VALUES (:post_id, :user_id)'
        );
        $likeCreated = false;
        if (!$alreadyLiked) {
            $statement->execute(['post_id' => $postId, 'user_id' => $userId]);
            $likeCreated = $statement->rowCount() === 1;
        }

        if ($likeCreated && $postAuthorId !== $userId) {
            createNotificationRecord(
                $pdo,
                $postAuthorId,
                'community_like',
                $communityUser['username'] . ' liked your Community Post.',
                '#/community/' . $postId
            );
        }

        $countStatement = $pdo->prepare('SELECT COUNT(*) FROM post_likes WHERE post_id = :post_id');
        $countStatement->execute(['post_id' => $postId]);
        $likeCount = (int) $countStatement->fetchColumn();
        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Community Post liked.',
            'liked' => true,
            'like_count' => $likeCount,
        ]);
        exit;
    }

    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Unsupported Community action.']);
} catch (ImageUploadException $exception) {
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
    echo json_encode(['success' => false, 'message' => 'Unable to process the Community request.']);
}
