<?php

namespace App\Console\Commands;

use App\Models\Department;
use App\Models\Organization;
use App\Models\Vehicle;
use App\Models\VehicleService;
use App\Services\AttendanceRules;
use App\Services\Notifier;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The old app's vehicle-service-due cron. From a vehicle's latest service
 * record: 7, 3, 1 and 0 days before the next service date, then 1 day late
 * and every 7 days after; and once when the odometer comes within 500 km of
 * the next service reading. The driver and the department head hear (the
 * vehicle's creator when neither exists). Each milestone is sent once per
 * service record, so the next service starts a fresh set.
 */
class VehicleServiceDue extends Command
{
    public const MILESTONES = [7, 3, 1, 0];

    public const KM_WINDOW = 500;

    protected $signature = 'erp:vehicle-service-due {--date= : Pretend today is this date (Y-m-d)}';

    protected $description = 'Remind drivers and department heads of vehicle services that are due.';

    public static function milestone(int $daysLeft): ?string
    {
        if ($daysLeft >= 0) {
            foreach (array_reverse(self::MILESTONES) as $m) {
                if ($m >= $daysLeft && $m - $daysLeft <= 2) {
                    return 'd'.$m;
                }
            }

            return null;
        }
        $overdue = -$daysLeft;
        $k = 1 + 7 * intdiv($overdue - 1, 7);

        return $overdue - $k <= 2 ? 'o'.$k : null;
    }

    public function handle(): int
    {
        $sent = 0;
        Organization::query()->where('is_active', true)->each(function (Organization $organization) use (&$sent) {
            $today = $this->option('date') ? CarbonImmutable::parse($this->option('date')) : AttendanceRules::for($organization)->now()->startOfDay();
            Vehicle::withoutGlobalScopes()->where('organization_id', $organization->id)->where('is_active', true)->each(function (Vehicle $vehicle) use ($today, &$sent) {
                $last = VehicleService::query()->where('vehicle_id', $vehicle->id)->orderByDesc('service_date')->orderByDesc('id')->first();
                if ($last === null) {
                    return;
                }
                $milestones = [];
                if ($last->next_due_date) {
                    $days = (int) round($today->diffInDays(CarbonImmutable::parse($last->next_due_date->toDateString())));
                    if ($m = self::milestone($days)) {
                        $milestones[$m] = ['days' => $days];
                    }
                }
                if ((float) $last->next_due_km > 0 && (float) $vehicle->current_odometer >= (float) $last->next_due_km - self::KM_WINDOW) {
                    $milestones['km'] = ['km' => (float) $last->next_due_km];
                }
                foreach ($milestones as $milestone => $info) {
                    try {
                        DB::table('vehicle_service_reminders')->insert(['vehicle_service_id' => $last->id, 'milestone' => $milestone, 'created_at' => now()]);
                    } catch (UniqueConstraintViolationException) {
                        continue;
                    }
                    $head = Department::withoutGlobalScopes()->whereKey($vehicle->department_id)->value('head_user_id');
                    $recipients = array_values(array_unique(array_filter([$vehicle->driver_user_id, $head]) ?: [$vehicle->created_by]));
                    $data = ['vehicle' => $vehicle->vehicle_number, 'service' => mb_substr($last->type, 0, 80)];
                    $type = match (true) {
                        $milestone === 'km' => 'vehicle.service_km',
                        $info['days'] < 0 => 'vehicle.service_overdue',
                        default => 'vehicle.service_due',
                    };
                    $data += $milestone === 'km' ? ['km' => number_format($info['km'])] : ['days' => abs($info['days']), 'due_date' => $last->next_due_date->toDateString()];
                    foreach ($recipients as $userId) {
                        Notifier::user($userId, $vehicle->organization_id, $type, $data, "/vehicles/{$vehicle->id}");
                    }
                    DB::table('vehicle_service_reminders')->where(['vehicle_service_id' => $last->id, 'milestone' => $milestone])->update(['recipients' => count($recipients)]);
                    $sent += count($recipients);
                }
            });
        });
        $this->info("Vehicle service reminders checked ({$sent}).");

        return self::SUCCESS;
    }
}
