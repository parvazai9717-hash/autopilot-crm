<?php

namespace App\Policies;

use App\Models\Task;
use App\Models\TaskComment;
use App\Models\User;

class TaskCommentPolicy
{
    /**
     * Determine whether the user can create a comment on the given task.
     */
    public function create(User $user, Task $task): bool
    {
        // User must be able to view the task to comment on it
        return (new TaskPolicy())->view($user, $task);
    }

    /**
     * Determine whether the user can delete the comment.
     */
    public function delete(User $user, TaskComment $comment): bool
    {
        return $user->isAdmin() || $comment->user_id === $user->id;
    }
}
