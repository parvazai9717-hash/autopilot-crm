<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskEvent;
use App\Services\TaskStateMachine;
use App\Services\WebhookService;
use App\Support\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeTaskController extends Controller
{
    /**
     * GET /api/my-tasks
     * Returns the authenticated employee's assigned tasks, grouped by urgency bucket.
     * Employees see only their own tasks (enforced by policy + explicit owner_id filter).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $orgTz = $user->organization?->timezone ?: config('app.timezone', 'Asia/Karachi');
        $today = Carbon::now($orgTz)->startOfDay();

        $tasks = Task::withoutGlobalScopes()
            ->where('org_id', $user->org_id)
            ->where('owner_id', $user->id)
            ->whereNotIn('status', [
                TaskStateMachine::STATUS_REJECTED,
                TaskStateMachine::STATUS_DETECTED,
                TaskStateMachine::STATUS_PENDING_APPROVAL,
            ])
            ->with(['activeBlocker', 'comments.author'])
            ->orderBy('due_date', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $overdue   = [];
        $dueToday  = [];
        $upcoming  = [];

        foreach ($tasks as $task) {
            $formatted = $this->formatTask($task, $orgTz, $today);

            if ($formatted['bucket'] === 'overdue') {
                $overdue[] = $formatted;
            } elseif ($formatted['bucket'] === 'due_today') {
                $dueToday[] = $formatted;
            } else {
                $upcoming[] = $formatted;
            }
        }

        // Fetch open tasks in the org (for the "waiting on" dropdown in blocker modal)
        $openTasks = Task::withoutGlobalScopes()
            ->where('org_id', $user->org_id)
            ->whereNotIn('status', [
                TaskStateMachine::STATUS_REJECTED,
                TaskStateMachine::STATUS_DETECTED,
                TaskStateMachine::STATUS_PENDING_APPROVAL,
                TaskStateMachine::STATUS_COMPLETED,
                TaskStateMachine::STATUS_BLOCKED,
            ])
            ->where('id', '!=', null) // guard
            ->select('id', 'title', 'owner_id')
            ->with('owner:id,name')
            ->orderBy('due_date', 'asc')
            ->limit(200)
            ->get()
            ->map(fn(Task $t) => [
                'id'         => $t->id,
                'title'      => $t->title,
                'owner_name' => $t->owner?->name,
            ]);

        return response()->json([
            'overdue'    => $overdue,
            'due_today'  => $dueToday,
            'upcoming'   => $upcoming,
            'open_tasks' => $openTasks,
        ]);
    }

    /**
     * POST /api/my-tasks/{id}/start
     * Transitions task to in_progress. Employee must be the owner.
     */
    public function start(Request $request, int $id, TaskStateMachine $sm): JsonResponse
    {
        $task = $this->findOwnedTask($request, $id);
        if ($task instanceof JsonResponse) {
            return $task;
        }

        try {
            $sm->start($task, $request->user()->id, 'user');
        } catch (\Throwable $e) {
            return ApiResponse::error('INVALID_TRANSITION', $e->getMessage(), 422);
        }

        return response()->json(['ok' => true, 'task' => $this->formatTask($task->fresh(['activeBlocker', 'comments.author']))]);
    }

    /**
     * POST /api/my-tasks/{id}/complete
     * Transitions task to completed. Employee must be the owner.
     */
    public function complete(Request $request, int $id, TaskStateMachine $sm): JsonResponse
    {
        $task = $this->findOwnedTask($request, $id);
        if ($task instanceof JsonResponse) {
            return $task;
        }

        try {
            $sm->complete($task, $request->user()->id, 'user');
        } catch (\Throwable $e) {
            return ApiResponse::error('INVALID_TRANSITION', $e->getMessage(), 422);
        }

        return response()->json(['ok' => true, 'task' => $this->formatTask($task->fresh(['activeBlocker', 'comments.author']))]);
    }

    /**
     * POST /api/my-tasks/{id}/block
     * Marks a task blocked. Reason dropdown required; waiting-on-task optional.
     * Fires task.blocked webhook on success.
     */
    public function block(Request $request, int $id, TaskStateMachine $sm, WebhookService $webhookService): JsonResponse
    {
        $task = $this->findOwnedTask($request, $id);
        if ($task instanceof JsonResponse) {
            return $task;
        }

        $validated = $request->validate([
            'reason_code'        => ['required', 'string', 'in:waiting_for_person,waiting_for_info,waiting_for_approval,technical_issue,unclear_requirement,other'],
            'description'        => ['nullable', 'string', 'max:1000'],
            'depends_on_task_id' => ['nullable', 'integer', 'exists:tasks,id'],
        ]);

        try {
            $sm->block(
                $task,
                $request->user()->id,
                'user',
                $validated['reason_code'],
                $validated['description'] ?? null,
                $validated['depends_on_task_id'] ?? null
            );
        } catch (\Throwable $e) {
            return ApiResponse::error('INVALID_TRANSITION', $e->getMessage(), 422);
        }

        // Fire task.blocked webhook
        try {
            $user = $request->user();
            $webhookService->dispatchTaskBlocked(
                $task->org_id,
                $task->id,
                $task->title,
                $validated['reason_code'],
                $validated['description'] ?? null,
                $user->id,
                $validated['depends_on_task_id'] ?? null,
                $user->manager?->email
            );
        } catch (\Throwable) {
            // Non-fatal — webhook delivery failures are logged by the job, never bubble up
        }

        return response()->json(['ok' => true, 'task' => $this->formatTask($task->fresh(['activeBlocker', 'comments.author']))]);
    }

    /**
     * POST /api/my-tasks/{id}/unblock
     * Unblocks a task. Restores previous_status, resolves task_blockers, writes UNBLOCKED event.
     * Employee must be the owner.
     */
    public function unblock(Request $request, int $id, TaskStateMachine $sm): JsonResponse
    {
        $task = $this->findOwnedTask($request, $id);
        if ($task instanceof JsonResponse) {
            return $task;
        }

        try {
            $sm->unblock($task, $request->user()->id, 'user');
        } catch (\Throwable $e) {
            return ApiResponse::error('INVALID_TRANSITION', $e->getMessage(), 422);
        }

        return response()->json(['ok' => true, 'task' => $this->formatTask($task->fresh(['activeBlocker', 'comments.author']))]);
    }

    /**
     * POST /api/my-tasks/{id}/comment
     * Adds a comment to the task. Employee must be the owner.
     */
    public function comment(Request $request, int $id): JsonResponse
    {
        $task = $this->findOwnedTask($request, $id);
        if ($task instanceof JsonResponse) {
            return $task;
        }

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $comment = TaskComment::create([
            'task_id'    => $task->id,
            'user_id'    => $request->user()->id,
            'body'       => $validated['body'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        TaskEvent::create([
            'task_id'    => $task->id,
            'event_type' => 'COMMENTED',
            'actor_type' => 'user',
            'actor_id'   => $request->user()->id,
            'metadata'   => ['comment_id' => $comment->id],
            'created_at' => now(),
        ]);

        return response()->json([
            'ok'      => true,
            'comment' => [
                'id'          => $comment->id,
                'body'        => $comment->body,
                'author_name' => $request->user()->name,
                'created_at'  => $comment->created_at->toIso8601String(),
            ],
        ]);
    }

    /**
     * POST /api/my-tasks/{id}/attach
     * Attaches a file to the task. Stored in storage/app/task-attachments/.
     */
    public function attach(Request $request, int $id): JsonResponse
    {
        $task = $this->findOwnedTask($request, $id);
        if ($task instanceof JsonResponse) {
            return $task;
        }

        $request->validate([
            'file' => ['required', 'file', 'max:20480'], // 20 MB
        ]);

        $uploadedFile = $request->file('file');
        $path = $uploadedFile->store('task-attachments', 'local');

        TaskEvent::create([
            'task_id'    => $task->id,
            'event_type' => 'ATTACHMENT_ADDED',
            'actor_type' => 'user',
            'actor_id'   => $request->user()->id,
            'metadata'   => [
                'filename'  => $uploadedFile->getClientOriginalName(),
                'path'      => $path,
                'mime_type' => $uploadedFile->getMimeType(),
                'size'      => $uploadedFile->getSize(),
            ],
            'created_at' => now(),
        ]);

        return response()->json([
            'ok'       => true,
            'filename' => $uploadedFile->getClientOriginalName(),
            'path'     => $path,
        ]);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * Load a task and verify the authenticated user is the owner.
     * Returns JsonResponse (404/403) on failure, Task on success.
     */
    private function findOwnedTask(Request $request, int $id): Task|JsonResponse
    {
        $user = $request->user();

        $task = Task::withoutGlobalScopes()
            ->where('id', $id)
            ->where('org_id', $user->org_id)
            ->first();

        if (!$task) {
            return ApiResponse::error('NOT_FOUND', 'Task not found.', 404);
        }

        if ($task->owner_id !== $user->id) {
            // Return 404 not 403 to avoid confirming another owner's task exists
            return ApiResponse::error('NOT_FOUND', 'Task not found.', 404);
        }

        return $task;
    }

    /**
     * Format a task with bucket classification for the My Tasks response.
     */
    private function formatTask(Task $task, ?string $orgTz = null, ?Carbon $today = null): array
    {
        if (!$today) {
            $orgTz = $orgTz ?: config('app.timezone', 'Asia/Karachi');
            $today = Carbon::now($orgTz)->startOfDay();
        }

        $dueDate  = $task->due_date ? Carbon::parse($task->due_date) : null;
        $bucket   = 'upcoming';

        if ($dueDate) {
            if ($dueDate->lt($today)) {
                $bucket = 'overdue';
            } elseif ($dueDate->isSameDay($today)) {
                $bucket = 'due_today';
            }
        }

        $activeBlocker = $task->activeBlocker;

        return [
            'id'             => $task->id,
            'title'          => $task->title,
            'description'    => $task->description,
            'priority'       => $task->priority,
            'status'         => $task->status,
            'due_date'       => $dueDate?->format('Y-m-d'),
            'bucket'         => $bucket,
            'escalation_level' => (int) $task->escalation_level,
            'blocker'        => $activeBlocker ? [
                'id'          => $activeBlocker->id,
                'reason_code' => $activeBlocker->reason_code,
                'description' => $activeBlocker->description,
            ] : null,
            'comments'       => ($task->relationLoaded('comments') ? $task->comments : collect())->map(fn($c) => [
                'id'          => $c->id,
                'body'        => $c->body,
                'author_name' => $c->author?->name ?? 'Unknown',
                'created_at'  => $c->created_at?->toIso8601String(),
            ])->values()->all(),
            'meeting_id'     => $task->meeting_id,
            'created_at'     => $task->created_at?->toIso8601String(),
        ];
    }
}
