<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Building;
use App\Models\ResidencyAllotmentRequest;
use App\Models\ResidencyBillShare;
use App\Models\ResidencyOccupant;
use App\Services\Notifier;
use App\Support\Access\AccessContext;
use App\Support\Access\PermissionHolders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** A staff member's own housing: where they live, their family, their bill shares and their requests. */
class MyResidencyController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $me = $request->user()->id;
        $home = ResidencyOccupant::query()->where('user_id', $me)->where('occupant_type', 'staff')->where('status', 'active')
            ->with(['location.building:id,name', 'family' => fn ($q) => $q->where('status', 'active')])->first();

        return response()->json(['data' => [
            'home' => $home ? [
                'room' => $home->location?->name, 'building' => $home->location?->building?->name, 'moved_in_date' => $home->moved_in_date?->toDateString(),
                'family' => $home->family->map(fn (ResidencyOccupant $f) => $f->only(['id', 'name', 'relation', 'age']))->values(),
            ] : null,
            'bills' => ResidencyBillShare::query()->whereIn('occupant_id', ResidencyOccupant::query()->where('user_id', $me)->select('id'))
                ->with('bill:id,billing_month,total_amount,billing_unit_count')->orderByDesc('id')->limit(24)->get()
                ->map(fn (ResidencyBillShare $s) => ['id' => $s->id, 'billing_month' => $s->bill?->billing_month, 'amount_due' => (float) $s->amount_due,
                    'bill_total' => (float) $s->bill?->total_amount, 'shared_by' => $s->bill?->billing_unit_count, 'paid_at' => $s->paid_at?->toIso8601String()])->values(),
            'requests' => ResidencyAllotmentRequest::query()->where('requested_by', $me)->with(['building:id,name', 'location:id,name', 'decider:id,name'])->orderByDesc('id')->get()
                ->map(fn ($r) => ResidencyController::request($r))->values(),
            'buildings' => Building::query()->whereHas('locations', fn ($q) => $q->where('is_residential', true)->where('is_active', true))->orderBy('name')->get(['id', 'name']),
        ]]);
    }

    public function request(Request $request): JsonResponse
    {
        $me = $request->user()->id;
        $data = $request->validate([
            'preferred_building_id' => ['nullable', 'integer', Rule::exists('buildings', 'id')->where('organization_id', AccessContext::current()->organizationId())],
            'family_size' => ['required', 'integer', 'min:1', 'max:20'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        if (ResidencyOccupant::query()->where('user_id', $me)->where('occupant_type', 'staff')->where('status', 'active')->exists()) {
            throw ValidationException::withMessages(['reason' => [__('residency.already_housed')]]);
        }
        if (ResidencyAllotmentRequest::query()->where('requested_by', $me)->whereIn('status', ['pending', 'waitlisted'])->exists()) {
            throw ValidationException::withMessages(['reason' => [__('residency.request_open')]]);
        }
        $r = ResidencyAllotmentRequest::create($data + ['requested_by' => $me, 'status' => 'pending']);
        foreach (PermissionHolders::userIds($r->organization_id, 'residency.manage') as $userId) {
            Notifier::user($userId, $r->organization_id, 'residency.request_new', ['by' => $request->user()->name], '/residency/requests');
        }

        return $this->show($request)->setStatusCode(201);
    }

    public function cancel(Request $request, ResidencyAllotmentRequest $allotment): JsonResponse
    {
        abort_unless($allotment->requested_by === $request->user()->id, 404);
        if (! in_array($allotment->status, ['pending', 'waitlisted'], true)) {
            throw ValidationException::withMessages(['request' => [__('residency.request_closed')]]);
        }
        $allotment->forceFill(['status' => 'cancelled'])->save();

        return $this->show($request);
    }
}
