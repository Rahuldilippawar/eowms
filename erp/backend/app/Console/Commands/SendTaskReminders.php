<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\Task;
use App\Services\AttendanceRules;
use App\Services\Notifier;
use App\Services\TaskService;
use Illuminate\Console\Command;

/**
 * Due-tomorrow and overdue reminders. Safe to run as often as the scheduler
 * likes: each reminder has a dedupe key, so it is sent at most once.
 */
class SendTaskReminders extends Command
{
    protected $signature = 'erp:task-reminders';

    protected $description = 'Notify assignees about tasks due tomorrow, and assignees and assigners about overdue tasks (once each).';

    public function handle(): int
    {
        $sent = 0;
        Organization::query()->where('is_active', true)->each(function (Organization $organization) use (&$sent) {
            $today = AttendanceRules::for($organization)->now()->toDateString();
            $tomorrow = AttendanceRules::for($organization)->now()->addDay()->toDateString();

            Task::query()->where('organization_id', $organization->id)->whereIn('status', ['open', 'in_progress'])
                ->where('due_date', $tomorrow)->with('assignee')->each(function (Task $task) use (&$sent) {
                    Notifier::employee($task->assignee, 'task.due_soon', $this->data($task), TaskService::link($task), "task.due_soon:{$task->id}:{$task->due_date->toDateString()}");
                    $sent++;
                });

            Task::query()->where('organization_id', $organization->id)->whereIn('status', Task::OPEN_STATES)
                ->where('due_date', '<', $today)->with('assignee')->each(function (Task $task) use (&$sent) {
                    $key = "task.overdue:{$task->id}:{$task->due_date->toDateString()}";
                    Notifier::employee($task->assignee, 'task.overdue', $this->data($task), TaskService::link($task), $key);
                    Notifier::user($task->assigned_by, $task->organization_id, 'task.overdue', $this->data($task) + ['assignee' => $task->assignee->name], TaskService::link($task), $key);
                    $sent++;
                });
        });
        $this->info("Task reminders checked ({$sent} tasks).");

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function data(Task $task): array
    {
        return ['task_id' => $task->id, 'code' => $task->code, 'title' => mb_substr($task->title, 0, 120), 'due_date' => $task->due_date->toDateString()];
    }
}
