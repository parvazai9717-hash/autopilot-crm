<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskEvent;
use App\Models\User;
use App\Services\TaskStateMachine;
use App\Support\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ManagerDashboardController extends Controller
{
    /**
     * Ensure the authenticated user has manager or admin privileges.
     */
    protected function authorizeManager(Request $request): ?JsonResponse
    {
        $user = $request->user();
        if (!$user->isManager() && !$user->isAdmin()) {
            return ApiResponse::error('FORBIDDEN', 'Manager or admin access required.', 403);
        }
        return null;
    }

    /**
     * GET /api/manager/dashboard
     * Returns team metrics, direct reports roster, and team tasks.
     * Direct reports only (users.manager_id = me).
     */
    public function index(Request $request): JsonResponse
    {
        if ($deny = $this->authorizeManager($request)) {
            return $deny;
        }

        $user = $request->user();
        $orgTz = $user->organization?->timezone ?: config('app.timezone', 'Asia/Karachi');
        $today = Carbon::now($orgTz)->startOfDay();

        // 1. Fetch direct reports (users.manager_id = me)
        $directReports = User::withoutGlobalScopes()
            ->where('org_id', $user->org_id)
            ->where('manager_id', $user->id)
            ->get(['id', 'name', 'email', 'role', 'status']);

        $reportIds = $directReports->pluck('id')->all();

        // 2. Fetch all tasks owned by direct reports
        $allTeamTasks = Task::withoutGlobalScopes()
            ->where('org_id', $user->org_id)
            ->whereIn('owner_id', $reportIds)
            ->whereNotIn('status', [
                TaskStateMachine::STATUS_DETECTED,
                TaskStateMachine::STATUS_PENDING_APPROVAL,
                TaskStateMachine::STATUS_REJECTED,
            ])
            ->with([
                'owner:id,name,email',
                'activeBlocker',
                'comments.author:id,name',
            ])
            ->orderBy('due_date', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        // 3. Compute team metrics across all direct reports' tasks
        $totalTasks = $allTeamTasks->count();
        $completedCount = $allTeamTasks->where('status', TaskStateMachine::STATUS_COMPLETED)->count();
        $blockedCount = $allTeamTasks->where('status', TaskStateMachine::STATUS_BLOCKED)->count();
        $overdueCount = 0;
        $upcomingCount = 0;

        foreach ($allTeamTasks as $t) {
            if ($t->status === TaskStateMachine::STATUS_COMPLETED) {
                continue;
            }
            $dueDate = $t->due_date ? Carbon::parse($t->due_date) : null;
            if ($dueDate && $dueDate->lt($today)) {
                $overdueCount++;
            } else {
                $upcomingCount++;
            }
        }

        $completionRate = $totalTasks > 0 ? (int) round(($completedCount / $totalTasks) * 100) : 100;

        // 4. Summarize stats per direct report
        $roster = $directReports->map(function (User $report) use ($allTeamTasks) {
            $userTasks = $allTeamTasks->where('owner_id', $report->id);
            return [
                'id'                   => $report->id,
                'name'                 => $report->name,
                'email'                => $report->email,
                'role'                 => $report->role,
                'total_tasks'          => $userTasks->count(),
                'open_tasks'           => $userTasks->where('status', '!=', TaskStateMachine::STATUS_COMPLETED)->count(),
                'blocked_tasks'        => $userTasks->where('status', TaskStateMachine::STATUS_BLOCKED)->count(),
                'completed_tasks'      => $userTasks->where('status', TaskStateMachine::STATUS_COMPLETED)->count(),
            ];
        })->values();

        // 5. Apply filters to task list if requested
        $tasks = $allTeamTasks;

        if ($request->filled('report_id')) {
            $reportId = (int) $request->input('report_id');
            if (in_array($reportId, $reportIds, true)) {
                $tasks = $tasks->where('owner_id', $reportId);
            }
        }

        if ($request->filled('status')) {
            $statusFilter = $request->input('status');
            if ($statusFilter === 'overdue') {
                $tasks = $tasks->filter(function (Task $t) use ($today) {
                    if ($t->status === TaskStateMachine::STATUS_COMPLETED) return false;
                    $due = $t->due_date ? Carbon::parse($t->due_date) : null;
                    return $due && $due->lt($today);
                });
            } elseif ($statusFilter === 'blocked') {
                $tasks = $tasks->where('status', TaskStateMachine::STATUS_BLOCKED);
            } elseif ($statusFilter === 'upcoming') {
                $tasks = $tasks->filter(function (Task $t) use ($today) {
                    if ($t->status === TaskStateMachine::STATUS_COMPLETED) return false;
                    $due = $t->due_date ? Carbon::parse($t->due_date) : null;
                    return !$due || $due->gte($today);
                });
            } elseif ($statusFilter === 'completed') {
                $tasks = $tasks->where('status', TaskStateMachine::STATUS_COMPLETED);
            }
        }

        $formattedTasks = $tasks->map(fn(Task $t) => $this->formatTask($t, $orgTz, $today))->values();

        return response()->json([
            'metrics' => [
                'total_tasks'     => $totalTasks,
                'completed_tasks' => $completedCount,
                'overdue_tasks'   => $overdueCount,
                'blocked_tasks'   => $blockedCount,
                'upcoming_tasks'  => $upcomingCount,
                'completion_rate' => $completionRate,
            ],
            'direct_reports' => $roster,
            'tasks'          => $formattedTasks,
        ]);
    }

    /**
     * POST /api/manager/tasks/{id}/reassign
     * Reassign a task to another direct report.
     * Returns 422 if the target owner is not in the manager's direct reports.
     */
    public function reassign(Request $request, int $id, TaskStateMachine $sm): JsonResponse
    {
        if ($deny = $this->authorizeManager($request)) {
            return $deny;
        }

        $task = $this->findDirectReportTask($request, $id);
        if ($task instanceof JsonResponse) {
            return $task;
        }

        $validated = $request->validate([
            'owner_id' => ['required', 'integer'],
        ]);

        $user = $request->user();

        // Validate that new owner is in this manager's direct reports list!
        $newOwner = User::withoutGlobalScopes()
            ->where('org_id', $user->org_id)
            ->where('manager_id', $user->id)
            ->where('id', $validated['owner_id'])
            ->first();

        if (!$newOwner) {
            return ApiResponse::error(
                'INVALID_OWNER',
                'New owner must be a direct report of this manager.',
                422,
                'owner_id'
            );
        }

        $previousOwnerId = $task->owner_id;
        $task->owner_id = $newOwner->id;
        $task->owner_ambiguous = false;
        $task->save();

        TaskEvent::create([
            'task_id'    => $task->id,
            'event_type' => 'ASSIGNED',
            'actor_type' => 'user',
            'actor_id'   => $user->id,
            'metadata'   => [
                'reassigned_by'     => $user->id,
                'previous_owner_id' => $previousOwnerId,
                'new_owner_id'      => $newOwner->id,
            ],
            'created_at' => now(),
        ]);

        return response()->json([
            'ok'   => true,
            'task' => $this->formatTask($task->fresh(['owner:id,name,email', 'activeBlocker', 'comments.author:id,name'])),
        ]);
    }

    /**
     * PATCH /api/manager/tasks/{id}/deadline
     * Change deadline for a direct report's task.
     */
    public function updateDeadline(Request $request, int $id): JsonResponse
    {
        if ($deny = $this->authorizeManager($request)) {
            return $deny;
        }

        $task = $this->findDirectReportTask($request, $id);
        if ($task instanceof JsonResponse) {
            return $task;
        }

        $validated = $request->validate([
            'due_date' => ['required', 'date_format:Y-m-d'],
        ]);

        $user = $request->user();
        $previousDueDate = $task->due_date ? Carbon::parse($task->due_date)->format('Y-m-d') : null;

        $task->due_date = $validated['due_date'];
        $task->save();

        TaskEvent::create([
            'task_id'    => $task->id,
            'event_type' => 'DEADLINE_CHANGED',
            'actor_type' => 'user',
            'actor_id'   => $user->id,
            'metadata'   => [
                'changed_by'        => $user->id,
                'previous_due_date' => $previousDueDate,
                'new_due_date'      => $validated['due_date'],
            ],
            'created_at' => now(),
        ]);

        return response()->json([
            'ok'   => true,
            'task' => $this->formatTask($task->fresh(['owner:id,name,email', 'activeBlocker', 'comments.author:id,name'])),
        ]);
    }

    /**
     * POST /api/manager/tasks/{id}/comment
     * Add a manager comment to a direct report's task.
     */
    public function comment(Request $request, int $id): JsonResponse
    {
        if ($deny = $this->authorizeManager($request)) {
            return $deny;
        }

        $task = $this->findDirectReportTask($request, $id);
        if ($task instanceof JsonResponse) {
            return $task;
        }

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $user = $request->user();

        $comment = TaskComment::create([
            'task_id'    => $task->id,
            'user_id'    => $user->id,
            'body'       => $validated['body'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        TaskEvent::create([
            'task_id'    => $task->id,
            'event_type' => 'COMMENTED',
            'actor_type' => 'user',
            'actor_id'   => $user->id,
            'metadata'   => ['comment_id' => $comment->id],
            'created_at' => now(),
        ]);

        return response()->json([
            'ok'      => true,
            'comment' => [
                'id'          => $comment->id,
                'body'        => $comment->body,
                'author_name' => $user->name,
                'created_at'  => $comment->created_at->toIso8601String(),
            ],
        ]);
    }

    /**
     * POST /api/manager/tasks/{id}/resolve-blocker
     * Resolve an active blocker on a direct report's task.
     * Restores task to its previous_status and preserves the original employee owner.
     */
    public function resolveBlocker(Request $request, int $id, TaskStateMachine $sm): JsonResponse
    {
        if ($deny = $this->authorizeManager($request)) {
            return $deny;
        }

        $task = $this->findDirectReportTask($request, $id);
        if ($task instanceof JsonResponse) {
            return $task;
        }

        $user = $request->user();

        try {
            $sm->unblock($task, $user->id, 'user', 'manager');
        } catch (\Throwable $e) {
            return ApiResponse::error('INVALID_TRANSITION', $e->getMessage(), 422);
        }

        return response()->json([
            'ok'   => true,
            'task' => $this->formatTask($task->fresh(['owner:id,name,email', 'activeBlocker', 'comments.author:id,name'])),
        ]);
    }

    /**
     * POST /api/manager/tasks/{id}/escalate
     * Manually escalate a direct report's task.
     */
    public function escalate(Request $request, int $id, TaskStateMachine $sm): JsonResponse
    {
        if ($deny = $this->authorizeManager($request)) {
            return $deny;
        }

        $task = $this->findDirectReportTask($request, $id);
        if ($task instanceof JsonResponse) {
            return $task;
        }

        $validated = $request->validate([
            'escalation_level' => ['nullable', 'integer', 'min:1', 'max:5'],
        ]);

        $user = $request->user();
        $targetLevel = $validated['escalation_level'] ?? (($task->escalation_level ?: 0) + 1);

        try {
            $sm->escalate($task, $targetLevel, $user->id, 'user');
        } catch (\Throwable $e) {
            return ApiResponse::error('INVALID_TRANSITION', $e->getMessage(), 422);
        }

        return response()->json([
            'ok'   => true,
            'task' => $this->formatTask($task->fresh(['owner:id,name,email', 'activeBlocker', 'comments.author:id,name'])),
        ]);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * Find a task and verify that it belongs to one of the manager's direct reports.
     * Returns 404 (never 403) to prevent confirming another tenant's or non-report's record.
     */
    protected function findDirectReportTask(Request $request, int $id): Task|JsonResponse
    {
        $user = $request->user();

        $task = Task::withoutGlobalScopes()
            ->where('id', $id)
            ->where('org_id', $user->org_id)
            ->first();

        if (!$task) {
            return ApiResponse::error('NOT_FOUND', 'Task not found.', 404);
        }

        // Direct reports only: users.manager_id = me
        $isDirectReport = User::withoutGlobalScopes()
            ->where('org_id', $user->org_id)
            ->where('manager_id', $user->id)
            ->where('id', $task->owner_id)
            ->exists();

        if (!$isDirectReport) {
            return ApiResponse::error('NOT_FOUND', 'Task not found.', 404);
        }

        return $task;
    }

    /**
     * Format a task for manager dashboard responses.
     */
    protected function formatTask(Task $task, ?string $orgTz = null, ?Carbon $today = null): array
    {
        if (!$today) {
            $orgTz = $orgTz ?: config('app.timezone', 'Asia/Karachi');
            $today = Carbon::now($orgTz)->startOfDay();
        }

        $dueDate = $task->due_date ? Carbon::parse($task->due_date) : null;
        $bucket = 'upcoming';

        if ($dueDate) {
            if ($dueDate->lt($today)) {
                $bucket = 'overdue';
            } elseif ($dueDate->isSameDay($today)) {
                $bucket = 'due_today';
            }
        }

        $activeBlocker = $task->activeBlocker;

        return [
            'id'               => $task->id,
            'title'            => $task->title,
            'description'      => $task->description,
            'priority'         => $task->priority,
            'status'           => $task->status,
            'previous_status'  => $task->previous_status,
            'due_date'         => $dueDate?->format('Y-m-d'),
            'bucket'           => $bucket,
            'escalation_level' => (int) $task->escalation_level,
            'owner'            => $task->owner ? [
                'id'    => $task->owner->id,
                'name'  => $task->owner->name,
                'email' => $task->owner->email,
            ] : null,
            'blocker'          => $activeBlocker ? [
                'id'          => $activeBlocker->id,
                'reason_code' => $activeBlocker->reason_code,
                'description' => $activeBlocker->description,
                'created_at'  => $activeBlocker->created_at?->toIso8601String(),
            ] : null,
            'comments'         => ($task->relationLoaded('comments') ? $task->comments : collect())->map(fn($c) => [
                'id'          => $c->id,
                'body'        => $c->body,
                'author_name' => $c->author?->name ?? 'User',
                'created_at'  => $c->created_at?->toIso8601String(),
            ])->values()->all(),
            'meeting_id'       => $task->meeting_id,
            'created_at'       => $task->created_at?->toIso8601String(),
        ];
    }
}
