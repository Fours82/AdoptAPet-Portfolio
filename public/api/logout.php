<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['success' => false, 'message' => 'Only POST requests are allowed.']);
    exit;
}

require_once __DIR__ . '/csrf.php';

startAuthenticationSession();
$userId = authenticatedUserId(false);
if ($userId !== null) {
    requireValidCsrfToken();
    try {
        $pdo = getDatabaseConnection();
        $pdo->beginTransaction();
        securityEndCurrentSession($pdo, $userId, 'logout');
        securityRecordEvent($pdo, $userId, 'logout');
        $pdo->commit();
    } catch (Throwable $exception) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        // A registry outage must never prevent local PHP-session logout.
        error_log('AdoptAPet session registry logout update failed.');
    }
    clearCsrfToken();
}
destroyAuthenticationSession();

echo json_encode(['success' => true, 'message' => 'Logged out successfully.']);
