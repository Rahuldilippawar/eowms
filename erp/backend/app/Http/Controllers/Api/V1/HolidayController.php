<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Holiday;
use App\Models\Institution;
use App\Support\Access\AccessContext;
use App\Support\Access\Reach;
use App\Support\Audit\AuditLogger;
use App\Support\Export\Csv;
use App\Support\Export\CsvReader;
use App\Support\Forms\Dates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Holiday calendar. Everyone signed in reads it; `attendance.manage` adds
 * holidays for the institutions in scope, and organization-wide ones only
 * at organization scope.
 */
class HolidayController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate(['year' => ['nullable', 'integer', 'min:2000', 'max:2100'], 'institution_id' => ['nullable', 'integer']]);
        $year = $f['year'] ?? now()->year;
        $rows = Holiday::query()->with('institution')->whereYear('holiday_date', $year)
            ->when($f['institution_id'] ?? null, fn ($q, $id) => $q->where(fn ($w) => $w->whereNull('institution_id')->orWhere('institution_id', $id)))
            ->orderBy('holiday_date')->get();

        return response()->json(['data' => $rows->map(fn (Holiday $h) => $this->row($h))->values(), 'year' => (int) $year]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $holiday = Holiday::create($data + ['created_by' => $request->user()->id]);
        AuditLogger::record('holidays', 'create', $holiday, null, AuditLogger::snapshot($holiday));

        return response()->json(['data' => $this->row($holiday->load('institution'))], 201);
    }

    public function update(Request $request, Holiday $holiday): JsonResponse
    {
        $this->assertManage($holiday->institution_id);
        $before = $holiday->getAttributes();
        $holiday->fill($this->validated($request, $holiday))->save();
        AuditLogger::recordChanges('holidays', 'update', $holiday, $before);

        return response()->json(['data' => $this->row($holiday->load('institution'))]);
    }

    public function destroy(Holiday $holiday): JsonResponse
    {
        $this->assertManage($holiday->institution_id);
        AuditLogger::record('holidays', 'delete', $holiday, AuditLogger::snapshot($holiday));
        $holiday->delete();

        return response()->json(['data' => ['id' => $holiday->id, 'deleted' => true]]);
    }

    public function template(): StreamedResponse
    {
        return Csv::download('holidays-template.csv', ['holiday_date', 'name', 'institution_code'], [['2026-08-15', 'Independence Day', ''], ['26/01/2027', 'Republic Day', '']]);
    }

    /** CSV `holiday_date, name, institution_code?` — all rows or none; existing dates are skipped. */
    public function import(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:1024', 'mimes:csv,txt']]);
        $rows = CsvReader::rows($request->file('file'), ['holiday_date', 'name'], 500);
        $institutions = Institution::query()->pluck('id', 'code');
        $errors = [];
        $clean = [];
        foreach ($rows as $i => $row) {
            $line = $i + 2;
            $date = Dates::parse($row['holiday_date']);
            $instId = null;
            if (($row['institution_code'] ?? '') !== '') {
                $instId = $institutions->first(fn ($id, $c) => strcasecmp($c, $row['institution_code']) === 0);
                if ($instId === null) {
                    $errors["line_{$line}"] = [__('import.institution_not_found')];

                    continue;
                }
            }
            if ($date === null || $row['name'] === '' || mb_strlen($row['name']) > 255) {
                $errors["line_{$line}"] = [__('import.bad_date')];

                continue;
            }
            if (! $this->mayManage($instId)) {
                $errors["line_{$line}"] = [__('access.outside_scope')];

                continue;
            }
            $clean[] = ['institution_id' => $instId, 'holiday_date' => $date, 'name' => $row['name']];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $created = DB::transaction(function () use ($clean, $request) {
            $n = 0;
            foreach ($clean as $row) {
                $holiday = Holiday::firstOrCreate(['institution_id' => $row['institution_id'], 'holiday_date' => $row['holiday_date']], ['name' => $row['name'], 'created_by' => $request->user()->id]);
                $n += $holiday->wasRecentlyCreated ? 1 : 0;
            }

            return $n;
        });
        AuditLogger::record('holidays', 'import', null, null, ['rows' => count($clean), 'created' => $created]);

        return response()->json(['data' => ['created' => $created, 'skipped' => count($clean) - $created]], 201);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Holiday $holiday = null): array
    {
        $orgId = AccessContext::current()->organizationId();
        $data = $request->validate([
            'holiday_date' => ['required', 'date_format:Y-m-d'],
            'name' => ['required', 'string', 'max:255'],
            'institution_id' => ['nullable', 'integer', Rule::exists('institutions', 'id')->where('organization_id', $orgId)],
        ]);
        $data['institution_id'] ??= null;
        $this->assertManage($data['institution_id']);
        $exists = Holiday::query()->where('holiday_date', $data['holiday_date'])->where('institution_id', $data['institution_id'])
            ->when($holiday, fn ($q) => $q->whereKeyNot($holiday->id))->exists();
        if ($exists) {
            throw ValidationException::withMessages(['holiday_date' => [__('validation.unique', ['attribute' => 'holiday_date'])]]);
        }

        return $data;
    }

    private function mayManage(?int $institutionId): bool
    {
        $scope = AccessContext::current()->scope('attendance.manage');
        if ($scope === null) {
            return false;
        }

        return $institutionId === null ? $scope->isOrganizationWide() : Reach::institution($scope, $institutionId);
    }

    private function assertManage(?int $institutionId): void
    {
        abort_unless(AccessContext::current()->can('attendance.manage'), 403, __('auth.forbidden'));
        if (! $this->mayManage($institutionId)) {
            throw ValidationException::withMessages(['institution_id' => [__('access.outside_scope')]]);
        }
    }

    /** @return array<string, mixed> */
    private function row(Holiday $h): array
    {
        return ['id' => $h->id, 'holiday_date' => $h->holiday_date->toDateString(), 'name' => $h->name, 'institution' => $h->institution ? ['id' => $h->institution->id, 'name' => $h->institution->name] : null];
    }
}
