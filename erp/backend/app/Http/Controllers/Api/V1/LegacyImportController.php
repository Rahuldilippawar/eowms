<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Legacy\LegacyImport;
use App\Services\Legacy\LegacyImportRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** The old-data importer for super admins (erp/docs/LEGACY-IMPORT.md). */
class LegacyImportController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $this->superAdmin($request);

        return response()->json(['data' => [
            'source' => (new LegacyImport)->check(),
            'runs' => DB::table('legacy_import_runs')->orderByDesc('id')->limit(20)->get(['id', 'mode', 'status', 'error', 'safety_backup', 'started_at', 'finished_at'])
                ->map(fn ($r) => (array) $r)->values(),
        ]]);
    }

    public function report(Request $request, int $id): JsonResponse
    {
        $this->superAdmin($request);
        $run = DB::table('legacy_import_runs')->find($id) ?? abort(404);

        return response()->json(['data' => [...(array) $run, 'report' => json_decode((string) $run->report, true)]]);
    }

    /** A dry run needs nothing more; an import needs the password and "IMPORT". */
    public function run(Request $request, LegacyImportRunner $runner): JsonResponse
    {
        $this->superAdmin($request);
        $data = $request->validate(['mode' => ['required', 'in:dry_run,import'], 'password' => ['required_if:mode,import', 'nullable', 'string'],
            'confirmation' => ['required_if:mode,import', 'nullable', 'string']]);
        abort_unless((new LegacyImport)->check()['connected'], 409, __('legacy.not_connected'));
        if ($data['mode'] === 'import') {
            if (! Hash::check((string) $data['password'], $request->user()->password)) {
                throw ValidationException::withMessages(['password' => [__('backup.wrong_password')]]);
            }
            if (trim((string) $data['confirmation']) !== 'IMPORT') {
                throw ValidationException::withMessages(['confirmation' => [__('backup.confirmation_mismatch')]]);
            }
        }

        return response()->json(['data' => $runner->run($data['mode'] === 'dry_run', $request->user())]);
    }

    private function superAdmin(Request $request): void
    {
        abort_unless($request->user()->is_super_admin, 403, __('auth.forbidden'));
    }
}
