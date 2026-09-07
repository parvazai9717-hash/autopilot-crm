<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;

class OrganizationPolicy
{
    /**
     * Determine whether the user can view the organization.
     */
    public function view(User $user, Organization $organization): bool
    {
        return $user->org_id === $organization->id;
    }

    /**
     * Determine whether the user can update the organization settings.
     * Section 17 & 18: Admin only
     */
    public function update(User $user, Organization $organization): bool
    {
        return $user->org_id === $organization->id && $user->isAdmin();
    }
}
