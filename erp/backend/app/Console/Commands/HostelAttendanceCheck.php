<?php

namespace App\Console\Commands;

use App\Models\Department;
use App\Models\Hostel;
use App\Models\Organization;
use App\Services\AttendanceRules;
use App\Services\Notifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The old app's check-hostel-attendance cron (evening): for every active
 * hostel with residents, a roll call of today that was never taken is
 * reported to the hostel's staff and the head of the department running it.
 * Once a day per hostel and person.
 */
class HostelAttendanceCheck extends Command
{
    protected $signature = 'erp:hostel-attendance-check {--date= : Check this date (Y-m-d) instead of today}';

    protected $description = 'Remind hostel staff of roll calls not taken today.';

    public function handle(): int
    {
        $sent = 0;
        Organization::query()->where('is_active', true)->each(function (Organization $organization) use (&$sent) {
            $date = $this->option('date') ?: AttendanceRules::for($organization)->now()->toDateString();
            Hostel::withoutGlobalScopes()->where('organization_id', $organization->id)->where('is_active', true)
                ->whereHas('residents', fn ($q) => $q->where('is_active', true))
                ->each(function (Hostel $hostel) use ($date, &$sent) {
                    $marked = DB::table('hostel_attendance')->where('hostel_id', $hostel->id)->where('attendance_date', $date)->distinct()->pluck('session')->all();
                    $missing = array_values(array_diff(Hostel::SESSIONS, $marked));
                    if ($missing === []) {
                        return;
                    }
                    $to = $hostel->staff()->pluck('user_id')->all();
                    $to[] = Department::withoutGlobalScopes()->whereKey($hostel->department_id)->value('head_user_id');
                    foreach (array_unique(array_filter($to)) as $userId) {
                        Notifier::user($userId, $hostel->organization_id, 'hostel.attendance_missing',
                            ['hostel' => $hostel->name, 'sessions' => implode(',', $missing), 'date' => $date],
                            "/hostels/{$hostel->id}/attendance", "hostel.attendance:{$hostel->id}:{$userId}:{$date}");
                        $sent++;
                    }
                });
        });
        $this->info("Hostel roll calls checked ({$sent}).");

        return self::SUCCESS;
    }
}
