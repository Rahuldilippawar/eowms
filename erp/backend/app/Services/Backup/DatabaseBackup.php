<?php

namespace App\Services\Backup;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Whole-database backups made in PHP (erp/docs/BACKUP.md), so they work where the host does not
 * allow running mysqldump. A backup is gzip-compressed SQL, one statement per line, starting with
 * HEADER; only files that start with it are ever restored.
 */
final class DatabaseBackup
{
    public const HEADER = '-- ERP database backup v1';

    public const KINDS = ['manual', 'scheduled', 'uploaded', 'pre_restore', 'pre_clear'];

    /** Scheduled backups kept; the others stay until someone deletes them. */
    public const KEEP_SCHEDULED = 14;

    /** Per-request state that is never worth restoring. */
    private const SKIP_TABLES = ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens'];

    private const NAME = '/^erp-backup-(\d{8}-\d{6})-(manual|scheduled|uploaded|pre_restore|pre_clear)(?:-\d+)?\.sql\.gz$/';

    public function directory(): string
    {
        $dir = config('erp.backups_path') ?: storage_path('app/private/backups');
        File::ensureDirectoryExists($dir, 0750);

        return $dir;
    }

    /** @return list<array{name: string, kind: string, created_at: string, size: int}> newest first */
    public function list(): array
    {
        $rows = [];
        foreach (File::files($this->directory()) as $file) {
            if (preg_match(self::NAME, $file->getFilename(), $m)) {
                $rows[] = ['name' => $file->getFilename(), 'kind' => $m[2],
                    'created_at' => CarbonImmutable::createFromFormat('Ymd-His', $m[1], 'UTC')->toIso8601String(), 'size' => $file->getSize()];
            }
        }
        usort($rows, fn ($a, $b) => strcmp($b['name'], $a['name']));

        return $rows;
    }

    /** Full path of a backup by its file name; null when the name is not one of ours. */
    public function path(string $name): ?string
    {
        $path = $this->directory().DIRECTORY_SEPARATOR.$name;

        return preg_match(self::NAME, $name) && is_file($path) ? $path : null;
    }

    public function create(string $kind): string
    {
        if (! in_array($kind, self::KINDS, true) || $kind === 'uploaded') {
            throw new RuntimeException("Unknown backup kind {$kind}.");
        }
        @set_time_limit(0);
        $path = $this->newPath($kind);
        $tmp = $path.'.part';
        $gz = gzopen($tmp, 'wb6');
        $pdo = DB::connection()->getPdo();
        try {
            gzwrite($gz, self::HEADER.' '.now()->utc()->toIso8601String()."\n");
            gzwrite($gz, "SET FOREIGN_KEY_CHECKS=0;\n");
            foreach ($this->tables() as $table) {
                $create = (array) DB::selectOne('SHOW CREATE TABLE `'.$table.'`');
                $ddl = (string) ($create['Create Table'] ?? array_values($create)[1]);
                gzwrite($gz, "DROP TABLE IF EXISTS `{$table}`;\n".preg_replace('/\s*\R\s*/', ' ', $ddl).";\n");
                // Generated columns are computed by the database and cannot be inserted.
                $columns = collect(Schema::getColumns($table))->filter(fn ($c) => empty($c['generation']))->pluck('name')->all();
                $list = '`'.implode('`, `', $columns).'`';
                $query = DB::table($table)->select($columns);
                $rows = in_array('id', $columns, true) ? $query->lazyById(500) : $query->cursor();
                $batch = [];
                foreach ($rows as $row) {
                    $batch[] = '('.implode(', ', array_map(fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), (array) $row)).')';
                    if (count($batch) === 200) {
                        gzwrite($gz, "INSERT INTO `{$table}` ({$list}) VALUES ".implode(', ', $batch).";\n");
                        $batch = [];
                    }
                }
                if ($batch) {
                    gzwrite($gz, "INSERT INTO `{$table}` ({$list}) VALUES ".implode(', ', $batch).";\n");
                }
            }
            gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
        } catch (Throwable $e) {
            gzclose($gz);
            @unlink($tmp);
            throw $e;
        }
        gzclose($gz);
        rename($tmp, $path);
        @chmod($path, 0640);

        return basename($path);
    }

    /** Keeps an uploaded file as a backup after checking it is one of ours. */
    public function adopt(string $uploadedPath): string
    {
        if (! $this->isBackup($uploadedPath)) {
            throw new RuntimeException('not_a_backup');
        }
        $path = $this->newPath('uploaded');
        copy($uploadedPath, $path);
        @chmod($path, 0640);

        return basename($path);
    }

    public function isBackup(string $path): bool
    {
        $gz = @gzopen($path, 'rb');
        if ($gz === false) {
            return false;
        }
        $first = (string) gzgets($gz, 200);
        gzclose($gz);

        return str_starts_with($first, self::HEADER);
    }

    /**
     * Replaces the whole database with the backup, then brings the schema up to date. A backup of
     * the current database is taken first, and put back if the restore fails part-way.
     *
     * @return string the name of the safety backup taken first
     */
    public function restore(string $name): string
    {
        $path = $this->path($name) ?? throw new RuntimeException('not_found');
        if (! $this->isBackup($path)) {
            throw new RuntimeException('not_a_backup');
        }
        @set_time_limit(0);
        $safety = $this->create('pre_restore');
        app()->maintenanceMode()->activate(['time' => time(), 'retry' => 60, 'status' => 503]);
        try {
            try {
                $this->load($path);
            } catch (Throwable $e) {
                $this->load($this->path($safety));
                throw $e;
            }
            Artisan::call('migrate', ['--force' => true]);
        } finally {
            app()->maintenanceMode()->deactivate();
        }

        return $safety;
    }

    public function delete(string $name): bool
    {
        $path = $this->path($name);

        return $path !== null && @unlink($path);
    }

    /** Deletes scheduled backups beyond the newest KEEP_SCHEDULED. @return int how many */
    public function prune(): int
    {
        $old = array_slice(array_values(array_filter($this->list(), fn ($b) => $b['kind'] === 'scheduled')), self::KEEP_SCHEDULED);
        foreach ($old as $b) {
            $this->delete($b['name']);
        }

        return count($old);
    }

    private function load(string $path): void
    {
        $gz = gzopen($path, 'rb');
        $pdo = DB::connection()->getPdo();
        try {
            gzgets($gz);
            $statement = '';
            while (($line = gzgets($gz)) !== false) {
                $statement .= $line;
                // Every statement is one line ending in ";" (values are quoted with newlines escaped).
                if (str_ends_with(rtrim($line, "\r\n"), ';')) {
                    $pdo->exec($statement);
                    $statement = '';
                }
            }
        } finally {
            gzclose($gz);
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    /** @return list<string> */
    private function tables(): array
    {
        $names = array_map(fn ($t) => $t['name'], Schema::getTables(DB::connection()->getDatabaseName()));
        sort($names);

        return array_values(array_diff($names, self::SKIP_TABLES));
    }

    private function newPath(string $kind): string
    {
        $base = $this->directory().DIRECTORY_SEPARATOR.'erp-backup-'.now()->utc()->format('Ymd-His')."-{$kind}";
        $path = "{$base}.sql.gz";
        for ($i = 2; file_exists($path); $i++) {
            $path = "{$base}-{$i}.sql.gz";
        }

        return $path;
    }
}
