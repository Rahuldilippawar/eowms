<?php

namespace App\Console\Commands;

use App\Support\Access\PermissionCatalog;
use Illuminate\Console\Command;

class SyncPermissions extends Command
{
    protected $signature = 'erp:sync-permissions';

    protected $description = 'Add permissions declared in config/permissions.php to the database (never removes any).';

    public function handle(): int
    {
        $added = PermissionCatalog::sync();
        $this->info("Permissions synced ({$added} added).");

        return self::SUCCESS;
    }
}
