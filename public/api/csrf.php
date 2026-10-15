<?php

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

const CSRF_SESSION_KEY = 'csrf_token';
const CSRF_FAILURE_MESSAGE = 'Your session security token has expired. Please refresh and try again.';

function csrfToken(): string
{
    startAuthenticationSession();
    $token = $_SESSION[CSRF_SESSION_KEY] ?? '';

    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) {
        $token = bin2hex(random_bytes(32));
        $_SESSION[CSRF_SESSION_KEY] = $token;
    }

    return $token;
}

function rotateCsrfToken(): string
{
    startAuthenticationSession();
    $token = bin2hex(random_bytes(32));
    $_SESSION[CSRF_SESSION_KEY] = $token;
    return $token;
}

function clearCsrfToken(): void
{
    startAuthenticationSession();
    unset($_SESSION[CSRF_SESSION_KEY]);
}

function csrfFailure(): never
{
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'code' => 'csrf_failed',
        'message' => CSRF_FAILURE_MESSAGE,
    ]);
    exit;
}

function csrfNormalisedPort(array $parts): int
{
    if (isset($parts['port'])) {
        return (int) $parts['port'];
    }

    return strtolower((string) ($parts['scheme'] ?? '')) === 'https' ? 443 : 80;
}

function csrfOriginMatchesRequest(): bool
{
    $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin === '') {
        return true;
    }

    if (strtolower($origin) === 'null' || str_contains($origin, ',')) {
        return false;
    }

    $originParts = parse_url($origin);
    $requestScheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        ? 'https'
        : 'http';
    $requestParts = parse_url($requestScheme . '://' . (string) ($_SERVER['HTTP_HOST'] ?? ''));

    if (!is_array($originParts) || !is_array($requestParts)) {
        return false;
    }

    if (isset($originParts['user']) || isset($originParts['pass']) || isset($originParts['query']) || isset($originParts['fragment'])) {
        return false;
    }

    $originPath = (string) ($originParts['path'] ?? '');
    if ($originPath !== '' && $originPath !== '/') {
        return false;
    }

    return strtolower((string) ($originParts['scheme'] ?? '')) === $requestScheme
        && strtolower((string) ($originParts['host'] ?? '')) === strtolower((string) ($requestParts['host'] ?? ''))
        && csrfNormalisedPort($originParts) === csrfNormalisedPort($requestParts);
}

function csrfRequestToken(): string
{
    $headerToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (is_string($headerToken) && $headerToken !== '') {
        return $headerToken;
    }

    $formToken = $_POST['_csrf_token'] ?? '';
    return is_string($formToken) ? $formToken : '';
}

function requireValidCsrfToken(): void
{
    startAuthenticationSession();

    if (!csrfOriginMatchesRequest()) {
        csrfFailure();
    }

    $expected = $_SESSION[CSRF_SESSION_KEY] ?? '';
    $provided = csrfRequestToken();

    if (!is_string($expected)
        || !preg_match('/^[a-f0-9]{64}$/D', $expected)
        || !preg_match('/^[a-f0-9]{64}$/D', $provided)
        || !hash_equals($expected, $provided)) {
        csrfFailure();
    }
}
