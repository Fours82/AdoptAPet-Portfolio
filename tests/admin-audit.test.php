<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/public/api/admin-audit-service.php';

function auditCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

foreach (['user_status_updated', 'medical_record_created', 'stray_report_updated', 'news_created',
    'community_post_removed', 'pet_created', 'pet_image_added', 'application_approved',
    'application_rejected', 'support_ticket_closed'] as $action) {
    auditCheck(isset(ADMIN_AUDIT_ACTION_ENTITIES[$action]), 'Missing controlled action: ' . $action);
}

auditCheck(adminAuditSafeValues(['status' => 'suspended', 'pet_id' => 42]) ===
    ['status' => 'suspended', 'pet_id' => 42], 'Safe values retained');
foreach ([['body' => 'full article'], ['details' => 'medical details'],
    ['admin_notes' => 'private note'], ['csrf_token' => 'secret'], ['status' => str_repeat('a', 100)]] as $unsafe) {
    try {
        adminAuditSafeValues($unsafe);
        throw new RuntimeException('Unsafe audit value accepted');
    } catch (InvalidArgumentException $expected) {
    }
}
$memory = new PDO('sqlite::memory:');
try {
    adminAuditWrite($memory, 'pet_created', 1);
    throw new RuntimeException('Audit write without transaction accepted');
} catch (LogicException $expected) {
}
$memory->beginTransaction();
try {
    adminAuditWrite($memory, 'frontend_selected_action', 1);
    throw new RuntimeException('Uncontrolled action accepted');
} catch (InvalidArgumentException $expected) {
    $memory->rollBack();
}

$entry = adminAuditDisplayRow([
    'id' => 25, 'actor_username' => 'admin', 'actor_name' => 'Administrator',
    'action_type' => 'news_updated', 'entity_type' => 'news_post', 'entity_id' => 17,
    'ip_address' => '127.0.0.1', 'created_at' => '2026-09-13 08:00:00',
    'old_values_json' => '{"body":"SENSITIVE HISTORICAL ARTICLE"}',
    'new_values_json' => '{"details":"SENSITIVE HISTORICAL DETAILS"}',
]);
auditCheck($entry['action'] === 'News updated' && $entry['target'] === 'News #17'
    && $entry['target_id'] === 'News #17' && $entry['details'] === 'Updated news article News #17.',
    'Historical or deleted target falls back to entity and ID');
auditCheck($entry['actor'] === '@admin' && !array_key_exists('ip_address', $entry),
    'Actor remains visible; historical IP is not returned');
auditCheck(!array_key_exists('old_values_json', $entry) && !array_key_exists('new_values_json', $entry)
    && !str_contains(json_encode($entry), 'SENSITIVE'), 'Historical JSON never exposed');

$cases = [
    ['user', 'user_status_updated', ['target_username' => 'john123'], '@john123', 'User #23'],
    ['pet', 'pet_updated', ['target_pet_name' => 'Milo'], 'Milo', 'Pet #23'],
    ['news_post', 'news_created', ['target_news_title' => 'Adoption Day 2026'], '“Adoption Day 2026”', 'News #23'],
    ['adoption_application', 'application_approved', ['application_pet_name' => 'Milo', 'application_username' => 'jane'], 'Milo · @jane', 'Application #23'],
    ['medical_record', 'medical_record_updated', ['medical_pet_name' => 'Milo', 'medical_record_type' => 'vaccination'], 'Milo · Vaccination', 'Medical record #23'],
    ['medical_record', 'medical_record_updated', ['medical_record_type' => 'vaccination'], 'Vaccination record', 'Medical record #23'],
    ['community_post', 'community_post_removed', ['community_author_username' => 'jane'], 'Post by @jane', 'Post #23'],
    ['stray_report', 'stray_report_updated', [], 'Stray Report #23', 'Stray Report #23'],
    ['chat_conversation', 'support_ticket_closed', [], 'Ticket #23', 'Ticket #23'],
];
foreach ($cases as [$type, $action, $fields, $target, $targetId]) {
    $display = adminAuditDisplayRow(array_merge([
        'id' => 26, 'entity_id' => 23, 'entity_type' => $type,
        'action_type' => $action, 'created_at' => '2026-09-13 08:00:00',
    ], $fields));
    auditCheck($display['target'] === $target && $display['target_id'] === $targetId
        && str_contains($display['details'], $target), 'Resolved target and detail for ' . $type);
}
$escaped = adminAuditDisplayRow([
    'id' => 27, 'entity_id' => 7, 'entity_type' => 'news_post', 'action_type' => 'news_updated',
    'created_at' => '2026-09-13 08:00:00', 'target_news_title' => '<script>alert(1)</script>',
]);
auditCheck($escaped['target'] === '“<script>alert(1)</script>”', 'API returns plain text for UI escaping');

$strayBase = [
    'id' => 28, 'entity_id' => 38, 'entity_type' => 'stray_report',
    'action_type' => 'stray_report_updated', 'created_at' => '2026-09-13 08:00:00',
    'stray_location_text' => 'Jalan Ampang, Kuala Lumpur',
];
$status = adminAuditDisplayRow($strayBase + [
    'stray_old_values_json' => '{"status":"under_review"}',
    'stray_new_values_json' => '{"status":"assigned","notes_changed":false}',
]);
auditCheck($status['target'] === 'Jalan Ampang, Kuala Lumpur'
    && $status['target_id'] === 'Stray Report #38'
    && $status['details'] === 'Changed status from Acknowledged to Rescue in Progress.',
    'Stray location and status transition');
$notes = adminAuditDisplayRow($strayBase + [
    'stray_old_values_json' => '{"status":"assigned"}',
    'stray_new_values_json' => '{"status":"assigned","notes_changed":true}',
]);
auditCheck($notes['details'] === 'Updated admin notes for Stray Report #38.', 'Note-only change does not expose text');
$both = adminAuditDisplayRow($strayBase + [
    'stray_old_values_json' => '{"status":"assigned"}',
    'stray_new_values_json' => '{"status":"resolved","notes_changed":true,"admin_notes":"PRIVATE"}',
]);
auditCheck($both['details'] === 'Changed status from Rescue in Progress to Resolved and updated admin notes.'
    && !str_contains(json_encode($both), 'PRIVATE'), 'Combined change uses only whitelisted indicators');
$missing = adminAuditDisplayRow(array_diff_key($strayBase, ['stray_location_text' => true]) + [
    'stray_old_values_json' => '{"status":"arbitrary_private_value"}',
    'stray_new_values_json' => '{"status":"resolved","admin_notes":"PRIVATE"}',
]);
auditCheck($missing['target'] === 'Stray Report #38' && $missing['details'] === 'Updated Stray Report #38.'
    && !str_contains(json_encode($missing), 'PRIVATE'), 'Missing report and unsafe history fall back safely');
$unsafeIndicator = adminAuditDisplayRow($strayBase + [
    'stray_old_values_json' => '{"status":"assigned"}',
    'stray_new_values_json' => '{"status":"resolved","notes_changed":"true"}',
]);
auditCheck($unsafeIndicator['details'] === 'Changed status from Rescue in Progress to Resolved.',
    'Non-boolean note indicators are ignored');

$api = file_get_contents(dirname(__DIR__) . '/public/api/admin-audit.php');
auditCheck(is_string($api)
    && str_contains($api, "a.entity_type = \\'stray_report\\'")
    && str_contains($api, 'AS stray_old_values_json')
    && str_contains($api, 'AS stray_new_values_json')
    && !str_contains($api, 'a.ip_address'), 'Reader scopes historical JSON to stray updates and omits IP');
auditCheck(str_contains($api, 'ORDER BY a.created_at DESC, a.id DESC'), 'Stable newest-first order');
auditCheck(str_contains($api, 'PDO::PARAM_INT'), 'Integer limit/offset bindings');
auditCheck(str_contains($api, 'LEFT JOIN pets target_pet')
    && str_contains($api, 'LEFT JOIN news_posts target_news')
    && str_contains($api, 'LEFT JOIN medical_records target_medical')
    && str_contains($api, 'LEFT JOIN stray_reports target_stray'), 'Target resolution uses page joins');
auditCheck(str_contains($api, "header('Cache-Control: private, no-store')")
    && str_contains($api, 'adminAuditActorId($pdo)'), 'Private response and Admin authorization');
foreach (['admin-users.php', 'admin-medical-records.php', 'admin-stray-reports.php',
    'admin-news.php', 'admin-community.php', 'admin-pets.php', 'admin-pet-images.php',
    'admin-adoption-applications.php', 'admin-chat.php'] as $endpoint) {
    $source = file_get_contents(dirname(__DIR__) . '/public/api/' . $endpoint);
    auditCheck(is_string($source) && str_contains($source, 'adminAuditWrite($pdo,'),
        'Missing transactional audit path in ' . $endpoint);
    auditCheck(!str_contains($source, 'INSERT INTO audit_logs'),
        'Duplicated raw audit insertion in ' . $endpoint);
}

echo "Administrator Audit Log whitelist, minimization, display, and read-query checks passed.\n";
