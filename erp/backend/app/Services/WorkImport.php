<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\User;
use App\Models\WorkEntry;
use App\Support\Access\AccessContext;
use App\Support\Audit\AuditLogger;
use App\Support\Forms\Dates;
use App\Support\Queries\PeopleQueries;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Brings the old app's work records in (erp/docs/WORK-AND-TASKS.md). Rows are
 * matched to employees by code, ignore the edit window, and are written all
 * together or not at all. Imported entries are marked `is_imported`.
 */
final class WorkImport
{
    public const COLUMNS = [
        'date', 'employee_code', 'title', 'description', 'hours', 'status', 'requested_by', 'team_codes', 'estimated_cost', 'actual_cost',
    ];

    public const REQUIRED = ['date', 'employee_code', 'title'];

    public const MAX_ROWS = 5000;

    /**
     * @param  list<array<string, string>>  $rows
     * @return list<array{line: int, data: array<string, mixed>, errors: array<string, string>}>
     */
    public function check(array $rows): array
    {
        $organization = AccessContext::current()->organization();
        $today = AttendanceRules::for($organization)->now()->toDateString();
        $reachable = PeopleQueries::employees('daily_work.import')->get(['id', 'employee_code', 'institution_id', 'department_id'])
            ->keyBy(fn (Employee $e) => mb_strtolower($e->employee_code));
        $active = Employee::query()->where('is_active', true)->pluck('id', 'employee_code')
            ->mapWithKeys(fn ($id, $code) => [mb_strtolower($code) => $id]);

        $result = [];
        foreach ($rows as $i => $row) {
            $errors = [];
            $v = Validator::make($row, [
                'employee_code' => ['required', 'max:30'],
                'title' => ['required', 'max:255'],
                'description' => ['nullable', 'max:5000'],
                'hours' => ['nullable', 'numeric', 'min:0.25', 'max:24', 'multiple_of:0.25'],
                'status' => ['nullable', 'in:'.implode(',', WorkEntry::STATUSES)],
                'requested_by' => ['nullable', 'max:255'],
                'estimated_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
                'actual_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            ]);
            foreach ($v->errors()->messages() as $field => $messages) {
                $errors[$field] = $messages[0];
            }

            $date = ($row['date'] ?? '') === '' ? null : Dates::parse($row['date']);
            if ($date === null) {
                $errors['date'] = __('import.bad_date');
            } elseif ($date > $today) {
                $errors['date'] = __('work.future');
            }

            $employee = $reachable[mb_strtolower($row['employee_code'] ?? '')] ?? null;
            if (($row['employee_code'] ?? '') !== '' && $employee === null) {
                $errors['employee_code'] = __('import.employee_not_found');
            }

            $team = [];
            foreach (array_filter(array_map('trim', explode(',', $row['team_codes'] ?? ''))) as $code) {
                $id = $active[mb_strtolower($code)] ?? null;
                if ($id === null) {
                    $errors['team_codes'] = __('import.team_not_found', ['code' => $code]);
                } elseif ($id !== $employee?->id) {
                    $team[] = $id;
                }
            }
            if (count($team) > WorkEntry::MAX_TEAM) {
                $errors['team_codes'] = __('validation.max.array', ['attribute' => 'team_codes', 'max' => WorkEntry::MAX_TEAM]);
            }

            $data = [
                'work_date' => $date,
                'employee_id' => $employee?->id,
                'institution_id' => $employee?->institution_id,
                'department_id' => $employee?->department_id,
                'title' => $row['title'] ?? '',
                'team' => array_values(array_unique($team)),
            ];
            foreach (['description', 'hours', 'requested_by', 'estimated_cost', 'actual_cost'] as $f) {
                $data[$f] = ($row[$f] ?? '') === '' ? null : $row[$f];
            }
            $data['status'] = ($row['status'] ?? '') === '' ? 'completed' : $row['status'];

            $result[] = ['line' => $i + 2, 'data' => $data, 'errors' => $errors];
        }

        return $result;
    }

    /** @param  list<array{line: int, data: array<string, mixed>, errors: array<string, string>}>  $checked */
    public function import(array $checked, User $actor): int
    {
        return DB::transaction(function () use ($checked, $actor) {
            foreach ($checked as $row) {
                $entry = WorkEntry::create(collect($row['data'])->except('team')->all() + ['created_by' => $actor->id, 'is_imported' => true]);
                $entry->team()->sync($row['data']['team']);
            }
            AuditLogger::record('daily_work', 'import', null, null, ['rows' => count($checked)]);

            return count($checked);
        });
    }
}
