<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Building;
use App\Models\Employee;
use App\Models\MaintenanceLocation;
use App\Models\ResidencyAllotmentRequest;
use App\Models\ResidencyBill;
use App\Models\ResidencyBillShare;
use App\Models\ResidencyGuest;
use App\Models\ResidencyInspection;
use App\Models\ResidencyOccupant;
use App\Models\ResidencyRoomItem;
use App\Models\ResidencyTransfer;
use App\Models\User;
use App\Services\AttendanceRules;
use App\Services\Notifier;
use App\Services\Residency\ResidencyDesk;
use App\Support\Access\AccessContext;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Staff housing for the people who run it (residency.view / residency.manage):
 * rooms and who lives there, room items, inspections, bills, guests,
 * allotment requests, transfers, the staff directory and occupancy figures.
 */
class ResidencyController extends Controller
{
    public function __construct(private readonly ResidencyDesk $desk) {}

    public function rooms(Request $request): JsonResponse
    {
        $f = $request->validate(['building_id' => ['nullable', 'integer'], 'search' => ['nullable', 'string', 'max:100'], 'vacant' => ['nullable', 'boolean']]);
        $rooms = MaintenanceLocation::query()->where('is_residential', true)->where('is_active', true)->with('building:id,name')
            ->withCount(['residents as occupants_count' => fn ($q) => $q->where('status', 'active')])
            ->with(['residents' => fn ($q) => $q->where('status', 'active')->where('occupant_type', 'staff')->select('id', 'location_id', 'name', 'user_id')])
            ->when($f['building_id'] ?? null, fn ($q, $id) => $q->where('building_id', $id))
            ->when($f['search'] ?? null, function ($q, $s) {
                $like = '%'.addcslashes($s, '%_\\').'%';
                $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('location_code', 'like', $like)
                    ->orWhereHas('residents', fn ($r) => $r->where('status', 'active')->where('name', 'like', $like)));
            })
            ->when($request->boolean('vacant'), fn ($q) => $q->whereDoesntHave('residents', fn ($r) => $r->where('status', 'active')))
            ->orderBy('building_id')->orderBy('floor')->orderBy('name')->limit(2000)->get();

        return response()->json([
            'data' => $rooms->map(fn (MaintenanceLocation $l) => [
                'id' => $l->id, 'name' => $l->name, 'location_code' => $l->location_code, 'floor' => $l->floor, 'capacity' => $l->capacity,
                'building' => $l->building ? ['id' => $l->building->id, 'name' => $l->building->name] : null,
                'occupants_count' => $l->occupants_count, 'staff' => $l->residents->pluck('name')->values(),
            ])->values(),
            'meta' => ['can_manage' => AccessContext::current()->can('residency.manage'), 'buildings' => Building::query()->orderBy('name')->get(['id', 'name'])],
        ]);
    }

    public function room(MaintenanceLocation $location): JsonResponse
    {
        $room = $this->desk->room($location->id)->load('building:id,name');
        $occupants = ResidencyOccupant::query()->where('location_id', $room->id)->with('user:id,name')->orderByRaw("case status when 'active' then 0 else 1 end")
            ->orderByRaw("case occupant_type when 'staff' then 0 else 1 end")->orderBy('id')->get();
        $bills = ResidencyBill::query()->where('location_id', $room->id)->with('shares.occupant:id,name')->orderByDesc('billing_month')->limit(24)->get();

        return response()->json(['data' => [
            'id' => $room->id, 'name' => $room->name, 'location_code' => $room->location_code, 'floor' => $room->floor, 'capacity' => $room->capacity,
            'building' => $room->building ? ['id' => $room->building->id, 'name' => $room->building->name] : null,
            'occupants' => $occupants->map(fn (ResidencyOccupant $o) => $this->occupant($o))->values(),
            'items' => ResidencyRoomItem::query()->where('location_id', $room->id)->orderBy('item_name')->get()
                ->map(fn (ResidencyRoomItem $i) => $i->only(['id', 'item_name', 'ownership', 'quantity', 'remarks']))->values(),
            'inspections' => ResidencyInspection::query()->where('location_id', $room->id)->with('inspector:id,name')->orderByDesc('inspection_date')->limit(20)->get()
                ->map(fn (ResidencyInspection $i) => ['id' => $i->id, 'inspection_date' => $i->inspection_date->toDateString(), 'condition_rating' => $i->condition_rating,
                    'remarks' => $i->remarks, 'inspected_by' => $i->inspector?->name])->values(),
            'bills' => $bills->map(fn (ResidencyBill $b) => $this->bill($b))->values(),
            'guests' => ResidencyGuest::query()->where('location_id', $room->id)->with('host:id,name')->orderByDesc('arrival_date')->limit(20)->get()
                ->map(fn (ResidencyGuest $g) => $this->guest($g))->values(),
            'transfers' => ResidencyTransfer::query()->where(fn ($q) => $q->where('from_location_id', $room->id)->orWhere('to_location_id', $room->id))
                ->with(['occupant:id,name', 'from:id,name', 'to:id,name'])->orderByDesc('transfer_date')->limit(20)->get()
                ->map(fn (ResidencyTransfer $t) => ['occupant' => $t->occupant?->name, 'from' => $t->from?->name, 'to' => $t->to?->name, 'transfer_date' => $t->transfer_date->toDateString(), 'reason' => $t->reason])->values(),
            'can_manage' => AccessContext::current()->can('residency.manage'),
        ]]);
    }

    /** A staff member (with an account) or a family member of a staff occupant of the same room. */
    public function addOccupant(Request $request, MaintenanceLocation $location): JsonResponse
    {
        $room = $this->desk->room($location->id);
        $data = $request->validate([
            'occupant_type' => ['required', Rule::in(['staff', 'family'])],
            'user_id' => ['nullable', 'required_if:occupant_type,staff', 'integer'],
            'family_of_id' => ['nullable', 'required_if:occupant_type,family', 'integer'],
            'name' => ['nullable', 'required_if:occupant_type,family', 'string', 'max:255'],
            'relation' => ['nullable', 'string', 'max:100'],
            'age' => ['nullable', 'integer', 'min:0', 'max:120'],
            'moved_in_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$this->today()],
        ]);
        $this->desk->assertRoomHasSpace($room, 1);
        if ($data['occupant_type'] === 'staff') {
            $employee = Employee::query()->where('user_id', $data['user_id'])->where('is_active', true)->first();
            if ($employee === null) {
                throw ValidationException::withMessages(['user_id' => [__('letters.choose_recipient')]]);
            }
            $this->desk->assertNotHousedElsewhere((int) $data['user_id']);
            $attributes = ['occupant_type' => 'staff', 'user_id' => $data['user_id'], 'name' => $employee->name, 'moved_in_date' => $data['moved_in_date'] ?? null];
        } else {
            $head = ResidencyOccupant::query()->where('location_id', $room->id)->where('occupant_type', 'staff')->where('status', 'active')->find($data['family_of_id']);
            if ($head === null) {
                throw ValidationException::withMessages(['family_of_id' => [__('residency.family_needs_staff')]]);
            }
            $attributes = ['occupant_type' => 'family', 'family_of_id' => $head->id] + array_intersect_key($data, array_flip(['name', 'relation', 'age', 'moved_in_date']));
        }
        $occupant = $this->desk->moveIn($room, $attributes, $request->user()->id);
        AuditLogger::record('residency', 'move_in', $occupant, null, ['room' => $room->id, 'type' => $occupant->occupant_type]);
        Notifier::user($occupant->user_id, $room->organization_id, 'residency.allotted', ['room' => $room->name], '/my/residency');

        return $this->room($room);
    }

    public function vacate(Request $request, ResidencyOccupant $occupant): JsonResponse
    {
        $o = $this->active($occupant);
        $data = $request->validate(['vacated_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$this->today()], 'remark' => ['nullable', 'string', 'max:1000']]);
        $this->desk->vacate($o, $data['vacated_date'] ?? $this->today(), $data['remark'] ?? null, $request->user()->id);
        AuditLogger::record('residency', 'vacate', $o, null, ['room' => $o->location_id]);

        return $this->room(MaintenanceLocation::query()->findOrFail($o->location_id));
    }

    public function transfer(Request $request, ResidencyOccupant $occupant): JsonResponse
    {
        $o = $this->active($occupant);
        if ($o->occupant_type !== 'staff') {
            throw ValidationException::withMessages(['occupant' => [__('residency.transfer_staff_only')]]);
        }
        $data = $request->validate([
            'to_location_id' => ['required', 'integer'],
            'transfer_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$this->today()],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        $to = $this->desk->room((int) $data['to_location_id']);
        $this->desk->transfer($o, $to, $data['transfer_date'] ?? $this->today(), $data['reason'] ?? null, $request->user()->id);
        AuditLogger::record('residency', 'transfer', $o, null, ['to' => $to->id]);
        Notifier::user($o->user_id, $to->organization_id, 'residency.transferred', ['room' => $to->name], '/my/residency');

        return $this->room($to);
    }

    /** Linking an imported staff occupant to their account (the old link-unlinked page). */
    public function link(Request $request, ResidencyOccupant $occupant): JsonResponse
    {
        $o = $this->active($occupant);
        $data = $request->validate(['user_id' => ['required', 'integer']]);
        if ($o->occupant_type !== 'staff' || ! Employee::query()->where('user_id', $data['user_id'])->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['user_id' => [__('letters.choose_recipient')]]);
        }
        $this->desk->assertNotHousedElsewhere((int) $data['user_id'], $o->id);
        $o->forceFill(['user_id' => $data['user_id']])->save();
        AuditLogger::record('residency', 'link', $o, null, ['user' => $data['user_id']]);

        return response()->json(['data' => $this->occupant($o->load('user:id,name'))]);
    }

    public function unlinked(): JsonResponse
    {
        return response()->json(['data' => ResidencyOccupant::query()->where('occupant_type', 'staff')->where('status', 'active')->whereNull('user_id')
            ->with('location.building:id,name')->orderBy('name')->get()
            ->map(fn (ResidencyOccupant $o) => $this->occupant($o) + ['room' => $o->location?->name, 'building' => $o->location?->building?->name])->values()]);
    }

    public function addItem(Request $request, MaintenanceLocation $location): JsonResponse
    {
        $room = $this->desk->room($location->id);
        $data = $request->validate([
            'item_name' => ['required', 'string', 'max:255'],
            'ownership' => ['required', Rule::in(['institution', 'personal'])],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000'],
            'remarks' => ['nullable', 'string', 'max:500'],
        ]);
        ResidencyRoomItem::create($data + ['location_id' => $room->id, 'added_by' => $request->user()->id]);

        return $this->room($room);
    }

    public function removeItem(ResidencyRoomItem $item): JsonResponse
    {
        $room = $this->desk->room($item->location_id);
        $item->delete();

        return $this->room($room);
    }

    public function inspect(Request $request, MaintenanceLocation $location): JsonResponse
    {
        $room = $this->desk->room($location->id);
        $data = $request->validate([
            'inspection_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$this->today()],
            'condition_rating' => ['required', Rule::in(['excellent', 'good', 'fair', 'poor'])],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);
        $inspection = ResidencyInspection::create($data + ['location_id' => $room->id, 'inspected_by' => $request->user()->id]);
        AuditLogger::record('residency', 'inspection', $inspection);

        return $this->room($room);
    }

    public function createBill(Request $request, MaintenanceLocation $location): JsonResponse
    {
        $room = $this->desk->room($location->id);
        $data = $request->validate([
            'billing_month' => ['required', 'date_format:Y-m'],
            'room_rent' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'electricity_bill' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'water_bill' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
        ]);
        $bill = $this->desk->bill($room, $data['billing_month'], (float) ($data['room_rent'] ?? 0), (float) ($data['electricity_bill'] ?? 0), (float) ($data['water_bill'] ?? 0), $request->user()->id);
        AuditLogger::record('residency', 'bill', $bill, null, ['month' => $bill->billing_month, 'total' => $bill->total_amount]);

        return $this->room($room);
    }

    public function markPaid(Request $request, ResidencyBillShare $share): JsonResponse
    {
        $bill = ResidencyBill::query()->findOrFail($share->bill_id);
        $paid = $request->validate(['paid' => ['required', 'boolean']])['paid'];
        $share->forceFill(['paid_at' => $paid ? now() : null, 'marked_paid_by' => $paid ? $request->user()->id : null])->save();
        AuditLogger::record('residency', $paid ? 'share_paid' : 'share_unpaid', $bill, null, ['share' => $share->id]);

        return response()->json(['data' => $this->bill($bill->load('shares.occupant:id,name'))]);
    }

    /** Bills of a month across rooms: what was billed and what is still pending. */
    public function billsReport(Request $request): JsonResponse
    {
        $month = $request->validate(['month' => ['nullable', 'date_format:Y-m']])['month'] ?? AttendanceRules::for(AccessContext::current()->organization())->now()->format('Y-m');
        $bills = ResidencyBill::query()->where('billing_month', $month)->with(['location.building:id,name', 'shares.occupant:id,name'])->get();

        return response()->json(['data' => [
            'month' => $month,
            'total' => round((float) $bills->sum('total_amount'), 2),
            'pending' => round((float) $bills->flatMap->shares->whereNull('paid_at')->sum('amount_due'), 2),
            'bills' => $bills->map(fn (ResidencyBill $b) => $this->bill($b) + ['room' => $b->location?->name, 'building' => $b->location?->building?->name])->values(),
        ]]);
    }

    public function addGuest(Request $request, MaintenanceLocation $location): JsonResponse
    {
        $room = $this->desk->room($location->id);
        $data = $request->validate([
            'guest_name' => ['required', 'string', 'max:255'],
            'guest_contact' => ['nullable', 'string', 'max:50'],
            'relation' => ['nullable', 'string', 'max:100'],
            'purpose' => ['nullable', 'string', 'max:255'],
            'host_occupant_id' => ['nullable', 'integer', Rule::exists('residency_occupants', 'id')->where('location_id', $room->id)->where('status', 'active')],
            'arrival_date' => ['required', 'date_format:Y-m-d'],
            'expected_departure_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:arrival_date'],
        ]);
        ResidencyGuest::create($data + ['location_id' => $room->id, 'created_by' => $request->user()->id]);

        return $this->room($room);
    }

    public function guestLeft(Request $request, ResidencyGuest $guest): JsonResponse
    {
        $data = $request->validate(['actual_departure_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.$guest->arrival_date->toDateString()]]);
        $guest->forceFill(['actual_departure_date' => $data['actual_departure_date'] ?? $this->today()])->save();

        return $this->room(MaintenanceLocation::query()->findOrFail($guest->location_id));
    }

    public function requests(Request $request): JsonResponse
    {
        $status = $request->validate(['status' => ['nullable', Rule::in(['open', 'approved', 'rejected', 'cancelled', 'all'])]])['status'] ?? 'open';

        return response()->json(['data' => ResidencyAllotmentRequest::query()->with(['requester:id,name', 'building:id,name', 'location:id,name', 'decider:id,name'])
            ->when($status === 'open', fn ($q) => $q->whereIn('status', ['pending', 'waitlisted']))
            ->when(! in_array($status, ['open', 'all'], true), fn ($q) => $q->where('status', $status))
            ->orderByRaw("case status when 'pending' then 0 when 'waitlisted' then 1 else 2 end")->orderBy('id')->limit(500)->get()
            ->map(fn (ResidencyAllotmentRequest $r) => self::request($r))->values()]);
    }

    /** Approve (allotting a room, which moves the person in), waitlist or reject. */
    public function decide(Request $request, ResidencyAllotmentRequest $allotment): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'waitlist', 'reject'])],
            'location_id' => ['nullable', 'required_if:decision,approve', 'integer'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);
        if (! in_array($allotment->status, ['pending', 'waitlisted'], true)) {
            throw ValidationException::withMessages(['decision' => [__('residency.request_closed')]]);
        }
        DB::transaction(function () use ($allotment, $data, $request) {
            $room = null;
            if ($data['decision'] === 'approve') {
                $room = $this->desk->room((int) $data['location_id']);
                $this->desk->assertNotHousedElsewhere($allotment->requested_by);
                $this->desk->assertRoomHasSpace($room, 1);
                $name = Employee::query()->where('user_id', $allotment->requested_by)->value('name') ?? User::query()->whereKey($allotment->requested_by)->value('name');
                $this->desk->moveIn($room, ['occupant_type' => 'staff', 'user_id' => $allotment->requested_by, 'name' => $name], $request->user()->id);
            }
            $allotment->forceFill([
                'status' => ['approve' => 'approved', 'waitlist' => 'waitlisted', 'reject' => 'rejected'][$data['decision']],
                'allotted_location_id' => $room?->id, 'decided_by' => $request->user()->id, 'decided_at' => now(), 'remarks' => $data['remarks'] ?? $allotment->remarks,
            ])->save();
            AuditLogger::record('residency', 'request_'.$allotment->status, $allotment);
            Notifier::user($allotment->requested_by, $allotment->organization_id, 'residency.request_'.$allotment->status, ['room' => $room?->name ?? ''], '/my/residency');
        });

        return response()->json(['data' => self::request($allotment->fresh(['requester:id,name', 'building:id,name', 'location:id,name', 'decider:id,name']))]);
    }

    /** Staff who live on campus and those who do not (the old staff directory). */
    public function directory(): JsonResponse
    {
        $housed = ResidencyOccupant::query()->where('occupant_type', 'staff')->where('status', 'active')->whereNotNull('user_id')
            ->with('location.building:id,name')->withCount(['family as family_count' => fn ($q) => $q->where('status', 'active')])->get()->keyBy('user_id');
        $employees = Employee::query()->where('is_active', true)->whereNotNull('user_id')->with('department:id,name')->orderBy('name')->get(['id', 'user_id', 'name', 'designation', 'phone', 'department_id']);

        $row = fn (Employee $e) => ['user_id' => $e->user_id, 'name' => $e->name, 'designation' => $e->designation, 'phone' => $e->phone, 'department' => $e->department?->name];

        return response()->json(['data' => [
            'resident' => $employees->filter(fn ($e) => isset($housed[$e->user_id]))->map(fn (Employee $e) => $row($e) + [
                'room' => $housed[$e->user_id]->location?->name, 'building' => $housed[$e->user_id]->location?->building?->name,
                'moved_in_date' => $housed[$e->user_id]->moved_in_date?->toDateString(), 'family_count' => $housed[$e->user_id]->family_count,
            ])->values(),
            'non_resident' => $employees->reject(fn ($e) => isset($housed[$e->user_id]))->map($row)->values(),
            'unlinked' => ResidencyOccupant::query()->where('occupant_type', 'staff')->where('status', 'active')->whereNull('user_id')->count(),
        ]]);
    }

    public function analytics(): JsonResponse
    {
        $rooms = MaintenanceLocation::query()->where('is_residential', true)->where('is_active', true);
        $occupied = (clone $rooms)->whereHas('residents', fn ($q) => $q->where('status', 'active'));
        $total = (clone $rooms)->count();
        $tenure = ResidencyOccupant::query()->where('status', 'vacated')->whereNotNull('moved_in_date')->whereNotNull('vacated_date')->get(['moved_in_date', 'vacated_date'])
            ->avg(fn (ResidencyOccupant $o) => $o->moved_in_date->diffInDays($o->vacated_date));
        $byBuilding = Building::query()->orderBy('name')->get()->map(fn (Building $b) => [
            'id' => $b->id, 'name' => $b->name,
            'rooms' => (clone $rooms)->where('building_id', $b->id)->count(),
            'occupied' => (clone $occupied)->where('building_id', $b->id)->count(),
        ])->filter(fn ($b) => $b['rooms'] > 0)->values();

        return response()->json(['data' => [
            'rooms' => $total,
            'occupied_rooms' => $occupied->count(),
            'occupancy_rate' => $total > 0 ? (int) round($occupied->count() / $total * 100) : 0,
            'occupants' => ResidencyOccupant::query()->where('status', 'active')->count(),
            'staff_housed' => ResidencyOccupant::query()->where('status', 'active')->where('occupant_type', 'staff')->count(),
            'avg_tenure_days' => $tenure === null ? null : (int) round($tenure),
            'waiting' => ResidencyAllotmentRequest::query()->whereIn('status', ['pending', 'waitlisted'])->count(),
            'guests_now' => ResidencyGuest::query()->whereNull('actual_departure_date')->count(),
            'by_building' => $byBuilding,
        ]]);
    }

    private function active(ResidencyOccupant $occupant): ResidencyOccupant
    {
        if ($occupant->status !== 'active') {
            throw ValidationException::withMessages(['occupant' => [__('residency.not_living_here')]]);
        }

        return $occupant;
    }

    /** @return array<string, mixed> */
    public static function request(ResidencyAllotmentRequest $r): array
    {
        return [
            'id' => $r->id, 'requested_by' => $r->requester?->name, 'requested_by_id' => $r->requested_by, 'preferred_building' => $r->building?->name, 'family_size' => $r->family_size,
            'reason' => $r->reason, 'status' => $r->status, 'allotted_room' => $r->location?->name, 'decided_by' => $r->decider?->name,
            'decided_at' => $r->decided_at?->toIso8601String(), 'remarks' => $r->remarks, 'created_at' => $r->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function occupant(ResidencyOccupant $o): array
    {
        return [
            'id' => $o->id, 'occupant_type' => $o->occupant_type, 'name' => $o->name, 'user' => $o->user ? ['id' => $o->user->id, 'name' => $o->user->name] : null,
            'family_of_id' => $o->family_of_id, 'relation' => $o->relation, 'age' => $o->age, 'moved_in_date' => $o->moved_in_date?->toDateString(),
            'status' => $o->status, 'vacated_date' => $o->vacated_date?->toDateString(), 'vacated_remark' => $o->vacated_remark,
        ];
    }

    /** @return array<string, mixed> */
    public static function bill(ResidencyBill $b): array
    {
        return [
            'id' => $b->id, 'billing_month' => $b->billing_month, 'room_rent' => (float) $b->room_rent, 'electricity_bill' => (float) $b->electricity_bill,
            'water_bill' => (float) $b->water_bill, 'total_amount' => (float) $b->total_amount, 'billing_unit_count' => $b->billing_unit_count,
            'shares' => $b->shares->map(fn (ResidencyBillShare $s) => ['id' => $s->id, 'occupant' => $s->occupant?->name, 'amount_due' => (float) $s->amount_due, 'paid_at' => $s->paid_at?->toIso8601String()])->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function guest(ResidencyGuest $g): array
    {
        return [
            'id' => $g->id, 'guest_name' => $g->guest_name, 'guest_contact' => $g->guest_contact, 'relation' => $g->relation, 'purpose' => $g->purpose,
            'host' => $g->host?->name, 'arrival_date' => $g->arrival_date->toDateString(), 'expected_departure_date' => $g->expected_departure_date?->toDateString(),
            'actual_departure_date' => $g->actual_departure_date?->toDateString(),
        ];
    }

    private function today(): string
    {
        return AttendanceRules::for(AccessContext::current()->organization())->now()->toDateString();
    }
}
