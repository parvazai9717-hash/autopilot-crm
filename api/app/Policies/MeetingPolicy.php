<?php

namespace App\Policies;

use App\Models\Meeting;
use App\Models\User;

class MeetingPolicy
{
    /**
     * Determine whether the user can view any meetings in their org.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the meeting.
     */
    public function view(User $user, Meeting $meeting): bool
    {
        return $user->org_id === $meeting->org_id;
    }

    /**
     * Determine whether the user can create/upload meetings.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the meeting.
     */
    public function update(User $user, Meeting $meeting): bool
    {
        return $user->org_id === $meeting->org_id && ($user->isAdmin() || $meeting->created_by === $user->id);
    }

    /**
     * Determine whether the user can delete the meeting.
     */
    public function delete(User $user, Meeting $meeting): bool
    {
        return $user->org_id === $meeting->org_id && $user->isAdmin();
    }

    /**
     * Determine whether the user can review tasks in this meeting.
     * Section 16: Approvers: any user with role admin, or the user who uploaded the meeting.
     */
    public function review(User $user, Meeting $meeting): bool
    {
        return $user->org_id === $meeting->org_id && ($user->isAdmin() || $meeting->created_by === $user->id);
    }

    /**
     * Determine whether the user can retry a stuck/failed meeting.
     * Section 10: POST /api/v1/meetings/{id}/retry (admin only)
     */
    public function retry(User $user, Meeting $meeting): bool
    {
        return $user->org_id === $meeting->org_id && $user->isAdmin();
    }
}
