<?php
declare(strict_types=1);

require_once __DIR__ . '/admin-audit-service.php';

const ADMIN_ROLE_GOVERNANCE_ACTIONS = [
    'promote_user', 'demote_admin', 'deactivate_admin', 'reactivate_admin',
    'promote_admin', 'demote_super', 'deactivate_super', 'reactivate_super',
];

/** Fixed transitions only; no role or status value is accepted from the browser. */
function adminRoleGovernanceTransition(string $action): array
{
    return match ($action) {
        'promote_user' => ['registered_user', 'active', 'administrator', 'active', 'user_promoted_to_admin', 'promoted_to_administrator'],
        'demote_admin' => ['administrator', 'active', 'registered_user', 'active', 'administrator_demoted', 'demoted_to_registered_user'],
        'deactivate_admin' => ['administrator', 'active', 'administrator', 'deactivated', 'administrator_deactivated', 'administrator_account_deactivated'],
        'reactivate_admin' => ['administrator', 'deactivated', 'administrator', 'active', 'administrator_reactivated', 'administrator_account_reactivated'],
        'promote_admin' => ['administrator', 'active', 'super_administrator', 'active', 'administrator_promoted_to_super_admin', 'promoted_to_super_administrator'],
        'demote_super' => ['super_administrator', 'active', 'administrator', 'active', 'super_admin_demoted', 'demoted_to_administrator'],
        'deactivate_super' => ['super_administrator', 'active', 'super_administrator', 'deactivated', 'super_admin_deactivated', 'administrator_account_deactivated'],
        'reactivate_super' => ['super_administrator', 'deactivated', 'super_administrator', 'active', 'super_admin_reactivated', 'administrator_account_reactivated'],
        default => throw new InvalidArgumentException('Unsupported role-governance action.'),
    };
}

function adminRoleGovernanceAllowed(string $action, array $target, int $actorId, int $activeSuperCount, bool $mfaEnabled): ?string
{
    [$oldRole, $oldStatus] = adminRoleGovernanceTransition($action);
    if ((int) ($target['id'] ?? 0) === $actorId) {
        return 'You cannot change your own privileged role or account status here.';
    }
    if (($target['role'] ?? null) !== $oldRole || ($target['account_status'] ?? null) !== $oldStatus
        || ($target['deleted_at'] ?? null) !== null) {
        return 'This account changed or is not eligible for that action. Refresh and try again.';
    }
    if ($action === 'promote_user' && !(bool) ($target['email_verified'] ?? false)) {
        return 'A verified email is required for this role transition.';
    }
    if ($action === 'promote_admin' && !$mfaEnabled) {
        return 'This Administrator must enroll in MFA before promotion.';
    }
    if (in_array($action, ['demote_super', 'deactivate_super'], true) && $activeSuperCount <= 1) {
        return 'The last active Super Administrator cannot be removed.';
    }
    return null;
}

/** Caller owns the transaction. Lock every Super Administrator in a stable order first. */
function adminRoleGovernanceApply(PDO $pdo, int $actorId, int $targetId, string $action): array
{
    if (!$pdo->inTransaction() || $actorId < 1 || $targetId < 1) {
        throw new LogicException('A valid governance transaction is required.');
    }
    [$oldRole, $oldStatus, $newRole, $newStatus, $auditAction, $securityEvent] = adminRoleGovernanceTransition($action);
    $lockSuper = $pdo->prepare(
        'SELECT id, account_status, deleted_at FROM users
         WHERE role = :role ORDER BY id FOR UPDATE'
    );
    $lockSuper->execute(['role' => ADOPTAPET_SUPER_ADMIN_ROLE]);
    $superRows = $lockSuper->fetchAll();
    $activeSuperCount = count(array_filter($superRows, static fn (array $row): bool =>
        $row['account_status'] === 'active' && $row['deleted_at'] === null));

    $actorStatement = $pdo->prepare('SELECT role, account_status, deleted_at FROM users WHERE id = :id FOR UPDATE');
    $actorStatement->execute(['id' => $actorId]);
    $actor = $actorStatement->fetch();
    if (!$actor || !isSuperAdministratorRole($actor['role']) || $actor['account_status'] !== 'active'
        || $actor['deleted_at'] !== null || ($_SESSION['role'] ?? null) !== ADOPTAPET_SUPER_ADMIN_ROLE) {
        throw new DomainException('Super Administrator access is required.');
    }

    $targetStatement = $pdo->prepare(
        'SELECT u.id, u.username, u.role, u.account_status, u.deleted_at,
                EXISTS (SELECT 1 FROM email_verifications ev
                        WHERE ev.user_id = u.id AND ev.verified_at IS NOT NULL) AS email_verified
         FROM users u WHERE u.id = :id FOR UPDATE'
    );
    $targetStatement->execute(['id' => $targetId]);
    $target = $targetStatement->fetch();
    if (!$target) {
        throw new DomainException('The target account was not found.');
    }
    $mfaStatement = $pdo->prepare('SELECT user_id FROM administrator_mfa_settings WHERE user_id = :id LIMIT 1 FOR UPDATE');
    $mfaStatement->execute(['id' => $targetId]);
    $mfaEnabled = $mfaStatement->fetchColumn() !== false;
    $error = adminRoleGovernanceAllowed($action, $target, $actorId, $activeSuperCount, $mfaEnabled);
    if ($error !== null) {
        throw new DomainException($error);
    }

    $update = $pdo->prepare(
        'UPDATE users SET role = :new_role, account_status = :new_status
         WHERE id = :id AND role = :old_role AND account_status = :old_status AND deleted_at IS NULL'
    );
    $update->execute([
        'new_role' => $newRole, 'new_status' => $newStatus, 'id' => $targetId,
        'old_role' => $oldRole, 'old_status' => $oldStatus,
    ]);
    if ($update->rowCount() !== 1) {
        throw new DomainException('This account changed concurrently. Refresh and try again.');
    }
    if ($action === 'demote_admin' || $action === 'promote_user') {
        // Recovery codes are deleted by the existing settings FK cascade.
        $deleteMfa = $pdo->prepare('DELETE FROM administrator_mfa_settings WHERE user_id = :id');
        $deleteMfa->execute(['id' => $targetId]);
    }
    securityRevokeAllSessions($pdo, $targetId, $oldRole !== $newRole ? 'role_changed' : 'account_status_changed');
    securityRecordEvent($pdo, $targetId, $securityEvent, false);
    adminAuditWrite($pdo, $auditAction, $targetId,
        ['role' => $oldRole, 'status' => $oldStatus],
        ['role' => $newRole, 'status' => $newStatus]);
    return [
        'id' => $targetId, 'username' => $target['username'],
        'role' => $newRole, 'account_status' => $newStatus,
    ];
}
