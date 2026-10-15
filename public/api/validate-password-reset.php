<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['success' => false, 'message' => 'Only POST requests are allowed.']);
    exit;
}

require_once __DIR__ . '/password-reset-service.php';

$input = json_decode(file_get_contents('php://input'), true);
$token = is_array($input) ? trim((string) ($input['token'] ?? '')) : '';

if (!passwordResetTokenIsWellFormed($token)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => ADOPTAPET_PASSWORD_RESET_INVALID_MESSAGE]);
    exit;
}

try {
    $pdo = getDatabaseConnection();
    $tokenHash = hashPasswordResetToken($token);
    $request = findPasswordResetRequest($pdo, $tokenHash);

    if (!passwordResetRequestIsUsable($request, $tokenHash)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => ADOPTAPET_PASSWORD_RESET_INVALID_MESSAGE]);
        exit;
    }

    echo json_encode(['success' => true, 'valid' => true]);
} catch (PDOException $exception) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Unable to check this password reset link right now. Please try again.']);
}
