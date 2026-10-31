<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PermissionScope;
use App\Http\Controllers\Controller;
use App\Services\Backup\DatabaseBackup;
use App\Services\Backup\OrganizationData;
use App\Support\Access\AccessContext;
use App\Support\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Backups (erp/docs/BACKUP.md). Whole-database backup and restore: super admins only. An
 * organization's export and "clear data": organization-wide organizations.edit. Restoring and
 * clearing need the person's password again and a typed confirmation.
 */
class BackupController extends Controller
{
    public function __construct(private readonly DatabaseBackup $backups) {}

    public function index(Request $request): JsonResponse
    {
        $this->superAdmin($request);

        return response()->json(['data' => $this->backups->list(), 'meta' => ['keep_scheduled' => DatabaseBackup::KEEP_SCHEDULED]]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->superAdmin($request);
        $name = $this->backups->create('manual');
        AuditLogger::record('backup', 'create', null, null, ['file' => $name]);

        return response()->json(['data' => ['name' => $name]], 201);
    }

    public function upload(Request $request): JsonResponse
    {
        $this->superAdmin($request);
        $request->validate(['file' => ['required', 'file', 'max:512000']]);
        try {
            $name = $this->backups->adopt($request->file('file')->getRealPath());
        } catch (RuntimeException) {
            throw ValidationException::withMessages(['file' => [__('backup.not_a_backup')]]);
        }
        AuditLogger::record('backup', 'upload', null, null, ['file' => $name]);

        return response()->json(['data' => ['name' => $name]], 201);
    }

    public function download(Request $request, string $name): BinaryFileResponse
    {
        $this->superAdmin($request);
        $path = $this->backups->path($name) ?? abort(404);
        AuditLogger::record('backup', 'download', null, null, ['file' => $name]);

        return response()->download($path, $name, ['Content-Type' => 'application/gzip', 'Cache-Control' => 'private, no-store']);
    }

    public function destroy(Request $request, string $name): JsonResponse
    {
        $this->superAdmin($request);
        abort_unless($this->backups->delete($name), 404);
        AuditLogger::record('backup', 'delete', null, null, ['file' => $name]);

        return response()->json(null, 204);
    }

    public function restore(Request $request, string $name): JsonResponse
    {
        $this->superAdmin($request);
        abort_if($this->backups->path($name) === null, 404);
        $this->confirm($request, 'RESTORE');
        $actor = $request->user()->id;
        try {
            $safety = $this->backups->restore($name);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['name' => [__('backup.'.($e->getMessage() === 'not_a_backup' ? 'not_a_backup' : 'restore_failed'))]]);
        }
        // Written into the restored database, so the log shows who put it back (when the person and
        // organization exist in it).
        try {
            AuditLogger::record('backup', 'restore', null, null, ['file' => $name, 'safety_backup' => $safety], userId: $actor);
        } catch (QueryException) {
        }

        return response()->json(['data' => ['restored' => $name, 'safety_backup' => $safety]]);
    }

    public function exportOrganization(OrganizationData $data): StreamedResponse
    {
        $this->organizationAdmin();
        AuditLogger::record('backup', 'organization_export');

        return $data->export(AccessContext::current()->organization());
    }

    public function clearOrganization(Request $request, OrganizationData $data): JsonResponse
    {
        $this->organizationAdmin();
        $org = AccessContext::current()->organization();
        $this->confirm($request, $org->name);
        $safety = $this->backups->create('pre_clear');
        $deleted = $data->clear($org);
        AuditLogger::record('backup', 'organization_clear', null, null, ['safety_backup' => $safety, 'rows' => array_sum($deleted)]);

        return response()->json(['data' => ['deleted' => $deleted, 'safety_backup' => $safety]]);
    }

    /** The person's password again, and the exact confirmation text. */
    private function confirm(Request $request, string $phrase): void
    {
        $data = $request->validate(['password' => ['required', 'string'], 'confirmation' => ['required', 'string']]);
        if (! Hash::check($data['password'], $request->user()->password)) {
            throw ValidationException::withMessages(['password' => [__('backup.wrong_password')]]);
        }
        if (trim($data['confirmation']) !== $phrase) {
            throw ValidationException::withMessages(['confirmation' => [__('backup.confirmation_mismatch')]]);
        }
    }

    private function superAdmin(Request $request): void
    {
        abort_unless($request->user()->is_super_admin, 403, __('auth.forbidden'));
    }

    private function organizationAdmin(): void
    {
        abort_unless(AccessContext::current()->scope('organizations.edit')?->scope === PermissionScope::Organization, 403, __('auth.forbidden'));
    }
}
