<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\StudentResource;
use App\Models\Department;
use App\Models\Student;
use App\Services\StudentImport;
use App\Support\Access\AccessContext;
use App\Support\Access\Reach;
use App\Support\Audit\AuditLogger;
use App\Support\Export\Csv;
use App\Support\Export\CsvReader;
use App\Support\Forms\CustomFields;
use App\Support\Queries\PeopleQueries;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentController extends Controller
{
    private const MAX_IMPORT_ROWS = 2000;

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = $this->filtered($request, $f);

        return StudentResource::collection($query->with(['institution', 'department'])->orderBy('name')->paginate($f['per_page'] ?? 25)->withQueryString());
    }

    public function export(Request $request): StreamedResponse
    {
        $rows = $this->filtered($request, $f, 'students.export')->with(['institution', 'department'])->orderBy('name')->limit(20000)->get();
        AuditLogger::record('students', 'export', null, null, ['rows' => $rows->count()]);

        return Csv::download('students.csv', [...StudentImport::COLUMNS, 'is_active'], $rows->map(fn (Student $s) => [
            $s->student_code, $s->name, $s->institution->code, $s->department?->code, $s->class_name, $s->section, $s->roll_no,
            $s->admission_no, $s->year_of_study, $s->academic_year, $s->gender, $s->date_of_birth?->toDateString(),
            $s->admission_date?->toDateString(), $s->guardian_name, $s->guardian_phone, $s->mobile, $s->email, $s->is_active ? 'yes' : 'no',
        ]));
    }

    public function importTemplate(): StreamedResponse
    {
        return Csv::download('students-template.csv', StudentImport::COLUMNS, [
            ['STU-101', 'Sample Student', 'SET', '', 'FY B.Sc', 'A', '12', 'ADM-2026-12', '1', '2026-27', 'female', '2008-04-15', '2026-06-15', 'Parent Name', '9800000000', '9800000001', ''],
        ]);
    }

    /** Checks every row and reports errors; writes nothing. */
    public function importPreview(Request $request, StudentImport $import): JsonResponse
    {
        $checked = $import->check($this->importRows($request));

        return response()->json(['data' => [
            'rows' => $checked,
            'total' => count($checked),
            'with_errors' => collect($checked)->filter(fn ($r) => $r['errors'] !== [])->count(),
        ]]);
    }

    /** Imports every row, or nothing if any row has an error. */
    public function import(Request $request, StudentImport $import): JsonResponse
    {
        $checked = $import->check($this->importRows($request));
        $bad = collect($checked)->filter(fn ($r) => $r['errors'] !== [])->values();
        if ($bad->isNotEmpty()) {
            return response()->json(['message' => __('import.has_errors'), 'errors' => ['file' => [__('import.has_errors')]], 'rows' => $bad], 422);
        }

        return response()->json(['data' => ['imported' => $import->import($checked, $request->user())]], 201);
    }

    public function show(Student $student): StudentResource
    {
        abort_unless(PeopleQueries::students()->whereKey($student->id)->exists(), 404);

        return new StudentResource($student->load(['institution', 'department']));
    }

    public function store(Request $request): StudentResource
    {
        $data = $this->validated($request);
        $this->assertPlacement('students.create', $data);

        $student = new Student($data);
        $student->forceFill(['created_by' => $request->user()->id])->save();
        AuditLogger::record('students', 'create', $student, null, AuditLogger::snapshot($student));

        return new StudentResource($student->load(['institution', 'department']));
    }

    public function update(Request $request, Student $student): StudentResource
    {
        abort_unless(PeopleQueries::students('students.edit')->whereKey($student->id)->exists(), 404);
        $data = $this->validated($request, $student);
        if ((int) $data['institution_id'] !== $student->institution_id || ($data['department_id'] ?? null) !== $student->department_id) {
            $this->assertPlacement('students.edit', $data);
        }

        $before = $student->getAttributes();
        $student->fill($data);
        $student->forceFill(['updated_by' => $request->user()->id])->save();
        AuditLogger::recordChanges('students', 'update', $student, $before);

        return new StudentResource($student->load(['institution', 'department']));
    }

    public function setActive(Request $request, Student $student): StudentResource
    {
        abort_unless(PeopleQueries::students('students.activate')->whereKey($student->id)->exists(), 404);
        $data = $request->validate(['is_active' => ['required', 'boolean'], 'reason' => ['nullable', 'string', 'max:500']]);

        $before = $student->getAttributes();
        $student->forceFill(['is_active' => (bool) $data['is_active'], 'updated_by' => $request->user()->id])->save();
        AuditLogger::recordChanges('students', $data['is_active'] ? 'activate' : 'deactivate', $student, $before, $data['reason'] ?? null);

        return new StudentResource($student->load(['institution', 'department']));
    }

    /**
     * @param-out array<string, mixed> $f
     *
     * @return Builder<Student>
     */
    private function filtered(Request $request, ?array &$f, string $permission = 'students.view'): Builder
    {
        $f = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'institution_id' => ['nullable', 'integer'],
            'class_name' => ['nullable', 'string', 'max:50'],
            'academic_year' => ['nullable', 'string', 'max:9'],
            'year_of_study' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return PeopleQueries::students($permission)
            ->when($f['search'] ?? null, function ($q, $s) {
                $like = '%'.addcslashes($s, '%_\\').'%';
                $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('student_code', 'like', $like)
                    ->orWhere('roll_no', 'like', $like)->orWhere('admission_no', 'like', $like));
            })
            ->when($f['institution_id'] ?? null, fn ($q, $id) => $q->where('institution_id', $id))
            ->when($f['class_name'] ?? null, fn ($q, $c) => $q->where('class_name', $c))
            ->when($f['academic_year'] ?? null, fn ($q, $y) => $q->where('academic_year', $y))
            ->when($f['year_of_study'] ?? null, fn ($q, $y) => $q->where('year_of_study', $y))
            ->when(array_key_exists('is_active', $f) && $f['is_active'] !== null, fn ($q) => $q->where('is_active', (bool) $f['is_active']));
    }

    /** @return list<array<string, string>> */
    private function importRows(Request $request): array
    {
        $request->validate(['file' => ['required', 'file', 'max:2048', 'mimes:csv,txt']]);

        return CsvReader::rows($request->file('file'), StudentImport::REQUIRED, self::MAX_IMPORT_ROWS);
    }

    /** @param  array<string, mixed>  $data */
    private function assertPlacement(string $permission, array $data): void
    {
        if (! empty($data['department_id'])) {
            Reach::assertDepartment($permission, (int) $data['department_id']);
        } else {
            Reach::assertInstitution($permission, (int) $data['institution_id']);
        }
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Student $student = null): array
    {
        $orgId = AccessContext::current()->organizationId();
        $data = $request->validate([
            'student_code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9_\/-]+$/', Rule::unique('students')->where('organization_id', $orgId)->ignore($student?->id)],
            'name' => ['required', 'string', 'max:255'],
            'institution_id' => ['required', 'integer', Rule::exists('institutions', 'id')->where('organization_id', $orgId)],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('organization_id', $orgId)],
            'class_name' => ['nullable', 'string', 'max:50'],
            'section' => ['nullable', 'string', 'max:20'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other'])],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'admission_date' => ['nullable', 'date'],
            'guardian_name' => ['nullable', 'string', 'max:255'],
            'guardian_phone' => ['nullable', 'string', 'max:30'],
            'roll_no' => ['nullable', 'string', 'max:30'],
            'admission_no' => ['nullable', 'string', 'max:50'],
            'year_of_study' => ['nullable', 'integer', 'min:1', 'max:10'],
            'academic_year' => ['nullable', 'string', 'regex:'.Student::ACADEMIC_YEAR_PATTERN],
            'mobile' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            ...CustomFields::rules('student'),
        ]);
        $data['department_id'] = $data['department_id'] ?? null;
        $data['custom'] = CustomFields::clean('student', $data['custom'] ?? null, $student?->custom);

        // A student's department must belong to the student's institution.
        if ($data['department_id'] && ! Department::query()->whereKey($data['department_id'])->where('institution_id', $data['institution_id'])->exists()) {
            throw ValidationException::withMessages(['department_id' => [__('students.department_mismatch')]]);
        }

        return $data;
    }
}
