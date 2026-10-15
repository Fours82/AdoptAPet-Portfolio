<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/http-security.php';

/**
 * Resolves connection settings. Process environment values override the
 * optional ignored config/database.local.php file; the defaults preserve the
 * existing local development behavior when neither source is configured.
 */
function resolveDatabaseConnectionSettings(array $localSettings, array $environment): array
{
    $read = static function (string $environmentKey, string $localKey, $fallback) use ($localSettings, $environment) {
        $value = $environment[$environmentKey] ?? false;
        if (is_string($value) && ($environmentKey === 'DB_PASS' || trim($value) !== '')) {
            return $value;
        }

        return $localSettings[$localKey] ?? $fallback;
    };

    $host = $read('DB_HOST', 'host', 'localhost');
    $database = $read('DB_NAME', 'database', 'pet_adoption_system');
    $username = $read('DB_USER', 'username', 'root');
    $password = $read('DB_PASS', 'password', '');
    $portValue = $read('DB_PORT', 'port', null);

    foreach (['host' => $host, 'database' => $database, 'username' => $username, 'password' => $password] as $name => $value) {
        if (!is_string($value) || ($name !== 'password' && trim($value) === '')) {
            throw new RuntimeException('The local database configuration is invalid. Check its fields without sharing their values.');
        }
    }

    if (is_string($portValue) && ctype_digit($portValue)) {
        $portValue = (int) $portValue;
    }
    if ($portValue !== null && (!is_int($portValue) || $portValue < 1 || $portValue > 65535)) {
        throw new RuntimeException('The local database port must be an integer from 1 to 65535.');
    }

    return [
        'host' => $host,
        'port' => $portValue,
        'database' => $database,
        'username' => $username,
        'password' => $password,
    ];
}

function getDatabaseConnectionSettings(): array
{
    $localSettings = [];
    $localConfigurationFile = dirname(__DIR__, 2) . '/config/database.local.php';
    if (is_file($localConfigurationFile)) {
        $loadedSettings = require $localConfigurationFile;
        if (!is_array($loadedSettings)) {
            throw new RuntimeException('The private database configuration must return an array.');
        }
        $localSettings = $loadedSettings;
    }

    $environment = [];
    foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $name) {
        $value = getenv($name);
        if ($value !== false) {
            $environment[$name] = $value;
        }
    }

    return resolveDatabaseConnectionSettings($localSettings, $environment);
}

/** Creates the application's MariaDB connection. */
function getDatabaseConnection(): PDO
{
    $settings = getDatabaseConnectionSettings();
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $settings['host'], $settings['database']);
    if (is_int($settings['port'])) {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $settings['host'], $settings['port'], $settings['database']);
    }

    return new PDO($dsn, $settings['username'], $settings['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}
