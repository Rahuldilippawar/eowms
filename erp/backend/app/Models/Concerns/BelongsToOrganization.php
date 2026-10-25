<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Support\Access\AccessContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant isolation for models with an organization_id column.
 *
 * While a request has an AccessContext (a signed-in user with a current
 * organization), every query on the model is limited to that organization,
 * so route-model binding of another organization's id simply finds nothing
 * (404). Console commands, seeders and jobs run without a context and must
 * filter by organization explicitly.
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope('organization', function (Builder $query) {
            $context = AccessContext::current();
            if ($context !== null) {
                $query->where($query->qualifyColumn('organization_id'), $context->organizationId());
            }
        });

        static::creating(function ($model) {
            $context = AccessContext::current();
            if ($context !== null && empty($model->organization_id)) {
                $model->organization_id = $context->organizationId();
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
