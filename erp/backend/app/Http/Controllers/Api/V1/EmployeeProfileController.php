<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\ResolvesMyEmployee;
use App\Http\Controllers\Controller;
use App\Http\Resources\EmployeeProfileResource;
use App\Models\Employee;
use App\Models\EmployeeAchievement;
use App\Models\EmployeeDocument;
use App\Models\EmployeeFamilyMember;
use App\Models\EmployeePrivate;
use App\Support\Access\EmployeeAccess;
use App\Support\Audit\AuditLogger;
use App\Support\Forms\CustomFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The full staff profile: HR fields, private data, photo, documents, family
 * and achievements. Employees see their own profile and may change their
 * contact details, family and documents; everything else needs a scoped
 * permission (erp/docs/PEOPLE.md).
 */
class EmployeeProfileController extends Controller
{
    use ResolvesMyEmployee;

    private const RELATIONS = ['institution', 'department', 'departments', 'reportingOfficer', 'user', 'private', 'documents.uploader', 'familyMembers', 'achievements.adder'];

    public function show(Employee $employee): EmployeeProfileResource
    {
        abort_unless(EmployeeAccess::mayView($employee), 404);

        return new EmployeeProfileResource($employee->load(self::RELATIONS));
    }

    public function me(): EmployeeProfileResource
    {
        return $this->show($this->employeeOrFail());
    }

    public function update(Request $request, Employee $employee): EmployeeProfileResource
    {
        abort_unless(EmployeeAccess::mayView($employee), 404);
        abort_unless(EmployeeAccess::mayEdit($employee), 403, __('auth.forbidden'));
        $data = $request->validate([...$this->profileRules(), ...CustomFields::rules('employee')]);

        $before = $employee->getAttributes();
        $employee->fill(collect($data)->only(Employee::PROFILE_FIELDS)->all());
        $employee->custom = CustomFields::clean('employee', $data['custom'] ?? null, $employee->custom);
        $employee->forceFill(['updated_by' => $request->user()->id])->save();
        AuditLogger::recordChanges('employees', 'profile_update', $employee, $before);

        return $this->show($employee);
    }

    /** Employees keep their own contact details and emergency contact up to date. */
    public function updateMine(Request $request): EmployeeProfileResource
    {
        $employee = $this->activeEmployeeOrFail();
        $data = $request->validate(collect($this->profileRules())->only(Employee::SELF_FIELDS)->all());

        $before = $employee->getAttributes();
        $employee->fill(collect($data)->only(Employee::SELF_FIELDS)->all())->save();
        AuditLogger::recordChanges('employees', 'self_update', $employee, $before);

        return $this->show($employee);
    }

    public function updatePrivate(Request $request, Employee $employee): EmployeeProfileResource
    {
        abort_unless(EmployeeAccess::mayView($employee), 404);
        abort_unless(EmployeeAccess::reaches($employee, 'employees.sensitive'), 403, __('auth.forbidden'));
        $data = $request->validate([
            'aadhaar_number' => ['nullable', 'string', 'regex:/^\d{4}\s?\d{4}\s?\d{4}$/'],
            'ration_card' => ['nullable', 'string', 'max:50'],
            'bank_account_no' => ['nullable', 'string', 'regex:/^[0-9]{6,20}$/'],
            'ifsc_code' => ['nullable', 'string', 'regex:/^[A-Za-z]{4}0[A-Za-z0-9]{6}$/'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_branch' => ['nullable', 'string', 'max:255'],
            'pf_applicable' => ['sometimes', 'boolean'],
            'uan_no' => ['nullable', 'string', 'regex:/^[0-9]{12}$/'],
            'esic_applicable' => ['sometimes', 'boolean'],
            'esic_no' => ['nullable', 'string', 'max:30'],
            'pt_applicable' => ['sometimes', 'boolean'],
            'monthly_salary' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
        ]);
        // Keep the stored number when the field was left out (the form only shows it masked).
        foreach (['aadhaar_number', 'bank_account_no'] as $secret) {
            if (array_key_exists($secret, $data) && $data[$secret] !== null) {
                $data[$secret] = preg_replace('/\s+/', '', $data[$secret]);
            }
        }
        if (isset($data['ifsc_code'])) {
            $data['ifsc_code'] = strtoupper($data['ifsc_code']);
        }

        $private = $employee->private ?? new EmployeePrivate(['employee_id' => $employee->id]);
        $changed = array_keys(array_filter($data, fn ($v, $k) => (string) ($private->getAttribute($k) ?? '') !== (string) ($v ?? ''), ARRAY_FILTER_USE_BOTH));
        $private->fill($data + ['updated_by' => $request->user()->id])->save();
        // Field names only: private values never go into the audit log.
        AuditLogger::record('employees', 'private_update', $employee, null, ['fields' => $changed]);

        return $this->show($employee->unsetRelation('private'));
    }

    /** Full Aadhaar / bank numbers, on request; every reveal is audited. */
    public function revealPrivate(Request $request, Employee $employee): JsonResponse
    {
        abort_unless(EmployeeAccess::mayView($employee), 404);
        abort_unless(EmployeeAccess::maySeePrivate($employee), 403, __('auth.forbidden'));
        AuditLogger::record('employees', 'private_reveal', $employee);

        return response()->json(['data' => [
            'aadhaar_number' => $employee->private?->aadhaar_number,
            'bank_account_no' => $employee->private?->bank_account_no,
        ]]);
    }

    public function uploadPhoto(Request $request, Employee $employee): EmployeeProfileResource
    {
        abort_unless(EmployeeAccess::mayView($employee), 404);
        abort_unless(EmployeeAccess::mayEdit($employee) || EmployeeAccess::isSelf($employee), 403, __('auth.forbidden'));
        $request->validate(['photo' => ['required', 'file', 'max:2048', 'mimes:jpg,jpeg,png,webp', 'dimensions:min_width=100,min_height=100,max_width=6000,max_height=6000']]);

        $file = $request->file('photo');
        $path = $file->storeAs("employees/{$employee->organization_id}/photos", Str::uuid().'.'.$file->guessExtension(), 'local');
        $old = $employee->photo_path;
        $employee->forceFill(['photo_path' => $path])->save();
        if ($old) {
            Storage::disk('local')->delete($old);
        }
        AuditLogger::record('employees', 'photo_update', $employee);

        return $this->show($employee);
    }

    public function photo(Employee $employee): StreamedResponse
    {
        abort_unless(EmployeeAccess::mayView($employee), 404);

        return $this->streamPhoto($employee);
    }

    public function uploadDocument(Request $request, Employee $employee): EmployeeProfileResource
    {
        abort_unless(EmployeeAccess::mayView($employee), 404);
        abort_unless(EmployeeAccess::mayEdit($employee) || EmployeeAccess::isSelf($employee), 403, __('auth.forbidden'));
        $data = $request->validate([
            'document_type' => ['required', Rule::in(EmployeeDocument::TYPES)],
            'title' => ['nullable', 'string', 'max:255'],
            'file' => ['required', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png'],
        ]);
        $file = $request->file('file');
        $document = EmployeeDocument::create([
            'employee_id' => $employee->id,
            'document_type' => $data['document_type'],
            'title' => $data['title'] ?? null,
            'file_path' => $file->storeAs("employees/{$employee->organization_id}/documents", Str::uuid().'.'.$file->guessExtension(), 'local'),
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'size_bytes' => $file->getSize(),
            'uploaded_by' => $request->user()->id,
        ]);
        AuditLogger::record('employees', 'document_upload', $employee, null, ['document_id' => $document->id, 'type' => $document->document_type]);

        return $this->show($employee);
    }

    public function downloadDocument(EmployeeDocument $document): StreamedResponse
    {
        abort_unless(EmployeeAccess::mayView($document->employee), 404);
        abort_unless(Storage::disk('local')->exists($document->file_path), 404);
        AuditLogger::record('employees', 'document_download', $document->employee, null, ['document_id' => $document->id]);

        return Storage::disk('local')->download($document->file_path, $document->original_name);
    }

    public function deleteDocument(Request $request, EmployeeDocument $document): EmployeeProfileResource
    {
        $employee = $document->employee;
        abort_unless(EmployeeAccess::mayView($employee), 404);
        abort_unless(EmployeeAccess::mayEdit($employee) || ($document->uploaded_by === $request->user()->id && EmployeeAccess::isSelf($employee)), 403, __('auth.forbidden'));
        Storage::disk('local')->delete($document->file_path);
        AuditLogger::record('employees', 'document_delete', $employee, ['document_id' => $document->id, 'name' => $document->original_name]);
        $document->delete();

        return $this->show($employee);
    }

    public function saveFamilyMember(Request $request, Employee $employee, ?EmployeeFamilyMember $member = null): EmployeeProfileResource
    {
        abort_unless(EmployeeAccess::mayView($employee), 404);
        abort_unless(EmployeeAccess::mayEdit($employee) || EmployeeAccess::isSelf($employee), 403, __('auth.forbidden'));
        abort_if($member !== null && $member->employee_id !== $employee->id, 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'relation' => ['required', 'string', 'max:50'],
            'date_of_birth' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'phone' => ['nullable', 'string', 'max:30'],
            'lives_on_campus' => ['sometimes', 'boolean'],
        ]);
        $member ??= new EmployeeFamilyMember(['employee_id' => $employee->id]);
        $member->fill($data)->save();
        AuditLogger::record('employees', 'family_save', $employee, null, ['member_id' => $member->id]);

        return $this->show($employee);
    }

    public function deleteFamilyMember(Employee $employee, EmployeeFamilyMember $member): EmployeeProfileResource
    {
        abort_unless(EmployeeAccess::mayView($employee) && $member->employee_id === $employee->id, 404);
        abort_unless(EmployeeAccess::mayEdit($employee) || EmployeeAccess::isSelf($employee), 403, __('auth.forbidden'));
        AuditLogger::record('employees', 'family_delete', $employee, ['member_id' => $member->id, 'name' => $member->name]);
        $member->delete();

        return $this->show($employee);
    }

    public function addAchievement(Request $request, Employee $employee): EmployeeProfileResource
    {
        abort_unless(EmployeeAccess::mayView($employee), 404);
        abort_unless(EmployeeAccess::mayEdit($employee), 403, __('auth.forbidden'));
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'achieved_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ]);
        $achievement = EmployeeAchievement::create($data + ['employee_id' => $employee->id, 'added_by' => $request->user()->id]);
        AuditLogger::record('employees', 'achievement_add', $employee, null, ['achievement_id' => $achievement->id]);

        return $this->show($employee);
    }

    public function deleteAchievement(Employee $employee, EmployeeAchievement $achievement): EmployeeProfileResource
    {
        abort_unless(EmployeeAccess::mayView($employee) && $achievement->employee_id === $employee->id, 404);
        abort_unless(EmployeeAccess::mayEdit($employee), 403, __('auth.forbidden'));
        AuditLogger::record('employees', 'achievement_delete', $employee, ['achievement_id' => $achievement->id, 'title' => $achievement->title]);
        $achievement->delete();

        return $this->show($employee);
    }

    public static function streamPhoto(Employee $employee): StreamedResponse
    {
        abort_if($employee->photo_path === null || ! Storage::disk('local')->exists($employee->photo_path), 404);

        return Storage::disk('local')->response($employee->photo_path, null, ['Cache-Control' => 'private, max-age=300']);
    }

    /** @return array<string, list<mixed>> */
    private function profileRules(): array
    {
        return [
            'secondary_designation' => ['nullable', 'string', 'max:255'],
            'employment_type' => ['nullable', Rule::in(['permanent', 'probation', 'contract', 'temporary', 'part_time', 'honorary', 'volunteer'])],
            'staff_group' => ['nullable', 'string', 'max:50'],
            'teaching_mode' => ['nullable', Rule::in(['teaching', 'non_teaching'])],
            'father_name' => ['nullable', 'string', 'max:255'],
            'mother_name' => ['nullable', 'string', 'max:255'],
            'gender' => ['nullable', Rule::in(['female', 'male', 'other'])],
            'date_of_birth' => ['nullable', 'date_format:Y-m-d', 'before:today'],
            'marital_status' => ['nullable', Rule::in(['single', 'married', 'widowed', 'divorced'])],
            'blood_group' => ['nullable', Rule::in(['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'])],
            'whatsapp' => ['nullable', 'string', 'max:30'],
            'local_address' => ['nullable', 'string', 'max:1000'],
            'permanent_address' => ['nullable', 'string', 'max:1000'],
            'religion' => ['nullable', 'string', 'max:50'],
            'caste' => ['nullable', 'string', 'max:100'],
            'caste_validity_no' => ['nullable', 'string', 'max:50'],
            'is_disabled' => ['sometimes', 'boolean'],
            'emergency_contact_name' => ['nullable', 'string', 'max:255'],
            'emergency_contact_relation' => ['nullable', 'string', 'max:50'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:30'],
            'left_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
