<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApprovalWorkflow;
use App\Models\ApprovalWorkflowStep;
use App\Models\Employee;
use App\Models\Letter;
use App\Models\LetterCategory;
use App\Services\Correspondence\ApprovalEngine;
use App\Support\Access\AccessContext;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Letter categories and approval workflows (`letters.manage`, organization scope). */
class LetterSettingsController extends Controller
{
    public function __construct(private readonly ApprovalEngine $engine) {}

    private function guard(): void
    {
        abort_unless(AccessContext::current()->scope('letters.manage')?->isOrganizationWide(), 403, __('auth.forbidden'));
    }

    public function categories(): JsonResponse
    {
        $this->guard();

        return response()->json(['data' => LetterCategory::query()->orderBy('name')->get(['id', 'name', 'is_active'])]);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $this->guard();
        $category = LetterCategory::create($this->category($request));
        AuditLogger::record('letters', 'category_create', $category);

        return response()->json(['data' => $category->only(['id', 'name', 'is_active'])], 201);
    }

    public function updateCategory(Request $request, LetterCategory $category): JsonResponse
    {
        $this->guard();
        $before = $category->getAttributes();
        $category->update($this->category($request, $category));
        AuditLogger::recordChanges('letters', 'category_update', $category, $before);

        return response()->json(['data' => $category->only(['id', 'name', 'is_active'])]);
    }

    /** Adds the old app's usual categories that are not there yet. */
    public function seedCategories(): JsonResponse
    {
        $this->guard();
        $existing = LetterCategory::query()->pluck('name')->map(fn ($n) => mb_strtolower($n))->all();
        $added = 0;
        foreach (LetterCategory::DEFAULTS as $name) {
            if (! in_array(mb_strtolower($name), $existing, true)) {
                LetterCategory::create(['name' => $name]);
                $added++;
            }
        }
        AuditLogger::record('letters', 'category_defaults', null, null, ['added' => $added]);

        return response()->json(['data' => ['added' => $added]]);
    }

    public function workflows(): JsonResponse
    {
        $this->guard();
        $workflows = ApprovalWorkflow::query()->where('module', 'letters')->with(['steps.department', 'steps.user', 'category'])->orderBy('name')->get();

        return response()->json(['data' => $workflows->map(fn (ApprovalWorkflow $w) => $this->present($w))->values()]);
    }

    public function storeWorkflow(Request $request): JsonResponse
    {
        $this->guard();
        $workflow = DB::transaction(function () use ($request) {
            $data = $this->workflow($request);
            $workflow = ApprovalWorkflow::create(['module' => 'letters'] + collect($data)->except('steps')->all());
            $this->syncSteps($workflow, $data['steps']);
            AuditLogger::record('letters', 'workflow_create', $workflow, null, ['steps' => count($data['steps'])]);

            return $workflow;
        });

        return response()->json(['data' => $this->present($workflow->load(['steps.department', 'steps.user', 'category']))], 201);
    }

    /** Steps can change while files are on the way; a file at a removed step moves on from the next one. */
    public function updateWorkflow(Request $request, ApprovalWorkflow $workflow): JsonResponse
    {
        $this->guard();
        abort_unless($workflow->module === 'letters', 404);
        DB::transaction(function () use ($request, $workflow) {
            $data = $this->workflow($request, $workflow);
            $before = $workflow->getAttributes();
            $workflow->update(collect($data)->except('steps')->all());
            $this->syncSteps($workflow, $data['steps']);
            AuditLogger::recordChanges('letters', 'workflow_update', $workflow, $before);
        });

        return response()->json(['data' => $this->present($workflow->refresh()->load(['steps.department', 'steps.user', 'category']))]);
    }

    /** @return array<string, mixed> */
    private function present(ApprovalWorkflow $w): array
    {
        return [
            'id' => $w->id,
            'name' => $w->name,
            'is_active' => $w->is_active,
            'letter_category_id' => $w->letter_category_id,
            'category' => $w->category?->name,
            'files_on_the_way' => Letter::query()->where('approval_workflow_id', $w->id)->whereIn('status', Letter::MOVING)->count(),
            'steps' => $w->steps->map(fn (ApprovalWorkflowStep $s) => [
                'step_order' => $s->step_order,
                'name' => $s->name,
                'approver_type' => $s->approver_type,
                'department_id' => $s->department_id,
                'department' => $s->department?->name,
                'user_id' => $s->user_id,
                'user' => $s->user?->name,
                // Who it would go to today (for the origin department's head this depends on the file).
                'resolves_to' => $s->approver_type === 'origin_department_head' ? null : $this->engine->approver($s, null)?->name,
            ])->values(),
        ];
    }

    /** @param  list<array<string, mixed>>  $steps */
    private function syncSteps(ApprovalWorkflow $workflow, array $steps): void
    {
        $workflow->steps()->delete();
        foreach (array_values($steps) as $i => $step) {
            $workflow->steps()->create([
                'step_order' => $i + 1,
                'name' => $step['name'],
                'approver_type' => $step['approver_type'],
                'department_id' => $step['approver_type'] === 'department_head' ? $step['department_id'] : null,
                'user_id' => $step['approver_type'] === 'user' ? $step['user_id'] : null,
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function category(Request $request, ?LetterCategory $category = null): array
    {
        $orgId = AccessContext::current()->organizationId();

        return $request->validate([
            'name' => ['required', 'string', 'max:150', Rule::unique('letter_categories')->where('organization_id', $orgId)->ignore($category?->id)],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    /** @return array<string, mixed> */
    private function workflow(Request $request, ?ApprovalWorkflow $workflow = null): array
    {
        $orgId = AccessContext::current()->organizationId();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150', Rule::unique('approval_workflows')->where('organization_id', $orgId)->where('module', 'letters')->ignore($workflow?->id)],
            'letter_category_id' => ['nullable', 'integer', Rule::exists('letter_categories', 'id')->where('organization_id', $orgId)],
            'is_active' => ['sometimes', 'boolean'],
            'steps' => ['required', 'array', 'min:1', 'max:15'],
            'steps.*.name' => ['required', 'string', 'max:150'],
            'steps.*.approver_type' => ['required', Rule::in(ApprovalWorkflowStep::TYPES)],
            'steps.*.department_id' => ['nullable', 'required_if:steps.*.approver_type,department_head', 'integer', Rule::exists('departments', 'id')->where('organization_id', $orgId)],
            'steps.*.user_id' => ['nullable', 'required_if:steps.*.approver_type,user', 'integer'],
        ]);
        foreach ($data['steps'] as $i => $step) {
            if ($step['approver_type'] === 'user'
                && ! Employee::query()->where('user_id', $step['user_id'])->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(["steps.{$i}.user_id" => [__('letters.choose_recipient')]]);
            }
        }

        return $data;
    }
}
