<?php

/*
| Every permission the API checks, as module => actions. Synced into the
| `permissions` table by `php artisan erp:sync-permissions` (also run by the
| seeder). Later phases add their modules here.
*/

return [
    'modules' => [
        'organizations' => ['view', 'edit'],
        'institutions' => ['view', 'create', 'edit', 'activate'],
        'departments' => ['view', 'create', 'edit', 'activate'],
        'users' => ['view', 'create', 'edit', 'activate', 'reset_password'],
        'roles' => ['view', 'create', 'edit', 'delete', 'assign'],
        'audit' => ['view', 'export'],
    ],
];
