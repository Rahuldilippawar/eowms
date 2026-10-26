<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PermissionScope;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Letter;
use App\Models\LetterInwardNumber;
use App\Services\AttendanceRules;
use App\Support\Access\AccessContext;
use App\Support\Access\ResolvedScope;
use App\Support\Audit\AuditLogger;
use App\Support\Export\Csv;
use App\Support\Numbering\NumberSequence;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Outward and inward registers per financial year, for the departments in
 * the caller's `letters.view` scope (CSV needs `letters.export`).
 */
class LetterRegisterController extends Controller
{
    public function __invoke(Request $request): JsonResponse|StreamedResponse
    {
        $context = AccessContext::current();
        [$currentFy] = NumberSequence::financialYear(AttendanceRules::for($context->organization())->now());
        $f = $request->validate([
            'type' => ['required', Rule::in(['outward', 'inward'])],
            'fy' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'department_id' => ['nullable', 'integer'],
            'format' => ['nullable', Rule::in(['csv'])],
        ]);
        $fy = (int) ($f['fy'] ?? $currentFy);
        $csv = ($f['format'] ?? null) === 'csv';
        abort_if($csv && ! $context->can('letters.export'), 403, __('auth.forbidden'));
        $departments = $this->departmentIds($context->scope('letters.view'));
        if (! empty($f['department_id'])) {
            $departments = $departments === null ? [(int) $f['department_id']] : array_values(array_intersect($departments, [(int) $f['department_id']]));
        }
        // The financial year in the organization's own timezone.
        $timezone = AttendanceRules::for($context->organization())->now()->getTimezone();
        $from = CarbonImmutable::parse("{$fy}-04-01 00:00:00", $timezone)->utc();
        $to = CarbonImmutable::parse(($fy + 1).'-04-01 00:00:00', $timezone)->utc()->subSecond();

        if ($f['type'] === 'outward') {
            $rows = Letter::query()->with(['department', 'creator', 'movements' => fn ($q) => $q->where('type', 'submitted')->with('toUser')])
                ->whereNotNull('outward_no')->whereBetween('submitted_at', [$from, $to])
                ->when($departments !== null, fn ($q) => $q->whereIn('department_id', $departments ?: [0]))
                ->orderBy('submitted_at')->orderBy('id')->limit(20000)->get()
                ->map(fn (Letter $l) => [
                    'number' => $l->outward_no, 'date' => $l->submitted_at?->toIso8601String(), 'file_number' => $l->file_number, 'letter_id' => $l->id,
                    'subject' => $l->subject, 'reference_no' => $l->reference_no, 'department' => $l->department?->name,
                    'party' => $l->movements->first()?->toUser?->name, 'created_by' => $l->creator?->name, 'priority' => $l->priority, 'status' => $l->status,
                    'dispatch_mode' => $l->dispatch_mode, 'dispatch_tracking_no' => $l->dispatch_tracking_no, 'dispatch_date' => $l->dispatch_date?->toDateString(),
                ]);
        } else {
            $rows = LetterInwardNumber::query()->with(['department', 'letter.department', 'letter.creator'])
                ->where('fy_start_year', $fy)
                ->whereHas('letter')
                ->when($departments !== null, fn ($q) => $q->whereIn('department_id', $departments ?: [0]))
                ->orderBy('created_at')->orderBy('id')->limit(20000)->get()
                ->map(fn (LetterInwardNumber $n) => [
                    'number' => $n->inward_no, 'date' => $n->created_at?->toIso8601String(), 'file_number' => $n->letter->file_number, 'letter_id' => $n->letter_id,
                    'subject' => $n->letter->subject, 'reference_no' => $n->letter->reference_no, 'department' => $n->department?->name,
                    'party' => trim(($n->letter->creator?->name ?? '').($n->letter->department ? ' ('.$n->letter->department->name.')' : '')),
                    'created_by' => $n->letter->creator?->name, 'priority' => $n->letter->priority, 'status' => $n->letter->status,
                    'dispatch_mode' => null, 'dispatch_tracking_no' => null, 'dispatch_date' => null,
                ]);
        }

        if ($csv) {
            AuditLogger::record('letters', 'register_export', null, null, ['type' => $f['type'], 'fy' => $fy, 'rows' => $rows->count()]);
            $label = sprintf('%02d-%02d', $fy % 100, ($fy + 1) % 100);
            $columns = ['number', 'date', 'file_number', 'subject', 'reference_no', 'department', $f['type'] === 'outward' ? 'sent_to' : 'from', 'priority', 'status'];
            if ($f['type'] === 'outward') {
                array_push($columns, 'dispatch_mode', 'dispatch_tracking_no', 'dispatch_date');
            }

            return Csv::download("{$f['type']}-register-{$label}.csv", $columns, $rows->map(fn ($r) => [
                $r['number'], $r['date'] ? substr($r['date'], 0, 10) : null, $r['file_number'], $r['subject'], $r['reference_no'], $r['department'], $r['party'],
                $r['priority'], $r['status'], ...($f['type'] === 'outward' ? [$r['dispatch_mode'], $r['dispatch_tracking_no'], $r['dispatch_date']] : []),
            ]));
        }

        return response()->json(['data' => $rows->values(), 'meta' => ['fy' => $fy, 'current_fy' => $currentFy]]);
    }

    /** @return list<int>|null null = every department of the organization */
    private function departmentIds(?ResolvedScope $scope): ?array
    {
        return match ($scope?->scope) {
            PermissionScope::Organization => null,
            PermissionScope::Institutions => Department::query()->whereIn('institution_id', $scope->institutionIds ?: [0])->pluck('id')->all(),
            PermissionScope::Departments => $scope->departmentIds,
            default => [],
        };
    }
}
