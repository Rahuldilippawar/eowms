<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Forms\CustomFields;
use App\Support\Queries\PeopleQueries;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Daily work log rules: an employee logs their own work for today or the
 * last `daily_work.edit_days` days (organization setting, default 3); an
 * entry is frozen once reviewed. Reviewers need `daily_work.review` with a
 * scope reaching the employee and never review their own work.
 */
final class WorkService
{
    public static function editDays(Organization $organization): int
    {
        return max(0, min(31, (int) ((($organization->settings ?? [])['daily_work'] ?? [])['edit_days'] ?? 3)));
    }

    public function today(Organization $organization): CarbonImmutable
    {
        return AttendanceRules::for($organization)->now()->startOfDay();
    }

    public function editable(WorkEntry $entry, ?User $user): bool
    {
        $entry->loadMissing('employee');
        if ($user === null || $entry->employee->user_id !== $user->id || $entry->reviewed_at !== null) {
            return false;
        }
        $organization = $entry->employee->organization;

        return $entry->work_date->toDateString() >= $this->today($organization)->subDays(self::editDays($organization))->toDateString();
    }

    /** @param  array<string, mixed>  $data */
    public function save(Employee $employee, array $data, User $actor, ?WorkEntry $entry = null): WorkEntry
    {
        if ($entry !== null && ! $this->editable($entry, $actor)) {
            throw ValidationException::withMessages(['work_date' => [$entry->reviewed_at ? __('work.reviewed') : __('work.outside_window', ['days' => self::editDays($employee->organization)])]]);
        }
        $this->assertDate($employee, $data['work_date']);
        if (! empty($data['task_id'])) {
            $ownTask = Task::query()->whereKey($data['task_id'])->where('assignee_employee_id', $employee->id)->where('status', '!=', 'cancelled')->exists();
            if (! $ownTask) {
                throw ValidationException::withMessages(['task_id' => [__('work.task_not_yours')]]);
            }
        }

        return DB::transaction(function () use ($employee, $data, $actor, $entry) {
            // Serialise this employee's writes so the 24-hour check cannot be raced.
            Employee::query()->whereKey($employee->id)->lockForUpdate()->first();
            $others = WorkEntry::query()->where('employee_id', $employee->id)->where('work_date', $data['work_date'])
                ->when($entry, fn ($q) => $q->whereKeyNot($entry->id))->sum('hours');
            if ((float) $others + (float) ($data['hours'] ?? 0) > 24) {
                throw ValidationException::withMessages(['hours' => [__('work.too_many_hours')]]);
            }

            $values = [
                'work_date' => $data['work_date'],
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'hours' => $data['hours'] ?? null,
                'status' => $data['status'],
                'task_id' => $data['task_id'] ?? null,
                'custom' => CustomFields::clean('work_entry', $data['custom'] ?? null, $entry?->custom),
            ];
            if ($entry === null) {
                $entry = WorkEntry::create($values + [
                    'employee_id' => $employee->id,
                    'institution_id' => $employee->institution_id,
                    'department_id' => $employee->department_id,
                    'created_by' => $actor->id,
                ]);
                AuditLogger::record('daily_work', 'create', $entry, null, AuditLogger::snapshot($entry));
            } else {
                $before = $entry->getAttributes();
                $entry->fill($values + ['updated_by' => $actor->id])->save();
                AuditLogger::recordChanges('daily_work', 'update', $entry, $before);
            }

            return $entry;
        });
    }

    public function delete(WorkEntry $entry, User $actor): void
    {
        if (! $this->editable($entry, $actor)) {
            throw ValidationException::withMessages(['work_date' => [$entry->reviewed_at ? __('work.reviewed') : __('work.outside_window', ['days' => self::editDays($entry->employee->organization)])]]);
        }
        AuditLogger::record('daily_work', 'delete', $entry, AuditLogger::snapshot($entry));
        $entry->delete();
    }

    public function review(WorkEntry $entry, ?string $remarks, User $actor): WorkEntry
    {
        abort_unless(PeopleQueries::employeeIds('daily_work.review')->where('employees.id', $entry->employee_id)->exists(), 404);
        if ($entry->employee->user_id === $actor->id) {
            throw ValidationException::withMessages(['entry' => [__('work.not_own')]]);
        }

        $entry = DB::transaction(function () use ($entry, $remarks, $actor) {
            $locked = WorkEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $before = $locked->getAttributes();
            $locked->forceFill(['reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_remarks' => $remarks])->save();
            AuditLogger::recordChanges('daily_work', 'review', $locked, $before, $remarks);

            return $locked;
        });
        Notifier::employee($entry->employee, 'work.reviewed', [
            'entry_id' => $entry->id, 'title' => mb_substr($entry->title, 0, 120), 'work_date' => $entry->work_date->toDateString(),
            'by' => $actor->name, 'note' => $remarks ? mb_substr($remarks, 0, 200) : null,
        ], '/my/work');

        return $entry;
    }

    private function assertDate(Employee $employee, string $date): void
    {
        // Plain Y-m-d strings compare correctly and avoid timezone shifts.
        $today = $this->today($employee->organization);
        if ($date > $today->toDateString()) {
            throw ValidationException::withMessages(['work_date' => [__('work.future')]]);
        }
        $days = self::editDays($employee->organization);
        if ($date < $today->subDays($days)->toDateString()) {
            throw ValidationException::withMessages(['work_date' => [__('work.outside_window', ['days' => $days])]]);
        }
    }
}
