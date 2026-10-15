<?php
declare(strict_types=1);

const ADOPTAPET_REGISTERED_ROLE = 'registered_user';
const ADOPTAPET_ADMIN_ROLE = 'administrator';
const ADOPTAPET_SUPER_ADMIN_ROLE = 'super_administrator';

function isOperationalAdministratorRole(mixed $role): bool
{
    return $role === ADOPTAPET_ADMIN_ROLE || $role === ADOPTAPET_SUPER_ADMIN_ROLE;
}

function isKnownAuthenticatedRole(mixed $role): bool
{
    return $role === ADOPTAPET_REGISTERED_ROLE || isOperationalAdministratorRole($role);
}

function isSuperAdministratorRole(mixed $role): bool
{
    return $role === ADOPTAPET_SUPER_ADMIN_ROLE;
}
