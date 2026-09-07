<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    /**
     * Determine whether the user can view any tasks in their organization.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the specific task.
     * Section 18:
     * - employee: own tasks only
     * - manager: own tasks + direct reports' tasks
     * - executive: all tasks in the org (read-only)
     * - admin: all tasks in the org
     */
    public function view(User $user, Task $task): bool
    {
        if ($user->org_id !== $task->org_id) {
            return false;
        }

        if ($user->isAdmin() || $user->isExecutive()) {
            return true;
        }

        if ($user->isManager()) {
            return $task->owner_id === $user->id || $user->directReports()->where('id', $task->owner_id)->exists();
        }

        if ($user->isEmployee()) {
            return $task->owner_id === $user->id;
        }

        return false;
    }

    /**
     * Determine whether the user can create tasks.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->isManager() || $user->isEmployee();
    }

    /**
     * Determine whether the user can update the task.
     * Section 18:
     * - employee: own tasks only
     * - manager: own tasks + direct reports' tasks
     * - executive: read-only on other people's work (false)
     * - admin: everything
     */
    public function update(User $user, Task $task): bool
    {
        if ($user->org_id !== $task->org_id) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isManager()) {
            return $task->owner_id === $user->id || $user->directReports()->where('id', $task->owner_id)->exists();
        }

        if ($user->isEmployee()) {
            return $task->owner_id === $user->id;
        }

        return false;
    }

    /**
     * Determine whether the user can delete the task.
     */
    public function delete(User $user, Task $task): bool
    {
        return $user->org_id === $task->org_id && $user->isAdmin();
    }

    /**
     * Determine whether the user can approve or reject the task.
     * Section 16: Approvers: any user with role admin, or the user who uploaded the meeting.
     */
    public function approve(User $user, Task $task): bool
    {
        if ($user->org_id !== $task->org_id) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        return $task->meeting && $task->meeting->created_by === $user->id;
    }

    /**
     * Determine whether the user can reject the task.
     */
    public function reject(User $user, Task $task): bool
    {
        return $this->approve($user, $task);
    }

    /**
     * Determine whether the user can block the task.
     */
    public function block(User $user, Task $task): bool
    {
        return $this->update($user, $task);
    }

    /**
     * Determine whether the user can unblock the task.
     */
    public function unblock(User $user, Task $task): bool
    {
        return $this->update($user, $task);
    }
}
