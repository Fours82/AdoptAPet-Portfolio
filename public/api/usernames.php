<?php
declare(strict_types=1);

const USERNAME_PATTERN = '/\A[A-Za-z0-9_]{3,20}\z/D';

function isValidUsername(string $username): bool
{
    return preg_match(USERNAME_PATTERN, $username) === 1;
}

function usernameValidationMessage(): string
{
    return 'Username must be 3–20 characters using letters, numbers, and underscores only.';
}

function usernameIsTaken(PDO $pdo, string $username, ?int $exceptUserId = null): bool
{
    $sql = 'SELECT id FROM users WHERE username = :username';
    $parameters = ['username' => $username];

    if ($exceptUserId !== null) {
        $sql .= ' AND id <> :except_user_id';
        $parameters['except_user_id'] = $exceptUserId;
    }

    $sql .= ' LIMIT 1';
    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);

    return (bool) $statement->fetch();
}

function isUsernameUniqueConstraintViolation(PDOException $exception): bool
{
    $driverMessage = strtolower((string) ($exception->errorInfo[2] ?? ''));

    return (int) ($exception->errorInfo[1] ?? 0) === 1062
        && str_contains($driverMessage, 'uq_users_username');
}
