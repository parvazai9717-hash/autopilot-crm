<?php

namespace App\Traits;

use App\Models\Organization;
use App\Scopes\OrgScope;
use App\Services\TenantContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

trait BelongsToOrg
{
    /**
     * Boot the BelongsToOrg trait.
     */
    protected static function bootBelongsToOrg(): void
    {
        static::addGlobalScope(new OrgScope());

        static::creating(function ($model) {
            $tenantId = TenantContext::get() ?? (Auth::check() ? Auth::user()?->org_id : null);

            // In tenant-scoped contexts, strictly force model org_id to the verified tenant ID.
            // Never trust caller-supplied request query parameters or request body org_id.
            if ($tenantId !== null && !TenantContext::isBypassed()) {
                $model->org_id = $tenantId;
            } elseif (empty($model->org_id) && $tenantId !== null) {
                $model->org_id = $tenantId;
            }
        });
    }

    /**
     * Get the organization that owns the model.
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
    }
}
