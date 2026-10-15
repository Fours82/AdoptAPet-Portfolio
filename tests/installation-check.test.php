<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/tools/installation-check.php';

$checks = 0;
function installationTestAssert(bool $condition, string $label): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException('Installation checker test failed: ' . $label);
    }
    $checks++;
}

$root = dirname(__DIR__);
$schema = file_get_contents($root . '/config/database-public.sql');
installationTestAssert(is_string($schema), 'public SQL is readable');
$tables = installationCheckExpectedTables($schema);
installationTestAssert(count($tables) === 31, 'all 31 schema tables are recognized');
installationTestAssert(in_array('first_super_admin_bootstrap', $tables, true), 'bootstrap marker is part of the schema');
installationTestAssert(
    preg_match('/INSERT INTO users\s*\(id, username, name, email, password_hash, role, account_status\)\s*VALUES\s*\(1, \'demo_superadmin\', \'FYP Demonstration Administrator\', \'demo.admin@example.invalid\', \'([^\']+)\', \'super_administrator\', \'active\'\)/s', $schema, $seedMatch) === 1,
    'SQL contains the documented active Demo administrator'
);
installationTestAssert(password_verify('DemoAdmin!2026', $seedMatch[1]), 'seeded password hash matches the documented Demo password');
installationTestAssert(
    str_contains($schema, "SET state = 'completed', target_user_id = 1, prepared_at = NOW(), completed_at = NOW()"),
    'SQL completes bootstrap for the seeded administrator'
);
installationTestAssert(
    preg_match('/\b(DROP\s+TABLE|TRUNCATE\s+TABLE|DELETE\s+FROM)\b/i', $schema) !== 1,
    'public SQL contains no destructive table or row removal statement'
);

$demoUser = [
    'id' => 1,
    'username' => 'demo_superadmin',
    'name' => 'FYP Demonstration Administrator',
    'email' => 'demo.admin@example.invalid',
    'password_hash' => password_hash('DemoAdmin!2026', PASSWORD_DEFAULT),
    'role' => 'super_administrator',
    'account_status' => 'active',
    'failed_login_count' => 0,
    'locked_until' => null,
    'last_login_at' => null,
    'deleted_at' => null,
];
$demoProfile = ['user_id' => 1, 'phone' => null, 'location' => null, 'profile_image_path' => null, 'preferences_json' => null];
$demoVerification = ['user_id' => 1, 'verified_at' => '2026-10-14 00:00:00'];
$demoMarker = [['state' => 'completed', 'target_user_id' => 1, 'prepared_at' => '2026-10-14 00:00:00', 'completed_at' => '2026-10-14 00:00:00']];
$demoTables = ['email_verifications', 'user_profiles', 'users'];
installationTestAssert(
    installationCheckDemoSeedIsReady($demoMarker, $demoTables, [$demoUser], [$demoProfile], [$demoVerification]),
    'exact completed Demo seed is ready for first sign-in'
);
installationTestAssert(
    !installationCheckDemoSeedIsReady([], $demoTables, [$demoUser], [$demoProfile], [$demoVerification]),
    'missing bootstrap marker is refused'
);
installationTestAssert(
    !installationCheckDemoSeedIsReady($demoMarker, [...$demoTables, 'pets'], [$demoUser], [$demoProfile], [$demoVerification]),
    'unexpected demo content is refused'
);
$unverified = $demoVerification;
$unverified['verified_at'] = null;
installationTestAssert(
    !installationCheckDemoSeedIsReady($demoMarker, $demoTables, [$demoUser], [$demoProfile], [$unverified]),
    'unverified seed account is refused'
);
$wrongPassword = $demoUser;
$wrongPassword['password_hash'] = password_hash('not-the-demo-password', PASSWORD_DEFAULT);
installationTestAssert(
    !installationCheckDemoSeedIsReady($demoMarker, $demoTables, [$wrongPassword], [$demoProfile], [$demoVerification]),
    'incorrect password hash is refused'
);

$validTables = installationCheckCompareTables(['users', 'pets'], ['pets', 'users']);
installationTestAssert($validTables['matches'], 'table comparison ignores ordering');
$invalidObjects = installationCheckInvalidTableObjects([['TABLE_NAME' => 'pets', 'TABLE_TYPE' => 'BASE TABLE', 'ENGINE' => 'MyISAM']]);
installationTestAssert($invalidObjects === ['pets'], 'non-InnoDB objects are refused');

$smtpMissing = installationCheckSmtpStatus(['host' => '', 'username' => '', 'password' => '', 'from_email' => '', 'port' => 587], [], []);
installationTestAssert(!$smtpMissing['ok'] && str_contains($smtpMissing['message'], 'can sign in without SMTP'), 'SMTP is informational for Demo sign-in');
$captchaDisabled = installationCheckHcaptchaStatus(['enabled' => false], [], [], true);
installationTestAssert($captchaDisabled['ok'], 'disabled hCaptcha is optional');

$helper = file_get_contents($root . '/setup-helper.bat');
installationTestAssert(is_string($helper) && str_contains($helper, '1 Create MFA key, 2 Run read-only check, 3 Exit'), 'helper menu matches the Demo workflow');
installationTestAssert(is_string($helper) && str_contains($helper, 'tools\\installation-check.php'), 'helper invokes the Demo readiness checker');
$login = file_get_contents($root . '/public/api/login.php');
installationTestAssert(is_string($login) && str_contains($login, 'WHERE email = :email'), 'login accepts the seeded email');
installationTestAssert(is_string($login) && str_contains($login, "'mfa_mode' => \$mode"), 'administrator login enters the existing MFA flow');
$checker = file_get_contents($root . '/tools/installation-check.php');
installationTestAssert(
    is_string($checker) && preg_match('/->(?:exec|query|prepare)\s*\(\s*[\'\"]\s*(?:INSERT|UPDATE|DELETE|REPLACE|DROP|ALTER|TRUNCATE)\b/i', $checker) !== 1,
    'readiness checker does not issue data-changing SQL'
);
installationTestAssert(is_string($checker) && str_contains($checker, 'PDO::MYSQL_ATTR_MULTI_STATEMENTS => false'), 'database connection disables multi-statement SQL');

echo "Installation checker tests passed: {$checks} checks; no database connection or file mutation.\n";
