<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\LetterResource;
use App\Models\ApprovalWorkflow;
use App\Models\Employee;
use App\Models\Letter;
use App\Models\LetterAttachment;
use App\Models\LetterCategory;
use App\Services\Correspondence\LetterService;
use App\Services\WebAuthn\WebAuthn;
use App\Support\Access\AccessContext;
use App\Support\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Correspondence files: lists, the file view and every move on a file. */
class LetterController extends Controller
{
    private const WITH = ['category', 'department', 'creator', 'holder', 'workflow'];

    public function __construct(private readonly LetterService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $f = $request->validate([
            'box' => ['nullable', Rule::in(['with_me', 'created', 'all'])],
            'status' => ['nullable', Rule::in(['draft', 'in_progress', 'returned', 'completed'])],
            'letter_category_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $user = $request->user();
        $box = $f['box'] ?? 'with_me';

        $query = LetterService::visible($user)->with(self::WITH)
            ->when($box === 'with_me', fn (Builder $q) => $q->where('current_holder_id', $user->id)->whereIn('status', Letter::MOVING))
            ->when($box === 'created', fn (Builder $q) => $q->where('created_by', $user->id))
            ->when($f['status'] ?? null, fn (Builder $q, $s) => $q->where('status', $s))
            ->when($f['letter_category_id'] ?? null, fn (Builder $q, $id) => $q->where('letter_category_id', $id))
            ->when($f['search'] ?? null, function (Builder $q, $s) {
                $like = '%'.addcslashes($s, '%_\\').'%';
                $q->where(fn ($w) => $w->where('subject', 'like', $like)->orWhere('file_number', 'like', $like)
                    ->orWhere('outward_no', 'like', $like)->orWhere('reference_no', 'like', $like));
            })
            ->orderByRaw("FIELD(priority, 'urgent', 'high', 'normal', 'low')")->orderByDesc('updated_at')->orderByDesc('id');

        return LetterResource::collection($query->paginate($f['per_page'] ?? 25)->withQueryString())->additional([
            'meta' => ['with_me' => Letter::query()->where('current_holder_id', $user->id)->whereIn('status', Letter::MOVING)->count()],
        ]);
    }

    public function show(Request $request, Letter $letter): LetterResource
    {
        $letter = $this->visibleOrFail($request, $letter);
        $this->service->markSeen($letter, $request->user());

        return $this->detail($letter);
    }

    /** What the create form offers: active categories and workflows. */
    public function options(): JsonResponse
    {
        return response()->json(['data' => [
            'categories' => LetterCategory::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'workflows' => ApprovalWorkflow::query()->where('module', 'letters')->where('is_active', true)->orderBy('name')->get(['id', 'name', 'letter_category_id']),
        ]]);
    }

    /** Active colleagues a file can be sent to (people with an employee record). */
    public function recipients(Request $request): JsonResponse
    {
        $f = $request->validate(['search' => ['required', 'string', 'min:2', 'max:100']]);
        $like = '%'.addcslashes($f['search'], '%_\\').'%';

        return response()->json(['data' => Employee::query()->with('department:id,name')->where('is_active', true)->whereNotNull('user_id')
            ->where('user_id', '!=', $request->user()->id)
            ->whereHas('user', fn ($u) => $u->where('is_active', true))
            ->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('employee_code', 'like', $like)->orWhere('designation', 'like', $like))
            ->orderBy('name')->limit(20)->get()
            ->map(fn (Employee $e) => ['user_id' => $e->user_id, 'name' => $e->name, 'designation' => $e->designation, 'department' => $e->department?->name])]);
    }

    public function store(Request $request): LetterResource
    {
        return $this->detail($this->service->create($this->validated($request), $request->user()));
    }

    public function update(Request $request, Letter $letter): LetterResource
    {
        $letter = $this->visibleOrFail($request, $letter);

        return $this->detail($this->service->update($letter, $this->validated($request), $request->user()));
    }

    public function send(Request $request, Letter $letter, string $action): LetterResource
    {
        $letter = $this->visibleOrFail($request, $letter);
        $data = $request->validate(['to_user_id' => ['nullable', 'integer'], 'remark' => ['nullable', 'string', 'max:2000']]);

        return $this->detail($this->service->send($letter, $action, $data['to_user_id'] ?? null, $data['remark'] ?? null, $request->user()));
    }

    public function sendBack(Request $request, Letter $letter): LetterResource
    {
        $letter = $this->visibleOrFail($request, $letter);
        $data = $request->validate(['remark' => ['required', 'string', 'max:2000']]);

        return $this->detail($this->service->sendBack($letter, $data['remark'], $request->user()));
    }

    public function remark(Request $request, Letter $letter): LetterResource
    {
        $letter = $this->visibleOrFail($request, $letter);
        $data = $request->validate(['remark' => ['required', 'string', 'max:2000']]);
        $this->service->remark($letter, $data['remark'], $request->user());

        return $this->detail($letter);
    }

    public function reassign(Request $request, Letter $letter): LetterResource
    {
        $letter = $this->visibleOrFail($request, $letter);
        $data = $request->validate(['to_user_id' => ['required', 'integer'], 'remark' => ['nullable', 'string', 'max:2000']]);

        return $this->detail($this->service->reassign($letter, $data['to_user_id'], $data['remark'] ?? null, $request->user()));
    }

    public function recordDispatch(Request $request, Letter $letter): LetterResource
    {
        $letter = $this->visibleOrFail($request, $letter);
        $data = $request->validate([
            'dispatch_mode' => ['required', Rule::in(Letter::DISPATCH_MODES)],
            'dispatch_tracking_no' => ['nullable', 'string', 'max:100'],
            'dispatch_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return $this->detail($this->service->dispatch($letter, $data, $request->user()));
    }

    public function sign(Request $request, Letter $letter, WebAuthn $webauthn): LetterResource
    {
        $letter = $this->visibleOrFail($request, $letter);
        // Confirmed with the password, or with the person's fingerprint / face / device PIN (WebAuthn).
        $data = $request->validate([
            'password' => ['required_without:webauthn', 'nullable', 'string', 'max:255'],
            'webauthn' => ['required_without:password', 'nullable', 'array'],
            'webauthn.id' => ['required_with:webauthn', 'string', 'max:512'],
            'webauthn.response.clientDataJSON' => ['required_with:webauthn', 'string', 'max:4096'],
            'webauthn.response.authenticatorData' => ['required_with:webauthn', 'string', 'max:4096'],
            'webauthn.response.signature' => ['required_with:webauthn', 'string', 'max:1024'],
            'remark' => ['nullable', 'string', 'max:2000'],
            'approved_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
        ]);
        $amount = isset($data['approved_amount']) ? (float) $data['approved_amount'] : null;
        if (! empty($data['password'])) {
            $this->service->sign($letter, $data['password'], $data['remark'] ?? null, $amount, $request->user());
        } else {
            try {
                $webauthn->verify('confirm', $request->input('webauthn'), $request->user());
            } catch (RuntimeException) {
                AuditLogger::record('letters', 'sign_failed', $letter);
                throw ValidationException::withMessages(['webauthn' => [__('webauthn.confirm_failed')]]);
            }
            $this->service->signConfirmed($letter, $data['remark'] ?? null, $amount, $request->user());
        }

        return $this->detail($letter->refresh());
    }

    public function attach(Request $request, Letter $letter): LetterResource
    {
        $letter = $this->visibleOrFail($request, $letter);
        $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx']]);
        $this->service->attach($letter, $request->file('file'), $request->user());

        return $this->detail($letter);
    }

    public function attachment(Request $request, Letter $letter, LetterAttachment $attachment): StreamedResponse
    {
        $letter = $this->visibleOrFail($request, $letter);
        abort_unless($attachment->letter_id === $letter->id && Storage::disk('local')->exists($attachment->path), 404);
        AuditLogger::record('letters', 'attachment_download', $letter, null, ['file' => $attachment->original_name]);

        return Storage::disk('local')->download($attachment->path, $attachment->original_name, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function visibleOrFail(Request $request, Letter $letter): Letter
    {
        abort_unless(LetterService::visible($request->user())->whereKey($letter->id)->exists(), 404);

        return $letter;
    }

    private function detail(Letter $letter): LetterResource
    {
        $letter->refresh()->load([...self::WITH, 'workflow.steps', 'movements.fromUser', 'movements.toUser', 'attachments', 'inwardNumbers.department', 'signatures']);

        return (new LetterResource($letter))->detailed();
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $orgId = AccessContext::current()->organizationId();

        return $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'letter_category_id' => ['nullable', 'integer', Rule::exists('letter_categories', 'id')->where('organization_id', $orgId)->where('is_active', true)],
            'priority' => ['required', Rule::in(Letter::PRIORITIES)],
            'approval_workflow_id' => ['nullable', 'integer', Rule::exists('approval_workflows', 'id')->where('organization_id', $orgId)->where('module', 'letters')->where('is_active', true)],
            'is_budget_expense' => ['sometimes', 'boolean'],
            'budget_category' => ['nullable', Rule::in(Letter::BUDGET_CATEGORIES)],
            'requested_amount' => ['nullable', 'required_if:is_budget_expense,true', 'numeric', 'gt:0', 'max:9999999999'],
        ]);
    }
}
