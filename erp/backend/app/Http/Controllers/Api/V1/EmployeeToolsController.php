<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\EmployeeImport;
use App\Support\Access\AccessContext;
use App\Support\Access\Reach;
use App\Support\Audit\AuditLogger;
use App\Support\Export\Csv;
use App\Support\Export\CsvReader;
use App\Support\Queries\PeopleQueries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Staff import, ID cards and moving many employees at once. */
class EmployeeToolsController extends Controller
{
    private const MAX_ROWS = 2000;

    public function importTemplate(): StreamedResponse
    {
        return Csv::download('employees-template.csv', EmployeeImport::COLUMNS, [
            ['EMP-101', 'Sample Name', 'PHY', '', 'Assistant Professor', '9800000000', 'sample@example.com', '2026-06-01', 'female', '1990-04-15', '', '', '', 'permanent', '', ''],
        ]);
    }

    /** Checks every row and reports errors; writes nothing. */
    public function importPreview(Request $request, EmployeeImport $import): JsonResponse
    {
        $checked = $import->check($this->rows($request));

        return response()->json(['data' => [
            'rows' => $checked,
            'total' => count($checked),
            'with_errors' => collect($checked)->filter(fn ($r) => $r['errors'] !== [])->count(),
        ]]);
    }

    /** Imports every row, or nothing if any row has an error. */
    public function import(Request $request, EmployeeImport $import): JsonResponse
    {
        $checked = $import->check($this->rows($request));
        $bad = collect($checked)->filter(fn ($r) => $r['errors'] !== [])->values();
        if ($bad->isNotEmpty()) {
            return response()->json(['message' => __('import.has_errors'), 'errors' => ['file' => [__('import.has_errors')]], 'rows' => $bad], 422);
        }

        return response()->json(['data' => ['imported' => $import->import($checked, $request->user())]], 201);
    }

    /** Data for printing ID cards; the QR on each card points at the public verify page. */
    public function idCards(Request $request): JsonResponse
    {
        $f = $request->validate([
            'ids' => ['nullable', 'array', 'max:500'],
            'ids.*' => ['integer'],
            'department_id' => ['nullable', 'integer'],
        ]);
        abort_if(empty($f['ids']) && empty($f['department_id']), 422, __('validation.required', ['attribute' => 'ids']));
        $employees = PeopleQueries::employees()->where('employees.is_active', true)->with(['department', 'institution'])
            ->when($f['ids'] ?? null, fn ($q, $ids) => $q->whereIn('employees.id', $ids))
            ->when($f['department_id'] ?? null, fn ($q, $id) => $q->where('employees.department_id', $id))
            ->orderBy('name')->limit(500)->get();
        $organization = AccessContext::current()->organization();
        AuditLogger::record('employees', 'id_cards', null, null, ['count' => $employees->count()]);

        return response()->json(['data' => $employees->map(fn (Employee $e) => [
            'id' => $e->id, 'name' => $e->name, 'employee_code' => $e->employee_code, 'designation' => $e->designation,
            'department' => $e->department?->name, 'institution' => $e->institution?->name ?? $organization->name,
            'blood_group' => $e->blood_group, 'phone' => $e->phone, 'emergency_contact_phone' => $e->emergency_contact_phone,
            'has_photo' => $e->photo_path !== null, 'public_token' => $e->public_token,
        ]), 'organization' => ['name' => $organization->name, 'short_name' => $organization->short_name]]);
    }

    public function bulkMove(Request $request): JsonResponse
    {
        $orgId = AccessContext::current()->organizationId();
        $data = $request->validate([
            'employee_ids' => ['required', 'array', 'min:1', 'max:500'],
            'employee_ids.*' => ['integer', 'distinct'],
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')->where('organization_id', $orgId)],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        Reach::assertDepartment('employees.edit', (int) $data['department_id']);
        $employees = PeopleQueries::employees('employees.edit')->whereIn('employees.id', $data['employee_ids'])->get();
        if ($employees->count() !== count($data['employee_ids'])) {
            throw ValidationException::withMessages(['employee_ids' => [__('access.outside_scope')]]);
        }
        $institutionId = DB::table('departments')->where('id', $data['department_id'])->value('institution_id');

        DB::transaction(function () use ($employees, $data, $institutionId, $request) {
            foreach ($employees as $employee) {
                $before = $employee->getAttributes();
                $employee->forceFill(['department_id' => $data['department_id'], 'institution_id' => $institutionId, 'updated_by' => $request->user()->id])->save();
                // The new primary department is no longer an additional one.
                $employee->departments()->detach($data['department_id']);
                AuditLogger::recordChanges('employees', 'bulk_move', $employee, $before, $data['reason'] ?? null);
            }
        });

        return response()->json(['data' => ['moved' => $employees->count()]]);
    }

    /** @return list<array<string, string>> */
    private function rows(Request $request): array
    {
        $request->validate(['file' => ['required', 'file', 'max:2048', 'mimes:csv,txt']]);

        return CsvReader::rows($request->file('file'), EmployeeImport::REQUIRED, self::MAX_ROWS);
    }
}
