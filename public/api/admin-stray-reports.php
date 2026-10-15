<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/admin-audit-service.php';
require_once __DIR__ . '/notification-service.php';
require_once __DIR__ . '/stray-status-service.php';

const ADMIN_STRAY_STATUSES = ['submitted', 'under_review', 'assigned', 'resolved', 'closed'];

function requireStrayAdministrator(PDO $pdo): int
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

function adminStrayTextLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function adminStrayId(): ?int
{
    if (!array_key_exists('id', $_GET)) {
        return null;
    }
    $value = $_GET['id'];
    if (!is_string($value) || !ctype_digit($value) || (int) $value < 1) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'A valid Stray Report ID is required.']);
        exit;
    }
    return (int) $value;
}

function adminStrayReference(int $id): string
{
    return 'STR-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
}

function adminStrayStatusLabel(string $status): string
{
    return [
        'submitted' => 'Reported',
        'under_review' => 'Acknowledged',
        'assigned' => 'Rescue in Progress',
        'resolved' => 'Resolved',
        'closed' => 'Unable to Locate',
    ][$status] ?? 'Reported';
}

function adminStraySelect(): string
{
    return 'SELECT sr.id, sr.reporter_user_id, sr.animal_type, sr.breed, sr.colour,
                   sr.size, sr.sex, sr.condition_category, sr.description, sr.condition_notes,
                   sr.admin_notes, sr.location_text, sr.latitude, sr.longitude, sr.urgency,
                   sr.report_status, sr.submitted_at, sr.resolved_at, sr.updated_at,
                   u.name AS reporter_name, u.email AS reporter_email,
                   (SELECT sri.file_path FROM stray_report_images sri
                    WHERE sri.stray_report_id = sr.id
                    ORDER BY sri.sort_order ASC, sri.id ASC LIMIT 1) AS image_path
            FROM stray_reports sr
            JOIN users u ON u.id = sr.reporter_user_id';
}

function formatAdminStrayReport(array $report): array
{
    $report['id'] = (int) $report['id'];
    $report['reporter_user_id'] = (int) $report['reporter_user_id'];
    $report['latitude'] = $report['latitude'] === null ? null : (float) $report['latitude'];
    $report['longitude'] = $report['longitude'] === null ? null : (float) $report['longitude'];
    $report['reference'] = adminStrayReference($report['id']);
    return $report;
}

function adminStrayImages(PDO $pdo, int $reportId): array
{
    $statement = $pdo->prepare(
        'SELECT file_path, original_filename, sort_order
         FROM stray_report_images
         WHERE stray_report_id = :id
         ORDER BY sort_order ASC, id ASC'
    );
    $statement->execute(['id' => $reportId]);
    return array_map(
        static fn (array $image): array => [
            'file_path' => $image['file_path'],
            'original_filename' => $image['original_filename'],
            'sort_order' => (int) $image['sort_order'],
        ],
        $statement->fetchAll()
    );
}

$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'PATCH'], true)) {
    http_response_code(405);
    header('Allow: GET, PATCH');
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    $administratorId = requireStrayAdministrator($pdo);
    if ($method !== 'GET') {
        requireValidCsrfToken();
    }
    $reportId = adminStrayId();

    if ($method === 'GET') {
        $allowedParameters = ['id', 'q', 'status', 'urgent'];
        if (array_diff(array_keys($_GET), $allowedParameters) !== []) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Unsupported Stray Report management filter.']);
            exit;
        }

        if ($reportId !== null) {
            if (count($_GET) !== 1) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Report detail cannot be combined with filters.']);
                exit;
            }
            $statement = $pdo->prepare(adminStraySelect() . ' WHERE sr.id = :id LIMIT 1');
            $statement->execute(['id' => $reportId]);
            $report = $statement->fetch();
            if (!$report) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Stray Report not found.']);
                exit;
            }
            $report = formatAdminStrayReport($report);
            $report['images'] = adminStrayImages($pdo, $reportId);
            echo json_encode(['success' => true, 'report' => $report]);
            exit;
        }

        foreach (['q', 'status', 'urgent'] as $parameter) {
            if (isset($_GET[$parameter]) && !is_string($_GET[$parameter])) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Stray Report filters must be text values.']);
                exit;
            }
        }
        $query = trim($_GET['q'] ?? '');
        $status = strtolower(trim($_GET['status'] ?? ''));
        $urgent = trim($_GET['urgent'] ?? '');
        if (adminStrayTextLength($query) > 100) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Search text must be 100 characters or fewer.']);
            exit;
        }
        if ($status !== '' && !in_array($status, ADMIN_STRAY_STATUSES, true)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Select a valid report status.']);
            exit;
        }
        if ($urgent !== '' && $urgent !== '1') {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Select a valid urgency filter.']);
            exit;
        }

        $filters = [];
        $parameters = [];
        if ($query !== '') {
            $filters[] = "(CONCAT('STR-', LPAD(sr.id, 6, '0')) LIKE :reference_query
                          OR sr.location_text LIKE :location_query
                          OR sr.description LIKE :description_query
                          OR u.name LIKE :reporter_query)";
            $parameters['reference_query'] = '%' . $query . '%';
            $parameters['location_query'] = '%' . $query . '%';
            $parameters['description_query'] = '%' . $query . '%';
            $parameters['reporter_query'] = '%' . $query . '%';
        }
        if ($status !== '') {
            $filters[] = 'sr.report_status = :report_status';
            $parameters['report_status'] = $status;
        }
        if ($urgent === '1') {
            $filters[] = "sr.urgency IN ('high', 'critical')";
        }

        $sql = adminStraySelect();
        if ($filters !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $filters);
        }
        $sql .= " ORDER BY FIELD(sr.urgency, 'critical', 'high', 'medium', 'low'), sr.updated_at DESC, sr.id DESC";
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);
        $reports = array_map('formatAdminStrayReport', $statement->fetchAll());
        echo json_encode(['success' => true, 'reports' => $reports, 'count' => count($reports)]);
        exit;
    }

    if ($reportId === null || count($_GET) !== 1) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'A Stray Report ID is required.']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid JSON request body.']);
        exit;
    }
    if (array_diff(array_keys($input), ['report_status', 'admin_notes', 'expected_status', 'expected_admin_notes']) !== []) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Unsupported Stray Report update field.']);
        exit;
    }
    if (!isset($input['report_status'], $input['expected_status'], $input['expected_admin_notes'])
        || !is_string($input['report_status']) || !is_string($input['expected_status'])
        || !is_string($input['expected_admin_notes'])
        || (array_key_exists('admin_notes', $input) && !is_string($input['admin_notes']))) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Stray Report update values must be text.']);
        exit;
    }
    $newStatus = strtolower(trim($input['report_status']));
    $expectedStatus = strtolower(trim($input['expected_status']));
    $expectedNotes = trim($input['expected_admin_notes']);
    $notesProvided = array_key_exists('admin_notes', $input);
    $adminNotes = $notesProvided ? trim($input['admin_notes']) : '';
    if (!in_array($newStatus, ADMIN_STRAY_STATUSES, true)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Select a valid report status.']);
        exit;
    }
    if (adminStrayTextLength($adminNotes) > 2000 || adminStrayTextLength($expectedNotes) > 2000) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Admin/rescue note must be 2,000 characters or fewer.']);
        exit;
    }

    $pdo->beginTransaction();
    $statement = $pdo->prepare(
        'SELECT id, reporter_user_id, report_status, admin_notes
         FROM stray_reports WHERE id = :id LIMIT 1 FOR UPDATE'
    );
    $statement->execute(['id' => $reportId]);
    $existing = $statement->fetch();
    if (!$existing) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Stray Report not found.']);
        exit;
    }

    $oldStatus = (string) $existing['report_status'];
    $oldNotes = trim((string) ($existing['admin_notes'] ?? ''));
    if ($oldStatus !== $expectedStatus || $oldNotes !== $expectedNotes) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'This Stray Report changed since it was opened. Reopen it before saving.']);
        exit;
    }
    if (!strayStatusTransitionAllowed($oldStatus, $newStatus)) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'That Stray Report status transition is not allowed.']);
        exit;
    }
    if (!$notesProvided) {
        $adminNotes = $oldNotes;
    }
    $statusChanged = $oldStatus !== $newStatus;
    $notesChanged = $notesProvided && $oldNotes !== $adminNotes;
    if (!$statusChanged && !$notesChanged) {
        $pdo->rollBack();
        echo json_encode([
            'success' => true,
            'changed' => false,
            'notification_created' => false,
            'message' => 'No Stray Report changes were needed.',
        ]);
        exit;
    }

    $statement = $pdo->prepare(
        'UPDATE stray_reports
         SET report_status = :report_status,
             admin_notes = CASE WHEN :update_notes = 1 THEN :admin_notes ELSE admin_notes END,
             resolved_at = CASE
                 WHEN :set_resolved = 1 THEN NOW()
                 WHEN :keep_resolved = 1 THEN resolved_at
                 ELSE NULL
             END,
             updated_at = NOW()
         WHERE id = :id'
    );
    $resolvedMode = strayResolvedTimestampMode($oldStatus, $newStatus);
    $statement->execute([
        'report_status' => $newStatus,
        'admin_notes' => $adminNotes === '' ? null : $adminNotes,
        'update_notes' => $notesProvided ? 1 : 0,
        'set_resolved' => $resolvedMode['set_resolved'],
        'keep_resolved' => $resolvedMode['keep_resolved'],
        'id' => $reportId,
    ]);

    adminAuditWrite($pdo, 'stray_report_updated', $reportId,
        ['status' => $oldStatus], ['status' => $newStatus, 'notes_changed' => $notesChanged]);

    if ($statusChanged) {
        createNotificationRecord(
            $pdo,
            (int) $existing['reporter_user_id'],
            'stray_report_status',
            'Your stray report ' . adminStrayReference($reportId) . ' is now ' . adminStrayStatusLabel($newStatus) . '.',
            '#/stray/' . $reportId
        );
    }

    $pdo->commit();
    echo json_encode([
        'success' => true,
        'changed' => true,
        'notification_created' => $statusChanged,
        'message' => $statusChanged
            ? 'Stray Report status updated.'
            : 'Admin/rescue note updated.',
    ]);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to manage Stray Reports. Please try again later.']);
}
