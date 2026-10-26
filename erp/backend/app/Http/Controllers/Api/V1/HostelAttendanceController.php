<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Hostel;
use App\Models\HostelAttendance;
use App\Models\HostelResident;
use App\Services\AttendanceRules;
use App\Services\Hostels\HostelAccess;
use App\Support\Access\AccessContext;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The three daily roll calls (morning, afternoon, evening). A resident out on
 * an out-pass that day is suggested as "on leave"; saving again overwrites
 * the session. Past days can be corrected for 7 days, the future never.
 */
class HostelAttendanceController extends Controller
{
    public const EDIT_DAYS = 7;

    public function __construct(private readonly HostelAccess $access) {}

    public function sheet(Request $request, Hostel $hostel): JsonResponse
    {
        $hostel = $this->access->cared($hostel->id);
        [$date, $session] = $this->slot($request);
        $marked = HostelAttendance::query()->where('hostel_id', $hostel->id)->where('attendance_date', $date)->where('session', $session)->get()->keyBy('resident_id');
        $residents = $this->residents($hostel, $date);
        $out = DB::table('hostel_leaves')->whereIn('resident_id', $residents->pluck('id')->all() ?: [0])->where('leave_date', '<=', $date)
            ->where(fn ($w) => $w->whereNull('actual_return_date')->orWhere('actual_return_date', '>', $date))
            ->pluck('resident_id')->flip();

        return response()->json(['data' => [
            'date' => $date, 'session' => $session, 'marked' => $marked->isNotEmpty(),
            'residents' => $residents->map(fn (HostelResident $r) => [
                'id' => $r->id, 'name' => $r->name, 'room' => $r->allocations->first()?->room?->room_number,
                'status' => $marked[$r->id]->status ?? (isset($out[$r->id]) ? 'on_leave' : 'present'),
                'remark' => $marked[$r->id]->remark ?? null, 'on_out_pass' => isset($out[$r->id]),
            ])->values(),
            'sessions_marked' => HostelAttendance::query()->where('hostel_id', $hostel->id)->where('attendance_date', $date)->distinct()->pluck('session')->values(),
        ]]);
    }

    public function save(Request $request, Hostel $hostel): JsonResponse
    {
        $hostel = $this->access->cared($hostel->id);
        [$date, $session] = $this->slot($request);
        $data = $request->validate([
            'entries' => ['required', 'array', 'max:2000'],
            'entries.*.resident_id' => ['required', 'integer', 'distinct'],
            'entries.*.status' => ['required', Rule::in(['present', 'absent', 'on_leave'])],
            'entries.*.remark' => ['nullable', 'string', 'max:255'],
        ]);
        $valid = $this->residents($hostel, $date)->pluck('id')->flip();
        foreach ($data['entries'] as $i => $e) {
            if (! isset($valid[$e['resident_id']])) {
                throw ValidationException::withMessages(["entries.{$i}.resident_id" => [__('hostels.not_resident')]]);
            }
        }
        $userId = $request->user()->id;
        DB::transaction(function () use ($hostel, $date, $session, $data, $userId) {
            foreach ($data['entries'] as $e) {
                HostelAttendance::query()->updateOrCreate(
                    ['resident_id' => $e['resident_id'], 'attendance_date' => $date, 'session' => $session],
                    ['hostel_id' => $hostel->id, 'status' => $e['status'], 'remark' => $e['remark'] ?? null, 'marked_by' => $userId],
                );
            }
            AuditLogger::record('hostels', 'attendance', $hostel, null, ['date' => $date, 'session' => $session, 'rows' => count($data['entries'])]);
        });

        return $this->sheet($request, $hostel);
    }

    /** @return array{0: string, 1: string} */
    private function slot(Request $request): array
    {
        $today = AttendanceRules::for(AccessContext::current()->organization())->now();
        $data = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today->toDateString(), 'after_or_equal:'.$today->subDays(self::EDIT_DAYS)->toDateString()],
            'session' => ['required', Rule::in(Hostel::SESSIONS)],
        ]);

        return [$data['date'] ?? $today->toDateString(), $data['session']];
    }

    /** Active residents admitted by that date, with their current bed. */
    private function residents(Hostel $hostel, string $date)
    {
        return $hostel->residents()->where('is_active', true)
            ->where(fn ($w) => $w->whereNull('admission_date')->orWhere('admission_date', '<=', $date))
            ->with(['allocations' => fn ($q) => $q->whereNull('vacated_on')->with('room:id,room_number')])
            ->orderBy('name')->get();
    }
}
