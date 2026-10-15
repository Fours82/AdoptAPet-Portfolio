<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/public/api/request-abuse.php';

function phase10AbuseCheck(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException('Phase 10 abuse test failed: ' . $label);
    }
}

phase10AbuseCheck(requestAbuseCount('test_window', 60, 1000) === 0, 'new window');
phase10AbuseCheck(requestAbuseIncrement('test_window', 60, 1000) === 1, 'first request');
phase10AbuseCheck(requestAbuseIncrement('test_window', 60, 1010) === 2, 'same window count');
phase10AbuseCheck(requestAbuseCount('test_window', 60, 1059) === 2, 'window still active');
phase10AbuseCheck(requestAbuseCount('test_window', 60, 1060) === 0, 'window expiry');
phase10AbuseCheck(requestAbuseIncrement('test_window', 60, 1060) === 1, 'new bounded window');
requestAbuseClear('test_window');
phase10AbuseCheck(requestAbuseCount('test_window', 60, 1060) === 0, 'clear after success');

$login = file_get_contents(dirname(__DIR__) . '/public/api/login.php');
$forgot = file_get_contents(dirname(__DIR__) . '/public/api/request-password-reset.php');
$resend = file_get_contents(dirname(__DIR__) . '/public/api/resend-verification.php');
phase10AbuseCheck(is_string($login) && str_contains($login, "requestAbuseIncrement('login_requests', 60)")
    && str_contains($login, "requestAbuseIncrement('login_failures', 300)")
    && str_contains($login, "(int) \$user['failed_login_count'] >= 2")
    && str_contains($login, 'failed_login_count + 1 >= 5'), 'login bounds preserve account lockout and CAPTCHA');
phase10AbuseCheck(is_string($forgot) && str_contains($forgot, "requestAbuseIncrement('password_reset_requests', 600)")
    && str_contains($forgot, 'LIMIT 1 FOR UPDATE')
    && str_contains($forgot, '$pdo->beginTransaction()')
    && str_contains($forgot, '$pdo->commit()'), 'reset request bound and serialized cooldown');
phase10AbuseCheck(is_string($resend) && str_contains($resend, "requestAbuseIncrement('verification_resends', 600)")
    && str_contains($resend, 'LIMIT 1 FOR UPDATE')
    && str_contains($resend, '$pdo->beginTransaction()'), 'verification resend bound and serialization');

destroyAuthenticationSession();
echo "Phase 10 per-browser request bounds and cooldown guards passed.\n";
