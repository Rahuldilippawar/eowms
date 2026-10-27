<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\Vendor;
use App\Support\Access\AccessContext;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The organization's vendors (purchasing.view lists, purchasing.manage edits). */
class VendorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'inactive' => ['nullable', 'boolean']]);
        $vendors = Vendor::query()
            ->when(! $request->boolean('inactive'), fn ($q) => $q->where('is_active', true))
            ->when($f['search'] ?? null, function ($q, $s) {
                $like = '%'.addcslashes($s, '%_\\').'%';
                $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('contact_person', 'like', $like)->orWhere('phone', 'like', $like));
            })
            ->orderBy('name')->limit(2000)->get();
        $orders = PurchaseOrder::query()->whereIn('vendor_id', $vendors->pluck('id')->all() ?: [0])->whereNotIn('status', ['draft', 'cancelled'])
            ->groupBy('vendor_id')->selectRaw('vendor_id, count(*) as orders, sum(total_amount) as ordered')->get()->keyBy('vendor_id');

        return response()->json([
            'data' => $vendors->map(fn (Vendor $v) => $this->present($v) + [
                'orders' => (int) ($orders[$v->id]->orders ?? 0), 'ordered_amount' => round((float) ($orders[$v->id]->ordered ?? 0), 2),
            ])->values(),
            'meta' => ['can_manage' => AccessContext::current()->can('purchasing.manage')],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $vendor = Vendor::create($this->validated($request));
        AuditLogger::record('purchasing', 'vendor_create', $vendor, null, AuditLogger::snapshot($vendor));

        return response()->json(['data' => $this->present($vendor)], 201);
    }

    public function update(Request $request, Vendor $vendor): JsonResponse
    {
        $before = $vendor->getAttributes();
        $vendor->fill($this->validated($request, $vendor) + $request->validate(['is_active' => ['sometimes', 'boolean']]))->save();
        AuditLogger::recordChanges('purchasing', 'vendor_update', $vendor, $before);

        return response()->json(['data' => $this->present($vendor)]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Vendor $vendor = null): array
    {
        if (is_string($request->input('gstin'))) {
            $request->merge(['gstin' => strtoupper(trim($request->input('gstin'))) ?: null]);
        }

        return $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('vendors', 'name')->where('organization_id', AccessContext::current()->organizationId())->ignore($vendor?->id)],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'gstin' => ['nullable', 'string', 'regex:/^[0-9A-Za-z]{15}$/'],
            'address' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    /** @return array<string, mixed> */
    public static function present(Vendor $v): array
    {
        return $v->only(['id', 'name', 'contact_person', 'phone', 'email', 'gstin', 'address', 'is_active']);
    }
}
