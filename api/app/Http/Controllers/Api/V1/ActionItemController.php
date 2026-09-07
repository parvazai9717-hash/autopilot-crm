<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Meeting;
use App\Models\Organization;
use App\Models\Task;
use App\Models\TaskEvent;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ActionItemController extends Controller
{
    /**
     * POST /api/v1/action-items
     * Ingests AI-extracted action items from n8n.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'org_id' => ['required', 'integer', 'exists:organizations,id'],
            'meeting_id' => ['required', 'integer', 'exists:meetings,id'],
            'action_items' => ['required', 'array', 'min:1'],
            'action_items.*.title' => ['required', 'string', 'max:255'],
            'action_items.*.description' => ['nullable', 'string'],
            'action_items.*.owner_id' => ['nullable'],
            'action_items.*.owner_name_raw' => ['nullable', 'string', 'max:255'],
            'action_items.*.owner_ambiguous' => ['nullable', 'boolean'],
            'action_items.*.due_date' => ['nullable', 'date_format:Y-m-d'],
            'action_items.*.deadline_phrase' => ['nullable', 'string', 'max:255'],
            'action_items.*.priority' => ['nullable', 'string', 'in:high,medium,low'],
            'action_items.*.conditional' => ['nullable', 'boolean'],
            'action_items.*.source_text' => ['nullable', 'string'],
            'action_items.*.action_confidence' => ['nullable', 'numeric'],
            'action_items.*.owner_confidence' => ['nullable', 'numeric'],
            'action_items.*.deadline_confidence' => ['nullable', 'numeric'],
            'action_items.*.status' => ['nullable', 'string'],
            'transcript' => ['nullable', 'string'],
            'summary'    => ['nullable', 'string'],
        ]);

        $orgId = (int) $validated['org_id'];
        $meetingId = (int) $validated['meeting_id'];

        $meeting = Meeting::withoutGlobalScopes()->find($meetingId);
        if (!$meeting || $meeting->org_id !== $orgId) {
            return ApiResponse::error(
                'NOT_FOUND',
                'Meeting not found in this organization.',
                404,
                'meeting_id'
            );
        }

        $createdTaskIds = [];

        DB::transaction(function () use ($request, $validated, $orgId, $meeting, &$createdTaskIds) {
            $meeting->update([
                'transcript' => $request->input('transcript') ?: $meeting->transcript,
                'summary'    => $request->input('summary')    ?: $meeting->summary,
            ]);

            foreach ($validated['action_items'] as $item) {

                // Parse owner_id
                $ownerAmbiguous = (bool) ($item['owner_ambiguous'] ?? false);
                $resolvedOwnerId = null;

                if (!$ownerAmbiguous && !empty($item['owner_id'])) {
                    $rawId = (string) $item['owner_id'];
                    $cleanedId = (int) preg_replace('/[^0-9]/', '', $rawId);

                    if ($cleanedId > 0) {
                        $userExists = User::withoutGlobalScopes()
                            ->where('org_id', $orgId)
                            ->where('id', $cleanedId)
                            ->exists();

                        if ($userExists) {
                            $resolvedOwnerId = $cleanedId;
                        }
                    }
                }

                $task = Task::withoutGlobalScopes()->create([
                    'org_id' => $orgId,
                    'meeting_id' => $meeting->id,
                    'title' => $item['title'],
                    'description' => $item['description'] ?? null,
                    'owner_id' => $resolvedOwnerId,
                    'owner_name_raw' => $item['owner_name_raw'] ?? null,
                    'owner_ambiguous' => $ownerAmbiguous,
                    'created_by' => $meeting->created_by,
                    'priority' => $item['priority'] ?? 'medium',
                    'status' => 'pending_approval',
                    'due_date' => $item['due_date'] ?? null,
                    'deadline_phrase' => $item['deadline_phrase'] ?? null,
                    'source_text' => $item['source_text'] ?? null,
                    'conditional' => (bool) ($item['conditional'] ?? false),
                    'action_confidence' => $item['action_confidence'] ?? null,
                    'owner_confidence' => $item['owner_confidence'] ?? null,
                    'deadline_confidence' => $item['deadline_confidence'] ?? null,
                    'escalation_level' => 0,
                ]);

                // Create AI_DETECTED event
                TaskEvent::create([
                    'task_id' => $task->id,
                    'event_type' => 'AI_DETECTED',
                    'actor_type' => 'ai',
                    'actor_id' => null,
                    'metadata' => [
                        'source_text' => $task->source_text,
                        'confidence' => [
                            'action' => $task->action_confidence,
                            'owner' => $task->owner_confidence,
                            'deadline' => $task->deadline_confidence,
                        ],
                    ],
                    'created_at' => now(),
                ]);

                $createdTaskIds[] = $task->id;
            }

            // Update meeting status to extracted
            $meeting->update(['status' => 'extracted']);
        });

        return response()->json([
            'created' => count($createdTaskIds),
            'task_ids' => $createdTaskIds,
        ], 201);
    }
}
