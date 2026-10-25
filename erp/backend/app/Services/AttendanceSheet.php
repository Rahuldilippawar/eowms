<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Day-by-day attendance for many employees over a range, with the codes the
 * old app's monthly grid used: P present, L late, H half day, A absent,
 * LV leave, WO weekly off, HO holiday, "-" working day with no record,
 * null for days still to come. Used by the grid, certificate and salary report.
 */
final class AttendanceSheet
{
    /**
     * @param  Collection<int, Employee>  $employees  (shift relation loaded or loadable)
     * @return array<int, array{days: array<string, string|null>, totals: array<string, float|int>}>
     */
    public function build(Collection $employees, string $from, string $to, string $today): array
    {
        if ($employees->isEmpty()) {
            return [];
        }
        $employees = EloquentCollection::make($employees->all())->loadMissing('shift');
        $ids = $employees->pluck('id')->all();
        $records = AttendanceRecord::withoutGlobalScopes()->whereIn('employee_id', $ids)->whereBetween('work_date', [$from, $to])->get()
            ->groupBy('employee_id')->map(fn ($g) => $g->keyBy(fn ($r) => $r->work_date->toDateString()));
        $orgId = $employees->first()->organization_id;
        $holidays = Holiday::withoutGlobalScopes()->where('organization_id', $orgId)->whereBetween('holiday_date', [$from, $to])->get();
        $unpaid = LeaveRequest::withoutGlobalScopes()->whereIn('employee_id', $ids)->where('status', 'approved')
            ->whereHas('leaveType', fn ($q) => $q->where('is_paid', false))
            ->where('start_date', '<=', $to)->where('end_date', '>=', $from)->get()->groupBy('employee_id');

        $result = [];
        foreach ($employees as $e) {
            $rules = AttendanceRules::forEmployee($e);
            $hol = $holidays->filter(fn ($h) => $h->institution_id === null || $h->institution_id === $e->institution_id)->keyBy(fn ($h) => $h->holiday_date->toDateString());
            $unpaidDays = [];
            foreach ($unpaid->get($e->id, collect()) as $r) {
                foreach (CarbonPeriod::create(max($r->start_date->toDateString(), $from), min($r->end_date->toDateString(), $to)) as $d) {
                    $unpaidDays[$d->toDateString()] = true;
                }
            }
            $days = [];
            $t = ['present' => 0, 'late' => 0, 'half_day' => 0, 'absent' => 0, 'leave' => 0, 'unpaid_leave' => 0, 'weekly_off' => 0, 'holiday' => 0, 'unmarked' => 0, 'working_days' => 0];
            foreach (CarbonPeriod::create($from, $to) as $d) {
                $date = $d->toDateString();
                $record = $records->get($e->id)?->get($date);
                $off = $rules->isWeeklyOff($date);
                $isHoliday = $hol->has($date);
                if (! $off && ! $isHoliday) {
                    $t['working_days']++;
                }
                $code = match (true) {
                    $record !== null => match ($record->status->value) {
                        'present' => 'P', 'late' => 'L', 'half_day' => 'H', 'absent' => 'A', default => 'LV',
                    },
                    $isHoliday => 'HO',
                    $off => 'WO',
                    $date > $today => null,
                    default => '-',
                };
                match ($code) {
                    'P' => $t['present']++,
                    'L' => [$t['present']++, $t['late']++],
                    'H' => $t['half_day']++,
                    'A' => $t['absent']++,
                    'LV' => isset($unpaidDays[$date]) ? $t['unpaid_leave']++ : $t['leave']++,
                    'HO' => $t['holiday']++,
                    'WO' => $t['weekly_off']++,
                    '-' => $t['unmarked']++,
                    default => null,
                };
                $days[$date] = $code;
            }
            $result[$e->id] = ['days' => $days, 'totals' => $t];
        }

        return $result;
    }
}
