<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Task;
use App\Models\TaskUpdate;
use App\Models\User;
use App\Support\Access\AccessContext;
use App\Support\Audit\AuditLogger;
use App\Support\Queries\PeopleQueries;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Task rules (see docs/WORK-AND-TASKS.md).
 *
 * - Assign: to an active employee who is not yourself, when you are their
 *   reporting officer or your `tasks.create` scope reaches them.
 * - Manage (edit, verify, reopen, cancel): the assigner, the assignee's
 *   reporting officer, or a `tasks.edit` holder whose scope reaches the
 *   assignee — never the assignee.
 * - Work it (start, submit): the assignee only.
 */
final class TaskService
{
    /** action => [allowed from-states, to-state, who] */
    private const TRANSITIONS = [
        'start' => [['open'], 'in_progress', 'assignee'],
        'submit' => [['open', 'in_progress'], 'submitted', 'assignee'],
        'complete' => [['submitted'], 'completed', 'manager'],
        'reopen' => [['submitted'], 'in_progress', 'manager'],
        'cancel' => [['open', 'in_progress', 'submitted'], 'cancelled', 'manager'],
    ];

    /** Timeline entry type written for each action. */
    private const LOG_TYPE = ['start' => 'started', 'submit' => 'submitted', 'complete' => 'completed', 'reopen' => 'reopened', 'cancel' => 'cancelled'];

    /** Actions that need a note explaining why. */
    private const NEEDS_NOTE = ['reopen', 'cancel'];

    /** @return Builder<Employee> employees $actor may assign tasks to */
    public static function assignableEmployees(User $actor): Builder
    {
        $context = AccessContext::current();
        $mine = PeopleQueries::myEmployee();

        return Employee::query()
            ->where('employees.is_active', true)
            ->where(fn ($q) => $q->whereNull('employees.user_id')->orWhere('employees.user_id', '!=', $actor->id))
            ->where(function ($w) use ($context, $mine) {
                $w->whereRaw('1 = 0');
                if ($mine !== null) {
                    $w->orWhere('employees.reporting_officer_id', $mine->id);
                }
                if ($context?->can('tasks.create')) {
                    $w->orWhereIn('employees.id', PeopleQueries::employeeIds('tasks.create'));
                }
            });
    }

    public static function mayAssignTo(User $actor, Employee $employee): bool
    {
        return self::assignableEmployees($actor)->whereKey($employee->id)->exists();
    }

    /** @return Builder<Task> tasks $actor may see */
    public static function visible(User $actor): Builder
    {
        $context = AccessContext::current();
        $mine = PeopleQueries::myEmployee();

        return Task::query()->where(function ($w) use ($actor, $context, $mine) {
            $w->where('tasks.assigned_by', $actor->id);
            if ($mine !== null) {
                $w->orWhere('tasks.assignee_employee_id', $mine->id)
                    ->orWhereIn('tasks.assignee_employee_id', Employee::query()->where('reporting_officer_id', $mine->id)->select('id'));
            }
            foreach (['tasks.view', 'tasks.edit'] as $permission) {
                if ($context?->can($permission)) {
                    $w->orWhereIn('tasks.assignee_employee_id', PeopleQueries::employeeIds($permission));
                }
            }
        });
    }

    public static function isAssignee(User $actor, Task $task): bool
    {
        return $task->assignee->user_id === $actor->id;
    }

    public static function isManager(User $actor, Task $task): bool
    {
        if (self::isAssignee($actor, $task)) {
            return false;
        }
        if ($task->assigned_by === $actor->id) {
            return true;
        }
        $officer = $task->assignee->reportingOfficer;
        if ($officer !== null && $officer->user_id === $actor->id) {
            return true;
        }

        return AccessContext::current()?->can('tasks.edit')
            && PeopleQueries::employeeIds('tasks.edit')->where('employees.id', $task->assignee_employee_id)->exists();
    }

    /** @return list<string> what $actor can do with $task right now */
    public static function actionsFor(User $actor, Task $task): array
    {
        $assignee = self::isAssignee($actor, $task);
        $manager = self::isManager($actor, $task);
        $actions = [];
        foreach (self::TRANSITIONS as $action => [$from, , $who]) {
            if (in_array($task->status, $from, true) && ($who === 'assignee' ? $assignee : $manager)) {
                $actions[] = $action;
            }
        }
        if ($manager && $task->isOpen()) {
            $actions[] = 'edit';
        }
        if (($assignee || $manager) && $task->status !== 'cancelled') {
            $actions[] = 'comment';
        }

        return $actions;
    }

    /** @param  array{title: string, description?: ?string, priority: string, due_date: string, assignee_employee_id: int}  $data */
    public function create(array $data, User $actor): Task
    {
        $employee = Employee::query()->findOrFail($data['assignee_employee_id']);
        $this->assertAssignable($actor, $employee);

        $task = DB::transaction(function () use ($data, $actor, $employee) {
            $task = Task::create([
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'priority' => $data['priority'],
                'due_date' => $data['due_date'],
                'assignee_employee_id' => $employee->id,
                'institution_id' => $employee->institution_id,
                'department_id' => $employee->department_id,
                'assigned_by' => $actor->id,
                'status' => 'open',
            ]);
            $task->forceFill(['code' => sprintf('TSK-%05d', $task->id)])->save();
            $this->log($task, $actor, 'created', null, 'open', null);
            AuditLogger::record('tasks', 'create', $task, null, AuditLogger::snapshot($task));

            return $task;
        });
        Notifier::employee($employee, 'task.assigned', $this->summary($task, $actor), self::link($task));

        return $task;
    }

    /** @param  array<string, mixed>  $data  title, description, priority, due_date, assignee_employee_id */
    public function update(Task $task, array $data, User $actor): Task
    {
        return DB::transaction(function () use ($task, $data, $actor) {
            $task = $this->lock($task);
            abort_unless(self::isManager($actor, $task) && $task->isOpen(), 403, __('tasks.not_allowed'));
            $before = $task->getAttributes();
            $previousAssignee = $task->assignee;

            if (isset($data['assignee_employee_id']) && (int) $data['assignee_employee_id'] !== $task->assignee_employee_id) {
                $employee = Employee::query()->findOrFail($data['assignee_employee_id']);
                $this->assertAssignable($actor, $employee);
                $task->fill(['assignee_employee_id' => $employee->id, 'institution_id' => $employee->institution_id, 'department_id' => $employee->department_id]);
                $task->setRelation('assignee', $employee);
            }
            $task->fill(collect($data)->only(['title', 'description', 'priority', 'due_date'])->all());
            $task->updated_by = $actor->id;
            $task->save();

            if (AuditLogger::recordChanges('tasks', 'update', $task, $before) !== null) {
                $this->log($task, $actor, 'edited', null, null, null);
            }
            if ($task->assignee->id !== $previousAssignee->id) {
                Notifier::employee($task->assignee, 'task.assigned', $this->summary($task, $actor), self::link($task));
                Notifier::employee($previousAssignee, 'task.unassigned', $this->summary($task, $actor), null);
            }

            return $task;
        });
    }

    public function transition(Task $task, string $action, ?string $note, User $actor): Task
    {
        abort_unless(isset(self::TRANSITIONS[$action]), 404);
        [$from, $to, $who] = self::TRANSITIONS[$action];
        if (in_array($action, self::NEEDS_NOTE, true) && trim((string) $note) === '') {
            throw ValidationException::withMessages(['note' => [__('tasks.note_required')]]);
        }

        $task = DB::transaction(function () use ($task, $action, $from, $to, $who, $note, $actor) {
            $task = $this->lock($task);
            $allowed = $who === 'assignee' ? self::isAssignee($actor, $task) : self::isManager($actor, $task);
            abort_unless($allowed, 403, __('tasks.not_allowed'));
            if (! in_array($task->status, $from, true)) {
                throw ValidationException::withMessages(['status' => [__('tasks.wrong_state')]]);
            }
            $before = $task->getAttributes();
            $previous = $task->status;
            $task->status = $to;
            $task->updated_by = $actor->id;
            match ($action) {
                'submit' => $task->submitted_at = now(),
                'complete' => $task->forceFill(['completed_at' => now(), 'completed_by' => $actor->id]),
                'reopen' => $task->submitted_at = null,
                'cancel' => $task->cancelled_at = now(),
                default => null,
            };
            $task->save();
            $this->log($task, $actor, self::LOG_TYPE[$action], $previous, $to, $note);
            AuditLogger::recordChanges('tasks', $action, $task, $before, $note);

            return $task;
        });

        $data = $this->summary($task, $actor) + ($note ? ['note' => mb_substr($note, 0, 200)] : []);
        if ($who === 'assignee') {
            // Tell the assigner (or, if they left, nobody).
            Notifier::user($task->assigned_by, $task->organization_id, "task.{$action}", $data, self::link($task));
        } else {
            Notifier::employee($task->assignee, "task.{$action}", $data, self::link($task));
        }

        return $task;
    }

    public function comment(Task $task, string $body, User $actor): TaskUpdate
    {
        abort_unless(in_array('comment', self::actionsFor($actor, $task), true), 403, __('tasks.not_allowed'));
        $update = $this->log($task, $actor, 'comment', null, null, $body);
        $data = $this->summary($task, $actor) + ['note' => mb_substr($body, 0, 200)];
        if (self::isAssignee($actor, $task)) {
            Notifier::user($task->assigned_by, $task->organization_id, 'task.comment', $data, self::link($task));
        } else {
            Notifier::employee($task->assignee, 'task.comment', $data, self::link($task));
        }

        return $update;
    }

    public static function link(Task $task): string
    {
        return "/tasks/{$task->id}";
    }

    private function assertAssignable(User $actor, Employee $employee): void
    {
        if (! self::mayAssignTo($actor, $employee)) {
            throw ValidationException::withMessages(['assignee_employee_id' => [__('tasks.cannot_assign')]]);
        }
    }

    private function lock(Task $task): Task
    {
        return Task::query()->whereKey($task->id)->lockForUpdate()->with('assignee.reportingOfficer')->firstOrFail();
    }

    private function log(Task $task, User $actor, string $type, ?string $from, ?string $to, ?string $body): TaskUpdate
    {
        return TaskUpdate::create(['task_id' => $task->id, 'user_id' => $actor->id, 'type' => $type, 'from_status' => $from, 'to_status' => $to, 'body' => $body]);
    }

    /** @return array<string, mixed> */
    private function summary(Task $task, User $actor): array
    {
        return ['task_id' => $task->id, 'code' => $task->code, 'title' => mb_substr($task->title, 0, 120), 'by' => $actor->name, 'due_date' => $task->due_date?->toDateString()];
    }
}
