<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BiometricDevice;
use App\Models\BiometricPunch;
use App\Support\Access\AccessContext;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Attendance machines of the organization (`attendance.manage` at organization scope). */
class BiometricDeviceController extends Controller
{
    public function index(): JsonResponse
    {
        $this->assertManager();

        return response()->json(['data' => BiometricDevice::query()->with('institution')->orderBy('name')->get()->map(fn (BiometricDevice $d) => [
            'id' => $d->id, 'name' => $d->name, 'serial_number' => $d->serial_number, 'is_active' => $d->is_active,
            'institution' => $d->institution ? ['id' => $d->institution->id, 'name' => $d->institution->name] : null,
            'last_seen_at' => $d->last_seen_at?->toIso8601String(),
        ])]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->assertManager();
        $device = BiometricDevice::create($this->validated($request));
        AuditLogger::record('attendance', 'device_create', $device, null, AuditLogger::snapshot($device));

        return response()->json(['data' => ['id' => $device->id]], 201);
    }

    public function update(Request $request, BiometricDevice $device): JsonResponse
    {
        $this->assertManager();
        $before = $device->getAttributes();
        $device->fill($this->validated($request, $device))->save();
        AuditLogger::recordChanges('attendance', 'device_update', $device, $before);

        return response()->json(['data' => ['id' => $device->id]]);
    }

    /** The latest punches, including ones whose PIN matches nobody (to fix PIN mapping). */
    public function punches(Request $request): JsonResponse
    {
        $this->assertManager();
        $result = $request->validate(['result' => ['nullable', Rule::in(['applied', 'unknown_pin', 'inactive'])]])['result'] ?? null;
        $rows = BiometricPunch::query()->when($result, fn ($q, $r) => $q->where('result', $r))->latest('id')->limit(200)->get();
        $devices = BiometricDevice::query()->pluck('name', 'id');

        return response()->json(['data' => $rows->map(fn (BiometricPunch $p) => [
            'id' => $p->id, 'device' => $devices[$p->device_id] ?? null, 'pin' => $p->pin, 'punched_at' => $p->punched_at->toIso8601String(), 'result' => $p->result, 'employee_id' => $p->employee_id,
        ])]);
    }

    private function assertManager(): void
    {
        abort_unless(AccessContext::current()->scope('attendance.manage')?->isOrganizationWide(), 403, __('auth.forbidden'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?BiometricDevice $device = null): array
    {
        $orgId = AccessContext::current()->organizationId();

        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'serial_number' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{4,50}$/', Rule::unique('biometric_devices')->ignore($device?->id)],
            'institution_id' => ['nullable', 'integer', Rule::exists('institutions', 'id')->where('organization_id', $orgId)],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
