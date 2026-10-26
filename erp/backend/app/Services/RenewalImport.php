<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Employee;
use App\Models\RenewalItem;
use App\Models\User;
use App\Support\Access\AccessContext;
use App\Support\Access\ScopeDepartments;
use App\Support\Audit\AuditLogger;
use App\Support\Forms\Dates;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Renewal items from CSV: every row checked first, then all rows or none (erp/docs/RENEWALS.md). */
final class RenewalImport
{
    public const COLUMNS = [
        'department_code', 'item_type', 'regulatory_body', 'item_name', 'reference_no', 'issued_date', 'expiry_date', 'renewal_cost',
        'vendor_name', 'vendor_contact', 'responsible_employee_code',
    ];

    public const REQUIRED = ['department_code', 'item_type', 'item_name', 'expiry_date'];

    /**
     * @param  list<array<string, string>>  $rows
     * @return list<array{line: int, data: array<string, mixed>, errors: array<string, string>}>
     */
    public function check(array $rows): array
    {
        $scope = AccessContext::current()->scope('renewals.manage');
        $departments = Department::query()->get(['id', 'code', 'institution_id']);
        $employees = Employee::query()->where('is_active', true)->whereNotNull('user_id')->pluck('user_id', 'employee_code')
            ->mapWithKeys(fn ($u, $c) => [mb_strtolower($c) => $u]);

        $result = [];
        foreach ($rows as $i => $row) {
            $errors = [];
            $v = Validator::make($row, [
                'item_type' => ['required', 'max:100'],
                'item_name' => ['required', 'max:255'],
                'regulatory_body' => ['nullable', 'in:'.implode(',', RenewalItem::REGULATORY_BODIES)],
                'reference_no' => ['nullable', 'max:100'],
                'renewal_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
                'vendor_name' => ['nullable', 'max:255'],
                'vendor_contact' => ['nullable', 'max:100'],
            ]);
            foreach ($v->errors()->messages() as $field => $messages) {
                $errors[$field] = $messages[0];
            }
            $data = ['item_type' => $row['item_type'] ?? '', 'item_name' => $row['item_name'] ?? ''];
            foreach (['regulatory_body', 'reference_no', 'renewal_cost', 'vendor_name', 'vendor_contact'] as $f) {
                $data[$f] = ($row[$f] ?? '') === '' ? null : $row[$f];
            }
            foreach (['issued_date', 'expiry_date'] as $f) {
                $data[$f] = ($row[$f] ?? '') === '' ? null : Dates::parse($row[$f]);
                if (($row[$f] ?? '') !== '' && $data[$f] === null) {
                    $errors[$f] = __('import.bad_date');
                }
            }
            if ($data['expiry_date'] === null && ! isset($errors['expiry_date'])) {
                $errors['expiry_date'] = __('validation.required', ['attribute' => 'expiry_date']);
            }
            $department = $departments->first(fn ($d) => strcasecmp($d->code, $row['department_code'] ?? '') === 0);
            if ($department === null) {
                $errors['department_code'] = __('import.department_not_found');
            } elseif (! ScopeDepartments::reaches($scope, $department->id)) {
                $errors['department_code'] = __('access.outside_scope');
            } else {
                $data['department_id'] = $department->id;
                $data['institution_id'] = $department->institution_id;
            }
            $data['responsible_user_id'] = null;
            if (($row['responsible_employee_code'] ?? '') !== '') {
                $data['responsible_user_id'] = $employees[mb_strtolower($row['responsible_employee_code'])] ?? null;
                if ($data['responsible_user_id'] === null) {
                    $errors['responsible_employee_code'] = __('import.employee_not_found');
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
                RenewalItem::create($row['data'] + ['created_by' => $actor->id]);
            }
            AuditLogger::record('renewals', 'import', null, null, ['rows' => count($checked)]);

            return count($checked);
        });
    }
}
