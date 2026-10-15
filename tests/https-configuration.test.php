<?php
declare(strict_types=1);

$previousStoragePath = getenv('ADOPTAPET_PRIVATE_STORAGE_PATH');
$previousBaseUrl = getenv('ADOPTAPET_BASE_URL');
$temporaryStoragePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'adoptapet-https-config-' . bin2hex(random_bytes(8));
if (!mkdir($temporaryStoragePath, 0700)) {
    throw new RuntimeException('Could not create the isolated HTTPS configuration test storage directory.');
}
putenv('ADOPTAPET_PRIVATE_STORAGE_PATH=' . $temporaryStoragePath);

function removeHttpsTestFixtureDirectory(string $path): void
{
    if (is_link($path)) {
        unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }

    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $child = $path . DIRECTORY_SEPARATOR . $entry;
        if (is_link($child) || !is_dir($child)) {
            unlink($child);
        } else {
            removeHttpsTestFixtureDirectory($child);
        }
    }
    rmdir($path);
}

try {
    require_once dirname(__DIR__) . '/config/application.php';
    require_once dirname(__DIR__) . '/public/api/auth.php';

    function checkHttpsConfiguration(bool $condition, string $label): void
    {
        if (!$condition) {
            throw new RuntimeException('HTTPS configuration test failed: ' . $label);
        }
    }

    putenv('ADOPTAPET_BASE_URL');
    $_SERVER['HTTPS'] = 'on';
    checkHttpsConfiguration(adoptAPetApplicationBaseUrl() === 'https://adoptapet.test/', 'primary base URL');

    $token = str_repeat('A', 43);
    checkHttpsConfiguration(
        adoptAPetPasswordResetUrl($token) === 'https://adoptapet.test/#/reset-password?token=' . $token,
        'password reset email URL'
    );

    $_SERVER['HTTPS'] = 'off';
    checkHttpsConfiguration(adoptAPetApplicationBaseUrl() === 'http://adoptapet.test/', 'pre-activation HTTP URL');

    putenv('ADOPTAPET_BASE_URL=http://localhost/AdoptAPet/public/');
    checkHttpsConfiguration(
        adoptAPetApplicationBaseUrl() === 'http://localhost/AdoptAPet/public/',
        'localhost compatibility override'
    );

    putenv('ADOPTAPET_BASE_URL=http://adoptapet.test/AdoptAPet/public/');
    $_SERVER['HTTPS'] = 'on';
    checkHttpsConfiguration(
        adoptAPetApplicationBaseUrl() === 'https://adoptapet.test/',
        'canonical HTTPS root despite stale primary override'
    );

    putenv('ADOPTAPET_BASE_URL=http://localhost/AdoptAPet/public/');
    checkHttpsConfiguration(
        adoptAPetApplicationBaseUrl() === 'https://adoptapet.test/',
        'HTTPS email URL despite a localhost compatibility override'
    );
    $_SERVER['HTTP_HOST'] = 'untrusted.invalid';
    checkHttpsConfiguration(
        adoptAPetApplicationBaseUrl() === 'https://adoptapet.test/',
        'Host header cannot change email URLs'
    );

    $_SERVER['HTTPS'] = 'on';
    $secureCookie = authenticationSessionCookieOptions();
    checkHttpsConfiguration(
        $secureCookie['secure'] === true && $secureCookie['httponly'] === true && $secureCookie['samesite'] === 'Lax',
        'HTTPS session cookie flags'
    );
    $_SERVER['HTTPS'] = 'off';
    $localhostCookie = authenticationSessionCookieOptions();
    checkHttpsConfiguration(
        $localhostCookie['secure'] === false && $localhostCookie['httponly'] === true && $localhostCookie['samesite'] === 'Lax',
        'HTTP localhost session cookie compatibility'
    );

    echo "HTTPS configuration tests passed.\n";
} finally {
    if ($previousBaseUrl === false) {
        putenv('ADOPTAPET_BASE_URL');
    } else {
        putenv('ADOPTAPET_BASE_URL=' . $previousBaseUrl);
    }
    if ($previousStoragePath === false) {
        putenv('ADOPTAPET_PRIVATE_STORAGE_PATH');
    } else {
        putenv('ADOPTAPET_PRIVATE_STORAGE_PATH=' . $previousStoragePath);
    }
    removeHttpsTestFixtureDirectory($temporaryStoragePath);
}
