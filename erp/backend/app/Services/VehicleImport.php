<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Vehicles\VehicleBook;
use App\Support\Access\AccessContext;
use App\Support\Access\ScopeDepartments;
use App\Support\Audit\AuditLogger;
use App\Support\Forms\Dates;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/** Vehicles from CSV, with their document dates: every row checked first, then all rows or none. */
final class VehicleImport
{
    public const COLUMNS = [
        'vehicle_number', 'department_code', 'vehicle_type', 'model', 'registration_date', 'chassis_no', 'engine_no', 'vehicle_class',
        'passenger_capacity', 'fuel_type', 'current_odometer', 'driver_employee_code',
        'permit_validity', 'puc_validity', 'insurance_validity', 'fitness_validity', 'road_tax_validity', 'green_tax_validity', 'mv_tax_validity',
    ];

    public const REQUIRED = ['vehicle_number', 'department_code'];

    public function __construct(private readonly VehicleBook $book) {}

    /**
     * @param  list<array<string, string>>  $rows
     * @return list<array{line: int, data: array<string, mixed>, errors: array<string, string>}>
     */
    public function check(array $rows): array
    {
        $scope = AccessContext::current()->scope('vehicles.manage');
        $departments = Department::query()->get(['id', 'code', 'institution_id']);
        $existing = Vehicle::query()->pluck('vehicle_number')->map(fn ($n) => mb_strtoupper($n))->flip();
        $employees = Employee::query()->where('is_active', true)->whereNotNull('user_id')->pluck('user_id', 'employee_code')
            ->mapWithKeys(fn ($u, $c) => [mb_strtolower($c) => $u]);
        $seen = [];

        $result = [];
        foreach ($rows as $i => $row) {
            $errors = [];
            $v = Validator::make($row, [
                'vehicle_number' => ['required', 'max:50'],
                'vehicle_type' => ['nullable', 'max:100'],
                'model' => ['nullable', 'max:100'],
                'chassis_no' => ['nullable', 'max:100'],
                'engine_no' => ['nullable', 'max:100'],
                'vehicle_class' => ['nullable', 'max:100'],
                'passenger_capacity' => ['nullable', 'integer', 'min:0', 'max:1000'],
                'fuel_type' => ['nullable', 'in:'.implode(',', Vehicle::FUEL_TYPES)],
                'current_odometer' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            ]);
            foreach ($v->errors()->messages() as $field => $messages) {
                $errors[$field] = $messages[0];
            }
            $number = mb_strtoupper(preg_replace('/\s+/', '', $row['vehicle_number'] ?? ''));
            if ($number !== '' && (isset($existing[$number]) || isset($seen[$number]))) {
                $errors['vehicle_number'] = __('vehicles.duplicate_number');
            }
            $seen[$number] = true;
            $data = ['vehicle_number' => $number, 'fuel_type' => strtolower($row['fuel_type'] ?? '') ?: 'diesel', 'current_odometer' => ($row['current_odometer'] ?? '') === '' ? 0 : $row['current_odometer']];
            foreach (['vehicle_type', 'model', 'chassis_no', 'engine_no', 'vehicle_class', 'passenger_capacity'] as $f) {
                $data[$f] = ($row[$f] ?? '') === '' ? null : $row[$f];
            }
            $data['registration_date'] = null;
            $dates = [];
            foreach (['registration_date', ...array_map(fn ($k) => "{$k}_validity", Vehicle::RENEWAL_FIELDS)] as $f) {
                $parsed = ($row[$f] ?? '') === '' ? null : Dates::parse($row[$f]);
                if (($row[$f] ?? '') !== '' && $parsed === null) {
                    $errors[$f] = __('import.bad_date');
                }
                if ($f === 'registration_date') {
                    $data[$f] = $parsed;
                } else {
                    $dates[substr($f, 0, -9)] = $parsed;
                }
            }
            $data['documents'] = $dates;
            $department = $departments->first(fn ($d) => strcasecmp($d->code, $row['department_code'] ?? '') === 0);
            if ($department === null) {
                $errors['department_code'] = __('import.department_not_found');
            } elseif (! ScopeDepartments::reaches($scope, $department->id)) {
                $errors['department_code'] = __('access.outside_scope');
            } else {
                $data['department_id'] = $department->id;
                $data['institution_id'] = $department->institution_id;
            }
            $data['driver_user_id'] = null;
            if (($row['driver_employee_code'] ?? '') !== '') {
                $data['driver_user_id'] = $employees[mb_strtolower($row['driver_employee_code'])] ?? null;
                if ($data['driver_user_id'] === null) {
                    $errors['driver_employee_code'] = __('import.employee_not_found');
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
                $data = $row['data'];
                $dates = $data['documents'];
                unset($data['documents']);
                $vehicle = Vehicle::create($data + ['created_by' => $actor->id]);
                $this->book->syncRenewals($vehicle, $dates, $actor->id);
            }
            AuditLogger::record('vehicles', 'import', null, null, ['rows' => count($checked)]);

            return count($checked);
        });
    }
}
