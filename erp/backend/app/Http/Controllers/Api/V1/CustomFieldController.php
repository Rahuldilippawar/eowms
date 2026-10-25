<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CustomField;
use App\Support\Access\AccessContext;
use App\Support\Audit\AuditLogger;
use App\Support\Forms\CustomFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Organization-defined form fields (organizations.edit at organization scope to manage). */
class CustomFieldController extends Controller
{
    /** Active fields of one form, for rendering it. */
    public function active(Request $request): JsonResponse
    {
        $entity = $request->validate(['entity' => ['required', Rule::in(CustomField::ENTITIES)]])['entity'];

        return response()->json(['data' => CustomFields::for($entity)->map(fn ($f) => $f->only(['id', 'key', 'label', 'type', 'options', 'is_required']))->values()]);
    }

    public function index(): JsonResponse
    {
        $this->assertManager();

        return response()->json(['data' => CustomField::query()->orderBy('entity')->orderBy('sort_order')->orderBy('id')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->assertManager();
        $field = CustomField::create($this->validated($request));
        AuditLogger::record('custom_fields', 'create', $field, null, AuditLogger::snapshot($field));

        return response()->json(['data' => $field], 201);
    }

    public function update(Request $request, CustomField $field): JsonResponse
    {
        $this->assertManager();
        $before = $field->getAttributes();
        // Entity, key and type are fixed once created: stored values depend on them.
        $data = collect($this->validated($request, $field))->except(['entity', 'key', 'type'])->all();
        $field->fill($data)->save();
        AuditLogger::recordChanges('custom_fields', 'update', $field, $before);

        return response()->json(['data' => $field]);
    }

    private function assertManager(): void
    {
        abort_unless(AccessContext::current()->scope('organizations.edit')?->isOrganizationWide(), 403, __('auth.forbidden'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?CustomField $field = null): array
    {
        $orgId = AccessContext::current()->organizationId();
        $data = $request->validate([
            'entity' => [$field ? 'sometimes' : 'required', Rule::in(CustomField::ENTITIES)],
            'key' => [$field ? 'sometimes' : 'required', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('custom_fields')->where('organization_id', $orgId)->where('entity', $request->input('entity', $field?->entity))->ignore($field?->id)],
            'label' => ['required', 'string', 'max:255'],
            'type' => [$field ? 'sometimes' : 'required', Rule::in(CustomField::TYPES)],
            'options' => ['nullable', 'array', 'max:50'],
            'options.*' => ['string', 'max:100', 'distinct'],
            'is_required' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if (($data['type'] ?? $field?->type) === 'select' && empty($data['options'] ?? $field?->options)) {
            abort(422, __('validation.required', ['attribute' => 'options']));
        }

        return $data;
    }
}
