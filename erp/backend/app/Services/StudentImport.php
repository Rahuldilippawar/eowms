<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Institution;
use App\Models\Student;
use App\Models\User;
use App\Support\Access\AccessContext;
use App\Support\Access\Reach;
use App\Support\Audit\AuditLogger;
use App\Support\Forms\Dates;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Student CSV import, the same way as the staff import: every row is checked
 * first, then all rows are written in one transaction or none (erp/docs/PEOPLE.md).
 */
final class StudentImport
{
    public const COLUMNS = [
        'student_code', 'name', 'institution_code', 'department_code', 'class_name', 'section', 'roll_no', 'admission_no',
        'year_of_study', 'academic_year', 'gender', 'date_of_birth', 'admission_date', 'guardian_name', 'guardian_phone', 'mobile', 'email',
    ];

    public const REQUIRED = ['student_code', 'name', 'institution_code'];

    private const TEXT = ['class_name', 'section', 'roll_no', 'admission_no', 'academic_year', 'gender', 'guardian_name', 'guardian_phone', 'mobile', 'email'];

    /**
     * @param  list<array<string, string>>  $rows
     * @return list<array{line: int, data: array<string, mixed>, errors: array<string, string>}>
     */
    public function check(array $rows): array
    {
        $scope = AccessContext::current()->scope('students.create');
        $institutions = Institution::query()->get(['id', 'code']);
        $departments = Department::query()->get(['id', 'code', 'institution_id']);
        $existingCodes = Student::query()->pluck('student_code')->mapWithKeys(fn ($c) => [mb_strtolower($c) => true]);
        $fileCodes = collect($rows)->countBy(fn ($r) => mb_strtolower($r['student_code'] ?? ''));

        $result = [];
        foreach ($rows as $i => $row) {
            $errors = [];
            $v = Validator::make($row, [
                'student_code' => ['required', 'max:30', 'regex:/^[A-Za-z0-9_\/-]+$/'],
                'name' => ['required', 'max:255'],
                'institution_code' => ['required', 'max:30'],
                'class_name' => ['nullable', 'max:50'],
                'section' => ['nullable', 'max:20'],
                'roll_no' => ['nullable', 'max:30'],
                'admission_no' => ['nullable', 'max:50'],
                'year_of_study' => ['nullable', 'integer', 'min:1', 'max:10'],
                'academic_year' => ['nullable', 'regex:'.Student::ACADEMIC_YEAR_PATTERN],
                'gender' => ['nullable', 'in:female,male,other'],
                'guardian_name' => ['nullable', 'max:255'],
                'guardian_phone' => ['nullable', 'max:30'],
                'mobile' => ['nullable', 'max:30'],
                'email' => ['nullable', 'email', 'max:255'],
            ]);
            foreach ($v->errors()->messages() as $field => $messages) {
                $errors[$field] = $messages[0];
            }
            $code = mb_strtolower($row['student_code'] ?? '');
            if ($code !== '' && ($fileCodes[$code] ?? 0) > 1) {
                $errors['student_code'] = __('import.duplicate_in_file');
            } elseif ($code !== '' && isset($existingCodes[$code])) {
                $errors['student_code'] = __('validation.unique', ['attribute' => 'student_code']);
            }

            $data = ['student_code' => $row['student_code'] ?? '', 'name' => $row['name'] ?? '', 'department_id' => null];
            foreach (self::TEXT as $f) {
                $data[$f] = ($row[$f] ?? '') === '' ? null : $row[$f];
            }
            $data['year_of_study'] = ($row['year_of_study'] ?? '') === '' ? null : (int) $row['year_of_study'];
            foreach (['date_of_birth', 'admission_date'] as $f) {
                $data[$f] = null;
                if (($row[$f] ?? '') !== '') {
                    $data[$f] = Dates::parse($row[$f]);
                    if ($data[$f] === null) {
                        $errors[$f] = __('import.bad_date');
                    }
                }
            }

            $institution = $institutions->first(fn ($inst) => strcasecmp($inst->code, $row['institution_code'] ?? '') === 0);
            if (($row['institution_code'] ?? '') !== '' && $institution === null) {
                $errors['institution_code'] = __('import.institution_not_found');
            } elseif ($institution !== null) {
                $data['institution_id'] = $institution->id;
                $department = null;
                if (($row['department_code'] ?? '') !== '') {
                    $department = $departments->first(fn ($d) => $d->institution_id === $institution->id && strcasecmp($d->code, $row['department_code']) === 0);
                    if ($department === null) {
                        $errors['department_code'] = __('import.department_not_found');
                    } else {
                        $data['department_id'] = $department->id;
                    }
                }
                $reaches = $department !== null ? Reach::department($scope, $department->id) : Reach::institution($scope, $institution->id);
                if (! $reaches && ! isset($errors['department_code'])) {
                    $errors[$department !== null ? 'department_code' : 'institution_code'] = __('access.outside_scope');
                }
            }

            $result[] = ['line' => $i + 2, 'data' => $data, 'errors' => $errors];
        }

        return $result;
    }

    /** @param  list<array{line: int, data: array<string, mixed>, errors: array<string, string>}>  $checked */
    public function import(array $checked, User $actor): int
    {
        return DB::transaction(function () use ($checked, $actor) {
            foreach ($checked as $row) {
                (new Student($row['data']))->forceFill(['created_by' => $actor->id, 'is_active' => true])->save();
            }
            AuditLogger::record('students', 'import', null, null, ['rows' => count($checked)]);

            return count($checked);
        });
    }
}
