<?php

namespace App\Http\Controllers\Internal\V1;

use App\Http\Controllers\Controller;
use App\Models\Meeting;
use App\Models\Organization;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RunController extends Controller
{
    /**
     * POST /internal/v1/runs/claim
     * Atomic claim of an ingestion/processing run to prevent duplicate n8n execution.
     */
    public function claim(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'meeting_id' => ['required', 'integer'],
            'run_id'     => ['nullable', 'string', 'max:128'],
            'timestamp'  => ['nullable', 'integer'],
        ]);

        $meetingId = (int) $validated['meeting_id'];
        $runId = $validated['run_id'] ?? (string) Str::uuid();

        // Optional timestamp skew check (within 5 minutes)
        if (!empty($validated['timestamp'])) {
            $skew = abs(time() - (int) $validated['timestamp']);
            if ($skew > 300) {
                return response()->json([
                    'status'  => 'rejected',
                    'message' => 'Request timestamp skew exceeded 300 seconds.',
                ], 422);
            }
        }

        return DB::transaction(function () use ($meetingId, $runId) {
            /** @var Meeting|null $meeting */
            $meeting = Meeting::withoutGlobalScopes()
                ->lockForUpdate()
                ->find($meetingId);

            if (!$meeting) {
                return response()->json([
                    'status'  => 'not_found',
                    'message' => "Meeting {$meetingId} not found.",
                ], 404);
            }

            // Check if already being processed or already extracted
            $timeoutMinutes = config('autopilot.meeting_processing_timeout', 30);
            $isTimedOut = $meeting->status === 'processing' 
                && $meeting->processing_started_at 
                && $meeting->processing_started_at->diffInMinutes(now()) > $timeoutMinutes;

            if (in_array($meeting->status, ['processing', 'extracted', 'reviewed']) && !$isTimedOut) {
                return response()->json([
                    'status'         => 'duplicate',
                    'run_id'         => $runId,
                    'meeting_id'     => $meeting->id,
                    'current_status' => $meeting->status,
                    'message'        => 'Meeting is already claimed or processed.',
                ], 409);
            }

            // Atomically claim the run
            $meeting->status = 'processing';
            $meeting->processing_started_at = now();
            $meeting->error_message = null;
            $meeting->save();

            $runToken = hash_hmac('sha256', "{$runId}:{$meeting->id}", (string) config('app.key'));

            /** @var Organization|null $org */
            $org = Organization::withoutGlobalScopes()->find($meeting->org_id);

            return response()->json([
                'status'       => 'claimed',
                'run_id'       => $runId,
                'run_token'    => $runToken,
                'meeting_id'   => $meeting->id,
                'org_id'       => $meeting->org_id,
                'org_timezone' => $org?->timezone ?? $meeting->timezone,
            ], 200);
        });
    }
}
