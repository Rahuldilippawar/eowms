<?php

namespace App\Services\Backup;

use App\Models\Organization;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One organization's data (erp/docs/BACKUP.md): a read-only JSON export, and clearing its working
 * data while keeping its structure, people's accounts, roles and settings.
 */
final class OrganizationData
{
    /** Kept by "clear data": structure, accounts and roles, settings, and the append-only audit log. */
    public const KEEP = [
        'organizations', 'institutions', 'departments', 'users', 'roles', 'role_assignments', 'role_assignment_department',
        'role_assignment_institution', 'role_permission', 'permissions', 'audit_logs', 'migrations',
        'custom_fields', 'leave_types', 'letter_categories', 'approval_workflows', 'approval_workflow_steps', 'holidays', 'number_sequences',
        'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens',
    ];

    /** Never exported: secrets, and what would undo an anonymous grievance or reveal private ids. */
    private const EXPORT_SKIP_TABLES = ['users', 'sessions', 'password_reset_tokens', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'migrations', 'permissions', 'audit_logs'];

    private const EXPORT_SKIP_COLUMNS = ['password', 'remember_token', 'submitter_hash', 'two_factor_secret', 'two_factor_recovery_codes'];

    public function export(Organization $org): StreamedResponse
    {
        $tables = $this->scopedTables($org);
        $name = 'organization-'.($org->code ?: $org->id).'-'.now()->format('Ymd-His').'.json';

        return response()->streamDownload(function () use ($org, $tables) {
            echo '{"format":"erp-organization-export-v1","exported_at":'.json_encode(now()->toIso8601String()).',"organization":'
                .json_encode(['id' => $org->id, 'name' => $org->name, 'code' => $org->code], JSON_UNESCAPED_UNICODE).',"tables":{';
            $firstTable = true;
            foreach ($tables as $table => $rows) {
                echo ($firstTable ? '' : ',').json_encode($table).':[';
                $firstTable = false;
                $first = true;
                foreach ($rows->cursor() as $row) {
                    echo ($first ? '' : ',').json_encode(array_diff_key((array) $row, array_flip(self::EXPORT_SKIP_COLUMNS)), JSON_UNESCAPED_UNICODE);
                    $first = false;
                }
                echo ']';
            }
            echo '}}';
        }, $name, ['Content-Type' => 'application/json; charset=UTF-8']);
    }

    /**
     * Deletes the organization's working data, then rows of other tables left pointing at deleted
     * rows. Users, structure, roles and settings stay. @return array<string, int> rows deleted per table
     */
    public function clear(Organization $org): array
    {
        $db = DB::getDatabaseName();
        $deleted = [];
        $fks = collect(DB::select('select table_name as t, column_name as c, referenced_table_name as rt, referenced_column_name as rc
            from information_schema.key_column_usage where table_schema = ? and referenced_table_name is not null', [$db]))
            ->map(fn ($r) => array_change_key_case((array) $r));
        $nullable = collect(DB::select("select table_name as t, column_name as c from information_schema.columns where table_schema = ? and is_nullable = 'YES'", [$db]))
            ->map(fn ($r) => array_change_key_case((array) $r))->map(fn ($r) => "{$r['t']}.{$r['c']}")->flip();

        DB::transaction(function () use ($org, $fks, $nullable, &$deleted) {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            try {
                foreach ($this->tablesWith('organization_id') as $table) {
                    if (! in_array($table, self::KEEP, true)) {
                        $deleted[$table] = DB::table($table)->where('organization_id', $org->id)->delete();
                    }
                }
                // Rows left pointing at deleted rows: deleted in working tables, unlinked in kept ones.
                do {
                    $changed = 0;
                    foreach ($fks as $fk) {
                        // A table pointing at itself is read through a materialised copy: MySQL refuses a
                        // DELETE / UPDATE whose subquery reads the same table (error 1093); DISTINCT keeps
                        // the derived table from being merged back into the outer query.
                        $parent = $fk['t'] === $fk['rt']
                            ? DB::raw('(select distinct `'.$fk['rc'].'` from `'.$fk['rt'].'`) as p')
                            : $fk['rt'].' as p';
                        $orphans = DB::table($fk['t'])->whereNotNull($fk['c'])
                            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from($parent)->whereColumn('p.'.$fk['rc'], $fk['t'].'.'.$fk['c']));
                        if (! in_array($fk['t'], self::KEEP, true)) {
                            $n = $orphans->delete();
                            $deleted[$fk['t']] = ($deleted[$fk['t']] ?? 0) + $n;
                            $changed += $n;
                        } elseif ($nullable->has("{$fk['t']}.{$fk['c']}")) {
                            $changed += $orphans->update([$fk['c'] => null]);
                        }
                    }
                } while ($changed > 0);
            } finally {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }
        });

        return array_filter($deleted);
    }

    /** @return array<string, Builder> each table's rows of this organization */
    private function scopedTables(Organization $org): array
    {
        $out = ['organizations' => DB::table('organizations')->where('id', $org->id)];
        foreach ($this->tablesWith('organization_id') as $table) {
            if (! in_array($table, self::EXPORT_SKIP_TABLES, true)) {
                $out[$table] = DB::table($table)->where('organization_id', $org->id)->orderBy(Schema::hasColumn($table, 'id') ? 'id' : 'organization_id');
            }
        }
        // People's accounts: names and e-mail only.
        $out['users'] = DB::table('users')->whereIn('id', DB::table('role_assignments')->where('organization_id', $org->id)->select('user_id'))
            ->orderBy('id')->select(['id', 'name', 'email', 'is_active', 'last_login_at']);

        return $out;
    }

    /** @return list<string> */
    private function tablesWith(string $column): array
    {
        return collect(DB::select('select table_name as t from information_schema.columns where table_schema = ? and column_name = ? order by table_name', [DB::getDatabaseName(), $column]))
            ->map(fn ($r) => array_change_key_case((array) $r)['t'])->values()->all();
    }
}
