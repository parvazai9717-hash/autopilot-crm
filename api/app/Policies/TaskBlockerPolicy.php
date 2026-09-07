<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\TaskBlocker;
use App\Models\User;

class TaskBlockerPolicy
{
    /**
     * Determine whether the user can create a blocker on the given task.
     */
    public function create(User $user, Task $task): bool
    {
        return (new TaskPolicy())->update($user, $task);
    }

    /**
     * Determine whether the user can resolve the blocker.
     * Section 17: Manager can resolve blocker, admin can resolve, or the blocker creator.
     */
    public function resolve(User $user, TaskBlocker $blocker): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($blocker->blocked_by === $user->id) {
            return true;
        }

        if ($user->isManager() && $blocker->task) {
            return (new TaskPolicy())->update($user, $blocker->task);
        }

        return false;
    }
}
