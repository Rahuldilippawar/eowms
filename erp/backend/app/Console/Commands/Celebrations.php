<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\Organization;
use App\Services\AttendanceRules;
use App\Services\Notifier;
use Illuminate\Console\Command;

/**
 * Birthday and work-anniversary wishes (the old app's birthday cron), as
 * in-app notifications to the person and their reporting officer. Once a year each.
 */
class Celebrations extends Command
{
    protected $signature = 'erp:celebrations';

    protected $description = 'Send birthday and work-anniversary wishes for today.';

    public function handle(): int
    {
        $sent = 0;
        Organization::query()->where('is_active', true)->each(function (Organization $organization) use (&$sent) {
            $today = AttendanceRules::for($organization)->now();
            $md = $today->format('m-d');
            Employee::withoutGlobalScopes()->with('reportingOfficer')->where(['organization_id' => $organization->id, 'is_active' => true])
                ->where(fn ($q) => $q->whereRaw("DATE_FORMAT(date_of_birth, '%m-%d') = ?", [$md])->orWhereRaw("DATE_FORMAT(joining_date, '%m-%d') = ?", [$md]))
                ->each(function (Employee $e) use ($today, $md, &$sent) {
                    foreach (['birthday' => $e->date_of_birth, 'anniversary' => $e->joining_date] as $kind => $date) {
                        if ($date === null || $date->format('m-d') !== $md || ($kind === 'anniversary' && $date->year >= $today->year)) {
                            continue;
                        }
                        $data = ['employee' => $e->name, 'years' => $today->year - $date->year];
                        $key = "{$kind}:{$e->id}:{$today->year}";
                        Notifier::employee($e, "celebration.{$kind}_self", $data, '/my/profile', $key);
                        Notifier::employee($e->reportingOfficer, "celebration.{$kind}", $data, "/employees/{$e->id}", $key);
                        $sent++;
                    }
                });
        });
        $this->info("Celebrations: {$sent}.");

        return self::SUCCESS;
    }
}
