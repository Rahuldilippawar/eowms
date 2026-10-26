<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Vehicle;
use App\Models\VehicleCorrectionRequest;
use App\Services\Notifier;
use App\Services\Vehicles\VehicleBook;
use App\Support\Access\AccessContext;
use App\Support\Access\ScopeDepartments;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Someone who logged a trip, fill or service asks for it to be fixed or
 * removed; a vehicle manager approves (applying the fix) or rejects it.
 */
class VehicleCorrectionController extends Controller
{
    public function __construct(private readonly VehicleBook $book, private readonly VehicleController $vehicles) {}

    public function index(Request $request): JsonResponse
    {
        $f = $request->validate(['status' => ['nullable', Rule::in(['pending', 'approved', 'rejected', 'all'])]]);
        $status = $f['status'] ?? 'pending';
        $ctx = AccessContext::current();
        $ids = ScopeDepartments::ids($ctx->can('vehicles.manage') ? $ctx->scope('vehicles.manage') : null);
        $me = $ctx->user()->id;
        $rows = VehicleCorrectionRequest::query()->with(['vehicle:id,vehicle_number,department_id', 'requester:id,name', 'reviewer:id,name'])
            ->when($ids !== null, fn ($q) => $q->where(fn ($w) => $w->whereIn('vehicle_id', Vehicle::query()->whereIn('department_id', $ids ?: [0])->select('id'))->orWhere('requested_by', $me)))
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderByDesc('id')->limit(200)->get();

        return response()->json(['data' => $rows->map(function (VehicleCorrectionRequest $r) {
            $entry = VehicleBook::ENTRY_MODELS[$r->entry_type]::query()->where('vehicle_id', $r->vehicle_id)->find($r->entry_id);

            return [
                'id' => $r->id, 'vehicle' => ['id' => $r->vehicle->id, 'vehicle_number' => $r->vehicle->vehicle_number],
                'entry_type' => $r->entry_type, 'entry_id' => $r->entry_id, 'request_type' => $r->request_type, 'reason' => $r->reason, 'status' => $r->status,
                'requested_by' => $r->requester?->name, 'reviewed_by' => $r->reviewer?->name, 'reviewed_at' => $r->reviewed_at?->toIso8601String(),
                'review_note' => $r->review_note, 'created_at' => $r->created_at?->toIso8601String(),
                'entry' => $entry?->only(match ($r->entry_type) {
                    'trip' => ['trip_date', 'from_location', 'to_location', 'start_odometer', 'end_odometer', 'distance_km', 'purpose', 'start_time', 'end_time'],
                    'fuel' => ['fill_date', 'odometer_reading', 'liters', 'cost', 'fuel_station'],
                    default => ['service_date', 'type', 'description', 'cost', 'odometer_reading', 'next_due_date', 'next_due_km', 'vendor'],
                }),
                'can_review' => $r->status === 'pending' && $this->book->canManage($r->vehicle),
            ];
        })->values()]);
    }

    public function store(Request $request, Vehicle $vehicle, string $type, int $entry): JsonResponse
    {
        $vehicle = $this->book->visible()->findOrFail($vehicle->id);
        abort_unless($this->book->canLog($vehicle), 403, __('auth.forbidden'));
        $this->vehicles->findEntry($vehicle, $type, $entry);
        $data = $request->validate([
            'request_type' => ['required', Rule::in(['edit', 'delete'])],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        $exists = VehicleCorrectionRequest::query()->where(['vehicle_id' => $vehicle->id, 'entry_type' => $type, 'entry_id' => $entry, 'status' => 'pending'])->exists();
        if ($exists) {
            throw ValidationException::withMessages(['reason' => [__('vehicles.correction_pending')]]);
        }
        $correction = VehicleCorrectionRequest::create($data + [
            'vehicle_id' => $vehicle->id, 'entry_type' => $type, 'entry_id' => $entry, 'status' => 'pending', 'requested_by' => $request->user()->id,
        ]);
        AuditLogger::record('vehicles', 'correction_request', $correction, null, AuditLogger::snapshot($correction));
        $head = Department::query()->whereKey($vehicle->department_id)->value('head_user_id');
        Notifier::user($head, $vehicle->organization_id, 'vehicle.correction_requested',
            ['vehicle' => $vehicle->vehicle_number, 'by' => $request->user()->name], '/vehicles/corrections');

        return response()->json(['data' => ['id' => $correction->id, 'status' => 'pending']], 201);
    }

    /** Approving an edit applies `changes` over the entry as it is; approving a delete removes the entry. */
    public function review(Request $request, VehicleCorrectionRequest $correction): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'note' => ['nullable', 'string', 'max:500'],
            'changes' => ['nullable', 'array'],
        ]);
        $vehicle = $this->book->visible()->findOrFail($correction->vehicle_id);
        abort_unless($this->book->canManage($vehicle), 403, __('auth.forbidden'));
        if ($correction->status !== 'pending') {
            throw ValidationException::withMessages(['decision' => [__('vehicles.correction_closed')]]);
        }
        DB::transaction(function () use ($correction, $vehicle, $data, $request) {
            if ($data['decision'] === 'approve') {
                $entry = VehicleBook::ENTRY_MODELS[$correction->entry_type]::query()->where('vehicle_id', $vehicle->id)->find($correction->entry_id);
                if ($entry === null) {
                    throw ValidationException::withMessages(['decision' => [__('vehicles.entry_gone')]]);
                }
                if ($correction->request_type === 'delete') {
                    $this->vehicles->applyDelete($vehicle, $entry);
                } else {
                    $current = collect($entry->attributesToArray())->map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v)->all();
                    foreach (['start_time', 'end_time'] as $t) {
                        if (! empty($current[$t])) {
                            $current[$t] = substr($current[$t], 0, 5);
                        }
                    }
                    $this->vehicles->applyEdit($vehicle, $entry, new Request(array_merge($current, $data['changes'] ?? [])), $request->user()->id);
                }
            }
            $correction->forceFill([
                'status' => $data['decision'] === 'approve' ? 'approved' : 'rejected', 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'review_note' => $data['note'] ?? null,
            ])->save();
            AuditLogger::record('vehicles', 'correction_'.$correction->status, $correction);
        });
        Notifier::user($correction->requested_by, $vehicle->organization_id, 'vehicle.correction_'.$correction->status,
            ['vehicle' => $vehicle->vehicle_number], "/vehicles/{$vehicle->id}");

        return response()->json(['data' => ['id' => $correction->id, 'status' => $correction->status]]);
    }
}
