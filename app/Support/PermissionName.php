<?php

namespace App\Support;

use InvalidArgumentException;

class PermissionName
{
    /**
     * The frozen PRD-02 permission naming pattern.
     */
    private const PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*\.[a-z0-9]+(?:-[a-z0-9]+)*\.[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /**
     * Determine whether the given permission name matches the frozen convention.
     */
    public static function isValid(string $permission): bool
    {
        return preg_match(self::PATTERN, $permission) === 1;
    }

    /**
     * Assert that the given permission name matches the frozen convention.
     *
     * @throws InvalidArgumentException
     */
    public static function assertValid(string $permission): void
    {
        if (self::isValid($permission)) {
            return;
        }

        throw new InvalidArgumentException('Permission names must use lowercase {context}.{resource}.{action} dot notation.');
    }
}
