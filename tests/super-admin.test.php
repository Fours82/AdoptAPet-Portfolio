<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/public/api/admin-role-governance-service.php';

function superCheck(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException('Super Administrator test failed: ' . $label);
    }
}

superCheck(isKnownAuthenticatedRole('registered_user') && isOperationalAdministratorRole('administrator')
    && isOperationalAdministratorRole('super_administrator') && isSuperAdministratorRole('super_administrator'),
    'known role hierarchy');
superCheck(!isKnownAuthenticatedRole('unexpected_role') && !isOperationalAdministratorRole('registered_user'),
    'unknown and Registered User roles are not operational Admins');
superCheck(!isSuperAdministratorRole('administrator') && !isSuperAdministratorRole('unexpected_role'),
    'normal Administrator and unknown roles cannot govern');
try {
    signInUser(1, 'unexpected_role', 'test-only-hash');
    throw new RuntimeException('Unknown role signed in.');
} catch (InvalidArgumentException $expectedException) {
}

$expected = [
    'promote_user' => ['registered_user', 'active', 'administrator', 'active'],
    'demote_admin' => ['administrator', 'active', 'registered_user', 'active'],
    'deactivate_admin' => ['administrator', 'active', 'administrator', 'deactivated'],
    'reactivate_admin' => ['administrator', 'deactivated', 'administrator', 'active'],
    'promote_admin' => ['administrator', 'active', 'super_administrator', 'active'],
    'demote_super' => ['super_administrator', 'active', 'administrator', 'active'],
    'deactivate_super' => ['super_administrator', 'active', 'super_administrator', 'deactivated'],
    'reactivate_super' => ['super_administrator', 'deactivated', 'super_administrator', 'active'],
];
foreach ($expected as $action => $transition) {
    superCheck(array_slice(adminRoleGovernanceTransition($action), 0, 4) === $transition,
        'fixed transition ' . $action);
    [$role, $status] = $transition;
    $target = ['id' => 20, 'role' => $role, 'account_status' => $status,
        'deleted_at' => null, 'email_verified' => true];
    superCheck(adminRoleGovernanceAllowed($action, $target, 10, 2, true) === null,
        'eligible transition ' . $action);
    superCheck(adminRoleGovernanceAllowed($action, $target, 20, 2, true) !== null,
        'self-management rejected ' . $action);
    $target['account_status'] = 'suspended';
    superCheck(adminRoleGovernanceAllowed($action, $target, 10, 2, true) !== null,
        'stale status rejected ' . $action);
}
foreach (['demote_super', 'deactivate_super'] as $action) {
    $target = ['id' => 20, 'role' => 'super_administrator', 'account_status' => 'active',
        'deleted_at' => null, 'email_verified' => true];
    superCheck(adminRoleGovernanceAllowed($action, $target, 10, 1, true) !== null,
        'last active Super Administrator retained');
}
superCheck(adminRoleGovernanceAllowed('promote_admin', [
    'id' => 20, 'role' => 'administrator', 'account_status' => 'active',
    'deleted_at' => null, 'email_verified' => true,
], 10, 1, false) !== null, 'Super promotion requires existing MFA');
superCheck(adminRoleGovernanceAllowed('promote_user', [
    'id' => 20, 'role' => 'registered_user', 'account_status' => 'active',
    'deleted_at' => null, 'email_verified' => false,
], 10, 2, true) !== null, 'promotion to Administrator still requires verified email');
foreach ([false, true] as $emailVerified) {
    superCheck(adminRoleGovernanceAllowed('demote_admin', [
        'id' => 20, 'role' => 'administrator', 'account_status' => 'active',
        'deleted_at' => null, 'email_verified' => $emailVerified,
    ], 10, 2, true) === null, 'Administrator demotion does not depend on email verification');
}
try {
    adminRoleGovernanceTransition('arbitrary_role_change');
    throw new RuntimeException('Arbitrary transition accepted.');
} catch (InvalidArgumentException $expectedException) {
}
superCheck(adminAuditSafeValues(['role' => 'super_administrator']) === ['role' => 'super_administrator'],
    'bounded audit role');
try {
    adminAuditSafeValues(['role' => 'root']);
    throw new RuntimeException('Unsafe audit role accepted.');
} catch (InvalidArgumentException $expectedException) {
}
$audit = adminAuditDisplayRow([
    'id' => 1, 'created_at' => '2026-09-13 00:00:00', 'entity_type' => 'user',
    'entity_id' => 20, 'target_username' => 'casey',
    'action_type' => 'administrator_promoted_to_super_admin',
    'old_values_json' => '{"private":"must-not-display"}',
]);
superCheck($audit['target'] === '@casey'
    && $audit['details'] === 'Promoted @casey to Super Administrator.'
    && !str_contains(json_encode($audit), 'must-not-display'), 'safe audit display');

$service = file_get_contents(dirname(__DIR__) . '/public/api/admin-role-governance-service.php');
$endpoint = file_get_contents(dirname(__DIR__) . '/public/api/admin-role-governance.php');
$mfaEndpoint = file_get_contents(dirname(__DIR__) . '/public/api/admin-mfa.php');
superCheck(is_string($service) && str_contains($service, 'ORDER BY id FOR UPDATE')
    && str_contains($service, 'securityRevokeAllSessions($pdo, $targetId,')
    && str_contains($service, 'adminAuditWrite($pdo, $auditAction, $targetId,')
    && str_contains($service, 'DELETE FROM administrator_mfa_settings')
    && !str_contains($service, 'UPDATE email_verifications')
    && !str_contains($service, 'INSERT INTO email_verifications'),
    'locking, revocation, MFA cleanup, and audit paths');
superCheck(is_string($endpoint) && str_contains($endpoint, 'requireValidCsrfToken()')
    && str_contains($endpoint, 'isSuperAdministratorRole(')
    && str_contains($endpoint, 'adminRoleGovernanceApply($pdo,')
    && !str_contains($endpoint, 'bootstrap'), 'Super-only CSRF-protected endpoint without bootstrap');
superCheck(is_string($mfaEndpoint)
    && str_contains($mfaEndpoint, "\$user['role'] === \$expectedRole")
    && str_contains($mfaEndpoint, "\$account['role'] !== \$pending['role']"),
    'pending MFA binds to the password-stage role and rejects a changed live role');

foreach ([
    'admin-pets.php', 'admin-pet-images.php', 'admin-medical-records.php',
    'admin-stray-reports.php', 'admin-news.php', 'admin-community.php',
    'admin-chat.php', 'admin-users.php',
] as $endpointName) {
    $source = file_get_contents(dirname(__DIR__) . '/public/api/' . $endpointName);
    superCheck(is_string($source) && str_contains($source, 'isOperationalAdministratorRole('),
        'both privileged roles retain ' . $endpointName);
}
foreach (['admin-analytics.php', 'admin-adoption-applications.php'] as $endpointName) {
    $source = file_get_contents(dirname(__DIR__) . '/public/api/' . $endpointName);
    superCheck(is_string($source) && str_contains($source, 'requireOperationalAdministrator($pdo)'),
        'shared privileged-role gate retains ' . $endpointName);
}
foreach (['adoption-applications.php', 'documents.php', 'donations.php',
    'payment-verification.php', 'community.php', 'chat.php', 'stray-reports.php'] as $endpointName) {
    $source = file_get_contents(dirname(__DIR__) . '/public/api/' . $endpointName);
    superCheck(is_string($source) && str_contains($source, 'verified_at IS NOT NULL'),
        'verified-user feature gate remains in ' . $endpointName);
}

echo "Super Administrator roles, transitions, safeguards, audit, and endpoint checks passed.\n";
