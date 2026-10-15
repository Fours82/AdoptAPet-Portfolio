<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/email-change-service.php';

function emailChangeRespond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function emailChangeLockActiveUser(PDO $pdo, int $userId, bool $includePasswordHash = false): ?array
{
    $statement = $pdo->prepare(
        'SELECT id, email, role, account_status, deleted_at'
        . ($includePasswordHash ? ', password_hash' : '') . '
         FROM users
         WHERE id = :id
         FOR UPDATE'
    );
    $statement->execute(['id' => $userId]);
    $user = $statement->fetch();

    if (!$user
        || $user['role'] !== 'registered_user'
        || $user['account_status'] !== 'active'
        || $user['deleted_at'] !== null) {
        return null;
    }

    return $user;
}

function emailChangeRequireAuthenticatedUser(PDO $pdo): int
{
    $userId = authenticatedUserId();
    if ($userId === null) {
        emailChangeRespond(401, ['success' => false, 'message' => 'Authentication is required.']);
    }

    $statement = $pdo->prepare(
        'SELECT id
         FROM users
         WHERE id = :id
           AND role = :role
           AND account_status = :status
           AND deleted_at IS NULL
         LIMIT 1'
    );
    $statement->execute([
        'id' => $userId,
        'role' => 'registered_user',
        'status' => 'active',
    ]);
    if (!$statement->fetch()) {
        emailChangeRespond(403, ['success' => false, 'message' => 'An active registered-user account is required.']);
    }

    return $userId;
}

/** Isolated so a future linked-account reauthentication method can be added here. */
function emailChangeReauthenticationIsValid(array $user, string $currentPassword): bool
{
    return password_verify($currentPassword, (string) ($user['password_hash'] ?? ''));
}

function emailChangeLatestPending(PDO $pdo, int $userId, bool $forUpdate = false): ?array
{
    $statement = $pdo->prepare(
        'SELECT id, user_id, new_email, verification_code_hash, attempts, expires_at, created_at,
                GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), expires_at)) AS expires_in,
                GREATEST(0, :cooldown - TIMESTAMPDIFF(SECOND, created_at, NOW())) AS resend_after
         FROM email_change_requests
         WHERE user_id = :user_id
           AND used_at IS NULL
           AND expires_at > NOW()
           AND attempts < :max_attempts
         ORDER BY created_at DESC, id DESC
         LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '')
    );
    $statement->execute([
        'user_id' => $userId,
        'cooldown' => EMAIL_CHANGE_RESEND_COOLDOWN_SECONDS,
        'max_attempts' => EMAIL_CHANGE_MAX_ATTEMPTS,
    ]);
    $request = $statement->fetch();
    return $request ?: null;
}

function emailChangeLatestCooldown(PDO $pdo, int $userId): int
{
    $statement = $pdo->prepare(
        'SELECT GREATEST(0, :cooldown - TIMESTAMPDIFF(SECOND, created_at, NOW())) AS resend_after
         FROM email_change_requests
         WHERE user_id = :user_id
           AND used_at IS NULL
         ORDER BY created_at DESC, id DESC
         LIMIT 1
         FOR UPDATE'
    );
    $statement->execute([
        'user_id' => $userId,
        'cooldown' => EMAIL_CHANGE_RESEND_COOLDOWN_SECONDS,
    ]);
    $value = $statement->fetchColumn();
    return $value === false ? 0 : max(0, (int) $value);
}

function emailChangeAddressUnavailable(PDO $pdo, string $email, int $userId): bool
{
    $statement = $pdo->prepare(
        'SELECT id
         FROM users
         WHERE email = :email
           AND id <> :user_id
         LIMIT 1
         FOR UPDATE'
    );
    $statement->execute(['email' => $email, 'user_id' => $userId]);
    return (bool) $statement->fetch();
}

function emailChangeCreateRequest(PDO $pdo, int $userId, string $newEmail, string $codeHash): int
{
    $statement = $pdo->prepare(
        'INSERT INTO email_change_requests
            (user_id, new_email, verification_code_hash, attempts, expires_at, used_at, created_at)
         VALUES
            (:user_id, :new_email, :code_hash, 0, DATE_ADD(NOW(), INTERVAL '
            . EMAIL_CHANGE_CODE_EXPIRY_SECONDS . ' SECOND), NULL, NOW())'
    );
    $statement->execute([
        'user_id' => $userId,
        'new_email' => $newEmail,
        'code_hash' => $codeHash,
    ]);
    return (int) $pdo->lastInsertId();
}

function emailChangeDeleteFailedRequest(PDO $pdo, int $userId, int $requestId): void
{
    $statement = $pdo->prepare(
        'DELETE FROM email_change_requests
         WHERE id = :id
           AND user_id = :user_id
           AND used_at IS NULL'
    );
    $statement->execute(['id' => $requestId, 'user_id' => $userId]);
}

function emailChangeFinalizeDeliveredRequest(PDO $pdo, int $userId, int $requestId): ?array
{
    $pdo->beginTransaction();
    try {
        $user = emailChangeLockActiveUser($pdo, $userId);
        if ($user === null) {
            $pdo->rollBack();
            return null;
        }

        $statement = $pdo->prepare(
            'SELECT id, new_email, attempts,
                    GREATEST(0, TIMESTAMPDIFF(SECOND, NOW(), expires_at)) AS expires_in,
                    GREATEST(0, :cooldown - TIMESTAMPDIFF(SECOND, created_at, NOW())) AS resend_after
             FROM email_change_requests
             WHERE id = :id
               AND user_id = :user_id
               AND used_at IS NULL
               AND expires_at > NOW()
               AND attempts < :max_attempts
             LIMIT 1
             FOR UPDATE'
        );
        $statement->execute([
            'id' => $requestId,
            'user_id' => $userId,
            'cooldown' => EMAIL_CHANGE_RESEND_COOLDOWN_SECONDS,
            'max_attempts' => EMAIL_CHANGE_MAX_ATTEMPTS,
        ]);
        $request = $statement->fetch();
        if (!$request) {
            $pdo->rollBack();
            return null;
        }

        $invalidate = $pdo->prepare(
            'UPDATE email_change_requests
             SET used_at = NOW()
             WHERE user_id = :user_id
               AND id <> :id
               AND used_at IS NULL'
        );
        $invalidate->execute(['user_id' => $userId, 'id' => $requestId]);
        $pdo->commit();
        return $request;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function emailChangeSendCreatedRequest(PDO $pdo, int $userId, int $requestId, string $newEmail, string $code): array
{
    try {
        sendMailWithFailureCleanup(
            'email_change_verification',
            static function () use ($newEmail, $code): void {
                sendEmailChangeVerificationEmail($newEmail, $code);
            },
            static function () use ($pdo, $userId, $requestId): void {
                emailChangeDeleteFailedRequest($pdo, $userId, $requestId);
            }
        );
    } catch (Throwable $exception) {
        emailChangeRespond(503, [
            'success' => false,
            'message' => 'Unable to send the verification email. Please try again.',
        ]);
    } finally {
        unset($code);
    }

    $request = emailChangeFinalizeDeliveredRequest($pdo, $userId, $requestId);
    if ($request === null) {
        emailChangeRespond(409, [
            'success' => false,
            'message' => 'This email-change request is no longer available. Please start again.',
        ]);
    }

    return emailChangePendingResponse($request);
}

function emailChangeReadJson(): array
{
    $raw = file_get_contents('php://input');
    $input = json_decode($raw === false ? '' : $raw, true);
    if (!is_array($input) || array_is_list($input)) {
        emailChangeRespond(400, ['success' => false, 'message' => 'Invalid JSON request body.']);
    }
    return $input;
}

function emailChangeRequireFields(array $input, array $allowed): void
{
    if (array_diff(array_keys($input), $allowed) !== []) {
        emailChangeRespond(422, ['success' => false, 'message' => 'Invalid email-change request.']);
    }
}

function emailChangeDuplicateEmailFailure(PDOException $exception): bool
{
    return $exception->getCode() === '23000'
        && (int) ($exception->errorInfo[1] ?? 0) === 1062;
}

try {
    $pdo = getDatabaseConnection();
    $userId = emailChangeRequireAuthenticatedUser($pdo);
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        if ($_GET !== []) {
            emailChangeRespond(422, ['success' => false, 'message' => 'Email-change status does not accept query parameters.']);
        }
        $pending = emailChangeLatestPending($pdo, $userId);
        emailChangeRespond(200, [
            'success' => true,
            'pending' => $pending === null ? null : emailChangePendingResponse($pending),
        ]);
    }

    if ($method !== 'POST') {
        header('Allow: GET, POST');
        emailChangeRespond(405, ['success' => false, 'message' => 'Method not allowed.']);
    }

    requireValidCsrfToken();
    $input = emailChangeReadJson();
    $action = strtolower(trim((string) ($input['action'] ?? '')));

    if ($action === 'start') {
        emailChangeRequireFields($input, ['action', 'current_password', 'new_email']);
        $currentPassword = (string) ($input['current_password'] ?? '');
        $newEmail = normalizeEmailChangeAddress((string) ($input['new_email'] ?? ''));
        if ($currentPassword === '' || !isValidEmailChangeAddress($newEmail)) {
            emailChangeRespond(422, ['success' => false, 'message' => 'Enter your current password and a valid new email address.']);
        }

        $pdo->beginTransaction();
        $user = emailChangeLockActiveUser($pdo, $userId, true);
        if ($user === null) {
            $pdo->rollBack();
            emailChangeRespond(403, ['success' => false, 'message' => 'An active registered-user account is required.']);
        }
        if (!emailChangeReauthenticationIsValid($user, $currentPassword)) {
            unset($currentPassword);
            $pdo->rollBack();
            emailChangeRespond(422, [
                'success' => false,
                'code' => 'current_password_incorrect',
                'message' => 'Current password is incorrect.',
            ]);
        }
        unset($currentPassword);
        unset($user['password_hash']);

        if (hash_equals(normalizeEmailChangeAddress((string) $user['email']), $newEmail)
            || emailChangeAddressUnavailable($pdo, $newEmail, $userId)) {
            $pdo->rollBack();
            emailChangeRespond(422, ['success' => false, 'message' => 'This email address cannot be used.']);
        }

        $retryAfter = emailChangeLatestCooldown($pdo, $userId);
        if ($retryAfter > 0) {
            $pdo->rollBack();
            emailChangeRespond(429, [
                'success' => false,
                'message' => 'Please wait before sending another verification code.',
                'retry_after' => $retryAfter,
            ]);
        }

        $code = generateEmailChangeCode();
        $codeHash = password_hash($code, PASSWORD_DEFAULT);
        if (!is_string($codeHash)) {
            throw new RuntimeException('Unable to hash email-change code.');
        }
        $requestId = emailChangeCreateRequest($pdo, $userId, $newEmail, $codeHash);
        unset($codeHash);
        $pdo->commit();

        $pending = emailChangeSendCreatedRequest($pdo, $userId, $requestId, $newEmail, $code);
        unset($code);
        emailChangeRespond(200, [
            'success' => true,
            'message' => 'A verification code was sent to your new email address.',
            'pending' => $pending,
        ]);
    }

    if ($action === 'resend') {
        emailChangeRequireFields($input, ['action']);
        $pdo->beginTransaction();
        $user = emailChangeLockActiveUser($pdo, $userId);
        if ($user === null) {
            $pdo->rollBack();
            emailChangeRespond(403, ['success' => false, 'message' => 'An active registered-user account is required.']);
        }
        $request = emailChangeLatestPending($pdo, $userId, true);
        if ($request === null) {
            $pdo->rollBack();
            emailChangeRespond(422, ['success' => false, 'message' => 'No active email-change request is available. Please start again.']);
        }
        $retryAfter = max(0, (int) $request['resend_after']);
        if ($retryAfter > 0) {
            $pdo->rollBack();
            emailChangeRespond(429, [
                'success' => false,
                'message' => 'Please wait before sending another verification code.',
                'retry_after' => $retryAfter,
                'pending' => emailChangePendingResponse($request),
            ]);
        }

        $newEmail = normalizeEmailChangeAddress((string) $request['new_email']);
        if (emailChangeAddressUnavailable($pdo, $newEmail, $userId)) {
            $pdo->rollBack();
            emailChangeRespond(422, ['success' => false, 'message' => 'This email address cannot be used.']);
        }

        $code = generateEmailChangeCode();
        $codeHash = password_hash($code, PASSWORD_DEFAULT);
        if (!is_string($codeHash)) {
            throw new RuntimeException('Unable to hash email-change code.');
        }
        $requestId = emailChangeCreateRequest($pdo, $userId, $newEmail, $codeHash);
        unset($codeHash);
        $pdo->commit();

        $pending = emailChangeSendCreatedRequest($pdo, $userId, $requestId, $newEmail, $code);
        unset($code);
        emailChangeRespond(200, [
            'success' => true,
            'message' => 'A new verification code was sent.',
            'pending' => $pending,
        ]);
    }

    if ($action === 'verify') {
        emailChangeRequireFields($input, ['action', 'verification_code']);
        $code = trim((string) ($input['verification_code'] ?? ''));
        if (!isValidEmailChangeCodeFormat($code)) {
            emailChangeRespond(422, ['success' => false, 'message' => 'Enter the 6-digit verification code.']);
        }

        $pdo->beginTransaction();
        $user = emailChangeLockActiveUser($pdo, $userId);
        if ($user === null) {
            $pdo->rollBack();
            emailChangeRespond(403, ['success' => false, 'message' => 'An active registered-user account is required.']);
        }
        $request = emailChangeLatestPending($pdo, $userId, true);
        if ($request === null) {
            $pdo->rollBack();
            emailChangeRespond(422, ['success' => false, 'message' => 'The verification code is invalid or has expired.']);
        }

        if (!password_verify($code, (string) $request['verification_code_hash'])) {
            unset($code);
            $newAttemptCount = (int) $request['attempts'] + 1;
            $failure = $pdo->prepare(
                'UPDATE email_change_requests
                 SET attempts = :attempt_count,
                     used_at = CASE WHEN :attempt_limit_count >= :max_attempts THEN NOW() ELSE used_at END
                 WHERE id = :id
                   AND user_id = :user_id
                   AND used_at IS NULL'
            );
            $failure->execute([
                'attempt_count' => $newAttemptCount,
                'attempt_limit_count' => $newAttemptCount,
                'max_attempts' => EMAIL_CHANGE_MAX_ATTEMPTS,
                'id' => $request['id'],
                'user_id' => $userId,
            ]);
            $pdo->commit();
            emailChangeRespond(422, [
                'success' => false,
                'message' => emailChangeInvalidCodeMessage($newAttemptCount),
                'attempts_remaining' => max(0, EMAIL_CHANGE_MAX_ATTEMPTS - $newAttemptCount),
            ]);
        }
        unset($code);

        $newEmail = normalizeEmailChangeAddress((string) $request['new_email']);
        if (hash_equals(normalizeEmailChangeAddress((string) $user['email']), $newEmail)
            || emailChangeAddressUnavailable($pdo, $newEmail, $userId)) {
            $pdo->rollBack();
            emailChangeRespond(422, ['success' => false, 'message' => 'This email address cannot be used.']);
        }

        $oldEmail = (string) $user['email'];
        $updateUser = $pdo->prepare('UPDATE users SET email = :email WHERE id = :id');
        $updateUser->execute(['email' => $newEmail, 'id' => $userId]);

        $consume = $pdo->prepare(
            'UPDATE email_change_requests
             SET used_at = NOW()
             WHERE user_id = :user_id
               AND used_at IS NULL'
        );
        $consume->execute(['user_id' => $userId]);
        securityRevokeOtherSessions($pdo, $userId, securityCurrentRegistryId(), 'email_changed');
        securityRecordEvent($pdo, $userId, 'email_changed');
        $pdo->commit();

        if (!session_regenerate_id(true)) {
            error_log('AdoptAPet email change session regeneration did not complete.');
        }
        $csrfToken = rotateCsrfToken();

        try {
            sendEmailChangeSecurityNotice($oldEmail);
        } catch (Throwable $mailException) {
            logMailSendFailure('email_change_security_notice', $mailException);
        }

        emailChangeRespond(200, [
            'success' => true,
            'message' => 'Your email address was changed successfully.',
            'email' => $newEmail,
            'csrf_token' => $csrfToken,
        ]);
    }

    if ($action === 'cancel') {
        emailChangeRequireFields($input, ['action']);
        $pdo->beginTransaction();
        $user = emailChangeLockActiveUser($pdo, $userId);
        if ($user === null) {
            $pdo->rollBack();
            emailChangeRespond(403, ['success' => false, 'message' => 'An active registered-user account is required.']);
        }
        $cancel = $pdo->prepare(
            'UPDATE email_change_requests
             SET used_at = NOW()
             WHERE user_id = :user_id
               AND used_at IS NULL'
        );
        $cancel->execute(['user_id' => $userId]);
        $pdo->commit();
        emailChangeRespond(200, ['success' => true, 'message' => 'The email-change request was cancelled.']);
    }

    emailChangeRespond(422, ['success' => false, 'message' => 'Invalid email-change request.']);
} catch (PDOException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (emailChangeDuplicateEmailFailure($exception)) {
        emailChangeRespond(422, ['success' => false, 'message' => 'This email address cannot be used.']);
    }
    error_log('AdoptAPet email change database failure.');
    emailChangeRespond(500, ['success' => false, 'message' => 'Unable to manage the email change. Please try again later.']);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('AdoptAPet email change request failure.');
    emailChangeRespond(500, ['success' => false, 'message' => 'Unable to manage the email change. Please try again later.']);
}
