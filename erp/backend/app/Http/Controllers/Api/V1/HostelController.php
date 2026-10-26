<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Hostel;
use App\Models\HostelAllocation;
use App\Models\HostelFee;
use App\Models\HostelFeePayment;
use App\Models\HostelRoom;
use App\Models\HostelStaff;
use App\Services\AttendanceRules;
use App\Services\Hostels\HostelAccess;
use App\Support\Access\AccessContext;
use App\Support\Access\ScopeDepartments;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Hostels, their rooms and staff, and the per-hostel dashboard (the old hostels/ pages). */
class HostelController extends Controller
{
    public function __construct(private readonly HostelAccess $access) {}

    public function index(): JsonResponse
    {
        $today = $this->today();
        $hostels = $this->access->visible()->with('department:id,name')
            ->withCount(['residents as residents_count' => fn ($q) => $q->where('is_active', true)])
            ->withSum(['rooms as beds' => fn ($q) => $q->where('is_active', true)], 'capacity')
            ->orderBy('name')->get();
        $out = DB::table('hostel_leaves as l')->join('hostel_residents as r', 'r.id', '=', 'l.resident_id')
            ->whereIn('r.hostel_id', $hostels->pluck('id')->all() ?: [0])->whereNull('l.actual_return_date')
            ->groupBy('r.hostel_id')->selectRaw('r.hostel_id, count(*) as total, sum(case when l.expected_return_date < ? then 1 else 0 end) as overdue', [$today])
            ->get()->keyBy('hostel_id');

        return response()->json([
            'data' => $hostels->map(fn (Hostel $h) => $this->present($h) + [
                'residents_count' => $h->residents_count, 'beds' => (int) $h->beds,
                'out_now' => (int) ($out[$h->id]->total ?? 0), 'overdue_returns' => (int) ($out[$h->id]->overdue ?? 0),
            ])->values(),
            'meta' => ['can_create' => AccessContext::current()->can('hostels.manage')],
        ]);
    }

    /** The hostel dashboard: occupancy, today's roll calls, who is out, fees due. */
    public function show(Hostel $hostel): JsonResponse
    {
        $hostel = $this->access->hostel($hostel->id)->load(['department:id,name', 'staff.user:id,name']);
        $today = $this->today();
        $residentIds = $hostel->residents()->where('is_active', true)->pluck('id');
        $occupied = HostelAllocation::query()->whereIn('room_id', $hostel->rooms()->select('id'))->whereNull('vacated_on')->count();
        $sessions = DB::table('hostel_attendance')->where('hostel_id', $hostel->id)->where('attendance_date', $today)
            ->groupBy('session')->selectRaw("session, sum(case when status = 'present' then 1 else 0 end) as present,
                sum(case when status = 'absent' then 1 else 0 end) as absent, sum(case when status = 'on_leave' then 1 else 0 end) as on_leave")
            ->get()->keyBy('session');
        $fees = HostelFee::query()->whereIn('resident_id', $residentIds->all() ?: [0]);
        $due = (float) (clone $fees)->sum('amount_due') - (float) HostelFeePayment::query()->whereIn('fee_id', (clone $fees)->select('id'))->sum('amount');
        $out = DB::table('hostel_leaves')->whereIn('resident_id', $residentIds->all() ?: [0])->whereNull('actual_return_date');

        return response()->json(['data' => $this->present($hostel) + [
            'staff' => $hostel->staff->map(fn (HostelStaff $s) => ['user_id' => $s->user_id, 'name' => $s->user?->name, 'role' => $s->role])->values(),
            'rooms' => $hostel->rooms()->withCount(['allocations as occupied' => fn ($q) => $q->whereNull('vacated_on')])->get()
                ->map(fn (HostelRoom $r) => ['id' => $r->id, 'room_number' => $r->room_number, 'capacity' => $r->capacity, 'occupied' => $r->occupied, 'is_active' => $r->is_active])->values(),
            'stats' => [
                'residents' => $residentIds->count(),
                'beds' => (int) $hostel->rooms()->where('is_active', true)->sum('capacity'),
                'occupied_beds' => $occupied,
                'out_now' => (clone $out)->count(),
                'overdue_returns' => (clone $out)->where('expected_return_date', '<', $today)->count(),
                'fees_due' => round(max(0, (float) $due), 2),
                'today' => collect(Hostel::SESSIONS)->mapWithKeys(fn ($s) => [$s => isset($sessions[$s]) ? [
                    'present' => (int) $sessions[$s]->present, 'absent' => (int) $sessions[$s]->absent, 'on_leave' => (int) $sessions[$s]->on_leave,
                ] : null]),
            ],
            'can_manage' => $this->access->canManage($hostel),
            'can_care' => $this->access->canCare($hostel),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $hostel = Hostel::create($this->validated($request) + ['created_by' => $request->user()->id]);
        AuditLogger::record('hostels', 'create', $hostel, null, AuditLogger::snapshot($hostel));

        return $this->show($hostel)->setStatusCode(201);
    }

    public function update(Request $request, Hostel $hostel): JsonResponse
    {
        $hostel = $this->access->managed($hostel->id);
        $before = $hostel->getAttributes();
        $hostel->fill($this->validated($request, $hostel))->save();
        AuditLogger::recordChanges('hostels', 'update', $hostel, $before);

        return $this->show($hostel);
    }

    /** Wardens and other staff: the full list replaces the old one. */
    public function setStaff(Request $request, Hostel $hostel): JsonResponse
    {
        $hostel = $this->access->managed($hostel->id);
        $data = $request->validate(['staff' => ['present', 'array', 'max:20'], 'staff.*.user_id' => ['required', 'integer', 'distinct'], 'staff.*.role' => ['nullable', 'string', 'max:30']]);
        $ids = collect($data['staff'])->pluck('user_id')->all();
        if (Employee::query()->whereIn('user_id', $ids ?: [0])->where('is_active', true)->distinct()->count('user_id') !== count($ids)) {
            throw ValidationException::withMessages(['staff' => [__('letters.choose_recipient')]]);
        }
        DB::transaction(function () use ($hostel, $data) {
            $hostel->staff()->delete();
            foreach ($data['staff'] as $s) {
                HostelStaff::create(['hostel_id' => $hostel->id, 'user_id' => $s['user_id'], 'role' => $s['role'] ?? 'warden']);
            }
            AuditLogger::record('hostels', 'staff', $hostel, null, ['staff' => array_column($data['staff'], 'user_id')]);
        });

        return $this->show($hostel);
    }

    public function saveRoom(Request $request, Hostel $hostel, ?HostelRoom $room = null): JsonResponse
    {
        $hostel = $this->access->managed($hostel->id);
        abort_if($room !== null && $room->hostel_id !== $hostel->id, 404);
        $data = $request->validate([
            'room_number' => ['required', 'string', 'max:50', Rule::unique('hostel_rooms')->where('hostel_id', $hostel->id)->ignore($room?->id)],
            'capacity' => ['required', 'integer', 'min:1', 'max:50'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if ($room !== null) {
            $occupied = $room->allocations()->whereNull('vacated_on')->count();
            if ($data['capacity'] < $occupied || (($data['is_active'] ?? true) === false && $occupied > 0)) {
                throw ValidationException::withMessages(['capacity' => [__('hostels.room_occupied', ['count' => $occupied])]]);
            }
            $room->fill($data)->save();
        } else {
            $room = HostelRoom::create($data + ['hostel_id' => $hostel->id]);
        }
        AuditLogger::record('hostels', 'room', $hostel, null, ['room' => $room->room_number, 'capacity' => $room->capacity]);

        return $this->show($hostel);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Hostel $hostel = null): array
    {
        $orgId = AccessContext::current()->organizationId();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('hostels')->where('organization_id', $orgId)->ignore($hostel?->id)],
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')->where('organization_id', $orgId)],
            'gender' => ['required', Rule::in(Hostel::GENDERS)],
            'capacity' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if (! ScopeDepartments::reaches(AccessContext::current()->scope('hostels.manage'), (int) $data['department_id'])) {
            throw ValidationException::withMessages(['department_id' => [__('access.outside_scope')]]);
        }

        return $data + ['institution_id' => Department::query()->whereKey($data['department_id'])->value('institution_id')];
    }

    /** @return array<string, mixed> */
    private function present(Hostel $h): array
    {
        return [
            'id' => $h->id, 'name' => $h->name, 'gender' => $h->gender, 'capacity' => $h->capacity, 'is_active' => $h->is_active,
            'department' => $h->department ? ['id' => $h->department->id, 'name' => $h->department->name] : null,
        ];
    }

    private function today(): string
    {
        return AttendanceRules::for(AccessContext::current()->organization())->now()->toDateString();
    }
}
