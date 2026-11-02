<?php

namespace App\Console\Commands;

use App\Services\Legacy\LegacyImport;
use App\Services\Legacy\LegacyImportRunner;
use Illuminate\Console\Command;

/** The old app's data into the ERP, from a copy of its database (erp/docs/LEGACY-IMPORT.md). */
class LegacyImportCommand extends Command
{
    protected $signature = 'erp:legacy-import {--dry-run : Show what would be imported, write nothing}';

    protected $description = 'Import the old app\'s data from the LEGACY_DB_* database copy (a dry run writes nothing).';

    public function handle(LegacyImportRunner $runner): int
    {
        $check = (new LegacyImport)->check();
        if (! $check['connected']) {
            $this->error('The old database copy is not reachable ('.$check['error'].'). Set LEGACY_DB_* in .env.');

            return self::FAILURE;
        }
        $dry = (bool) $this->option('dry-run');
        if (! $dry && ! $this->confirm('Import into this ERP now? A backup is taken first.')) {
            return self::FAILURE;
        }
        $result = $runner->run($dry, null);
        if ($result['status'] !== 'done') {
            $this->error('Failed: '.$result['error'].' (nothing was written)');

            return self::FAILURE;
        }
        $this->table(['step', 'read', 'created', 'already', 'joined', 'skipped', 'warnings'], collect($result['report'])->map(fn ($r, $k) => [
            $k, $r['read'], $r['created'], $r['existing'], $r['matched'], $r['skipped'], count($r['warnings']),
        ])->values()->all());
        $this->info(($dry ? 'Dry run: nothing was written.' : 'Imported. Safety backup: '.$result['safety_backup']).' Report #'.$result['id'].'.');

        return self::SUCCESS;
    }
}
