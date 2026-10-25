<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\StudentResource;
use App\Models\Department;
use App\Models\Student;
use App\Support\Access\AccessContext;
use App\Support\Access\Reach;
use App\Support\Audit\AuditLogger;
use App\Support\Forms\CustomFields;
use App\Support\Queries\PeopleQueries;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StudentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $f = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'institution_id' => ['nullable', 'integer'],
            'class_name' => ['nullable', 'string', 'max:50'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = PeopleQueries::students()->with(['institution', 'department'])
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('student_code', 'like', "%{$s}%")))
            ->when($f['institution_id'] ?? null, fn ($q, $id) => $q->where('institution_id', $id))
            ->when($f['class_name'] ?? null, fn ($q, $c) => $q->where('class_name', $c))
            ->when(array_key_exists('is_active', $f) && $f['is_active'] !== null, fn ($q) => $q->where('is_active', (bool) $f['is_active']))
            ->orderBy('name');

        return StudentResource::collection($query->paginate($f['per_page'] ?? 25)->withQueryString());
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
