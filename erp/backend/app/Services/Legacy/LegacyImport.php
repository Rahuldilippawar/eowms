<?php

namespace App\Services\Legacy;

use App\Services\Legacy\Steps\DepartmentHeadsStep;
use App\Services\Legacy\Steps\DepartmentsStep;
use App\Services\Legacy\Steps\EmployeesStep;
use App\Services\Legacy\Steps\InstitutionsStep;
use App\Services\Legacy\Steps\OrganizationsStep;
use App\Services\Legacy\Steps\StudentsStep;
use App\Services\Legacy\Steps\UsersStep;
use App\Support\Access\AccessContext;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Imports the old app's data from a copy of its database (erp/docs/LEGACY-IMPORT.md).
 *
 * Every run happens in one transaction: a dry run rolls it back after writing the report, an import
 * commits it, and a failure leaves nothing behind. Rows already imported (legacy_id_map) are skipped,
 * so an import can be run again after fixing what the report shows.
 */
final class LegacyImport
{
    /** Steps in dependency order. @var list<class-string<Step>> */
    public const STEPS = [
        OrganizationsStep::class, InstitutionsStep::class, DepartmentsStep::class, UsersStep::class,
        EmployeesStep::class, DepartmentHeadsStep::class, StudentsStep::class,
    ];

    /** Kept per step in the report; the counts are always complete. */
    private const MAX_ITEMS = 200;

    /** @var array<string, array{read: int, created: int, existing: int, matched: int, skipped: int, skips: list<array{table: string, id: int|null, reason: string}>, warnings: list<string>}> */
    private array $report = [];

    private string $step = '';

    public static function configured(): bool
    {
        return (string) config('database.connections.legacy.database') !== '';
    }

    public function legacy(): Connection
    {
        return DB::connection('legacy');
    }

    /** @return array{connected: bool, error?: string, tables?: array<string, int>} rows in the old tables the steps read */
    public function check(): array
    {
        if (! self::configured()) {
            return ['connected' => false, 'error' => 'not_configured'];
        }
        try {
            $tables = [];
            foreach (['organizations', 'campuses', 'departments', 'users', 'students'] as $t) {
                $tables[$t] = $this->legacy()->getSchemaBuilder()->hasTable($t) ? $this->legacy()->table($t)->count() : 0;
            }

            return ['connected' => true, 'tables' => $tables];
        } catch (Throwable $e) {
            return ['connected' => false, 'error' => 'connection_failed'];
        }
    }

    /** @return array<string, mixed> the report */
    public function run(bool $dryRun): array
    {
        $previous = AccessContext::current();
        // Rows are written for many organizations: no organization context while importing.
        AccessContext::set(null);
        DB::beginTransaction();
        try {
            foreach (self::STEPS as $class) {
                $step = app($class);
                $this->step = $step->key();
                $this->report[$this->step] = ['read' => 0, 'created' => 0, 'existing' => 0, 'matched' => 0, 'skipped' => 0, 'skips' => [], 'warnings' => []];
                $step->run($this);
            }
            $dryRun ? DB::rollBack() : DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        } finally {
            AccessContext::set($previous);
        }

        return $this->report;
    }

    public function mapped(string $sourceTable, int|string|null $sourceId): ?int
    {
        if ($sourceId === null || $sourceId === '' || (int) $sourceId === 0) {
            return null;
        }
        $id = DB::table('legacy_id_map')->where(['source_table' => $sourceTable, 'source_id' => (int) $sourceId])->value('target_id');

        return $id === null ? null : (int) $id;
    }

    public function remember(string $sourceTable, int $sourceId, string $targetTable, int $targetId): void
    {
        DB::table('legacy_id_map')->insert(['source_table' => $sourceTable, 'source_id' => $sourceId, 'target_table' => $targetTable, 'target_id' => $targetId]);
    }

    public function read(int $n = 1): void
    {
        $this->report[$this->step]['read'] += $n;
    }

    public function created(): void
    {
        $this->report[$this->step]['created']++;
    }

    /** Already imported by an earlier run. */
    public function existing(): void
    {
        $this->report[$this->step]['existing']++;
    }

    /** Joined to a row the ERP already had (e.g. an account with the same e-mail). */
    public function matched(): void
    {
        $this->report[$this->step]['matched']++;
    }

    public function skip(string $table, int|string|null $id, string $reason): void
    {
        $r = &$this->report[$this->step];
        $r['skipped']++;
        if (count($r['skips']) < self::MAX_ITEMS) {
            $r['skips'][] = ['table' => $table, 'id' => $id === null ? null : (int) $id, 'reason' => $reason];
        }
    }

    public function warn(string $message): void
    {
        $r = &$this->report[$this->step];
        if (count($r['warnings']) < self::MAX_ITEMS) {
            $r['warnings'][] = $message;
        }
    }
}
