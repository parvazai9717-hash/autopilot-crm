<?php

namespace App\Policies;

use App\Models\User;
use App\Models\WebhookDelivery;

class WebhookDeliveryPolicy
{
    /**
     * Determine whether the user can view webhook delivery logs.
     * Section 17 & 18: Admin only
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can view a specific webhook delivery.
     */
    public function view(User $user, WebhookDelivery $delivery): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can resend a webhook delivery.
     */
    public function resend(User $user, WebhookDelivery $delivery): bool
    {
        return $user->isAdmin();
    }
}
