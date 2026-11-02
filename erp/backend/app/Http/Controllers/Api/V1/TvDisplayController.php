<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PermissionScope;
use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Organization;
use App\Models\TvDisplay;
use App\Models\WorkRequest;
use App\Services\AttendanceRules;
use App\Support\Access\AccessContext;
use App\Support\Access\Modules;
use App\Support\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Notice-board screens (erp/docs/ABOUT-AND-TV.md). An organization-wide admin makes a secret link
 * for each screen; the screen shows only counts and today's birthdays, never anyone's details.
 */
class TvDisplayController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorizeManage();

        return response()->json(['data' => TvDisplay::query()->orderBy('name')->get()->map(fn (TvDisplay $d) => [
            'id' => $d->id, 'name' => $d->name, 'is_active' => $d->is_active, 'last_seen_at' => $d->last_seen_at?->toIso8601String(),
            'created_at' => $d->created_at?->toIso8601String(),
        ])->values()]);
    }

    /** The link's secret is returned only here, once. */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeManage();
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);
        $token = Str::random(40);
        $display = TvDisplay::create(['name' => $data['name'], 'token_hash' => hash('sha256', $token), 'created_by' => $request->user()->id]);
        AuditLogger::record('tv', 'create', $display, null, ['name' => $display->name]);

        return response()->json(['data' => ['id' => $display->id, 'name' => $display->name, 'url' => '/tv/'.$token]], 201);
    }

    public function destroy(TvDisplay $display): JsonResponse
    {
        $this->authorizeManage();
        $display->delete();
        AuditLogger::record('tv', 'delete', $display, ['name' => $display->name]);

        return response()->json(null, 204);
    }

    /** What the screen shows; the link is the only key (no sign-in on a wall screen). */
    public function screen(string $token): JsonResponse
    {
        $display = TvDisplay::withoutGlobalScopes()->where('token_hash', hash('sha256', $token))->where('is_active', true)->first();
        abort_if($display === null, 404);
        $display->forceFill(['last_seen_at' => now()])->saveQuietly();
        $org = Organization::query()->findOrFail($display->organization_id);
        $tz = AttendanceRules::for($org)->timezone;
        $today = CarbonImmutable::now($tz);
        $off = Modules::disabled($org);
        $on = fn (string $m) => ! in_array($m, $off, true);
        $employees = Employee::withoutGlobalScopes()->where('organization_id', $org->id)->where('is_active', true);
        $md = $today->format('m-d');

        return response()->json(['data' => [
            'organization' => $org->name,
            'now' => $today->toIso8601String(),
            'timezone' => $tz,
            'staff' => (clone $employees)->count(),
            'present_today' => $on('attendance') ? AttendanceRecord::withoutGlobalScopes()->where('organization_id', $org->id)->where('work_date', $today->toDateString())
                ->whereIn('status', ReportController::PRESENT)->count() : null,
            'on_leave_today' => $on('leave') ? LeaveRequest::withoutGlobalScopes()->where('organization_id', $org->id)->where('status', 'approved')
                ->where('start_date', '<=', $today->toDateString())->where('end_date', '>=', $today->toDateString())->count() : null,
            'open_requests' => $on('requests') ? WorkRequest::withoutGlobalScopes()->where('organization_id', $org->id)->whereIn('status', WorkRequest::OPEN)->count() : null,
            'birthdays' => (clone $employees)->whereRaw("DATE_FORMAT(date_of_birth, '%m-%d') = ?", [$md])->orderBy('name')->pluck('name')->values(),
            'anniversaries' => (clone $employees)->whereRaw("DATE_FORMAT(joining_date, '%m-%d') = ?", [$md])->whereYear('joining_date', '<', $today->year)->orderBy('name')->pluck('name')->values(),
        ]]);
    }

    private function authorizeManage(): void
    {
        abort_unless(AccessContext::current()->scope('organizations.edit')?->scope === PermissionScope::Organization, 403, __('auth.forbidden'));
    }
}
