<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Organization;
use App\Models\WorkRequest;
use App\Models\WorkRequestAction;
use App\Services\Requests\RequestDesk;
use App\Support\Access\Modules;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;

/**
 * The public request form and tracker (the old request.php and
 * track-request.php), per organization code. A honeypot field catches bots
 * (they get the usual success), each address may send 5 requests an hour,
 * and the tracker needs the phone number given with the request.
 */
class PublicRequestController extends Controller
{
    public const PER_HOUR = 5;

    public function __construct(private readonly RequestDesk $desk) {}

    public function form(string $organization): JsonResponse
    {
        $org = $this->organization($organization);

        return response()->json(['data' => [
            'organization' => ['name' => $org->name, 'code' => $org->code],
            'departments' => Department::withoutGlobalScopes()->where('organization_id', $org->id)->where('is_active', true)->orderBy('name')
                ->get(['id', 'name'])->map(fn (Department $d) => ['id' => $d->id, 'name' => $d->name])->values(),
            'categories' => WorkRequest::CATEGORIES,
        ]]);
    }

    public function submit(Request $request, string $organization): JsonResponse
    {
        $org = $this->organization($organization);
        // Bots fill every field; people never see this one. They get the usual answer and nothing is saved.
        if ($request->filled('website')) {
            return response()->json(['data' => ['received' => true, 'request_code' => null]], 201);
        }
        $key = 'public-request:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, self::PER_HOUR)) {
            return response()->json(['message' => __('requests.too_many')], 429);
        }
        $data = $request->validate([
            'requester_name' => ['required', 'string', 'max:255'],
            'requester_contact' => ['required', 'string', 'max:20', 'regex:/^[0-9+\-\s]{10,20}$/'],
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')->where('organization_id', $org->id)->where('is_active', true)],
            'category' => ['nullable', Rule::in(WorkRequest::CATEGORIES)],
            'location' => ['nullable', 'string', 'max:255'],
            'work_description' => ['required', 'string', 'max:3000'],
        ]);
        RateLimiter::hit($key, 3600);
        $r = $this->desk->create($org, $data + ['category' => $data['category'] ?? 'general', 'priority' => 'normal'], null, 'public');
        AuditLogger::record('requests', 'public_create', $r, null, ['code' => $r->request_code]);

        return response()->json(['data' => ['received' => true, 'request_code' => $r->request_code]], 201);
    }

    /** Status of one request: its code and the phone number given with it must both match. */
    public function track(Request $request, string $organization): JsonResponse
    {
        $org = $this->organization($organization);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30'],
            'contact' => ['required', 'string', 'max:20'],
        ]);
        $digits = fn (?string $s) => substr(preg_replace('/\D/', '', (string) $s), -10);
        $r = WorkRequest::withoutGlobalScopes()->where('organization_id', $org->id)->where('request_code', strtoupper(trim($data['code'])))->first();
        if ($r === null || strlen($digits($data['contact'])) !== 10 || $digits($r->requester_contact) !== $digits($data['contact'])) {
            return response()->json(['message' => __('requests.track_not_found')], 404);
        }
        $firstSeen = $r->views()->min('first_viewed_at');

        return response()->json(['data' => [
            'request_code' => $r->request_code,
            'status' => $r->status,
            'department' => Department::withoutGlobalScopes()->whereKey($r->department_id)->value('name'),
            'created_at' => $r->created_at?->toIso8601String(),
            'completed_at' => $r->completed_at?->toIso8601String(),
            'completion_remarks' => $r->completion_remarks,
            'seen_at' => $firstSeen ? Carbon::parse($firstSeen)->toIso8601String() : null,
            // Status changes only: staff names and internal remarks stay inside.
            'timeline' => $r->actions()->whereIn('action_type', ['created', 'status_changed', 'reopened', 'closed', 'cancelled'])->get()
                ->filter(fn (WorkRequestAction $a) => $a->action_type !== 'status_changed' || $a->old_status !== $a->new_status)
                ->map(fn (WorkRequestAction $a) => ['type' => $a->action_type, 'status' => $a->new_status, 'at' => $a->created_at?->toIso8601String()])->values(),
        ]]);
    }

    private function organization(string $code): Organization
    {
        $org = Organization::query()->where('code', strtoupper($code))->where('is_active', true)->firstOrFail();
        abort_if(in_array('requests', Modules::disabled($org), true), 404);

        return $org;
    }
}
