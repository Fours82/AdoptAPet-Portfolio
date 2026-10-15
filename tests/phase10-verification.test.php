<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/public/api/email-verification-service.php';

function phase10Verify(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException('Phase 10 verification test failed: ' . $label);
    }
}

$pending = ['id' => 10, 'role' => 'registered_user', 'account_status' => 'deactivated', 'deleted_at' => null];
phase10Verify(emailVerificationRegistrationStateIsEligible($pending, false, false), 'untouched pending registration');
phase10Verify(!emailVerificationRegistrationStateIsEligible($pending, true, false), 'previously verified account');
phase10Verify(!emailVerificationRegistrationStateIsEligible($pending, false, true), 'administratively managed account');
foreach (['active', 'suspended'] as $status) {
    $changed = $pending;
    $changed['account_status'] = $status;
    phase10Verify(!emailVerificationRegistrationStateIsEligible($changed, false, false), 'reject ' . $status);
}
foreach (['administrator', 'super_administrator', 'unexpected_role'] as $role) {
    $changed = $pending;
    $changed['role'] = $role;
    phase10Verify(!emailVerificationRegistrationStateIsEligible($changed, false, false), 'reject ' . $role);
}
$deleted = $pending;
$deleted['deleted_at'] = '2026-09-14 00:00:00';
phase10Verify(!emailVerificationRegistrationStateIsEligible($deleted, false, false), 'reject deleted account');

$verify = file_get_contents(dirname(__DIR__) . '/public/api/verify-email.php');
$resend = file_get_contents(dirname(__DIR__) . '/public/api/resend-verification.php');
$management = file_get_contents(dirname(__DIR__) . '/public/api/admin-users.php');
phase10Verify(is_string($management)
    && str_contains($management, "securityRecordEvent(\$pdo, \$id, 'account_access_changed', false)"),
    'administrator status changes leave a verification-blocking management event');
phase10Verify(is_string($verify) && str_contains($verify, '$pdo->beginTransaction()')
    && str_contains($verify, 'FOR UPDATE')
    && str_contains($verify, 'attempts < 5 AND expires_at > NOW()')
    && str_contains($verify, 'emailVerificationPendingRegistrationIsEligible($pdo, $user)')
    && str_contains($verify, "'role' => 'registered_user', 'pending_status' => 'deactivated'")
    && str_contains($verify, '$activateUser->rowCount() !== 1'),
    'verification locks and conditional activation');
phase10Verify(strpos($verify, '$pdo->beginTransaction()') < strpos($verify, 'LIMIT 1 FOR UPDATE')
    && strpos($verify, 'LIMIT 1 FOR UPDATE') < strpos($verify, "(int) \$verification['attempts'] >= 5"),
    'attempt limit is enforced after entering the transaction and taking locks');
phase10Verify(is_string($resend) && str_contains($resend, '$pdo->beginTransaction()')
    && str_contains($resend, 'FOR UPDATE')
    && str_contains($resend, 'emailVerificationPendingRegistrationIsEligible($pdo, $user)'),
    'resend serializes and applies the same eligibility policy');

echo "Phase 10 verification eligibility and transactional guards passed.\n";
