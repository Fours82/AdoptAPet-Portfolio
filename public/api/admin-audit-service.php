<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

/** Operational events only. Account-owner security events belong to user_security_events. */
const ADMIN_AUDIT_ACTION_ENTITIES = [
    'user_status_updated' => 'user',
    'medical_record_created' => 'medical_record',
    'medical_record_updated' => 'medical_record',
    'medical_record_deleted' => 'medical_record',
    'stray_report_updated' => 'stray_report',
    'news_created' => 'news_post',
    'news_updated' => 'news_post',
    'news_archived' => 'news_post',
    'community_post_removed' => 'community_post',
    'pet_created' => 'pet',
    'pet_updated' => 'pet',
    'pet_status_updated' => 'pet',
    'pet_deactivated' => 'pet',
    'pet_reactivated' => 'pet',
    'pet_image_added' => 'pet',
    'pet_primary_image_changed' => 'pet',
    'pet_image_removed' => 'pet',
    'application_approved' => 'adoption_application',
    'application_rejected' => 'adoption_application',
    'support_ticket_closed' => 'chat_conversation',
    'user_promoted_to_admin' => 'user',
    'administrator_demoted' => 'user',
    'administrator_deactivated' => 'user',
    'administrator_reactivated' => 'user',
    'administrator_promoted_to_super_admin' => 'user',
    'first_super_admin_prepared' => 'user',
    'first_super_admin_finalized' => 'user',
    'super_admin_demoted' => 'user',
    'super_admin_deactivated' => 'user',
    'super_admin_reactivated' => 'user',
];

function adminAuditActorId(PDO $pdo): ?int
{
    return authenticatedOperationalAdministratorId($pdo);
}

/** Reject unexpected keys and values, even when accidentally supplied by an endpoint. */
function adminAuditSafeValues(?array $values): ?array
{
    if ($values === null) {
        return null;
    }
    $safe = [];
    foreach ($values as $key => $value) {
        if (in_array($key, ['status', 'record_type'], true)
            && is_string($value) && preg_match('/^[a-z_]{1,32}$/D', $value)) {
            $safe[$key] = $value;
        } elseif ($key === 'role' && isKnownAuthenticatedRole($value)) {
            $safe[$key] = $value;
        } elseif (in_array($key, ['pet_id', 'image_id', 'count'], true)
            && is_int($value) && $value >= ($key === 'count' ? 0 : 1)
            && ($key !== 'count' || $value <= 5)) {
            $safe[$key] = $value;
        } elseif ($key === 'notes_changed' && is_bool($value)) {
            $safe[$key] = $value;
        } else {
            throw new InvalidArgumentException('Unsupported audit detail.');
        }
    }
    return $safe;
}

function adminAuditWrite(PDO $pdo, string $action, int $entityId, ?array $old = null, ?array $new = null): void
{
    if (in_array($action, ['first_super_admin_prepared', 'first_super_admin_finalized'], true)) {
        throw new InvalidArgumentException('First Super Administrator audit actions are CLI-only.');
    }
    $entity = ADMIN_AUDIT_ACTION_ENTITIES[$action] ?? null;
    if ($entity === null || $entityId < 1) {
        throw new InvalidArgumentException('Unsupported administrator audit event.');
    }
    if (!$pdo->inTransaction()) {
        throw new LogicException('Administrator audit writes require the business transaction.');
    }
    $actor = adminAuditActorId($pdo);
    if ($actor === null) {
        throw new RuntimeException('Fully authenticated Administrator required for audit.');
    }
    $old = adminAuditSafeValues($old);
    $new = adminAuditSafeValues($new);
    $remoteIp = $_SERVER['REMOTE_ADDR'] ?? null;
    $ip = is_string($remoteIp) && strlen($remoteIp) <= 45 && filter_var($remoteIp, FILTER_VALIDATE_IP)
        ? $remoteIp : null;
    $statement = $pdo->prepare(
        'INSERT INTO audit_logs
         (actor_user_id, action_type, entity_type, entity_id, old_values_json, new_values_json, ip_address, created_at)
         VALUES (:actor, :action, :entity, :id, :old_values, :new_values, :ip, UTC_TIMESTAMP())'
    );
    $statement->execute([
        'actor' => $actor,
        'action' => $action,
        'entity' => $entity,
        'id' => $entityId,
        'old_values' => $old === null ? null : json_encode($old, JSON_THROW_ON_ERROR),
        'new_values' => $new === null ? null : json_encode($new, JSON_THROW_ON_ERROR),
        'ip' => $ip,
    ]);
}

/** A narrowly scoped, actorless audit event for the one-time CLI bootstrap only. */
function adminAuditWriteFirstSuperAdminBootstrap(PDO $pdo, string $action, int $entityId): void
{
    if (PHP_SAPI !== 'cli' || !$pdo->inTransaction() || $entityId < 1) {
        throw new LogicException('The first Super Administrator bootstrap audit requires its CLI transaction.');
    }
    $values = match ($action) {
        'first_super_admin_prepared' => [
            ['role' => 'registered_user', 'status' => 'active'],
            ['role' => 'administrator', 'status' => 'active'],
        ],
        'first_super_admin_finalized' => [
            ['role' => 'administrator', 'status' => 'active'],
            ['role' => 'super_administrator', 'status' => 'active'],
        ],
        default => throw new InvalidArgumentException('Unsupported bootstrap audit event.'),
    };
    $statement = $pdo->prepare(
        'INSERT INTO audit_logs
         (actor_user_id, action_type, entity_type, entity_id, old_values_json, new_values_json, ip_address, created_at)
         VALUES (NULL, :action, \'user\', :id, :old_values, :new_values, NULL, UTC_TIMESTAMP())'
    );
    $statement->execute([
        'action' => $action,
        'id' => $entityId,
        'old_values' => json_encode(adminAuditSafeValues($values[0]), JSON_THROW_ON_ERROR),
        'new_values' => json_encode(adminAuditSafeValues($values[1]), JSON_THROW_ON_ERROR),
    ]);
}

/** Bounded public-facing labels; the frontend escapes them before rendering. */
function adminAuditPublicLabel(mixed $value, int $limit = 80): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $value = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '');
    if ($value === '') {
        return null;
    }
    return function_exists('mb_substr') ? mb_substr($value, 0, $limit) : substr($value, 0, $limit);
}

/** Only whitelisted status codes and the note-change boolean affect display text. */
function adminAuditStrayDetails(array $row, string $targetId): string
{
    $decode = static function (mixed $value): array {
        if (!is_string($value) || strlen($value) > 2048) {
            return [];
        }
        $fields = json_decode($value, true, 4);
        return is_array($fields) ? $fields : [];
    };
    $old = $decode($row['stray_old_values_json'] ?? null);
    $new = $decode($row['stray_new_values_json'] ?? null);
    $statuses = [
        'submitted' => 'Reported', 'under_review' => 'Acknowledged',
        'assigned' => 'Rescue in Progress', 'resolved' => 'Resolved',
        'closed' => 'Unable to Locate',
    ];
    $oldStatus = $old['status'] ?? null;
    $newStatus = $new['status'] ?? null;
    $statusChanged = is_string($oldStatus) && is_string($newStatus)
        && isset($statuses[$oldStatus], $statuses[$newStatus]) && $oldStatus !== $newStatus;
    $notesChanged = ($new['notes_changed'] ?? null) === true;
    if ($statusChanged) {
        return 'Changed status from ' . $statuses[$oldStatus] . ' to ' . $statuses[$newStatus]
            . ($notesChanged ? ' and updated admin notes.' : '.');
    }
    if ($notesChanged) {
        return 'Updated admin notes for ' . $targetId . '.';
    }
    return 'Updated ' . $targetId . '.';
}

function adminAuditDisplayRow(array $row): array
{
    $labels = [
        'user_status_updated' => 'User status changed',
        'medical_record_created' => 'Medical record created',
        'medical_record_updated' => 'Medical record updated',
        'medical_record_deleted' => 'Medical record removed',
        'stray_report_updated' => 'Stray report updated',
        'news_created' => 'News created',
        'news_updated' => 'News updated',
        'news_archived' => 'News archived',
        'community_post_removed' => 'Community post removed',
        'pet_created' => 'Pet listing created',
        'pet_updated' => 'Pet listing updated',
        'pet_status_updated' => 'Pet status changed',
        'pet_deactivated' => 'Pet listing deactivated',
        'pet_reactivated' => 'Pet listing reactivated',
        'pet_image_added' => 'Pet image added',
        'pet_primary_image_changed' => 'Primary image changed',
        'pet_image_removed' => 'Pet image removed',
        'application_approved' => 'Application approved',
        'application_rejected' => 'Application rejected',
        'support_ticket_closed' => 'Support ticket closed',
        'user_promoted_to_admin' => 'User promoted to Administrator',
        'administrator_demoted' => 'Administrator demoted',
        'administrator_deactivated' => 'Administrator deactivated',
        'administrator_reactivated' => 'Administrator reactivated',
        'administrator_promoted_to_super_admin' => 'Administrator promoted to Super Administrator',
        'first_super_admin_prepared' => 'First Super Administrator preparation',
        'first_super_admin_finalized' => 'First Super Administrator initialized',
        'super_admin_demoted' => 'Super Administrator demoted',
        'super_admin_deactivated' => 'Super Administrator deactivated',
        'super_admin_reactivated' => 'Super Administrator reactivated',
    ];
    $modules = [
        'user' => 'User Management', 'medical_record' => 'Medical / Vaccination',
        'stray_report' => 'Stray Reports', 'news_post' => 'News',
        'community_post' => 'Community', 'pet' => 'Pet Listings',
        'adoption_application' => 'Adoption Applications', 'chat_conversation' => 'Live Chat',
    ];
    $action = (string) ($row['action_type'] ?? '');
    $entity = (string) ($row['entity_type'] ?? '');
    $id = isset($row['entity_id']) ? (int) $row['entity_id'] : null;
    $module = $modules[$entity] ?? 'Other Admin action';
    $label = $labels[$action] ?? 'Administrator action';
    $entities = [
        'user' => 'User', 'pet' => 'Pet', 'news_post' => 'News',
        'stray_report' => 'Stray Report', 'adoption_application' => 'Application',
        'community_post' => 'Post', 'medical_record' => 'Medical record',
        'chat_conversation' => 'Ticket',
    ];
    $targetId = $id !== null && $id > 0 ? ($entities[$entity] ?? 'Record') . ' #' . $id : '—';
    $target = $targetId;
    if ($id !== null && $id > 0) {
        if ($entity === 'user' && ($value = adminAuditPublicLabel($row['target_username'] ?? null)) !== null) {
            $target = '@' . $value;
        } elseif ($entity === 'pet' && ($value = adminAuditPublicLabel($row['target_pet_name'] ?? null)) !== null) {
            $target = $value;
        } elseif ($entity === 'news_post' && ($value = adminAuditPublicLabel($row['target_news_title'] ?? null)) !== null) {
            $target = '“' . $value . '”';
        } elseif ($entity === 'adoption_application') {
            $pet = adminAuditPublicLabel($row['application_pet_name'] ?? null);
            $applicant = adminAuditPublicLabel($row['application_username'] ?? null);
            if ($pet !== null || $applicant !== null) {
                $target = ($pet ?? $targetId) . ($applicant !== null ? ' · @' . $applicant : '');
            }
        } elseif ($entity === 'medical_record' && ($row['medical_record_type'] ?? null) !== null) {
            $types = ['vaccination' => 'Vaccination', 'treatment' => 'Treatment',
                'sterilization' => 'Sterilization', 'checkup' => 'Checkup', 'other' => 'Medical'];
            $type = $types[$row['medical_record_type'] ?? ''] ?? 'Medical';
            $pet = adminAuditPublicLabel($row['medical_pet_name'] ?? null);
            $target = $pet !== null ? $pet . ' · ' . $type : $type . ' record';
        } elseif ($entity === 'community_post' && ($author = adminAuditPublicLabel($row['community_author_username'] ?? null)) !== null) {
            $target = 'Post by @' . $author;
        } elseif ($entity === 'stray_report' && ($location = adminAuditPublicLabel($row['stray_location_text'] ?? null, 120)) !== null) {
            $target = $location;
        }
    }
    $verbs = [
        'user_status_updated' => 'Changed status for',
        'medical_record_created' => 'Created medical record for',
        'medical_record_updated' => 'Updated medical record for',
        'medical_record_deleted' => 'Removed medical record for',
        'stray_report_updated' => 'Updated',
        'news_created' => 'Created news article',
        'news_updated' => 'Updated news article',
        'news_archived' => 'Archived news article',
        'community_post_removed' => 'Removed',
        'pet_created' => 'Created pet listing',
        'pet_updated' => 'Updated pet listing',
        'pet_status_updated' => 'Changed pet status for',
        'pet_deactivated' => 'Deactivated pet listing',
        'pet_reactivated' => 'Reactivated pet listing',
        'pet_image_added' => 'Added an image to',
        'pet_primary_image_changed' => 'Changed the primary image for',
        'pet_image_removed' => 'Removed an image from',
        'application_approved' => 'Approved adoption application for',
        'application_rejected' => 'Rejected adoption application for',
        'support_ticket_closed' => 'Closed',
        'user_promoted_to_admin' => 'Promoted',
        'administrator_demoted' => 'Demoted',
        'administrator_deactivated' => 'Deactivated',
        'administrator_reactivated' => 'Reactivated',
        'administrator_promoted_to_super_admin' => 'Promoted',
        'first_super_admin_prepared' => 'Prepared',
        'first_super_admin_finalized' => 'Initialized',
        'super_admin_demoted' => 'Demoted',
        'super_admin_deactivated' => 'Deactivated',
        'super_admin_reactivated' => 'Reactivated',
    ];
    $details = ($verbs[$action] ?? $label) . ($target === '—' ? '.' : ' ' . $target . '.');
    $governanceSuffix = [
        'user_promoted_to_admin' => ' to Administrator',
        'administrator_demoted' => ' to Registered User',
        'administrator_deactivated' => ' Administrator',
        'administrator_reactivated' => ' Administrator',
        'administrator_promoted_to_super_admin' => ' to Super Administrator',
        'super_admin_demoted' => ' to Administrator',
        'super_admin_deactivated' => '',
        'super_admin_reactivated' => '',
    ];
    if (isset($governanceSuffix[$action])) {
        $suffix = $governanceSuffix[$action];
        $details = ($verbs[$action] ?? 'Updated') . ' ' . $target . $suffix . '.';
    }
    if (in_array($action, ['administrator_deactivated', 'administrator_reactivated', 'super_admin_deactivated', 'super_admin_reactivated'], true)) {
        $roleLabel = str_starts_with($action, 'super_') ? 'Super Administrator' : 'Administrator';
        $details = $verbs[$action] . ' ' . $roleLabel . ' ' . $target . '.';
    }
    if ($action === 'stray_report_updated' && $entity === 'stray_report') {
        $details = adminAuditStrayDetails($row, $targetId);
    }
    $username = $row['actor_username'] ?? null;
    $name = $row['actor_name'] ?? null;
    $isBootstrapAction = in_array($action, ['first_super_admin_prepared', 'first_super_admin_finalized'], true);
    $actor = $isBootstrapAction && ($row['actor_user_id'] ?? null) === null ? 'Installation CLI'
        : (is_string($username) && $username !== '' ? '@' . $username
            : (is_string($name) && $name !== '' ? $name : 'Former Administrator'));
    return [
        'id' => (int) $row['id'],
        'created_at' => (string) $row['created_at'],
        'actor' => $actor,
        'action' => $label,
        'module' => $module,
        'target' => $target,
        'target_id' => $targetId,
        'details' => $details,
    ];
}
