<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PermissionScope;
use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Institution;
use App\Models\Student;
use App\Models\WorkEntry;
use App\Models\WorkRequest;
use App\Services\AttendanceRules;
use App\Support\Access\AccessContext;
use App\Support\Access\Modules;
use App\Support\Audit\AuditLogger;
use App\Support\Export\Csv;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cross-module reports (erp/docs/REPORTS.md). Campus comparison: side-by-side counts for the
 * campuses within the caller's institutions.view scope (organization or campus wide: a whole campus's
 * figures are not shown for a department-wide grant); figures of switched-off modules are left out.
 */
class ReportController extends Controller
{
    public const PRESENT = ['present', 'late', 'half_day'];

    public function campuses(Request $request): JsonResponse|StreamedResponse
    {
        $ctx = AccessContext::current();
        $tz = AttendanceRules::for($ctx->organization())->timezone;
        $today = CarbonImmutable::now($tz)->toDateString();
        $f = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'format' => ['nullable', Rule::in(['csv'])],
        ]);
        $to = $f['to'] ?? $today;
        $from = max($f['from'] ?? CarbonImmutable::parse($to)->startOfMonth()->toDateString(), CarbonImmutable::parse($to)->subYear()->toDateString());
        $off = Modules::disabled($ctx->organization());
        $on = fn (string $module) => ! in_array($module, $off, true);

        $institutions = $this->visibleInstitutions()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'code', 'type']);
        $ids = $institutions->pluck('id')->all() ?: [0];
        $count = fn ($query, string $column = 'institution_id') => $query->whereIn($column, $ids)->groupBy($column)->selectRaw("{$column} as k, count(*) as n")->pluck('n', 'k');

        $metrics = [
            'departments' => $count(Department::query()->where('is_active', true)),
            'employees' => $count(Employee::query()->where('is_active', true)),
        ];
        if ($on('students')) {
            $metrics['students'] = $count(Student::query()->where('is_active', true));
        }
        if ($on('attendance')) {
            $metrics['present_today'] = $count(AttendanceRecord::query()->where('work_date', $today)->whereIn('status', self::PRESENT));
        }
        if ($on('daily_work')) {
            $metrics['work_entries'] = $count(WorkEntry::query()->whereBetween('work_date', [$from, $to]));
            $metrics['work_completed'] = $count(WorkEntry::query()->whereBetween('work_date', [$from, $to])->where('status', 'completed'));
        }
        if ($on('requests')) {
            $metrics['open_requests'] = $count(WorkRequest::query()->whereIn('status', WorkRequest::OPEN));
        }

        $rows = $institutions->map(function (Institution $i) use ($metrics) {
            $m = collect($metrics)->map(fn (Collection $c) => (int) ($c[$i->id] ?? 0))->all();
            if (isset($m['present_today'])) {
                $m['attendance_percent'] = $m['employees'] > 0 ? (int) round($m['present_today'] * 100 / $m['employees']) : null;
            }
            if (isset($m['work_entries'])) {
                $m['work_completion_percent'] = $m['work_entries'] > 0 ? (int) round($m['work_completed'] * 100 / $m['work_entries']) : null;
            }

            return ['id' => $i->id, 'name' => $i->name, 'code' => $i->code, 'type' => $i->type?->value] + $m;
        })->values();

        if (($f['format'] ?? null) === 'csv') {
            AuditLogger::record('reports', 'campus_export', null, null, ['from' => $from, 'to' => $to, 'rows' => $rows->count()]);
            $keys = array_values(array_diff(array_keys($rows->first() ?? ['id' => 0]), ['id']));

            return Csv::download("campus-comparison-{$from}-{$to}.csv", $keys, $rows->map(fn ($r) => array_map(fn ($k) => $r[$k], $keys)));
        }

        return response()->json(['data' => ['from' => $from, 'to' => $to, 'today' => $today, 'metrics' => array_keys($metrics), 'campuses' => $rows]]);
    }

    /** @return Builder<Institution> */
    private function visibleInstitutions()
    {
        $scope = AccessContext::current()->scope('institutions.view');

        return match ($scope?->scope) {
            PermissionScope::Organization => Institution::query(),
            PermissionScope::Institutions => Institution::query()->whereIn('id', $scope->institutionIds ?: [0]),
            default => Institution::query()->whereRaw('1 = 0'),
        };
    }
}
