<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';

function securityReply(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body);
    exit;
}

function securityUtcIso(?string $value): ?string
{
    return $value === null ? null : str_replace(' ', 'T', $value) . 'Z';
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    securityReply(405, ['success' => false, 'message' => 'Method not allowed.']);
}

$userId = authenticatedUserId();
if ($userId === null) {
    securityReply(401, ['success' => false, 'message' => 'Authentication is required.']);
}
if ($method === 'POST') {
    requireValidCsrfToken();
}

try {
    $pdo = getDatabaseConnection();
    $account = $pdo->prepare(
        'SELECT role FROM users WHERE id = :id AND account_status = :status AND deleted_at IS NULL LIMIT 1'
    );
    $account->execute(['id' => $userId, 'status' => 'active']);
    $role = $account->fetchColumn();
    if (!isKnownAuthenticatedRole($role)) {
        securityReply(403, ['success' => false, 'message' => 'An active account is required.']);
    }
    $currentId = securityCurrentRegistryId();

    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            securityReply(400, ['success' => false, 'message' => 'Invalid request.']);
        }
        $action = $input['action'] ?? null;
        if ($action === 'revoke') {
            $target = filter_var($input['session_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($target === false || $target === null || (int) $target === $currentId) {
                securityReply(422, ['success' => false, 'message' => 'Select another active session.']);
            }
            $pdo->beginTransaction();
            if (!securityRevokeOneOtherSession($pdo, $userId, $currentId, (int) $target)) {
                $pdo->rollBack();
                securityReply(404, ['success' => false, 'message' => 'That active session was not found.']);
            }
            securityRecordEvent($pdo, $userId, 'session_revoked');
            $pdo->commit();
            securityReply(200, ['success' => true, 'revoked_id' => (int) $target]);
        }
        if ($action === 'revoke_others') {
            $pdo->beginTransaction();
            $count = securityRevokeOtherSessions($pdo, $userId, $currentId, 'owner_revoked_others');
            securityRecordEvent($pdo, $userId, 'other_sessions_revoked');
            $pdo->commit();
            securityReply(200, ['success' => true, 'revoked_count' => $count]);
        }
        securityReply(400, ['success' => false, 'message' => 'Invalid action.']);
    }

    $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($page === false || $page === null) {
        securityReply(422, ['success' => false, 'message' => 'Invalid page.']);
    }
    $count = $pdo->prepare('SELECT COUNT(*) FROM user_security_events WHERE user_id = :user_id');
    $count->execute(['user_id' => $userId]);
    $totalEvents = (int) $count->fetchColumn();
    $totalPages = max(1, (int) ceil($totalEvents / 10));
    $page = min((int) $page, $totalPages);

    $sessions = $pdo->prepare(
        'SELECT id, created_at, last_active_at, user_agent, ip_address
         FROM user_auth_sessions
         WHERE user_id = :user_id AND revoked_at IS NULL
           AND absolute_expires_at > UTC_TIMESTAMP()
           AND (last_active_at > UTC_TIMESTAMP() - INTERVAL 30 MINUTE OR id = :current_id)
         ORDER BY CASE WHEN id = :sort_current_id THEN 1 ELSE 0 END DESC,
                  last_active_at DESC, id DESC LIMIT 100'
    );
    $sessions->execute(['user_id' => $userId, 'current_id' => $currentId, 'sort_current_id' => $currentId]);
    $sessionList = [];
    foreach ($sessions->fetchAll() as $row) {
        $sessionList[] = [
            'id' => (int) $row['id'],
            'is_current' => (int) $row['id'] === $currentId,
            'device' => securityDeviceSummary($row['user_agent']),
            'ip' => securityApproximateIp($row['ip_address']),
            'created_at' => securityUtcIso($row['created_at']),
            'last_active_at' => securityUtcIso($row['last_active_at']),
        ];
    }

    $events = $pdo->prepare(
        'SELECT id, event_type, occurred_at, device_summary, ip_address
         FROM user_security_events WHERE user_id = :user_id
         ORDER BY occurred_at DESC, id DESC LIMIT 10 OFFSET :offset'
    );
    $events->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $events->bindValue(':offset', ($page - 1) * 10, PDO::PARAM_INT);
    $events->execute();
    $eventList = [];
    foreach ($events->fetchAll() as $row) {
        $eventList[] = [
            'id' => (int) $row['id'],
            'type' => $row['event_type'],
            'occurred_at' => securityUtcIso($row['occurred_at']),
            'device' => $row['device_summary'],
            'ip' => securityApproximateIp($row['ip_address']),
        ];
    }
    securityReply(200, [
        'success' => true, 'sessions' => $sessionList, 'events' => $eventList,
        'page' => $page, 'total_pages' => $totalPages,
    ]);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    securityReply(500, ['success' => false, 'message' => 'Security information is temporarily unavailable.']);
}
