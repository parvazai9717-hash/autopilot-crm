<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Determine whether the user can view any users in the org.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the specific user.
     */
    public function view(User $user, User $model): bool
    {
        return $user->org_id === $model->org_id;
    }

    /**
     * Determine whether the user can create new users.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can update the user.
     */
    public function update(User $user, User $model): bool
    {
        return $user->org_id === $model->org_id && ($user->isAdmin() || $user->id === $model->id);
    }

    /**
     * Determine whether the user can delete the user.
     */
    public function delete(User $user, User $model): bool
    {
        return $user->org_id === $model->org_id && $user->isAdmin() && $user->id !== $model->id;
    }

    /**
     * Determine whether the user can manage users (assign managers, change roles, etc.).
     */
    public function manage(User $user): bool
    {
        return $user->isAdmin();
    }
}
