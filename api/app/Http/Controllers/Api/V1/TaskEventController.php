<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Support\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskEventController extends Controller
{
    /**
     * POST /api/v1/task-events
     * Records task event from automation layer.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'task_id' => ['required', 'integer', 'exists:tasks,id'],
            'event_type' => ['required', 'string', 'max:50'],
            'actor_type' => ['nullable', 'string', 'in:user,ai,system'],
            'actor_id' => ['nullable', 'integer', 'exists:users,id'],
            'channel' => ['nullable', 'string', 'max:50'],
            'metadata' => ['nullable', 'array'],
        ]);

        $task = Task::withoutGlobalScopes()->find($validated['task_id']);

        if (!$task) {
            return ApiResponse::error(
                'NOT_FOUND',
                'Task not found.',
                404,
                'task_id'
            );
        }

        $eventType = strtoupper($validated['event_type']);
        $metadata = $validated['metadata'] ?? [];

        if (!empty($validated['channel'])) {
            $metadata['channel'] = $validated['channel'];
        }

        // When event_type = REMINDER_SENT, set tasks.last_reminder_at = today (in org timezone)
        if ($eventType === 'REMINDER_SENT') {
            $org = Organization::find($task->org_id);
            $orgTz = $org?->timezone ?: config('app.timezone', 'Asia/Karachi');
            $today = Carbon::now($orgTz)->toDateString();

            $task->update(['last_reminder_at' => $today]);
        }

        TaskEvent::create([
            'task_id' => $task->id,
            'event_type' => $eventType,
            'actor_type' => $validated['actor_type'] ?? 'system',
            'actor_id' => $validated['actor_id'] ?? null,
            'metadata' => !empty($metadata) ? $metadata : null,
            'created_at' => now(),
        ]);

        return response()->json([
            'ok' => true,
        ]);
    }
}
