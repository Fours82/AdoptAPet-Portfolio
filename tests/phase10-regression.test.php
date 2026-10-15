<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/public/api/password-reset-service.php';
require_once dirname(__DIR__) . '/public/api/email-change-service.php';

function phase10Regression(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException('Phase 10 regression failed: ' . $label);
    }
}

function phase10Source(string $relativePath): string
{
    $source = file_get_contents(dirname(__DIR__) . '/' . $relativePath);
    if (!is_string($source)) {
        throw new RuntimeException('Missing source: ' . $relativePath);
    }
    return $source;
}

$token = generatePasswordResetToken();
$tokenHash = hashPasswordResetToken($token);
phase10Regression(passwordResetTokenIsWellFormed($token) && strlen($tokenHash) === 64,
    'high-entropy reset token shape and hash');
$request = [
    'reset_code_hash' => $tokenHash, 'used_at' => null,
    'is_expired' => 0, 'account_status' => 'active', 'deleted_at' => null,
];
phase10Regression(passwordResetRequestIsUsable($request, $tokenHash), 'unused active reset link');
foreach ([
    ['used_at' => '2026-09-14 00:00:00'],
    ['is_expired' => 1],
    ['account_status' => 'deactivated'],
    ['deleted_at' => '2026-09-14 00:00:00'],
] as $change) {
    phase10Regression(!passwordResetRequestIsUsable(array_replace($request, $change), $tokenHash),
        'used, expired, inactive or deleted reset link denied');
}
phase10Regression(!passwordResetRequestIsUsable($request, str_repeat('0', 64)), 'wrong reset token denied');

$forgot = phase10Source('public/api/request-password-reset.php');
$reset = phase10Source('public/api/reset-password.php');
phase10Regression(str_contains($forgot, 'If an account exists for that email')
    && str_contains($forgot, 'LIMIT 1 FOR UPDATE')
    && str_contains($forgot, 'created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)')
    && str_contains($forgot, 'sendMailWithFailureCleanup'), 'generic, serialized forgot-password cooldown');
phase10Regression(str_contains($reset, '$pdo->beginTransaction()')
    && str_contains($reset, 'findPasswordResetRequest($pdo, $tokenHash, true)')
    && str_contains($reset, 'SET used_at = NOW()')
    && str_contains($reset, 'securityRevokeAllSessions($pdo,'), 'single-use reset and session revocation');

$changeEmail = phase10Source('public/api/email-change.php');
phase10Regression(EMAIL_CHANGE_CODE_EXPIRY_SECONDS === 600
    && EMAIL_CHANGE_RESEND_COOLDOWN_SECONDS === 60 && EMAIL_CHANGE_MAX_ATTEMPTS === 5
    && str_contains($changeEmail, 'password_verify($currentPassword,')
    && str_contains($changeEmail, 'FOR UPDATE')
    && str_contains($changeEmail, 'securityRevokeOtherSessions($pdo,')
    && str_contains($changeEmail, 'requireValidCsrfToken()'), 'Change Email protections remain');

$mfa = phase10Source('public/api/admin-mfa.php');
phase10Regression(str_contains($mfa, "\$user['role'] === \$expectedRole")
    && str_contains($mfa, 'last_accepted_step')
    && str_contains($mfa, 'requireValidCsrfToken()'), 'Admin and Super MFA role binding, replay and CSRF');

foreach (['security.php', 'admin-role-governance.php', 'admin-users.php'] as $endpoint) {
    phase10Regression(str_contains(phase10Source('public/api/' . $endpoint), 'requireValidCsrfToken()'),
        'authenticated mutation CSRF in ' . $endpoint);
}
phase10Regression(!str_contains(phase10Source('public/api/admin-audit.php'), 'requireValidCsrfToken()')
    && str_contains(phase10Source('public/api/admin-audit.php'), "!== 'GET'"), 'Audit Log stays read-only');

$auth = phase10Source('public/api/auth.php');
$registry = phase10Source('public/api/security-session-service.php');
$governance = phase10Source('public/api/admin-role-governance-service.php');
$changePassword = phase10Source('public/api/change-password.php');
phase10Regression(str_contains($auth, "(\$_SESSION['role'] ?? null) !== \$user['role']")
    && str_contains($auth, 'No authenticated role may continue when its live role cannot be checked.')
    && str_contains($registry, 'revoked_at IS NULL AND absolute_expires_at > UTC_TIMESTAMP()')
    && str_contains($governance, 'securityRevokeAllSessions($pdo, $targetId,')
    && str_contains($changePassword, 'securityRevokeOtherSessions($pdo,'),
    'role mismatch, DB failure, registry revocation and password-change revocation');

$upload = phase10Source('public/api/image-upload.php');
$private = phase10Source('config/private-storage.php');
$documents = phase10Source('public/api/documents.php');
phase10Regression(str_contains($upload, 'IMAGE_UPLOAD_MAX_BYTES = 5 * 1024 * 1024')
    && str_contains($upload, 'new finfo(FILEINFO_MIME_TYPE)')
    && str_contains($upload, 'getimagesize($temporaryPath)')
    && str_contains($private, 'outsideWebRoot')
    && str_contains($documents, 'requireVerifiedDocumentUser($pdo)')
    && str_contains($documents, "header('Cache-Control: private, no-store, max-age=0')"),
    'upload and private document boundaries');

echo "Phase 10 recovery, email, MFA, CSRF, upload and document regressions passed.\n";
