<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/public/api/image-upload.php';

function newsCommunityCheck(bool $ok, string $description): void
{
    if (!$ok) {
        throw new RuntimeException('News/Community check failed: ' . $description);
    }
}

$root = dirname(__DIR__);
$news = file_get_contents($root . '/public/api/news.php');
$adminNews = file_get_contents($root . '/public/api/admin-news.php');
$community = file_get_contents($root . '/public/api/community.php');
$adminCommunity = file_get_contents($root . '/public/api/admin-community.php');
newsCommunityCheck(is_string($news) && is_string($adminNews) && is_string($community) && is_string($adminCommunity), 'sources readable');

newsCommunityCheck(str_contains($news, "np.publication_status = :publication_status")
    && str_contains($news, 'np.published_at IS NOT NULL')
    && str_contains($news, 'np.published_at <= NOW()')
    && str_contains($news, "'news_item' => [")
    && str_contains($news, "'cover_image_path' => \$post['cover_image_path']")
    && str_contains($news, 'http_response_code(404)'), 'published-only list/detail, optional cover and missing detail');
newsCommunityCheck(str_contains($adminNews, 'isOperationalAdministratorRole(')
    && str_contains($adminNews, 'requireValidCsrfToken()')
    && str_contains($adminNews, 'adminAuditWrite(')
    && str_contains($adminNews, "'#/news/' . \$newsId")
    && str_contains($adminNews, "\$existing['publication_status'] !== 'published' && \$news['publication_status'] === 'published'"),
    'privileged publishing, CSRF, audit and one transition notification');

newsCommunityCheck(str_contains($community, "\$user['role'] !== 'registered_user'")
    && str_contains($community, 'ev.verified_at IS NOT NULL')
    && str_contains($community, 'requireValidCsrfToken()'), 'verified Registered User and CSRF boundaries');
newsCommunityCheck(str_contains($community, "array_keys(\$_GET), ['post_id']")
    && str_contains($community, "'post' => \$formattedPosts[0]")
    && str_contains($community, "'Community Post not found.'"), 'single-post read and removed/missing fallback');
newsCommunityCheck(str_contains($community, 'SELECT pi.id, pi.post_id, pi.file_path, pi.sort_order')
    && !str_contains($community, "'original_filename' => \$image['original_filename']")
    && !str_contains($adminCommunity, "'original_filename' => \$image['original_filename']"), 'read responses omit original upload names');
newsCommunityCheck(str_contains($community, 'MAX_COMMUNITY_POST_IMAGES = 4')
    && str_contains($community, "storeRequestedImages('community', MAX_COMMUNITY_POST_IMAGES)"), 'server image limit');
newsCommunityCheck(str_contains($community, 'author_user_id = :user_id AND post_status = :status')
    && str_contains($community, 'author_user_id = :author_user_id')
    && str_contains($community, "'You can only delete your own comments.'"), 'post/comment ownership');
newsCommunityCheck(str_contains($community, 'requirePublishedCommunityPost($pdo, $postId, true)')
    && str_contains($community, 'SELECT 1 FROM post_likes WHERE post_id = :post_id AND user_id = :user_id LIMIT 1')
    && str_contains($community, 'INSERT IGNORE INTO post_likes')
    && str_contains($community, 'if ($likeCreated && $postAuthorId !== $userId)')
    && str_contains($community, 'if ($postAuthorId !== $userId)'), 'locked like deduplication and self-notification exclusion');
newsCommunityCheck(str_contains($community, "'#/community/' . \$postId")
    && str_contains($adminCommunity, 'isOperationalAdministratorRole(')
    && str_contains($adminCommunity, 'requireValidCsrfToken()')
    && str_contains($adminCommunity, 'adminAuditWrite('), 'exact Community links and separate moderation');

$_FILES = [];
newsCommunityCheck(storeRequestedImages('community', 4) === [], 'zero images accepted');
foreach ([1, 4, 5] as $count) {
    $_FILES['images'] = [
        'name' => array_fill(0, $count, 'image.jpg'),
        'type' => array_fill(0, $count, 'image/jpeg'),
        'tmp_name' => array_fill(0, $count, 'not-an-upload'),
        'error' => array_fill(0, $count, UPLOAD_ERR_OK),
        'size' => array_fill(0, $count, 12),
    ];
    newsCommunityCheck(count(uploadedImageFiles('images')) === $count, "$count images counted");
}
try {
    storeRequestedImages('community', 4);
    throw new RuntimeException('Five images unexpectedly passed.');
} catch (ImageUploadException $exception) {
    newsCommunityCheck(str_contains($exception->getMessage(), 'no more than 4'), 'five images rejected before storage');
}
$_FILES = [];

echo "News and Community focused source/upload checks passed.\n";
