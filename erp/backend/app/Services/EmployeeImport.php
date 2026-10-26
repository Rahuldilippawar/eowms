<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Institution;
use App\Models\User;
use App\Support\Access\AccessContext;
use App\Support\Access\Reach;
use App\Support\Audit\AuditLogger;
use App\Support\Forms\Dates;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Staff CSV import. Every row is checked first; the import writes all rows
 * in one transaction or nothing at all (erp/docs/PEOPLE.md).
 */
final class EmployeeImport
{
    public const COLUMNS = [
        'employee_code', 'name', 'department_code', 'institution_code', 'designation', 'phone', 'email', 'joining_date',
        'gender', 'date_of_birth', 'father_name', 'mother_name', 'whatsapp', 'employment_type', 'reporting_officer_code', 'local_address',
    ];

    public const REQUIRED = ['employee_code', 'name', 'department_code'];

    /**
     * @param  list<array<string, string>>  $rows
     * @return list<array{line: int, data: array<string, mixed>, errors: array<string, string>}>
     */
    public function check(array $rows): array
    {
        $scope = AccessContext::current()->scope('employees.create');
        $departments = Department::query()->with('institution')->get();
        $institutions = Institution::query()->pluck('id', 'code');
        $existingCodes = Employee::query()->pluck('id', 'employee_code')->mapWithKeys(fn ($id, $code) => [mb_strtolower($code) => $id]);
        $fileCodes = collect($rows)->countBy(fn ($r) => mb_strtolower($r['employee_code'] ?? ''));

        $result = [];
        foreach ($rows as $i => $row) {
            $errors = [];
            $v = Validator::make($row, [
                'employee_code' => ['required', 'max:30', 'regex:/^[A-Za-z0-9_\/-]+$/'],
                'name' => ['required', 'max:255'],
                'department_code' => ['required', 'max:30'],
                'designation' => ['nullable', 'max:255'],
                'phone' => ['nullable', 'max:30'],
                'whatsapp' => ['nullable', 'max:30'],
                'email' => ['nullable', 'email', 'max:255'],
                'gender' => ['nullable', 'in:female,male,other'],
                'employment_type' => ['nullable', 'in:permanent,probation,contract,temporary,part_time,honorary,volunteer'],
                'father_name' => ['nullable', 'max:255'],
                'mother_name' => ['nullable', 'max:255'],
                'local_address' => ['nullable', 'max:1000'],
            ]);
            foreach ($v->errors()->messages() as $field => $messages) {
                $errors[$field] = $messages[0];
            }
            $code = mb_strtolower($row['employee_code'] ?? '');
            if ($code !== '' && ($fileCodes[$code] ?? 0) > 1) {
                $errors['employee_code'] = __('import.duplicate_in_file');
            } elseif ($code !== '' && isset($existingCodes[$code])) {
                $errors['employee_code'] = __('validation.unique', ['attribute' => 'employee_code']);
            }

            $data = ['employee_code' => $row['employee_code'] ?? '', 'name' => $row['name'] ?? ''];
            foreach (['designation', 'phone', 'whatsapp', 'email', 'gender', 'employment_type', 'father_name', 'mother_name', 'local_address'] as $f) {
                $data[$f] = ($row[$f] ?? '') === '' ? null : $row[$f];
            }
            foreach (['joining_date', 'date_of_birth'] as $f) {
                $data[$f] = null;
                if (($row[$f] ?? '') !== '') {
                    $data[$f] = Dates::parse($row[$f]);
                    if ($data[$f] === null) {
                        $errors[$f] = __('import.bad_date');
                    }
                }
            }

            $candidates = $departments->filter(fn ($d) => strcasecmp($d->code, $row['department_code'] ?? '') === 0);
            if (($row['institution_code'] ?? '') !== '') {
                $instId = $institutions->first(fn ($id, $c) => strcasecmp($c, $row['institution_code']) === 0);
                $candidates = $candidates->where('institution_id', $instId);
            }
            if (($row['department_code'] ?? '') !== '' && ! isset($errors['department_code'])) {
                if ($candidates->isEmpty()) {
                    $errors['department_code'] = __('import.department_not_found');
                } elseif ($candidates->count() > 1) {
                    $errors['department_code'] = __('import.department_ambiguous');
                } elseif (! Reach::department($scope, $candidates->first()->id)) {
                    $errors['department_code'] = __('access.outside_scope');
                } else {
                    $data['department_id'] = $candidates->first()->id;
                    $data['institution_id'] = $candidates->first()->institution_id;
                }
            }
            $data['reporting_officer_code'] = ($row['reporting_officer_code'] ?? '') === '' ? null : $row['reporting_officer_code'];
            $officer = $data['reporting_officer_code'] ? mb_strtolower($data['reporting_officer_code']) : null;
            if ($officer !== null && ! isset($existingCodes[$officer]) && ! isset($fileCodes[$officer])) {
                $errors['reporting_officer_code'] = __('import.officer_not_found');
            }

            $result[] = ['line' => $i + 2, 'data' => $data, 'errors' => $errors];
        }

        return $result;
    }

    /** @param  list<array{line: int, data: array<string, mixed>, errors: array<string, string>}>  $checked */
    public function import(array $checked, User $actor): int
    {
        return DB::transaction(function () use ($checked, $actor) {
            $created = [];
            foreach ($checked as $row) {
                $employee = new Employee(collect($row['data'])->except('reporting_officer_code')->all());
                $employee->forceFill(['created_by' => $actor->id, 'is_active' => true])->save();
                $created[mb_strtolower($employee->employee_code)] = [$employee, $row['data']['reporting_officer_code']];
            }
            // Officers may be other rows of the same file, so they are linked once every row exists.
            $byCode = Employee::query()->pluck('id', 'employee_code')->mapWithKeys(fn ($id, $c) => [mb_strtolower($c) => $id]);
            foreach ($created as [$employee, $officerCode]) {
                if ($officerCode) {
                    $employee->forceFill(['reporting_officer_id' => $byCode[mb_strtolower($officerCode)]])->save();
                }
            }
            AuditLogger::record('employees', 'import', null, null, ['rows' => count($created)]);

            return count($created);
        });
    }
}
