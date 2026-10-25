<?php

namespace App\Support\Access;

use App\Models\Organization;

final class Modules
{
    /** @return list<string> */
    public static function toggleable(): array
    {
        return config('modules.toggleable');
    }

    /** @return list<string> switched-off module keys of $organization */
    public static function disabled(?Organization $organization): array
    {
        $disabled = (array) (($organization?->settings ?? [])['modules_disabled'] ?? []);

        return array_values(array_intersect(self::toggleable(), $disabled));
    }

    public static function permissionAllowed(?Organization $organization, string $permission): bool
    {
        return ! in_array(strtok($permission, '.'), self::disabled($organization), true);
    }
}
