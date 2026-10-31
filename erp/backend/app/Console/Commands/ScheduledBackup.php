<?php

namespace App\Console\Commands;

use App\Services\Backup\DatabaseBackup;
use App\Support\Audit\AuditLogger;
use Illuminate\Console\Command;

/** The nightly whole-database backup; keeps the newest scheduled ones (erp/docs/BACKUP.md). */
class ScheduledBackup extends Command
{
    protected $signature = 'erp:backup';

    protected $description = 'Back up the whole database and remove scheduled backups beyond the newest ones kept.';

    public function handle(DatabaseBackup $backups): int
    {
        $name = $backups->create('scheduled');
        $removed = $backups->prune();
        AuditLogger::record('backup', 'scheduled', null, null, ['file' => $name, 'removed' => $removed]);
        $this->info("Backup {$name}; removed {$removed} old.");

        return self::SUCCESS;
    }
}
