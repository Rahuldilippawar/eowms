<?php

namespace App\Services\Legacy\Steps;

use App\Models\CustomField;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeAchievement;
use App\Models\EmployeeFamilyMember;
use App\Models\EmployeePrivate;
use App\Services\Legacy\LegacyImport;
use App\Services\Legacy\Step;
use Illuminate\Support\Str;

/**
 * The old users' staff details → employee records (with private details encrypted, family and
 * achievements). The old community fields become organization custom fields. Super admins get an
 * employee record only when they had an employee code.
 */
final class EmployeesStep implements Step
{
    /** Old column → custom field [key, label, type, options]. */
    private const CUSTOM = [
        'ashram_resident' => ['आश्रमात राहतात', 'checkbox', null],
        'naam_dharak' => ['नामधारक', 'checkbox', null],
        'food_preference' => ['आहार', 'select', ['Veg', 'Non Veg']],
        'health_scheme_no' => ['आरोग्य योजना क्रमांक', 'text', null],
        'family_health_scheme_no' => ['कुटुंब आरोग्य योजना क्रमांक', 'text', null],
        'interested_sports_club' => ['क्रीडा मंडळात रस', 'checkbox', null],
        'interested_home_scheme' => ['गृह योजनेत रस', 'checkbox', null],
        'home_scheme_type' => ['गृह योजनेचा प्रकार', 'text', null],
        'family_interested_working' => ['कुटुंबातील व्यक्ती काम करू इच्छितात', 'checkbox', null],
        'family_currently_working' => ['कुटुंबातील व्यक्ती सध्या काम करतात', 'checkbox', null],
    ];

    /** @var array<int, int> organization → its "no department" department */
    private array $fallback = [];

    public function key(): string
    {
        return 'employees';
    }

    public function run(LegacyImport $import): void
    {
        $legacy = $import->legacy();
        $family = $legacy->getSchemaBuilder()->hasTable('employee_family_members') ? $legacy->table('employee_family_members')->get()->groupBy('user_id') : collect();
        $achievements = $legacy->getSchemaBuilder()->hasTable('staff_achievements') ? $legacy->table('staff_achievements')->get()->groupBy('user_id') : collect();
        $customReady = [];

        foreach ($legacy->table('users')->orderBy('id')->get() as $u) {
            $import->read();
            if ($import->mapped('employees', $u->id) !== null) {
                $import->existing();

                continue;
            }
            $org = $import->mapped('organizations', $u->organization_id);
            $userId = $import->mapped('users', $u->id);
            if ($org === null || $userId === null) {
                $import->skip('users', $u->id, 'account_missing');

                continue;
            }
            if ($u->role === 'super_admin' && empty($u->employee_code)) {
                $import->skip('users', $u->id, 'super_admin_without_employee_code');

                continue;
            }
            if (Employee::withoutGlobalScopes()->where(['organization_id' => $org, 'user_id' => $userId])->exists()) {
                $import->matched();

                continue;
            }
            if (! isset($customReady[$org])) {
                $this->ensureCustomFields($org);
                $customReady[$org] = true;
            }
            $department = $import->mapped('departments', $u->department_id) ?? $this->fallbackDepartment($import, $org, $import->mapped('campuses', $u->campus_id), (int) $u->id);
            $code = Str::limit(trim((string) $u->employee_code), 30, '') ?: 'OLD-'.$u->id;
            if (Employee::withoutGlobalScopes()->where(['organization_id' => $org, 'employee_code' => $code])->exists()) {
                $import->warn("users #{$u->id}: employee code {$code} is used twice; kept as {$code}-{$u->id}");
                $code = Str::limit($code, 22, '').'-'.$u->id;
            }
            $pin = trim((string) ($u->biometric_pin ?? '')) ?: null;
            if ($pin !== null && Employee::withoutGlobalScopes()->where(['organization_id' => $org, 'biometric_pin' => $pin])->exists()) {
                $import->warn("users #{$u->id}: attendance machine number {$pin} is used twice; left empty");
                $pin = null;
            }
            $employee = Employee::withoutGlobalScopes()->create([
                'organization_id' => $org, 'user_id' => $userId, 'employee_code' => $code, 'biometric_pin' => $pin, 'name' => $u->name,
                'designation' => $u->designation ?? null, 'secondary_designation' => $u->designation_2 ?? null, 'phone' => $u->mobile ?? null, 'email' => $u->email,
                'institution_id' => $import->mapped('campuses', $u->campus_id), 'department_id' => $department, 'joining_date' => $u->joining_date ?? null,
                'left_date' => $u->left_date ?? null, 'is_active' => (bool) $u->is_active,
                'employment_type' => match ($u->employment_type ?? null) {
                    'Salaried' => 'permanent', 'Non Salaried' => 'honorary', 'Contractor' => 'contract', default => null
                },
                'staff_group' => $u->group_type ?? null,
                'teaching_mode' => match ($u->teaching_mode ?? null) {
                    'Teaching' => 'teaching', 'Non Teaching' => 'non_teaching', default => null
                },
                'father_name' => $u->father_name ?? null, 'mother_name' => $u->mother_name ?? null,
                'gender' => match ($u->gender ?? null) {
                    'Male' => 'male', 'Female' => 'female', default => null
                },
                'date_of_birth' => $u->date_of_birth ?? null, 'marital_status' => isset($u->marital_status) ? strtolower($u->marital_status) : null,
                'whatsapp' => $u->whatsapp_number ?? null, 'local_address' => $u->local_address ?? null, 'permanent_address' => $u->permanent_address ?? null,
                'religion' => $u->religion_caste ?? null, 'caste_validity_no' => $u->caste_validity_no ?? null, 'is_disabled' => (bool) ($u->is_handicap ?? false),
                'emergency_contact_name' => $u->emergency_contact_name ?? null, 'emergency_contact_relation' => $u->emergency_contact_relation ?? null,
                'emergency_contact_phone' => $u->emergency_contact_mobile ?? null,
                'custom' => collect(self::CUSTOM)->mapWithKeys(fn ($def, $k) => [$k => isset($u->{$k}) ? ($def[1] === 'checkbox' ? (bool) $u->{$k} : $u->{$k}) : null])
                    ->reject(fn ($v) => $v === null || $v === '')->all(),
            ]);
            $private = array_filter([
                'aadhaar_number' => $u->aadhar_number ?? null, 'ration_card' => isset($u->ration_card) ? ((bool) $u->ration_card ? 'होय' : null) : null,
                'bank_account_no' => $u->bank_account_no ?? null, 'ifsc_code' => $u->ifsc_code ?? null, 'bank_name' => $u->bank_name ?? null,
                'bank_branch' => $u->branch_name ?? null, 'uan_no' => $u->uan_no ?? null, 'esic_no' => $u->esic_no ?? null,
                'monthly_salary' => $u->salary ?? null,
            ], fn ($v) => $v !== null && $v !== '');
            $flags = ['pf_applicable' => (bool) ($u->pf_applicable ?? false), 'esic_applicable' => (bool) ($u->esic_applicable ?? false), 'pt_applicable' => (bool) ($u->pt_applicable ?? false)];
            if ($private !== [] || in_array(true, $flags, true)) {
                EmployeePrivate::query()->create(['employee_id' => $employee->id, ...$private, ...$flags]);
            }
            foreach ($family->get($u->id, []) as $f) {
                EmployeeFamilyMember::withoutGlobalScopes()->create(['organization_id' => $org, 'employee_id' => $employee->id, 'name' => $f->name,
                    'relation' => $f->relation, 'lives_on_campus' => (bool) ($f->resides_in_ashram ?? false)]);
            }
            foreach ($achievements->get($u->id, []) as $a) {
                EmployeeAchievement::withoutGlobalScopes()->create(['organization_id' => $org, 'employee_id' => $employee->id, 'title' => $a->title,
                    'description' => $a->description, 'achieved_on' => $a->achievement_date, 'added_by' => $import->mapped('users', $a->added_by)]);
            }
            $import->remember('employees', $u->id, 'employees', $employee->id);
            $import->created();
        }
    }

    private function ensureCustomFields(int $org): void
    {
        $order = (int) CustomField::withoutGlobalScopes()->where(['organization_id' => $org, 'entity' => 'employee'])->max('sort_order');
        foreach (self::CUSTOM as $key => [$label, $type, $options]) {
            CustomField::withoutGlobalScopes()->firstOrCreate(['organization_id' => $org, 'entity' => 'employee', 'key' => $key],
                ['label' => $label, 'type' => $type, 'options' => $options, 'sort_order' => ++$order, 'is_active' => true]);
        }
    }

    /** People the old app kept without a department go to one department per campus, named so. */
    private function fallbackDepartment(LegacyImport $import, int $org, ?int $institution, int $legacyUser): int
    {
        $import->warn("users #{$legacyUser}: had no department; placed in \"विभाग नाही (जुना data)\"");
        $key = $org.'-'.($institution ?? 0);

        return $this->fallback[$key] ??= Department::withoutGlobalScopes()->firstOrCreate(
            ['organization_id' => $org, 'institution_id' => $institution, 'code' => 'NODEPT'],
            ['name' => 'विभाग नाही (जुना data)', 'is_active' => true],
        )->id;
    }
}
