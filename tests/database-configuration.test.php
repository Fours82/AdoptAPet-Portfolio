<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/public/api/db.php';

function databaseConfigurationCheck(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException('Database configuration test failed: ' . $label);
    }
}

$local = [
    'host' => '127.0.0.1',
    'port' => 3307,
    'database' => 'fresh_install',
    'username' => 'adoptapet_test',
    'password' => 'test-only-password',
];
$resolved = resolveDatabaseConnectionSettings($local, []);
databaseConfigurationCheck($resolved === $local, 'private settings are used when environment values are absent');

$environment = [
    'DB_HOST' => 'localhost',
    'DB_PORT' => '3308',
    'DB_NAME' => 'environment_install',
    'DB_USER' => 'environment_user',
    'DB_PASS' => 'environment-password',
];
$resolved = resolveDatabaseConnectionSettings($local, $environment);
databaseConfigurationCheck($resolved === [
    'host' => 'localhost',
    'port' => 3308,
    'database' => 'environment_install',
    'username' => 'environment_user',
    'password' => 'environment-password',
], 'environment settings override the private config');

$defaults = resolveDatabaseConnectionSettings([], []);
databaseConfigurationCheck($defaults === [
    'host' => 'localhost',
    'port' => null,
    'database' => 'pet_adoption_system',
    'username' => 'root',
    'password' => '',
], 'existing local defaults remain unchanged when no settings are supplied');

$invalidPortRejected = false;
try {
    resolveDatabaseConnectionSettings($local, ['DB_PORT' => 'not-a-port']);
} catch (RuntimeException) {
    $invalidPortRejected = true;
}
databaseConfigurationCheck($invalidPortRejected, 'invalid ports are rejected before connecting');

echo "Database configuration tests passed. No database connection was attempted.\n";
