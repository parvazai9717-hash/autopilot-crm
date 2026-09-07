<?php

namespace App\Traits;

use App\Models\Organization;
use App\Scopes\OrgScope;
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
            if (empty($model->org_id)) {
                if (request()->has('org_id')) {
                    $model->org_id = (int) request()->query('org_id', request()->input('org_id'));
                } elseif (Auth::check() && Auth::user()->org_id) {
                    $model->org_id = Auth::user()->org_id;
                }
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
