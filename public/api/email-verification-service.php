<?php
declare(strict_types=1);

/** Only an untouched, pending Registered User registration may self-activate. */
function emailVerificationRegistrationStateIsEligible(array $user, bool $wasVerified, bool $accessWasManaged): bool
{
    return ($user['role'] ?? null) === 'registered_user'
        && ($user['account_status'] ?? null) === 'deactivated'
        && ($user['deleted_at'] ?? null) === null
        && !$wasVerified
        && !$accessWasManaged;
}

function emailVerificationPendingRegistrationIsEligible(PDO $pdo, array $user): bool
{
    if (!emailVerificationRegistrationStateIsEligible($user, false, false)) {
        return false;
    }

    $history = $pdo->prepare(
        'SELECT
            EXISTS (SELECT 1 FROM email_verifications
                    WHERE user_id = :verified_user_id AND verified_at IS NOT NULL) AS was_verified,
            EXISTS (SELECT 1 FROM user_security_events
                    WHERE user_id = :managed_user_id AND event_type = :event_type) AS access_was_managed'
    );
    $history->execute([
        'verified_user_id' => $user['id'],
        'managed_user_id' => $user['id'],
        'event_type' => 'account_access_changed',
    ]);
    $state = $history->fetch();
    return $state && emailVerificationRegistrationStateIsEligible(
        $user, (bool) $state['was_verified'], (bool) $state['access_was_managed']
    );
}
