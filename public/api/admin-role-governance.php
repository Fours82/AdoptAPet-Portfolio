<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, no-store');
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/admin-role-governance-service.php';

function adminRoleGovernanceReply(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    adminRoleGovernanceReply(405, ['success' => false, 'message' => 'Method not allowed.']);
}
$actorId = authenticatedUserId();
if ($actorId === null) {
    adminRoleGovernanceReply(401, ['success' => false, 'message' => 'Authentication is required.']);
}
if ($method === 'POST') {
    requireValidCsrfToken();
}

try {
    $pdo = getDatabaseConnection();
    $actorStatement = $pdo->prepare(
        'SELECT role FROM users WHERE id = :id AND account_status = :status AND deleted_at IS NULL LIMIT 1'
    );
    $actorStatement->execute(['id' => $actorId, 'status' => 'active']);
    if (!isSuperAdministratorRole($actorStatement->fetchColumn())) {
        adminRoleGovernanceReply(403, ['success' => false, 'message' => 'Super Administrator access is required.']);
    }
    if ($method === 'GET') {
        if ($_GET !== []) {
            adminRoleGovernanceReply(422, ['success' => false, 'message' => 'Unsupported query parameter.']);
        }
        $statement = $pdo->query(
            "SELECT u.id, u.username, u.name, u.email, u.role, u.account_status,
                    EXISTS (SELECT 1 FROM email_verifications ev WHERE ev.user_id = u.id AND ev.verified_at IS NOT NULL) AS email_verified,
                    EXISTS (SELECT 1 FROM administrator_mfa_settings m WHERE m.user_id = u.id) AS has_mfa
             FROM users u WHERE u.role IN ('administrator', 'super_administrator') AND u.deleted_at IS NULL
             ORDER BY u.role DESC, u.username ASC, u.id ASC"
        );
        $users = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'], 'username' => $row['username'], 'name' => $row['name'],
            'email' => $row['email'], 'role' => $row['role'], 'account_status' => $row['account_status'],
            'has_mfa' => (bool) $row['has_mfa'], 'email_verified' => (bool) $row['email_verified'],
        ], $statement->fetchAll());
        adminRoleGovernanceReply(200, ['success' => true, 'users' => $users]);
    }
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input) || array_diff(array_keys($input), ['action', 'target_id']) !== []
        || count($input) !== 2 || !is_string($input['action'] ?? null)
        || !in_array($input['action'], ADMIN_ROLE_GOVERNANCE_ACTIONS, true)
        || !is_int($input['target_id'] ?? null) || $input['target_id'] < 1) {
        adminRoleGovernanceReply(422, ['success' => false, 'message' => 'Select a valid role-governance action and account.']);
    }
    $pdo->beginTransaction();
    $result = adminRoleGovernanceApply($pdo, $actorId, $input['target_id'], $input['action']);
    $pdo->commit();
    adminRoleGovernanceReply(200, ['success' => true, 'user' => $result, 'message' => 'Account access updated. All sessions for this account were signed out.']);
} catch (DomainException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $status = $exception->getMessage() === 'Super Administrator access is required.' ? 403 : 409;
    adminRoleGovernanceReply($status, ['success' => false, 'message' => $exception->getMessage()]);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    adminRoleGovernanceReply(500, ['success' => false, 'message' => 'Unable to update account access.']);
}
