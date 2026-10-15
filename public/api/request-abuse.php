<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

const ADOPTAPET_REQUEST_ABUSE_SESSION_KEY = 'request_abuse_windows';

/** A small per-browser bound; account lockout and hCaptcha remain authoritative. */
function requestAbuseCount(string $key, int $windowSeconds, ?int $now = null): int
{
    startAuthenticationSession();
    $now ??= time();
    $entry = $_SESSION[ADOPTAPET_REQUEST_ABUSE_SESSION_KEY][$key] ?? null;
    if (!is_array($entry) || !is_int($entry['started_at'] ?? null)
        || !is_int($entry['count'] ?? null) || $entry['count'] < 0
        || $entry['started_at'] > $now || $now - $entry['started_at'] >= $windowSeconds) {
        $_SESSION[ADOPTAPET_REQUEST_ABUSE_SESSION_KEY][$key] = ['started_at' => $now, 'count' => 0];
        return 0;
    }
    return $entry['count'];
}

function requestAbuseIncrement(string $key, int $windowSeconds, ?int $now = null): int
{
    $now ??= time();
    $count = requestAbuseCount($key, $windowSeconds, $now) + 1;
    $_SESSION[ADOPTAPET_REQUEST_ABUSE_SESSION_KEY][$key]['count'] = $count;
    return $count;
}

function requestAbuseClear(string $key): void
{
    startAuthenticationSession();
    unset($_SESSION[ADOPTAPET_REQUEST_ABUSE_SESSION_KEY][$key]);
}
