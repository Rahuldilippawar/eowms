<?php

namespace App\Console\Commands;

use App\Support\Access\DefaultRoles;
use App\Support\Access\PermissionCatalog;
use Illuminate\Console\Command;

class SyncPermissions extends Command
{
    protected $signature = 'erp:sync-permissions';

    protected $description = 'Add permissions declared in config/permissions.php (never removes any) and give them to Organization Admin roles.';

    public function handle(): int
    {
        $added = PermissionCatalog::sync();
        $granted = DefaultRoles::grantAllToOrganizationAdmins();
        $this->info("Permissions synced ({$added} added; {$granted} grants added to Organization Admin roles).");

        return self::SUCCESS;
    }
}
