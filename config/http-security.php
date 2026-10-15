<?php
declare(strict_types=1);

require_once __DIR__ . '/application.php';

const ADOPTAPET_CSP_REPORT_ONLY = "default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; script-src 'self' 'unsafe-inline' https://unpkg.com https://hcaptcha.com https://*.hcaptcha.com; style-src 'self' 'unsafe-inline' https://unpkg.com https://hcaptcha.com https://*.hcaptcha.com; img-src 'self' data: blob: https://images.unsplash.com https://unpkg.com https://*.tile.openstreetmap.org https://hcaptcha.com https://*.hcaptcha.com; font-src 'self' data: https://hcaptcha.com https://*.hcaptcha.com; connect-src 'self' https://hcaptcha.com https://*.hcaptcha.com; frame-src https://hcaptcha.com https://*.hcaptcha.com; child-src https://hcaptcha.com https://*.hcaptcha.com; worker-src 'self' blob: https://hcaptcha.com https://*.hcaptcha.com; media-src 'self' blob:; manifest-src 'self'";

/** True for application API entry points, including the localhost subdirectory URL. */
function adoptAPetRequestIsApi(): bool
{
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    return str_contains($scriptName, '/api/');
}

/** Public, read-only endpoints whose payloads contain no account-specific state. */
function adoptAPetRequestIsPublicApiRead(): bool
{
    if (!adoptAPetRequestIsApi() || strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        return false;
    }

    $scriptName = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    return in_array($scriptName, ['home.php', 'news.php', 'pets.php'], true);
}

function adoptAPetApplyCacheHeaders(): void
{
    if (adoptAPetRequestIsApi() && !adoptAPetRequestIsPublicApiRead()) {
        header('Cache-Control: private, no-store', true);
        header('Pragma: no-cache', true);
        header('Expires: 0', true);
        return;
    }

    // The SPA shell and public API data may be cached but must be revalidated.
    header('Cache-Control: no-cache, must-revalidate', true);
}

function adoptAPetLogUnhandledFailure(Throwable $exception): void
{
    error_log(sprintf(
        'AdoptAPet unhandled failure exception=%s location=%s:%d',
        get_class($exception),
        basename($exception->getFile()),
        $exception->getLine()
    ));
}

function adoptAPetProductionExceptionHandler(Throwable $exception): never
{
    adoptAPetLogUnhandledFailure($exception);

    if (!headers_sent()) {
        http_response_code(500);
        adoptAPetApplyCacheHeaders();
        if (adoptAPetRequestIsApi()) {
            header('Content-Type: application/json; charset=utf-8', true);
        } else {
            header('Content-Type: text/plain; charset=utf-8', true);
        }
    }

    if (adoptAPetRequestIsApi()) {
        echo json_encode([
            'success' => false,
            'message' => 'An unexpected server error occurred. Please try again later.',
        ]);
    } else {
        echo 'An unexpected server error occurred. Please try again later.';
    }
    exit;
}

function adoptAPetConfigureRuntime(): void
{
    static $configured = false;
    if ($configured) {
        return;
    }
    $configured = true;

    // Keep errors in the server log in every environment. Production never
    // renders PHP diagnostics into HTML, JSON, or document responses.
    ini_set('log_errors', '1');
    if (adoptAPetIsProduction()) {
        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '0');
        ini_set('html_errors', '0');
        set_exception_handler('adoptAPetProductionExceptionHandler');
    }
}

function adoptAPetApplyHttpSecurity(): void
{
    adoptAPetConfigureRuntime();
    if (headers_sent()) {
        return;
    }

    // Remove PHP's application-level version header even when expose_php is
    // enabled globally. Apache's Server header requires server configuration.
    header_remove('X-Powered-By');
    header('X-Content-Type-Options: nosniff', true);
    header('X-Frame-Options: DENY', true);
    header('Referrer-Policy: strict-origin-when-cross-origin', true);
    header('Permissions-Policy: geolocation=(self), camera=(), microphone=(), payment=(), usb=(), magnetometer=(), gyroscope=(), accelerometer=()', true);
    header('Content-Security-Policy-Report-Only: ' . ADOPTAPET_CSP_REPORT_ONLY, true);
    adoptAPetApplyCacheHeaders();
}

adoptAPetApplyHttpSecurity();
