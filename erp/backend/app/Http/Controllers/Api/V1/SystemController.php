<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Backup\DatabaseBackup;
use App\Services\Push\WebPush;
use App\Support\Audit\AuditLogger;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * System status for super admins (erp/docs/SYSTEM.md): versions, settings that matter in production,
 * whether the scheduler runs, and the database update (pending migrations) the old app's "DB update"
 * screen did: on FTP-only hosting there is no shell to run `php artisan migrate`.
 */
class SystemController extends Controller
{
    public const HEARTBEAT_KEY = 'erp:scheduler-heartbeat';

    public function show(Request $request, DatabaseBackup $backups): JsonResponse
    {
        $this->superAdmin($request);
        $beat = Cache::get(self::HEARTBEAT_KEY);
        $latest = collect($backups->list())->first();
        $url = (string) config('app.url');

        return response()->json(['data' => [
            'version' => (string) config('app.version', ''),
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'database' => DB::selectOne('select version() as v')->v ?? null,
            'pending_migrations' => $this->pending(),
            'scheduler_last_run' => $beat,
            'checks' => [
                'debug_off' => ! config('app.debug'),
                'https' => str_starts_with($url, 'https://'),
                'mail' => ! in_array(config('mail.default'), ['log', 'array'], true),
                'push' => WebPush::configured(),
                'scheduler' => $beat !== null && abs(now()->diffInMinutes($beat)) <= 10,
                'storage_writable' => is_writable(storage_path('app')),
                'recent_backup' => $latest !== null && abs(now()->diffInHours($latest['created_at'])) <= 26,
            ],
            'latest_backup' => $latest,
            'app_url' => $url,
            'webauthn_rp_id' => (string) (config('services.webauthn.rp_id') ?: parse_url($url, PHP_URL_HOST)),
        ]]);
    }

    /** Runs pending migrations after a safety backup; needs the password and "MIGRATE". */
    public function migrate(Request $request, DatabaseBackup $backups): JsonResponse
    {
        $this->superAdmin($request);
        $data = $request->validate(['password' => ['required', 'string'], 'confirmation' => ['required', 'string']]);
        if (! Hash::check($data['password'], $request->user()->password)) {
            throw ValidationException::withMessages(['password' => [__('backup.wrong_password')]]);
        }
        if (trim($data['confirmation']) !== 'MIGRATE') {
            throw ValidationException::withMessages(['confirmation' => [__('backup.confirmation_mismatch')]]);
        }
        $pending = $this->pending();
        if ($pending === []) {
            return response()->json(['data' => ['ran' => [], 'safety_backup' => null]]);
        }
        @set_time_limit(0);
        $safety = $backups->create('pre_migrate');
        Artisan::call('migrate', ['--force' => true]);
        AuditLogger::record('system', 'migrate', null, null, ['ran' => $pending, 'safety_backup' => $safety]);

        return response()->json(['data' => ['ran' => $pending, 'safety_backup' => $safety, 'output' => trim(Artisan::output())]]);
    }

    /** @return list<string> migration names not run yet */
    private function pending(): array
    {
        /** @var Migrator $migrator */
        $migrator = app('migrator');
        if (! $migrator->repositoryExists()) {
            return ['(migrations table)'];
        }
        $files = $migrator->getMigrationFiles([database_path('migrations')]);

        return array_values(array_diff(array_keys($files), $migrator->getRepository()->getRan()));
    }

    private function superAdmin(Request $request): void
    {
        abort_unless($request->user()->is_super_admin, 403, __('auth.forbidden'));
    }
}
