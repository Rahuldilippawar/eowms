<?php

namespace App\Console\Commands;

use App\Models\Department;
use App\Models\Grievance;
use App\Models\Organization;
use App\Models\WorkRequest;
use App\Models\WorkRequestAction;
use App\Services\AttendanceRules;
use App\Services\Notifier;
use App\Support\Access\PermissionHolders;
use Illuminate\Console\Command;

/**
 * The old app's escalate-tickets cron (hourly):
 * - a work request past its SLA goes to the receiving department head
 *   (level 1); past twice its SLA, also to whoever handles requests across
 *   the organization (level 2);
 * - a grievance past its 7-day deadline goes to the grievance handlers and
 *   its assignee, once.
 * Each person gets one summary per run, however many items are late. An
 * anonymous grievance never names its sender.
 */
class EscalateTickets extends Command
{
    protected $signature = 'erp:escalate-tickets';

    protected $description = 'Escalate work requests past their SLA and grievances past their deadline.';

    public function handle(): int
    {
        $sent = 0;
        Organization::query()->where('is_active', true)->each(function (Organization $organization) use (&$sent) {
            /** @var array<int, array{requests: list<string>, grievances: list<int>}> $digest */
            $digest = [];
            $add = function (array $userIds, string $kind, string|int $item) use (&$digest) {
                foreach (array_unique(array_filter($userIds)) as $userId) {
                    $digest[$userId][$kind][] = $item;
                }
            };
            $orgWide = null;

            WorkRequest::withoutGlobalScopes()->where('organization_id', $organization->id)->whereIn('status', WorkRequest::OPEN)
                ->whereNotNull('due_at')->where('due_at', '<', now())->where('escalation_level', '<', 2)
                ->each(function (WorkRequest $r) use ($organization, $add, &$orgWide) {
                    $target = now()->greaterThanOrEqualTo($r->due_at->copy()->addHours(WorkRequest::slaHours($r->priority))) ? 2 : 1;
                    if ($target <= $r->escalation_level) {
                        return;
                    }
                    $claimed = WorkRequest::withoutGlobalScopes()->whereKey($r->id)->where('escalation_level', '<', $target)
                        ->update(['escalation_level' => $target, 'escalated_at' => now()]);
                    if ($claimed === 0) {
                        return;
                    }
                    WorkRequestAction::create(['work_request_id' => $r->id, 'action_type' => 'escalated', 'old_status' => $r->status, 'new_status' => $r->status,
                        'remark' => $target === 2 ? 'SLA passed twice over: organization' : 'SLA passed: department head']);
                    $head = Department::withoutGlobalScopes()->whereKey($r->department_id)->value('head_user_id');
                    if ($r->escalation_level < 1) {
                        $add([$head], 'requests', $r->request_code);
                    }
                    if ($target === 2) {
                        $orgWide ??= PermissionHolders::userIds($organization->id, 'requests.manage', true);
                        $add($orgWide, 'requests', $r->request_code);
                    }
                });

            $today = AttendanceRules::for($organization)->now()->toDateString();
            $handlers = null;
            Grievance::withoutGlobalScopes()->where('organization_id', $organization->id)->whereIn('status', Grievance::OPEN)
                ->whereNotNull('sla_deadline')->where('sla_deadline', '<', $today)->whereNull('escalated_at')
                ->each(function (Grievance $g) use ($organization, $add, &$handlers) {
                    if (Grievance::withoutGlobalScopes()->whereKey($g->id)->whereNull('escalated_at')->update(['escalated_at' => now()]) === 0) {
                        return;
                    }
                    $handlers ??= PermissionHolders::userIds($organization->id, 'grievances.manage');
                    $add([...$handlers, $g->assigned_to], 'grievances', $g->id);
                });

            foreach ($digest as $userId => $items) {
                $requests = $items['requests'] ?? [];
                $grievances = $items['grievances'] ?? [];
                Notifier::user($userId, $organization->id, 'sla.escalated', [
                    'requests' => count($requests), 'grievances' => count($grievances),
                    'items' => implode(', ', [...array_slice($requests, 0, 5), ...array_map(fn ($id) => "#{$id}", array_slice($grievances, 0, 5))]),
                ], $requests ? '/requests?status=overdue' : '/grievances?status=overdue');
                $sent++;
            }
        });
        $this->info("Escalation checked ({$sent} summaries).");

        return self::SUCCESS;
    }
}
