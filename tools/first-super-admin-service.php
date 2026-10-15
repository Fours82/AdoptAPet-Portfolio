<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/public/api/admin-role-governance-service.php';
require_once dirname(__DIR__) . '/public/api/admin-mfa-service.php';
require_once dirname(__DIR__) . '/public/api/security-session-service.php';

const FIRST_SUPER_ADMIN_BOOTSTRAP_TABLE = 'first_super_admin_bootstrap';
const FIRST_SUPER_ADMIN_BOOTSTRAP_DEVELOPMENT_DATABASE = 'pet_adoption_system';
const FIRST_SUPER_ADMIN_BOOTSTRAP_TABLES = [
    'demo_seed_registry', 'users', 'administrator_mfa_settings', 'audit_logs',
    'chat_conversations', 'community_posts', 'donations', 'email_change_requests',
    'email_verifications', 'login_attempts', 'news_posts', 'notifications',
    'password_reset_codes', 'pets', 'stray_reports', 'user_auth_sessions',
    'user_profiles', 'user_security_events', 'administrator_mfa_recovery_codes',
    'adoption_applications', 'chat_messages', 'medical_records', 'payment_transactions',
    'pet_images', 'post_comments', 'post_images', 'post_likes', 'stray_report_images',
    'application_status_history', 'generated_documents', FIRST_SUPER_ADMIN_BOOTSTRAP_TABLE,
];

final class FirstBootstrapRefusal extends RuntimeException {}
final class FirstBootstrapRolledBack extends RuntimeException {}
final class FirstBootstrapCommitOutcomeUnknown extends RuntimeException {}

function firstBootstrapParseArguments(array $arguments, string $command): array
{
    if (!in_array($command, ['status', 'prepare', 'finalize'], true)) {
        throw new FirstBootstrapRefusal('Choose status, prepare, or finalize.');
    }
    $allowed = ['host', 'port', 'database', 'db-user', 'confirm-database'];
    if ($command !== 'status') {
        $allowed[] = 'user-id';
    }
    $options = [];
    foreach ($arguments as $argument) {
        if (!is_string($argument) || !str_starts_with($argument, '--') || !str_contains($argument, '=')) {
            throw new FirstBootstrapRefusal('Use named --option=value arguments; passwords are prompted securely and are not accepted as arguments.');
        }
        [$name, $value] = explode('=', substr($argument, 2), 2);
        if (!in_array($name, $allowed, true) || array_key_exists($name, $options) || $value === '') {
            throw new FirstBootstrapRefusal('An option is missing, repeated, or unsupported.');
        }
        $options[$name] = $value;
    }
    $required = ['host', 'port', 'database', 'db-user', 'confirm-database'];
    if ($command !== 'status') {
        $required[] = 'user-id';
    }
    if (array_diff($required, array_keys($options)) !== []) {
        throw new FirstBootstrapRefusal('Every required connection option and database confirmation must be supplied explicitly.');
    }
    if (!firstBootstrapDatabaseNameAllowed($options['database'])) {
        throw new FirstBootstrapRefusal('The database name is invalid or is a protected development or system database.');
    }
    if (!hash_equals($options['database'], $options['confirm-database'])) {
        throw new FirstBootstrapRefusal('--confirm-database must exactly match --database.');
    }
    if ($options['host'] !== '127.0.0.1'
        || !ctype_digit($options['port']) || (int) $options['port'] < 1 || (int) $options['port'] > 65535
        || strlen($options['db-user']) > 128 || preg_match('/[\x00-\x20\x7f]/', $options['db-user'])
        || strcasecmp($options['db-user'], 'root') === 0) {
        throw new FirstBootstrapRefusal('Use the local TCP host 127.0.0.1, a valid explicit port, and a non-root dedicated database username.');
    }
    if ($command !== 'status'
        && (!ctype_digit($options['user-id']) || filter_var($options['user-id'], FILTER_VALIDATE_INT) === false
            || (int) $options['user-id'] < 1)) {
        throw new FirstBootstrapRefusal('Supply an explicit positive numeric user ID.');
    }
    return $options;
}

function firstBootstrapDatabaseNameAllowed(string $database): bool
{
    return preg_match('/^[A-Za-z0-9_]{1,64}$/D', $database) === 1
        && !in_array(strtolower($database), [
            FIRST_SUPER_ADMIN_BOOTSTRAP_DEVELOPMENT_DATABASE,
            'mysql', 'information_schema', 'performance_schema', 'sys',
        ], true);
}

function firstBootstrapMarkerError(array $rows): ?string
{
    if (count($rows) !== 1) {
        return 'The bootstrap marker is missing or inconsistent. Import the verified public schema into a new empty database.';
    }
    $marker = $rows[0];
    if ((int) ($marker['singleton_id'] ?? 0) !== 1) {
        return 'The bootstrap marker is inconsistent; initialization is refused.';
    }
    $state = $marker['state'] ?? null;
    $target = $marker['target_user_id'] ?? null;
    $prepared = $marker['prepared_at'] ?? null;
    $completed = $marker['completed_at'] ?? null;
    if ($state === 'uninitialized' && $target === null && $prepared === null && $completed === null) {
        return null;
    }
    if ($state === 'prepared' && (int) $target > 0 && $prepared !== null && $completed === null) {
        return null;
    }
    if ($state === 'completed' && (int) $target > 0 && $prepared !== null && $completed !== null) {
        return null;
    }
    return 'The bootstrap marker is inconsistent; initialization is refused.';
}

function firstBootstrapExpectedAuditValues(string $action): ?array
{
    return match ($action) {
        'first_super_admin_prepared' => [
            ['role' => 'registered_user', 'status' => 'active'],
            ['role' => 'administrator', 'status' => 'active'],
        ],
        'first_super_admin_finalized' => [
            ['role' => 'administrator', 'status' => 'active'],
            ['role' => 'super_administrator', 'status' => 'active'],
        ],
        default => null,
    };
}

function firstBootstrapAuditRowMatches(array $row, string $action, int $targetId): bool
{
    $expected = firstBootstrapExpectedAuditValues($action);
    if ($expected === null || ($row['actor_user_id'] ?? null) !== null
        || ($row['action_type'] ?? null) !== $action || ($row['entity_type'] ?? null) !== 'user'
        || (int) ($row['entity_id'] ?? 0) !== $targetId || ($row['ip_address'] ?? null) !== null) {
        return false;
    }
    $old = is_string($row['old_values_json'] ?? null) ? json_decode($row['old_values_json'], true) : null;
    $new = is_string($row['new_values_json'] ?? null) ? json_decode($row['new_values_json'], true) : null;
    return $old === $expected[0] && $new === $expected[1];
}

function firstBootstrapCommonAccountError(array $snapshot, int $targetId, string $expectedRole, bool $finalize): ?string
{
    $users = $snapshot['users'] ?? [];
    if (count($users) !== 1 || (int) ($users[0]['id'] ?? 0) !== $targetId) {
        return 'The target must be the only account in this fresh installation.';
    }
    $user = $users[0];
    if (($user['role'] ?? null) !== $expectedRole) {
        return $finalize
            ? 'The prepared account no longer has the expected Administrator role.'
            : 'The target must still be a Registered User.';
    }
    if (($user['account_status'] ?? null) !== 'active' || ($user['deleted_at'] ?? null) !== null) {
        return 'The target account must be active and not deleted.';
    }
    if (empty($user['password_hash_present'])) {
        return 'The target account has no usable password hash.';
    }
    if ((int) ($user['failed_login_count'] ?? -1) !== 0 || ($user['locked_until'] ?? null) !== null) {
        return 'The target account has unexpected sign-in lock state; review it manually.';
    }
    if ($finalize ? ($user['last_login_at'] ?? null) === null : ($user['last_login_at'] ?? null) !== null) {
        return $finalize
            ? 'Complete the normal Administrator sign-in and MFA enrollment before finalizing.'
            : 'The target has sign-in history; Prepare is allowed only before the first login.';
    }

    $profiles = $snapshot['profiles'] ?? [];
    if (count($profiles) !== 1 || (int) ($profiles[0]['user_id'] ?? 0) !== $targetId
        || ($profiles[0]['phone'] ?? null) !== null || ($profiles[0]['location'] ?? null) !== null
        || ($profiles[0]['profile_image_path'] ?? null) !== null || ($profiles[0]['preferences_json'] ?? null) !== null) {
        return 'The fresh-install profile state is unexpected; initialization is refused.';
    }
    $verifications = $snapshot['email_verifications'] ?? [];
    if ($verifications === []) {
        return 'The target must complete email verification before initialization.';
    }
    $hasVerified = false;
    foreach ($verifications as $verification) {
        if ((int) ($verification['user_id'] ?? 0) !== $targetId
            || (int) ($verification['attempts'] ?? -1) < 0 || (int) ($verification['attempts'] ?? 6) > 5) {
            return 'Email verification history is unexpected; initialization is refused.';
        }
        $hasVerified = $hasVerified || ($verification['verified_at'] ?? null) !== null;
    }
    $latestVerification = $verifications[array_key_last($verifications)];
    if (!$hasVerified || ($latestVerification['verified_at'] ?? null) === null
        || (int) ($latestVerification['attempts'] ?? 5) >= 5) {
        return 'The target must complete email verification before initialization.';
    }
    return null;
}

function firstBootstrapCountsMatch(array $snapshot, array $expected, array $minimum = []): bool
{
    $counts = $snapshot['table_counts'] ?? [];
    foreach (FIRST_SUPER_ADMIN_BOOTSTRAP_TABLES as $table) {
        if (!array_key_exists($table, $counts)) {
            return false;
        }
        $actual = (int) $counts[$table];
        if (isset($minimum[$table])) {
            if ($actual < (int) $minimum[$table]) {
                return false;
            }
            continue;
        }
        if ($actual !== (int) ($expected[$table] ?? 0)) {
            return false;
        }
    }
    return true;
}

function firstBootstrapPrepareError(array $snapshot, int $targetId): ?string
{
    $markerError = firstBootstrapMarkerError($snapshot['marker_rows'] ?? []);
    if ($markerError !== null) {
        return $markerError;
    }
    $marker = $snapshot['marker_rows'][0];
    if ($marker['state'] !== 'uninitialized') {
        return 'Prepare has already been used or initialization is in progress. Do not reset the marker.';
    }
    $accountError = firstBootstrapCommonAccountError($snapshot, $targetId, 'registered_user', false);
    if ($accountError !== null) {
        return $accountError;
    }
    $expected = [
        FIRST_SUPER_ADMIN_BOOTSTRAP_TABLE => 1,
        'users' => 1,
        'user_profiles' => 1,
    ];
    $emailCount = count($snapshot['email_verifications'] ?? []);
    $expected['email_verifications'] = $emailCount;
    if ($emailCount < 1 || !firstBootstrapCountsMatch($snapshot, $expected)) {
        return 'Unexpected accounts, application data, sessions, security events, MFA, audit, seed, or other records exist. Use a new empty installation.';
    }
    if (($snapshot['mfa_settings'] ?? []) !== [] || ($snapshot['recovery_codes'] ?? []) !== []) {
        return 'Unexpected MFA state exists; initialization is refused.';
    }
    return null;
}

function firstBootstrapFinalizeError(array $snapshot, int $targetId): ?string
{
    $markerError = firstBootstrapMarkerError($snapshot['marker_rows'] ?? []);
    if ($markerError !== null) {
        return $markerError;
    }
    $marker = $snapshot['marker_rows'][0];
    if ($marker['state'] !== 'prepared' || (int) $marker['target_user_id'] !== $targetId) {
        return 'Finalize must use the same target recorded by a successful Prepare; the marker cannot be reset or reassigned.';
    }
    $accountError = firstBootstrapCommonAccountError($snapshot, $targetId, 'administrator', true);
    if ($accountError !== null) {
        return $accountError;
    }
    $settings = $snapshot['mfa_settings'] ?? [];
    if (count($settings) !== 1 || (int) ($settings[0]['user_id'] ?? 0) !== $targetId
        || ($settings[0]['enabled_at'] ?? null) === null || ($settings[0]['last_accepted_step'] ?? null) === null
        || empty($settings[0]['secret_valid'])) {
        return 'Complete MFA enrollment and retain the same valid MFA encryption key before Finalize.';
    }
    $codes = $snapshot['recovery_codes'] ?? [];
    if (count($codes) !== 8) {
        return 'The expected MFA recovery-code records are missing or unexpected; review the installation manually.';
    }
    foreach ($codes as $code) {
        if ((int) ($code['user_id'] ?? 0) !== $targetId || ($code['used_at'] ?? null) !== null || empty($code['hash_present'])) {
            return 'MFA recovery-code state is unexpected; initialization is refused.';
        }
    }
    $loginAttempts = $snapshot['login_attempts'] ?? [];
    $successfulLogin = false;
    foreach ($loginAttempts as $attempt) {
        if ((int) ($attempt['user_id'] ?? 0) !== $targetId || empty($attempt['email_matches_user'])) {
            return 'Sign-in history contains an unrelated or unexpected account attempt.';
        }
        $successfulLogin = $successfulLogin || (int) ($attempt['was_successful'] ?? 0) === 1;
    }
    if (!$successfulLogin) {
        return 'Complete the normal Administrator sign-in and MFA enrollment before finalizing.';
    }
    foreach ($snapshot['session_user_ids'] ?? [] as $sessionUserId) {
        if ((int) $sessionUserId !== $targetId) {
            return 'An unrelated authentication session exists; initialization is refused.';
        }
    }
    $eventCounts = [];
    $allowedEvents = [
        'promoted_to_administrator', 'mfa_enabled', 'login_success',
        'logout', 'session_revoked', 'other_sessions_revoked',
    ];
    foreach ($snapshot['security_events'] ?? [] as $event) {
        if ((int) ($event['user_id'] ?? 0) !== $targetId || !in_array($event['event_type'] ?? null, $allowedEvents, true)) {
            return 'Security history contains an unexpected event; initialization is refused.';
        }
        $eventCounts[$event['event_type']] = ($eventCounts[$event['event_type']] ?? 0) + 1;
    }
    if (($eventCounts['promoted_to_administrator'] ?? 0) !== 1
        || ($eventCounts['mfa_enabled'] ?? 0) !== 1
        || ($eventCounts['login_success'] ?? 0) < 1) {
        return 'The expected Prepare, login, or MFA security events are incomplete.';
    }
    $auditRows = $snapshot['audit_rows'] ?? [];
    if (count($auditRows) !== 1 || !firstBootstrapAuditRowMatches($auditRows[0], 'first_super_admin_prepared', $targetId)) {
        return 'Audit history is not the expected single Prepare event; initialization is refused.';
    }
    $expected = [
        FIRST_SUPER_ADMIN_BOOTSTRAP_TABLE => 1,
        'users' => 1,
        'user_profiles' => 1,
        'administrator_mfa_settings' => 1,
        'administrator_mfa_recovery_codes' => 8,
        'audit_logs' => 1,
        'email_verifications' => count($snapshot['email_verifications'] ?? []),
        'login_attempts' => count($loginAttempts),
        'user_auth_sessions' => count($snapshot['session_user_ids'] ?? []),
        'user_security_events' => count($snapshot['security_events'] ?? []),
    ];
    $minimum = [
        'email_verifications' => 1,
        'login_attempts' => 1,
        'user_auth_sessions' => 1,
        'user_security_events' => 3,
    ];
    if (!firstBootstrapCountsMatch($snapshot, $expected, $minimum)) {
        return 'Unexpected application, user, role, session, security, audit, seed, or business data exists. Initialization is refused.';
    }
    return null;
}

function firstBootstrapVerifySchema(PDO $pdo): void
{
    $statement = $pdo->query(
        'SELECT TABLE_NAME, TABLE_TYPE, ENGINE FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME'
    );
    $actual = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (($row['TABLE_TYPE'] ?? null) !== 'BASE TABLE' || strcasecmp((string) ($row['ENGINE'] ?? ''), 'InnoDB') !== 0) {
            throw new FirstBootstrapRefusal('The selected database has an unexpected object or a non-InnoDB table. Use the verified public schema in a new empty database.');
        }
        $actual[] = (string) $row['TABLE_NAME'];
    }
    $expected = FIRST_SUPER_ADMIN_BOOTSTRAP_TABLES;
    sort($actual, SORT_STRING);
    sort($expected, SORT_STRING);
    if ($actual !== $expected) {
        throw new FirstBootstrapRefusal('The selected database does not have exactly the public application schema plus its bootstrap marker.');
    }
}

function firstBootstrapBuildSnapshot(PDO $pdo): array
{
    $markerRows = $pdo->query(
        'SELECT singleton_id, state, target_user_id, prepared_at, completed_at
         FROM first_super_admin_bootstrap ORDER BY singleton_id FOR UPDATE'
    )->fetchAll(PDO::FETCH_ASSOC);
    $users = $pdo->query(
        'SELECT id, role, account_status, password_hash, failed_login_count, locked_until, last_login_at, deleted_at
         FROM users ORDER BY id FOR UPDATE'
    )->fetchAll(PDO::FETCH_ASSOC);
    $users = array_map(static fn (array $user): array => [
        'id' => (int) $user['id'], 'role' => $user['role'], 'account_status' => $user['account_status'],
        'password_hash_present' => is_string($user['password_hash']) && $user['password_hash'] !== '',
        'failed_login_count' => (int) $user['failed_login_count'], 'locked_until' => $user['locked_until'],
        'last_login_at' => $user['last_login_at'], 'deleted_at' => $user['deleted_at'],
    ], $users);
    $profileRows = $pdo->query(
        'SELECT user_id, phone, location, profile_image_path, preferences_json
         FROM user_profiles ORDER BY user_id FOR UPDATE'
    )->fetchAll(PDO::FETCH_ASSOC);
    $verificationRows = $pdo->query(
        'SELECT user_id, verified_at, attempts FROM email_verifications ORDER BY id FOR UPDATE'
    )->fetchAll(PDO::FETCH_ASSOC);
    $mfaRows = $pdo->query(
        'SELECT user_id, enabled_at, last_accepted_step, totp_secret_encrypted
         FROM administrator_mfa_settings ORDER BY user_id FOR UPDATE'
    )->fetchAll(PDO::FETCH_ASSOC);
    $mfaRows = array_map(static fn (array $row): array => [
        'user_id' => (int) $row['user_id'],
        'enabled_at' => $row['enabled_at'],
        'last_accepted_step' => $row['last_accepted_step'] === null ? null : (int) $row['last_accepted_step'],
        'secret_present' => is_string($row['totp_secret_encrypted']) && $row['totp_secret_encrypted'] !== '',
        'encrypted_secret' => $row['totp_secret_encrypted'],
    ], $mfaRows);
    $recoveryRows = $pdo->query(
        "SELECT user_id, used_at, (code_hash <> '') AS hash_present
         FROM administrator_mfa_recovery_codes ORDER BY id FOR UPDATE"
    )->fetchAll(PDO::FETCH_ASSOC);
    $loginRows = $pdo->query(
        'SELECT la.user_id, la.was_successful,
                CASE WHEN u.id IS NOT NULL AND la.email_attempted = u.email THEN 1 ELSE 0 END AS email_matches_user
         FROM login_attempts la LEFT JOIN users u ON u.id = la.user_id ORDER BY la.id FOR UPDATE'
    )->fetchAll(PDO::FETCH_ASSOC);
    $sessionRows = $pdo->query('SELECT user_id FROM user_auth_sessions ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
    $eventRows = $pdo->query('SELECT user_id, event_type FROM user_security_events ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
    $auditRows = $pdo->query(
        'SELECT actor_user_id, action_type, entity_type, entity_id, old_values_json, new_values_json, ip_address
         FROM audit_logs ORDER BY id FOR UPDATE'
    )->fetchAll(PDO::FETCH_ASSOC);
    $tableCounts = [];
    foreach (FIRST_SUPER_ADMIN_BOOTSTRAP_TABLES as $table) {
        $tableCounts[$table] = (int) $pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
    }
    return [
        'marker_rows' => $markerRows,
        'users' => $users,
        'profiles' => $profileRows,
        'email_verifications' => $verificationRows,
        'mfa_settings' => $mfaRows,
        'recovery_codes' => $recoveryRows,
        'login_attempts' => $loginRows,
        'session_user_ids' => array_map(static fn (array $row): int => (int) $row['user_id'], $sessionRows),
        'security_events' => $eventRows,
        'audit_rows' => $auditRows,
        'table_counts' => $tableCounts,
    ];
}

function firstBootstrapReadMarker(PDO $pdo): array
{
    $rows = $pdo->query(
        'SELECT singleton_id, state, target_user_id, prepared_at, completed_at
         FROM first_super_admin_bootstrap ORDER BY singleton_id'
    )->fetchAll(PDO::FETCH_ASSOC);
    $error = firstBootstrapMarkerError($rows);
    if ($error !== null) {
        throw new FirstBootstrapRefusal($error);
    }
    return $rows[0];
}

function firstBootstrapRunTransaction(PDO $pdo, callable $operation): mixed
{
    if ($pdo->inTransaction()) {
        throw new LogicException('The bootstrap transaction must own its PDO transaction.');
    }
    try {
        if (!$pdo->beginTransaction()) {
            throw new RuntimeException('Transaction start returned false.');
        }
    } catch (Throwable $exception) {
        throw new FirstBootstrapRolledBack('The transaction could not be started.', 0, $exception);
    }
    $commitAttempted = false;
    try {
        $result = $operation();
        $commitAttempted = true;
        if (!$pdo->commit()) {
            throw new RuntimeException('Commit returned false.');
        }
        return $result;
    } catch (Throwable $exception) {
        if ($commitAttempted) {
            throw new FirstBootstrapCommitOutcomeUnknown('The commit result is unknown; do not retry. Use the read-only status command.', 0, $exception);
        }
        try {
            if ($pdo->inTransaction()) {
                if (!$pdo->rollBack()) {
                    throw new RuntimeException('Rollback returned false.');
                }
            }
        } catch (Throwable $rollbackException) {
            throw new FirstBootstrapCommitOutcomeUnknown('Rollback could not be confirmed; do not retry. Use the read-only status command.', 0, $rollbackException);
        }
        if ($exception instanceof FirstBootstrapRefusal) {
            throw $exception;
        }
        throw new FirstBootstrapRolledBack('The operation failed and its transaction was rolled back.', 0, $exception);
    }
}

function firstBootstrapMutate(PDO $pdo, string $stage, int $targetId): void
{
    if (!in_array($stage, ['prepare', 'finalize'], true)) {
        throw new FirstBootstrapRefusal('Unsupported bootstrap stage.');
    }
    if ($targetId < 1) {
        throw new FirstBootstrapRefusal('Supply an explicit positive numeric user ID.');
    }
    $mfaKey = administratorMfaEncryptionKey();
    if (!is_string($mfaKey)) {
        throw new FirstBootstrapRefusal('A valid installation-specific MFA encryption key and Sodium support are required before bootstrap.');
    }
    try {
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
        firstBootstrapRunTransaction($pdo, static function () use ($pdo, $stage, $targetId, $mfaKey): void {
            $snapshot = firstBootstrapBuildSnapshot($pdo);
            if ($stage === 'finalize') {
                $settings = $snapshot['mfa_settings'] ?? [];
                $decryptedSecret = (count($settings) === 1
                    && is_string($settings[0]['encrypted_secret'] ?? null))
                    ? administratorMfaDecrypt($settings[0]['encrypted_secret'], $mfaKey) : null;
                $secretValid = is_string($decryptedSecret);
                if ($secretValid) {
                    sodium_memzero($decryptedSecret);
                }
                if (isset($snapshot['mfa_settings'][0]['encrypted_secret'])) {
                    unset($snapshot['mfa_settings'][0]['encrypted_secret']);
                }
                if (isset($snapshot['mfa_settings'][0])) {
                    $snapshot['mfa_settings'][0]['secret_valid'] = $secretValid;
                }
            }
            $error = $stage === 'prepare'
                ? firstBootstrapPrepareError($snapshot, $targetId)
                : firstBootstrapFinalizeError($snapshot, $targetId);
            if ($error !== null) {
                throw new FirstBootstrapRefusal($error);
            }

            $action = $stage === 'prepare' ? 'promote_user' : 'promote_admin';
            [$oldRole, $oldStatus, $newRole, $newStatus, , $securityEvent] = adminRoleGovernanceTransition($action);
            $target = [
                'id' => $targetId, 'role' => $oldRole, 'account_status' => $oldStatus,
                'deleted_at' => null, 'email_verified' => true,
            ];
            $transitionError = adminRoleGovernanceAllowed($action, $target, 0, 0, $stage === 'finalize');
            if ($transitionError !== null) {
                throw new FirstBootstrapRefusal($transitionError);
            }
            $updateUser = $pdo->prepare(
                'UPDATE users SET role = :new_role, account_status = :new_status
                 WHERE id = :id AND role = :old_role AND account_status = :old_status AND deleted_at IS NULL'
            );
            $updateUser->execute([
                'new_role' => $newRole, 'new_status' => $newStatus, 'id' => $targetId,
                'old_role' => $oldRole, 'old_status' => $oldStatus,
            ]);
            if ($updateUser->rowCount() !== 1) {
                throw new FirstBootstrapRefusal('The target account changed concurrently; no role change was committed.');
            }

            if ($stage === 'prepare') {
                $updateMarker = $pdo->prepare(
                    "UPDATE first_super_admin_bootstrap
                     SET state = 'prepared', target_user_id = :target_user_id, prepared_at = UTC_TIMESTAMP()
                     WHERE singleton_id = 1 AND state = 'uninitialized' AND target_user_id IS NULL"
                );
                $updateMarker->execute(['target_user_id' => $targetId]);
                $auditAction = 'first_super_admin_prepared';
            } else {
                $updateMarker = $pdo->prepare(
                    "UPDATE first_super_admin_bootstrap
                     SET state = 'completed', completed_at = UTC_TIMESTAMP()
                     WHERE singleton_id = 1 AND state = 'prepared' AND target_user_id = :target_user_id"
                );
                $updateMarker->execute(['target_user_id' => $targetId]);
                $auditAction = 'first_super_admin_finalized';
            }
            if ($updateMarker->rowCount() !== 1) {
                throw new FirstBootstrapRefusal('The bootstrap marker changed concurrently; no role change was committed.');
            }
            securityRevokeAllSessions($pdo, $targetId, 'role_changed');
            securityRecordEvent($pdo, $targetId, $securityEvent, false);
            adminAuditWriteFirstSuperAdminBootstrap($pdo, $auditAction, $targetId);
        });
    } finally {
        sodium_memzero($mfaKey);
    }
}
