<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Access\AccessContext;
use App\Support\Access\Modules;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Switch modules on or off for the current organization. */
class OrganizationModulesController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->state()]);
    }

    public function update(Request $request): JsonResponse
    {
        abort_unless(AccessContext::current()->scope('organizations.edit')?->isOrganizationWide(), 403, __('auth.forbidden'));
        $data = $request->validate([
            'disabled' => ['present', 'array'],
            'disabled.*' => ['string', Rule::in(Modules::toggleable())],
        ]);
        $organization = AccessContext::current()->organization();
        $before = Modules::disabled($organization);
        $settings = $organization->settings ?? [];
        $settings['modules_disabled'] = array_values(array_unique($data['disabled']));
        $organization->forceFill(['settings' => $settings])->save();
        AuditLogger::record('organizations', 'modules', $organization, ['disabled' => $before], ['disabled' => $settings['modules_disabled']]);

        return response()->json(['data' => $this->state()]);
    }

    /** @return array{toggleable: list<string>, disabled: list<string>} */
    private function state(): array
    {
        return ['toggleable' => Modules::toggleable(), 'disabled' => Modules::disabled(AccessContext::current()->organization())];
    }
}
