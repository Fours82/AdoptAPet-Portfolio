<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/admin-audit-service.php';

function requireUserManagementAdministrator(PDO $pdo): int
{
    $userId = authenticatedUserId();

    if ($userId === null) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Authentication is required.']);
        exit;
    }

    $statement = $pdo->prepare(
        'SELECT role FROM users WHERE id = :id AND account_status = :status AND deleted_at IS NULL LIMIT 1'
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

function userManagementTextLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}

function formatManagedUser(array $user): array
{
    return [
        'id' => (int) $user['id'],
        'username' => $user['username'],
        'name' => $user['name'],
        'email' => $user['email'],
        'role' => $user['role'],
        'account_status' => $user['account_status'],
        'email_verified' => (bool) $user['email_verified'],
        'phone' => $user['phone'],
        'location' => $user['location'],
        'failed_login_count' => (int) $user['failed_login_count'],
        'locked_until' => $user['locked_until'],
        'last_login_at' => $user['last_login_at'],
        'created_at' => $user['created_at'],
        'updated_at' => $user['updated_at'],
    ];
}

function managedUserSelect(): string
{
    return 'SELECT u.id, u.username, u.name, u.email, u.role, u.account_status, u.failed_login_count,
            u.locked_until, u.last_login_at, u.created_at, u.updated_at,
            p.phone, p.location,
            EXISTS (
                SELECT 1 FROM email_verifications ev
                WHERE ev.user_id = u.id AND ev.verified_at IS NOT NULL
            ) AS email_verified
        FROM users u
        LEFT JOIN user_profiles p ON p.user_id = u.id
        WHERE u.role = :registered_role AND u.deleted_at IS NULL';
}

function validManagedUserId(): ?int
{
    if (!isset($_GET['id'])) {
        return null;
    }

    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false || $id === null) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'A valid registered-user ID is required.']);
        exit;
    }

    return (int) $id;
}

try {
    $pdo = getDatabaseConnection();
    $administratorId = requireUserManagementAdministrator($pdo);
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method !== 'GET') {
        requireValidCsrfToken();
    }

    if ($method === 'GET') {
        $id = validManagedUserId();
        $query = trim((string) ($_GET['q'] ?? ''));
        $status = strtolower(trim((string) ($_GET['status'] ?? '')));

        if (userManagementTextLength($query) > 255) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Search text must be 255 characters or fewer.']);
            exit;
        }

        if ($status !== '' && !in_array($status, ['active', 'suspended', 'deactivated'], true)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Select a valid account status.']);
            exit;
        }

        $sql = managedUserSelect();
        $parameters = ['registered_role' => 'registered_user'];

        if ($id !== null) {
            $sql .= ' AND u.id = :id LIMIT 1';
            $parameters['id'] = $id;
            $statement = $pdo->prepare($sql);
            $statement->execute($parameters);
            $user = $statement->fetch();

            if (!$user) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Registered-user account not found.']);
                exit;
            }

            echo json_encode(['success' => true, 'user' => formatManagedUser($user)]);
            exit;
        }

        if ($query !== '') {
            $sql .= ' AND (u.username LIKE :username_query OR u.name LIKE :name_query OR u.email LIKE :email_query)';
            $parameters['username_query'] = '%' . $query . '%';
            $parameters['name_query'] = '%' . $query . '%';
            $parameters['email_query'] = '%' . $query . '%';
        }

        if ($status !== '') {
            $sql .= ' AND u.account_status = :account_status';
            $parameters['account_status'] = $status;
        }

        $sql .= ' ORDER BY u.created_at DESC, u.id DESC';
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);
        $users = array_map('formatManagedUser', $statement->fetchAll());

        echo json_encode(['success' => true, 'users' => $users]);
        exit;
    }

    if ($method !== 'PATCH') {
        http_response_code(405);
        header('Allow: GET, PATCH');
        echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
        exit;
    }

    $id = validManagedUserId();
    if ($id === null) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'A valid registered-user ID is required.']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON request body.']);
        exit;
    }

    $unexpectedFields = array_diff(array_keys($input), ['account_status']);
    if ($unexpectedFields !== [] || !array_key_exists('account_status', $input)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Only the account status can be changed.']);
        exit;
    }

    $newStatus = strtolower(trim((string) $input['account_status']));
    if (!in_array($newStatus, ['active', 'suspended', 'deactivated'], true)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Select a valid account status.']);
        exit;
    }

    $pdo->beginTransaction();

    $statement = $pdo->prepare(
        'SELECT u.id, u.account_status,
         EXISTS (
             SELECT 1 FROM email_verifications ev
             WHERE ev.user_id = u.id AND ev.verified_at IS NOT NULL
         ) AS email_verified
         FROM users u
         WHERE u.id = :id AND u.role = :registered_role AND u.deleted_at IS NULL
         LIMIT 1 FOR UPDATE'
    );
    $statement->execute(['id' => $id, 'registered_role' => 'registered_user']);
    $target = $statement->fetch();

    if (!$target) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Registered-user account not found.']);
        exit;
    }

    $oldStatus = $target['account_status'];
    if ($oldStatus === $newStatus) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'The account already has that status.']);
        exit;
    }

    $allowedTransitions = [
        'active' => ['suspended', 'deactivated'],
        'suspended' => ['active', 'deactivated'],
        'deactivated' => ['active'],
    ];

    if (!in_array($newStatus, $allowedTransitions[$oldStatus] ?? [], true)) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'That account status change is not allowed.']);
        exit;
    }

    if ($newStatus === 'active' && !(bool) $target['email_verified']) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Email verification is required before this account can be reactivated.']);
        exit;
    }

    $statement = $pdo->prepare('UPDATE users SET account_status = :account_status WHERE id = :id');
    $statement->execute(['account_status' => $newStatus, 'id' => $id]);
    if ($newStatus !== 'active') {
        securityRevokeAllSessions($pdo, $id, 'account_status_changed');
        securityRecordEvent($pdo, $id, 'account_access_changed', false);
    }

    adminAuditWrite($pdo, 'user_status_updated', $id, ['status' => $oldStatus], ['status' => $newStatus]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Registered-user account status updated successfully.',
        'user' => ['id' => $id, 'account_status' => $newStatus],
    ]);
} catch (JsonException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to update the account status. Please try again later.']);
} catch (PDOException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to manage registered-user accounts. Please try again later.']);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to manage registered-user accounts. Please try again later.']);
}
