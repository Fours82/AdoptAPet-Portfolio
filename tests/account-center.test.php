<?php
declare(strict_types=1);

function accountCenterCheck(bool $condition, string $description): void
{
    if (!$condition) {
        throw new RuntimeException('Account Center check failed: ' . $description);
    }
}

$overview = file_get_contents(dirname(__DIR__) . '/public/api/account-overview.php');
$profile = file_get_contents(dirname(__DIR__) . '/public/api/profile.php');
accountCenterCheck(is_string($overview) && str_contains($overview, "'GET'")
    && str_contains($overview, 'authenticatedUserId()')
    && str_contains($overview, 'isKnownAuthenticatedRole(')
    && str_contains($overview, 'u.account_status = :status AND u.deleted_at IS NULL'),
    'authenticated active role-gated read-only overview');
accountCenterCheck(str_contains($overview, "if (\$user['role'] === 'registered_user')")
    && str_contains($overview, 'applicant_user_id = :applications_user_id')
    && str_contains($overview, 'reporter_user_id = :strays_user_id')
    && str_contains($overview, 'donation_status = :completed')
    && !str_contains($overview, 'profileHistory('),
    'registered-only lightweight owned counts');
accountCenterCheck(!preg_match('/\b(?:INSERT|UPDATE|DELETE)\s+(?:INTO|FROM|users|user_profiles)\b/i', $overview),
    'overview has no mutation SQL');
accountCenterCheck(is_string($profile) && str_contains($profile, "'role' => 'registered_user'")
    && str_contains($profile, 'requireValidCsrfToken()'),
    'existing Registered User profile boundary and CSRF remain');
echo "Account Center overview and Registered User boundary checks passed.\n";
