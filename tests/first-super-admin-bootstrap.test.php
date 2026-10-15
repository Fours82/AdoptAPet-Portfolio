<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/tools/first-super-admin-service.php';

$bootstrapChecks = 0;
$bootstrapFailures = [];

function bootstrapCheck(bool $condition, string $message): void
{
    global $bootstrapChecks, $bootstrapFailures;
    $bootstrapChecks++;
    if (!$condition) {
        $bootstrapFailures[] = $message;
    }
}

function bootstrapBaseSnapshot(int $userId = 42): array
{
    $counts = array_fill_keys(FIRST_SUPER_ADMIN_BOOTSTRAP_TABLES, 0);
    $counts[FIRST_SUPER_ADMIN_BOOTSTRAP_TABLE] = 1;
    $counts['users'] = 1;
    $counts['user_profiles'] = 1;
    $counts['email_verifications'] = 1;
    return [
        'marker_rows' => [[
            'singleton_id' => 1, 'state' => 'uninitialized', 'target_user_id' => null,
            'prepared_at' => null, 'completed_at' => null,
        ]],
        'users' => [[
            'id' => $userId, 'role' => 'registered_user', 'account_status' => 'active',
            'password_hash_present' => true, 'failed_login_count' => 0, 'locked_until' => null,
            'last_login_at' => null, 'deleted_at' => null,
        ]],
        'profiles' => [[
            'user_id' => $userId, 'phone' => null, 'location' => null,
            'profile_image_path' => null, 'preferences_json' => null,
        ]],
        'email_verifications' => [['user_id' => $userId, 'verified_at' => '2026-10-14 10:00:00', 'attempts' => 0]],
        'mfa_settings' => [], 'recovery_codes' => [], 'login_attempts' => [],
        'session_user_ids' => [], 'security_events' => [], 'audit_rows' => [], 'table_counts' => $counts,
    ];
}

function bootstrapPreparedSnapshot(int $userId = 42): array
{
    $snapshot = bootstrapBaseSnapshot($userId);
    $snapshot['marker_rows'][0] = [
        'singleton_id' => 1, 'state' => 'prepared', 'target_user_id' => $userId,
        'prepared_at' => '2026-10-14 10:01:00', 'completed_at' => null,
    ];
    $snapshot['users'][0]['role'] = 'administrator';
    $snapshot['users'][0]['last_login_at'] = '2026-10-14 10:10:00';
    $snapshot['mfa_settings'] = [[
        'user_id' => $userId, 'enabled_at' => '2026-10-14 10:09:00',
        'last_accepted_step' => 59800000, 'secret_valid' => true,
    ]];
    $snapshot['recovery_codes'] = array_fill(0, 8, ['user_id' => $userId, 'used_at' => null, 'hash_present' => true]);
    $snapshot['login_attempts'] = [['user_id' => $userId, 'was_successful' => 1, 'email_matches_user' => 1]];
    $snapshot['session_user_ids'] = [$userId];
    $snapshot['security_events'] = [
        ['user_id' => $userId, 'event_type' => 'promoted_to_administrator'],
        ['user_id' => $userId, 'event_type' => 'mfa_enabled'],
        ['user_id' => $userId, 'event_type' => 'login_success'],
    ];
    $snapshot['audit_rows'] = [[
        'actor_user_id' => null, 'action_type' => 'first_super_admin_prepared',
        'entity_type' => 'user', 'entity_id' => $userId, 'ip_address' => null,
        'old_values_json' => '{"role":"registered_user","status":"active"}',
        'new_values_json' => '{"role":"administrator","status":"active"}',
    ]];
    $snapshot['table_counts']['administrator_mfa_settings'] = 1;
    $snapshot['table_counts']['administrator_mfa_recovery_codes'] = 8;
    $snapshot['table_counts']['login_attempts'] = 1;
    $snapshot['table_counts']['user_auth_sessions'] = 1;
    $snapshot['table_counts']['user_security_events'] = 3;
    $snapshot['table_counts']['audit_logs'] = 1;
    return $snapshot;
}

bootstrapCheck(firstBootstrapPrepareError(bootstrapBaseSnapshot(), 42) === null, 'eligible Prepare snapshot is accepted');
$snapshot = bootstrapBaseSnapshot();
$snapshot['email_verifications'] = [
    ['user_id' => 42, 'verified_at' => null, 'attempts' => 5],
    ['user_id' => 42, 'verified_at' => '2026-10-14 10:00:00', 'attempts' => 0],
];
$snapshot['table_counts']['email_verifications'] = 2;
bootstrapCheck(firstBootstrapPrepareError($snapshot, 42) === null, 'legitimate resend history with a verified latest code is accepted');
bootstrapCheck(firstBootstrapPrepareError(bootstrapBaseSnapshot(), 41) !== null, 'wrong Prepare target is refused');

bootstrapCheck(firstBootstrapMarkerError([]) !== null, 'missing marker is refused');
$validMarker = bootstrapBaseSnapshot()['marker_rows'][0];
bootstrapCheck(firstBootstrapMarkerError([$validMarker, $validMarker]) !== null, 'duplicated marker rows are refused');
$badMarker = $validMarker;
$badMarker['target_user_id'] = 42;
bootstrapCheck(firstBootstrapMarkerError([$badMarker]) !== null, 'inconsistent marker is refused');
$advancedMarker = $validMarker;
$advancedMarker['state'] = 'completed';
bootstrapCheck(firstBootstrapMarkerError([$advancedMarker]) !== null, 'advanced marker without required fields is refused');

$snapshot = bootstrapBaseSnapshot();
$snapshot['email_verifications'][0]['verified_at'] = null;
bootstrapCheck(firstBootstrapPrepareError($snapshot, 42) !== null, 'unverified target is refused');
$snapshot = bootstrapBaseSnapshot();
$snapshot['users'][0]['account_status'] = 'deactivated';
bootstrapCheck(firstBootstrapPrepareError($snapshot, 42) !== null, 'inactive target is refused');
$snapshot = bootstrapBaseSnapshot();
$snapshot['users'][0]['deleted_at'] = '2026-10-14 10:00:00';
bootstrapCheck(firstBootstrapPrepareError($snapshot, 42) !== null, 'deleted target is refused');
$snapshot = bootstrapBaseSnapshot();
$snapshot['users'][0]['role'] = 'administrator';
bootstrapCheck(firstBootstrapPrepareError($snapshot, 42) !== null, 'non-registered target is refused');
$snapshot = bootstrapBaseSnapshot();
$snapshot['users'][0]['password_hash_present'] = false;
bootstrapCheck(firstBootstrapPrepareError($snapshot, 42) !== null, 'target without password hash is refused');
$snapshot = bootstrapBaseSnapshot();
$snapshot['profiles'][0]['location'] = 'Unexpected profile data';
bootstrapCheck(firstBootstrapPrepareError($snapshot, 42) !== null, 'non-default profile data is refused');
$snapshot = bootstrapBaseSnapshot();
$snapshot['users'][] = ['id' => 43, 'role' => 'registered_user'];
$snapshot['table_counts']['users']++;
bootstrapCheck(firstBootstrapPrepareError($snapshot, 42) !== null, 'additional account is refused');
$snapshot = bootstrapBaseSnapshot();
$snapshot['table_counts']['pets'] = 1;
bootstrapCheck(firstBootstrapPrepareError($snapshot, 42) !== null, 'unexpected business data is refused');
$snapshot = bootstrapBaseSnapshot();
$snapshot['table_counts']['login_attempts'] = 1;
bootstrapCheck(firstBootstrapPrepareError($snapshot, 42) !== null, 'pre-Prepare login history is refused');
$snapshot = bootstrapBaseSnapshot();
$snapshot['marker_rows'][0]['state'] = 'prepared';
$snapshot['marker_rows'][0]['target_user_id'] = 42;
$snapshot['marker_rows'][0]['prepared_at'] = '2026-10-14 10:01:00';
bootstrapCheck(firstBootstrapPrepareError($snapshot, 42) !== null, 'repeated Prepare is refused');

bootstrapCheck(firstBootstrapFinalizeError(bootstrapPreparedSnapshot(), 42) === null, 'eligible Finalize snapshot is accepted');
bootstrapCheck(firstBootstrapFinalizeError(bootstrapPreparedSnapshot(), 43) !== null, 'wrong Finalize target is refused');
$snapshot = bootstrapPreparedSnapshot();
$snapshot['users'][] = ['id' => 43, 'role' => 'registered_user'];
$snapshot['table_counts']['users']++;
bootstrapCheck(firstBootstrapFinalizeError($snapshot, 42) !== null, 'an account registered after Prepare is detected and Finalize is refused');
$snapshot = bootstrapPreparedSnapshot();
$snapshot['marker_rows'][0]['state'] = 'completed';
$snapshot['marker_rows'][0]['completed_at'] = '2026-10-14 10:20:00';
bootstrapCheck(firstBootstrapFinalizeError($snapshot, 42) !== null, 'repeated Finalize is refused');
$snapshot = bootstrapPreparedSnapshot();
$snapshot['mfa_settings'] = [];
$snapshot['table_counts']['administrator_mfa_settings'] = 0;
bootstrapCheck(firstBootstrapFinalizeError($snapshot, 42) !== null, 'missing MFA enrollment is refused');
$snapshot = bootstrapPreparedSnapshot();
$snapshot['mfa_settings'][0]['secret_valid'] = false;
bootstrapCheck(firstBootstrapFinalizeError($snapshot, 42) !== null, 'MFA enrollment encrypted with a missing or different key is refused');
$snapshot = bootstrapPreparedSnapshot();
$snapshot['mfa_settings'][0]['last_accepted_step'] = null;
bootstrapCheck(firstBootstrapFinalizeError($snapshot, 42) !== null, 'MFA row without successful TOTP acceptance is refused');
$snapshot = bootstrapPreparedSnapshot();
$snapshot['recovery_codes'][0]['used_at'] = '2026-10-14 10:11:00';
bootstrapCheck(firstBootstrapFinalizeError($snapshot, 42) !== null, 'used recovery code during bootstrap is refused');
$snapshot = bootstrapPreparedSnapshot();
$snapshot['security_events'][] = ['user_id' => 42, 'event_type' => 'password_changed'];
$snapshot['table_counts']['user_security_events']++;
bootstrapCheck(firstBootstrapFinalizeError($snapshot, 42) !== null, 'unexpected security event is refused');
$snapshot = bootstrapPreparedSnapshot();
$snapshot['audit_rows'][0]['entity_id'] = 43;
bootstrapCheck(firstBootstrapFinalizeError($snapshot, 42) !== null, 'unexpected audit target is refused');
$snapshot = bootstrapPreparedSnapshot();
$snapshot['login_attempts'][0]['email_matches_user'] = 0;
bootstrapCheck(firstBootstrapFinalizeError($snapshot, 42) !== null, 'unrelated login attempt is refused');

bootstrapCheck(firstBootstrapDatabaseNameAllowed('adoptapet_fresh_install'), 'dedicated database name is allowed');
bootstrapCheck(!firstBootstrapDatabaseNameAllowed('pet_adoption_system'), 'known development database is refused');
bootstrapCheck(!firstBootstrapDatabaseNameAllowed('Pet_Adoption_System'), 'development database match is case insensitive');
bootstrapCheck(!firstBootstrapDatabaseNameAllowed('mysql'), 'server system database is refused');
$validPrepareArguments = [
    '--host=127.0.0.1', '--port=3307', '--database=adoptapet_fresh', '--db-user=adoptapet_app',
    '--user-id=42', '--confirm-database=adoptapet_fresh',
];
bootstrapCheck(firstBootstrapParseArguments($validPrepareArguments, 'prepare')['user-id'] === '42', 'explicit dedicated connection and target arguments are accepted');
$parseRejected = false;
try {
    firstBootstrapParseArguments(array_merge($validPrepareArguments, ['--db-password=not-accepted']), 'prepare');
} catch (FirstBootstrapRefusal) {
    $parseRejected = true;
}
bootstrapCheck($parseRejected, 'database password arguments are rejected');
$parseRejected = false;
try {
    $bad = $validPrepareArguments;
    $bad[count($bad) - 1] = '--confirm-database=another_database';
    firstBootstrapParseArguments($bad, 'prepare');
} catch (FirstBootstrapRefusal) {
    $parseRejected = true;
}
bootstrapCheck($parseRejected, 'mismatched database confirmation is rejected');
$parseRejected = false;
try {
    $bad = $validPrepareArguments;
    $bad[3] = '--db-user=root';
    firstBootstrapParseArguments($bad, 'prepare');
} catch (FirstBootstrapRefusal) {
    $parseRejected = true;
}
bootstrapCheck($parseRejected, 'root database username is rejected');
$parseRejected = false;
try {
    $bad = $validPrepareArguments;
    $bad[0] = '--host=db.example.invalid';
    firstBootstrapParseArguments($bad, 'prepare');
} catch (FirstBootstrapRefusal) {
    $parseRejected = true;
}
bootstrapCheck($parseRejected, 'non-local database hosts are refused');

final class BootstrapMemoryPdo extends PDO
{
    public array $state = [];
    private bool $active = false;
    private array $before = [];
    public int $transactionStarts = 0;
    public int $execCalls = 0;

    public function __construct() {}
    public function beginTransaction(): bool { $this->transactionStarts++; $this->before = $this->state; $this->active = true; return true; }
    public function inTransaction(): bool { return $this->active; }
    public function exec(string $statement): int|false { $this->execCalls++; return 0; }
    public function commit(): bool { $this->active = false; return true; }
    public function rollBack(): bool { $this->state = $this->before; $this->active = false; return true; }
}

$invalidStagePdo = new BootstrapMemoryPdo();
$invalidStageRefused = false;
try {
    firstBootstrapMutate($invalidStagePdo, 'invalid-stage', 42);
} catch (FirstBootstrapRefusal $exception) {
    $invalidStageRefused = $exception->getMessage() === 'Unsupported bootstrap stage.';
} catch (Throwable) {
    // A different failure is not evidence that the stage guard rejected the call.
}
bootstrapCheck($invalidStageRefused && $invalidStagePdo->transactionStarts === 0 && $invalidStagePdo->execCalls === 0,
    'invalid mutation stage is refused before PDO setup, transaction callbacks, or writes');

$fake = new BootstrapMemoryPdo();
$fake->state = ['role' => 'administrator', 'revoked' => false, 'event' => false, 'marker' => 'uninitialized'];
$baseline = $fake->state;
$rolledBack = false;
try {
    firstBootstrapRunTransaction($fake, static function () use ($fake): void {
        $fake->state = ['role' => 'super_administrator', 'revoked' => true, 'event' => true, 'marker' => 'completed'];
        throw new RuntimeException('injected audit/security write failure');
    });
} catch (FirstBootstrapRolledBack) {
    $rolledBack = true;
}
bootstrapCheck($rolledBack && $fake->state === $baseline && !$fake->inTransaction(), 'simulated write failure rolls back related state');

$rootHtaccess = file_get_contents(dirname(__DIR__) . '/.htaccess');
$toolsHtaccess = file_get_contents(dirname(__DIR__) . '/tools/.htaccess');
$entrySource = file_get_contents(dirname(__DIR__) . '/tools/first-super-admin.php');
$serviceSource = file_get_contents(dirname(__DIR__) . '/tools/first-super-admin-service.php');
$schemaSource = file_get_contents(dirname(__DIR__) . '/config/database-public.sql');
$guardPosition = strpos($entrySource, "if (PHP_SAPI !== 'cli')");
$includePosition = strpos($entrySource, 'require_once __DIR__');
bootstrapCheck(is_string($rootHtaccess) && preg_match('/RewriteRule\s+\^\(\?:[^\r\n]*tools/', $rootHtaccess) === 1, 'root Apache rules deny the tools directory');
bootstrapCheck(is_string($toolsHtaccess) && str_contains($toolsHtaccess, 'Require all denied'), 'tools directory has an Apache deny rule');
bootstrapCheck($guardPosition !== false && $includePosition !== false && $guardPosition < $includePosition, 'CLI-only guard precedes helper loading');
bootstrapCheck(is_string($serviceSource)
    && str_contains($serviceSource, 'ORDER BY singleton_id FOR UPDATE')
    && str_contains($serviceSource, 'SET TRANSACTION ISOLATION LEVEL SERIALIZABLE')
    && str_contains($serviceSource, "state = 'uninitialized'")
    && str_contains($serviceSource, "state = 'prepared' AND target_user_id = :target_user_id"),
    'mutations lock and recheck the singleton marker at serializable isolation');
bootstrapCheck(is_string($serviceSource) && !preg_match('/\b(CREATE|ALTER|DROP)\s+TABLE\b/i', $serviceSource), 'runtime bootstrap path contains no table DDL');
bootstrapCheck(is_string($serviceSource) && str_contains($serviceSource, 'administratorMfaDecrypt'), 'Finalize verifies the stored MFA secret with the installation key');
bootstrapCheck(is_string($schemaSource)
    && str_contains($schemaSource, 'CREATE TABLE `first_super_admin_bootstrap`')
    && str_contains($schemaSource, 'CHECK (`singleton_id` = 1)')
    && str_contains($schemaSource, "VALUES (1, 'uninitialized')")
    && str_contains($schemaSource, ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;'),
    'public schema seeds one constrained InnoDB marker');

$governanceSource = file_get_contents(dirname(__DIR__) . '/public/api/admin-role-governance-service.php');
$auditSource = file_get_contents(dirname(__DIR__) . '/public/api/admin-audit-service.php');
bootstrapCheck(is_string($governanceSource) && str_contains($governanceSource, "\$_SESSION['role'] ?? null")
    && str_contains($governanceSource, 'adminAuditWrite($pdo'), 'existing web governance still requires its Super Administrator session and normal audit writer');
bootstrapCheck(is_string($auditSource) && str_contains($auditSource, 'if ($actor === null)')
    && str_contains($auditSource, 'Fully authenticated Administrator required for audit.')
    && str_contains($auditSource, 'First Super Administrator audit actions are CLI-only.'), 'normal audit writer still requires an authenticated Administrator and rejects bootstrap actions');
$auditDisplay = adminAuditDisplayRow([
    'id' => 1, 'actor_user_id' => null, 'action_type' => 'first_super_admin_prepared',
    'entity_type' => 'user', 'entity_id' => 42, 'created_at' => '2026-10-14 10:00:00',
]);
bootstrapCheck($auditDisplay['actor'] === 'Installation CLI'
    && $auditDisplay['action'] === 'First Super Administrator preparation', 'bootstrap audit display identifies the CLI actor accurately');

if ($bootstrapFailures !== []) {
    foreach ($bootstrapFailures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    fwrite(STDERR, count($bootstrapFailures) . " of {$bootstrapChecks} bootstrap checks failed.\n");
    exit(1);
}
fwrite(STDOUT, "{$bootstrapChecks} first Super Administrator bootstrap checks passed (pure/static tests; no database connection).\n");
