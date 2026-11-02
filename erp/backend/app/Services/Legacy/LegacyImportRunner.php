<?php

namespace App\Services\Legacy;

use App\Models\User;
use App\Services\Backup\DatabaseBackup;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Runs a dry run or an import and keeps its report (erp/docs/LEGACY-IMPORT.md). */
final class LegacyImportRunner
{
    public function __construct(private readonly DatabaseBackup $backups) {}

    /** @return array{id: int, status: string, report: array<string, mixed>|null, error: string|null, safety_backup: string|null} */
    public function run(bool $dryRun, ?User $actor): array
    {
        @set_time_limit(0);
        $safety = $dryRun ? null : $this->backups->create('pre_import');
        $started = now();
        try {
            $report = (new LegacyImport)->run($dryRun);
            $status = 'done';
            $error = null;
        } catch (Throwable $e) {
            report($e);
            $report = null;
            $status = 'failed';
            $error = mb_substr($e->getMessage(), 0, 2000);
        }
        $id = DB::table('legacy_import_runs')->insertGetId([
            'mode' => $dryRun ? 'dry_run' : 'import', 'status' => $status, 'report' => $report === null ? null : json_encode($report, JSON_UNESCAPED_UNICODE),
            'error' => $error, 'safety_backup' => $safety, 'user_id' => $actor?->id, 'started_at' => $started, 'finished_at' => now(),
        ]);
        AuditLogger::record('legacy_import', $dryRun ? 'dry_run' : 'import', null, null, ['run' => $id, 'status' => $status], userId: $actor?->id);

        return ['id' => $id, 'status' => $status, 'report' => $report, 'error' => $error, 'safety_backup' => $safety];
    }
}
