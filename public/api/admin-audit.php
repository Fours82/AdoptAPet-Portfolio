<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
header('Pragma: no-cache');
require_once __DIR__ . '/admin-audit-service.php';
require_once __DIR__ . '/db.php';

function adminAuditReply(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    adminAuditReply(405, ['success' => false, 'message' => 'Method not allowed.']);
}
$userId = authenticatedUserId();
if ($userId === null) {
    adminAuditReply(401, ['success' => false, 'message' => 'Authentication is required.']);
}

try {
    $pdo = getDatabaseConnection();
    if (adminAuditActorId($pdo) === null) {
        adminAuditReply(403, ['success' => false, 'message' => 'Administrator access is required.']);
    }
    if (array_diff(array_keys($_GET), ['page', 'entity', 'action']) !== []) {
        adminAuditReply(422, ['success' => false, 'message' => 'Unsupported Audit Log filter.']);
    }
    $pageInput = $_GET['page'] ?? '1';
    if (!is_string($pageInput) || !ctype_digit($pageInput) || strlen($pageInput) > 6
        || (int) $pageInput < 1 || (int) $pageInput > 100000) {
        adminAuditReply(422, ['success' => false, 'message' => 'Select a valid page.']);
    }
    $page = (int) $pageInput;
    $entity = $_GET['entity'] ?? '';
    $action = $_GET['action'] ?? '';
    if (!is_string($entity) || !is_string($action)
        || ($entity !== '' && !in_array($entity, array_values(ADMIN_AUDIT_ACTION_ENTITIES), true))
        || ($action !== '' && !array_key_exists($action, ADMIN_AUDIT_ACTION_ENTITIES))) {
        adminAuditReply(422, ['success' => false, 'message' => 'Select a valid Audit Log filter.']);
    }
    $where = [];
    $params = [];
    if ($entity !== '') {
        $where[] = 'a.entity_type = :entity';
        $params['entity'] = $entity;
    }
    if ($action !== '') {
        $where[] = 'a.action_type = :action';
        $params['action'] = $action;
    }
    $condition = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
    $count = $pdo->prepare('SELECT COUNT(*) FROM audit_logs a' . $condition);
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $perPage = 20;
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min($page, $pages);
    $rows = $pdo->prepare(
        'SELECT a.id, a.actor_user_id, a.action_type, a.entity_type, a.entity_id, a.created_at,
                CASE WHEN a.entity_type = \'stray_report\' AND a.action_type = \'stray_report_updated\'
                     THEN a.old_values_json ELSE NULL END AS stray_old_values_json,
                CASE WHEN a.entity_type = \'stray_report\' AND a.action_type = \'stray_report_updated\'
                     THEN a.new_values_json ELSE NULL END AS stray_new_values_json,
                u.username AS actor_username, u.name AS actor_name,
                target_user.username AS target_username, target_pet.name AS target_pet_name,
                target_news.title AS target_news_title, application_pet.name AS application_pet_name,
                application_user.username AS application_username,
                medical_pet.name AS medical_pet_name, target_medical.record_type AS medical_record_type,
                community_author.username AS community_author_username,
                target_stray.location_text AS stray_location_text
         FROM audit_logs a
         LEFT JOIN users u ON u.id = a.actor_user_id
         LEFT JOIN users target_user ON a.entity_type = \'user\' AND target_user.id = a.entity_id AND target_user.deleted_at IS NULL
         LEFT JOIN pets target_pet ON a.entity_type = \'pet\' AND target_pet.id = a.entity_id
         LEFT JOIN news_posts target_news ON a.entity_type = \'news_post\' AND target_news.id = a.entity_id
         LEFT JOIN adoption_applications target_application ON a.entity_type = \'adoption_application\' AND target_application.id = a.entity_id
         LEFT JOIN pets application_pet ON application_pet.id = target_application.pet_id
         LEFT JOIN users application_user ON application_user.id = target_application.applicant_user_id AND application_user.deleted_at IS NULL
         LEFT JOIN medical_records target_medical ON a.entity_type = \'medical_record\' AND target_medical.id = a.entity_id
         LEFT JOIN pets medical_pet ON medical_pet.id = target_medical.pet_id
         LEFT JOIN community_posts target_post ON a.entity_type = \'community_post\' AND target_post.id = a.entity_id
         LEFT JOIN users community_author ON community_author.id = target_post.author_user_id AND community_author.deleted_at IS NULL
         LEFT JOIN stray_reports target_stray ON a.entity_type = \'stray_report\' AND target_stray.id = a.entity_id'
        . $condition . ' ORDER BY a.created_at DESC, a.id DESC LIMIT :limit OFFSET :offset'
    );
    foreach ($params as $key => $value) {
        $rows->bindValue(':' . $key, $value, PDO::PARAM_STR);
    }
    $rows->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $rows->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
    $rows->execute();
    adminAuditReply(200, [
        'success' => true,
        'entries' => array_map('adminAuditDisplayRow', $rows->fetchAll()),
        'page' => $page,
        'total_pages' => $pages,
        'total' => $total,
    ]);
} catch (Throwable $exception) {
    adminAuditReply(500, ['success' => false, 'message' => 'Unable to load the Administrator Audit Log.']);
}
