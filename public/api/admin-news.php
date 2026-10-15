<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/admin-audit-service.php';
require_once __DIR__ . '/image-upload.php';
require_once __DIR__ . '/notification-service.php';

function requireNewsAdministrator(PDO $pdo): int
{
    $userId = authenticatedUserId();

    if ($userId === null) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Authentication is required.']);
        exit;
    }

    $statement = $pdo->prepare(
        'SELECT role FROM users
         WHERE id = :id AND account_status = :account_status AND deleted_at IS NULL
         LIMIT 1'
    );
    $statement->execute(['id' => $userId, 'account_status' => 'active']);
    $user = $statement->fetch();

    if (!isOperationalAdministratorRole($user['role'] ?? null)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Administrator access is required.']);
        exit;
    }

    return $userId;
}

function newsPostId(): int
{
    $value = $_GET['id'] ?? null;

    if (!is_string($value) || !ctype_digit($value) || (int) $value < 1) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'A valid News announcement ID is required.']);
        exit;
    }

    return (int) $value;
}

function validatedNewsPayload(array $input): array
{
    $unexpected = array_diff(array_keys($input), ['title', 'body', 'publication_status', 'remove_cover_image']);
    if ($unexpected !== []) {
        throw new InvalidArgumentException('Unsupported News announcement field.');
    }

    if (
        !isset($input['title'], $input['body'], $input['publication_status'])
        || !is_string($input['title'])
        || !is_string($input['body'])
        || !is_string($input['publication_status'])
    ) {
        throw new InvalidArgumentException('News announcement fields must be text.');
    }

    $title = trim($input['title']);
    $body = trim($input['body']);
    $status = strtolower(trim($input['publication_status']));
    $removeCoverImage = $input['remove_cover_image'] ?? false;
    $titleLength = function_exists('mb_strlen') ? mb_strlen($title) : strlen($title);

    if (!is_bool($removeCoverImage)) {
        throw new InvalidArgumentException('The cover-image removal option must be true or false.');
    }

    if ($title === '' || $body === '') {
        throw new InvalidArgumentException('A title and announcement are required.');
    }

    if ($titleLength > 255) {
        throw new InvalidArgumentException('The News title must be 255 characters or fewer.');
    }

    if (strlen($body) > 65535) {
        throw new InvalidArgumentException('The News announcement is too long.');
    }

    if (!in_array($status, ['draft', 'published'], true)) {
        throw new InvalidArgumentException('The publication status must be draft or published.');
    }

    return [
        'title' => $title,
        'body' => $body,
        'publication_status' => $status,
        'remove_cover_image' => $removeCoverImage,
    ];
}

function notifyNewsPublication(PDO $pdo, int $newsId, string $title): void
{
    $statement = $pdo->prepare(
        'SELECT u.id
         FROM users u
         WHERE u.role = :role
           AND u.account_status = :account_status
           AND u.deleted_at IS NULL
           AND EXISTS (
               SELECT 1 FROM email_verifications ev
               WHERE ev.user_id = u.id AND ev.verified_at IS NOT NULL
           )'
    );
    $statement->execute(['role' => 'registered_user', 'account_status' => 'active']);

    foreach ($statement->fetchAll() as $recipient) {
        createNotificationRecord(
            $pdo,
            (int) $recipient['id'],
            'news_published',
            'New News announcement: ' . $title,
            '#/news/' . $newsId
        );
    }
}

function adminNewsRows(PDO $pdo): array
{
    $statement = $pdo->prepare(
        'SELECT np.id, np.title, np.body, np.cover_image_path, np.publication_status, np.published_at,
                np.created_at, np.updated_at, u.name AS author_name
         FROM news_posts np
         JOIN users u ON u.id = np.author_user_id
         WHERE np.publication_status <> :archived_status
         ORDER BY COALESCE(np.published_at, np.created_at) DESC, np.id DESC'
    );
    $statement->execute(['archived_status' => 'archived']);

    return array_map(
        static fn (array $post): array => [
            'id' => (int) $post['id'],
            'title' => $post['title'],
            'body' => $post['body'],
            'cover_image_path' => $post['cover_image_path'],
            'publication_status' => $post['publication_status'],
            'author_name' => $post['author_name'],
            'published_at' => $post['published_at'],
            'created_at' => $post['created_at'],
            'updated_at' => $post['updated_at'],
        ],
        $statement->fetchAll()
    );
}

$method = $_SERVER['REQUEST_METHOD'];
$effectiveMethod = $method;
if ($method === 'POST' && ($_POST['_method'] ?? null) === 'PATCH') {
    $effectiveMethod = 'PATCH';
}
if (!in_array($effectiveMethod, ['GET', 'POST', 'PATCH', 'DELETE'], true)) {
    http_response_code(405);
    header('Allow: GET, POST, PATCH, DELETE');
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$storedImage = null;
$replacedCoverPath = null;
try {
    $pdo = getDatabaseConnection();
    $administratorId = requireNewsAdministrator($pdo);

    if ($method !== 'GET') {
        requireValidCsrfToken();
    }

    if ($effectiveMethod === 'GET') {
        if ($_GET !== []) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Unsupported News management query parameter.']);
            exit;
        }

        echo json_encode(['success' => true, 'news' => adminNewsRows($pdo)]);
        exit;
    }

    if ($effectiveMethod === 'POST') {
        if ($_GET !== []) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Unsupported News management query parameter.']);
            exit;
        }

        $news = validatedNewsPayload(imageRequestPayload());
        if ($news['remove_cover_image']) {
            throw new InvalidArgumentException('A new News announcement does not have a cover image to remove.');
        }
        if (imageUploadWasProvided()) {
            $storedImage = storeUploadedImage('news');
        }
        $pdo->beginTransaction();
        $statement = $pdo->prepare(
            'INSERT INTO news_posts
             (author_user_id, title, body, cover_image_path, publication_status, published_at)
             VALUES (:author_user_id, :title, :body, :cover_image_path, :publication_status,
                     CASE WHEN :publish_now = 1 THEN NOW() ELSE NULL END)'
        );
        $statement->execute([
            'author_user_id' => $administratorId,
            'title' => $news['title'],
            'body' => $news['body'],
            'cover_image_path' => $storedImage['file_path'] ?? null,
            'publication_status' => $news['publication_status'],
            'publish_now' => $news['publication_status'] === 'published' ? 1 : 0,
        ]);
        $newsId = (int) $pdo->lastInsertId();

        adminAuditWrite($pdo, 'news_created', $newsId, null,
            ['status' => $news['publication_status']]);
        if ($news['publication_status'] === 'published') {
            notifyNewsPublication($pdo, $newsId, $news['title']);
        }

        $pdo->commit();
        $storedImage = null;
        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => $news['publication_status'] === 'published'
                ? 'News announcement published.'
                : 'News draft saved.',
            'id' => $newsId,
        ]);
        exit;
    }

    if (array_diff(array_keys($_GET), ['id']) !== []) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Unsupported News management query parameter.']);
        exit;
    }

    $newsId = newsPostId();
    $pdo->beginTransaction();
    $statement = $pdo->prepare(
        'SELECT id, title, body, cover_image_path, publication_status, published_at
         FROM news_posts
         WHERE id = :id AND publication_status <> :archived_status
         LIMIT 1 FOR UPDATE'
    );
    $statement->execute(['id' => $newsId, 'archived_status' => 'archived']);
    $existing = $statement->fetch();

    if (!$existing) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'News announcement not found.']);
        exit;
    }

    if ($effectiveMethod === 'DELETE') {
        $statement = $pdo->prepare(
            'UPDATE news_posts
             SET publication_status = :publication_status
             WHERE id = :id'
        );
        $statement->execute(['publication_status' => 'archived', 'id' => $newsId]);
        adminAuditWrite($pdo, 'news_archived', $newsId,
            ['status' => $existing['publication_status']], ['status' => 'archived']);
        $pdo->commit();

        echo json_encode(['success' => true, 'message' => 'News announcement removed from the public feed.']);
        exit;
    }

    $news = validatedNewsPayload(imageRequestPayload());
    $hasNewCover = imageUploadWasProvided();
    if ($hasNewCover && $news['remove_cover_image']) {
        throw new InvalidArgumentException('Choose either a replacement cover image or remove the current image.');
    }
    if ($hasNewCover) {
        $storedImage = storeUploadedImage('news');
    }
    $nextCoverPath = $storedImage['file_path'] ?? ($news['remove_cover_image'] ? null : $existing['cover_image_path']);
    if (($hasNewCover || $news['remove_cover_image']) && is_string($existing['cover_image_path'])) {
        $replacedCoverPath = $existing['cover_image_path'];
    }
    $statement = $pdo->prepare(
        'UPDATE news_posts
         SET title = :title,
             body = :body,
             cover_image_path = :cover_image_path,
             published_at = CASE
                 WHEN :publish_now = 0 THEN NULL
                 WHEN :was_published = 1 THEN published_at
                 ELSE NOW()
             END,
             publication_status = :publication_status
         WHERE id = :id'
    );
    $statement->execute([
        'title' => $news['title'],
        'body' => $news['body'],
        'cover_image_path' => $nextCoverPath,
        'publication_status' => $news['publication_status'],
        'publish_now' => $news['publication_status'] === 'published' ? 1 : 0,
        'was_published' => $existing['publication_status'] === 'published' ? 1 : 0,
        'id' => $newsId,
    ]);

    if ($existing['title'] !== $news['title'] || $existing['body'] !== $news['body']
        || $existing['cover_image_path'] !== $nextCoverPath
        || $existing['publication_status'] !== $news['publication_status']) {
        adminAuditWrite($pdo, 'news_updated', $newsId,
            ['status' => $existing['publication_status']], ['status' => $news['publication_status']]);
    }
    if ($existing['publication_status'] !== 'published' && $news['publication_status'] === 'published') {
        notifyNewsPublication($pdo, $newsId, $news['title']);
    }

    $pdo->commit();
    $storedImage = null;
    if ($replacedCoverPath !== null && $replacedCoverPath !== $nextCoverPath) {
        removeStoredImagePath($replacedCoverPath);
    }
    echo json_encode([
        'success' => true,
        'message' => $news['publication_status'] === 'published'
            ? 'News announcement updated and published.'
            : 'News draft updated.',
    ]);
} catch (ImageUploadException | InvalidArgumentException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    removeStoredImage($storedImage);

    http_response_code(422);
    echo json_encode(['success' => false, 'message' => $exception->getMessage()]);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    removeStoredImage($storedImage);

    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to process the News management request.']);
}
