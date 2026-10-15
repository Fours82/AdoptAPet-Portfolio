<?php
declare(strict_types=1);

/**
 * Returns the application runtime environment.
 *
 * Fresh/local installations deliberately remain in development mode. A real
 * deployment should set ADOPTAPET_ENV=production in the server environment.
 */
function adoptAPetEnvironment(): string
{
    static $environment = null;
    if (is_string($environment)) {
        return $environment;
    }

    $configured = strtolower(trim((string) (getenv('ADOPTAPET_ENV') ?: 'development')));
    if (!in_array($configured, ['development', 'production'], true)) {
        error_log('AdoptAPet ignored an unsupported ADOPTAPET_ENV value and used development mode.');
        $configured = 'development';
    }

    $environment = $configured;
    return $environment;
}

function adoptAPetIsProduction(): bool
{
    return adoptAPetEnvironment() === 'production';
}

/**
 * Returns the configured public application URL without consulting Host headers.
 *
 * The primary host uses the web server's direct HTTPS state. Once the HTTPS
 * vhost and redirect are active, generated links use HTTPS. Installations
 * that use only the compatibility URL can set:
 * ADOPTAPET_BASE_URL=http://localhost/AdoptAPet-Final-Submission/public/
 */
function adoptAPetApplicationBaseUrl(): string
{
    $https = strtolower(trim((string) ($_SERVER['HTTPS'] ?? '')));
    $requestIsHttps = $https !== '' && !in_array($https, ['off', '0', 'false'], true);
    $defaultUrl = ($requestIsHttps ? 'https' : 'http') . '://adoptapet.test/';
    $configured = trim((string) (getenv('ADOPTAPET_BASE_URL') ?: $defaultUrl));
    $parts = parse_url($configured);

    if (
        !is_array($parts)
        || !isset($parts['scheme'], $parts['host'])
        || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
        || isset($parts['user'])
        || isset($parts['pass'])
        || isset($parts['query'])
        || isset($parts['fragment'])
    ) {
        throw new RuntimeException('The AdoptAPet application base URL is invalid.');
    }

    $scheme = strtolower((string) $parts['scheme']);
    $host = (string) $parts['host'];
    if (str_contains($host, ':') && $host[0] !== '[') {
        $host = '[' . $host . ']';
    }
    $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
    $path = isset($parts['path']) ? '/' . trim((string) $parts['path'], '/') : '';
    if ($requestIsHttps) {
        // An HTTPS request must never put an HTTP link in a security email.
        $scheme = 'https';
        if (strcasecmp($host, 'localhost') === 0 || $host === '127.0.0.1') {
            // A localhost-only override must not leak into primary-host email.
            $host = 'adoptapet.test';
            $port = '';
        }
    }
    if (strcasecmp($host, 'adoptapet.test') === 0) {
        // The named vhost serves public/ as its root, even when an older
        // ADOPTAPET_BASE_URL value still contains the compatibility path.
        $path = '';
        $port = '';
    }

    return $scheme . '://' . $host . $port . rtrim($path, '/') . '/';
}

function adoptAPetPasswordResetUrl(string $token): string
{
    if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
        throw new InvalidArgumentException('The password-reset token format is invalid.');
    }

    return adoptAPetApplicationBaseUrl() . '#/reset-password?token=' . rawurlencode($token);
}
