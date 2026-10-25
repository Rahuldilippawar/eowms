<?php

namespace App\Support\Access;

use App\Models\Permission;

final class PermissionCatalog
{
    /** @return list<string> every permission key declared in config/permissions.php */
    public static function keys(): array
    {
        $keys = [];
        foreach (config('permissions.modules') as $module => $actions) {
            foreach ($actions as $action) {
                $keys[] = "{$module}.{$action}";
            }
        }

        return $keys;
    }

    /** Inserts missing permissions; returns how many were added. Never deletes. */
    public static function sync(): int
    {
        $added = 0;
        foreach (self::keys() as $key) {
            [$module, $action] = explode('.', $key, 2);
            $permission = Permission::firstOrCreate(['key' => $key], ['module' => $module, 'action' => $action]);
            $added += $permission->wasRecentlyCreated ? 1 : 0;
        }

        return $added;
    }
}
