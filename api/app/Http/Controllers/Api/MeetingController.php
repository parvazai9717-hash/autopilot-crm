<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessMeetingAudio;
use App\Models\Meeting;
use App\Models\Organization;
use App\Services\WebhookService;
use App\Support\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeetingController extends Controller
{
    /**
     * POST /api/meetings
     * Browser-facing endpoint for admins to create a meeting from pasted transcript.
     */
    public function store(Request $request, WebhookService $webhookService): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->isAdmin()) {
            return ApiResponse::error(
                'FORBIDDEN',
                'Only administrators can create meetings.',
                403
            );
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'meeting_date' => ['nullable', 'date_format:Y-m-d'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'transcript' => ['required', 'string'],
            'summary' => ['nullable', 'string'],
            'timezone' => ['nullable', 'string', 'max:50'],
        ]);

        $org = Organization::find($user->org_id);
        $meetingDate = $validated['meeting_date'] ?? $validated['date'] ?? now()->toDateString();
        $timezone = $validated['timezone'] ?? $org?->timezone ?? config('app.timezone', 'Asia/Karachi');

        $meeting = Meeting::withoutGlobalScopes()->create([
            'org_id' => $user->org_id,
            'title' => $validated['title'],
            'meeting_date' => $meetingDate,
            'timezone' => $timezone,
            'source' => 'text',
            'created_by' => $user->id,
            'transcript' => $validated['transcript'],
            'summary' => $validated['summary'] ?? null,
            'status' => 'processing',
            'processing_started_at' => now(),
        ]);

        // Dispatch meeting.uploaded webhook with transcript payload so n8n or automation can extract action items
        $webhookService->dispatchMeetingUploaded(
            orgId: $meeting->org_id,
            meetingId: $meeting->id,
            title: $meeting->title,
            meetingDate: $meetingDate,
            timezone: $timezone,
            source: 'text',
            mediaOrTranscript: $meeting->transcript
        );

        return response()->json([
            'meeting_id' => $meeting->id,
            'title' => $meeting->title,
            'status' => 'processing',
            'meeting_date' => $meetingDate,
            'source' => 'text',
        ], 201);
    }

    /**
     * POST /api/meetings/upload
     * Browser-facing endpoint for admins to upload an audio/video meeting recording.
     */
    public function upload(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->isAdmin()) {
            return ApiResponse::error(
                'FORBIDDEN',
                'Only administrators can upload meeting recordings.',
                403
            );
        }

        $maxMb = (int) config('autopilot.audio.max_upload_mb', 500);
        $maxKb = $maxMb * 1024;

        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                "max:{$maxKb}",
                'mimes:mp3,m4a,wav,mp4,webm,ogg',
            ],
            'title' => ['required', 'string', 'max:255'],
            'meeting_date' => ['nullable', 'date_format:Y-m-d'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'timezone' => ['nullable', 'string', 'max:50'],
        ]);

        $org = Organization::find($user->org_id);
        $meetingDate = $validated['meeting_date'] ?? $validated['date'] ?? now()->toDateString();
        $timezone = $validated['timezone'] ?? $org?->timezone ?? config('app.timezone', 'Asia/Karachi');

        // Store original uploaded file in storage/app/meetings/original/
        $uploadedFile = $request->file('file');
        $storedPath = $uploadedFile->store('meetings/original', 'local');

        $meeting = Meeting::withoutGlobalScopes()->create([
            'org_id' => $user->org_id,
            'title' => $validated['title'],
            'meeting_date' => $meetingDate,
            'timezone' => $timezone,
            'source' => 'upload',
            'created_by' => $user->id,
            'audio_path' => $storedPath,
            'status' => 'uploaded',
        ]);

        // Dispatch background audio processing job
        ProcessMeetingAudio::dispatch($meeting->id);

        return response()->json([
            'meeting_id' => $meeting->id,
            'title' => $meeting->title,
            'status' => 'uploaded',
            'meeting_date' => $meetingDate,
            'source' => 'upload',
        ], 201);
    }

    /**
     * GET /api/meetings/{id}/status
     * Polling endpoint to check live processing status of a meeting.
     */
    public function status(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $meeting = Meeting::withoutGlobalScopes()
            ->where('id', $id)
            ->where('org_id', $user->org_id)
            ->withCount('tasks')
            ->first();

        if (!$meeting) {
            return ApiResponse::error(
                'NOT_FOUND',
                'Meeting not found.',
                404
            );
        }

        return response()->json([
            'id' => $meeting->id,
            'title' => $meeting->title,
            'status' => $meeting->status,
            'source' => $meeting->source,
            'task_count' => $meeting->tasks_count,
            'error_message' => $meeting->error_message,
            'meeting_date' => $meeting->meeting_date ? Carbon::parse($meeting->meeting_date)->format('Y-m-d') : null,
            'processing_started_at' => $meeting->processing_started_at?->toIso8601String(),
            'audio_duration_seconds' => $meeting->audio_duration_seconds,
        ]);
    }

    /**
     * POST /api/meetings/{id}/retry
     * Retries audio processing for a failed meeting.
     */
    public function retry(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        if (!$user || !$user->isAdmin()) {
            return ApiResponse::error(
                'FORBIDDEN',
                'Only administrators can retry meeting processing.',
                403
            );
        }

        $meeting = Meeting::withoutGlobalScopes()
            ->where('id', $id)
            ->where('org_id', $user->org_id)
            ->first();

        if (!$meeting) {
            return ApiResponse::error(
                'NOT_FOUND',
                'Meeting not found.',
                404
            );
        }

        if (empty($meeting->audio_path)) {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'Meeting does not have an audio file to re-process.',
                422
            );
        }

        $meeting->update([
            'status' => 'uploaded',
            'error_message' => null,
            'processing_started_at' => null,
        ]);

        ProcessMeetingAudio::dispatch($meeting->id);

        return response()->json([
            'ok' => true,
            'meeting_id' => $meeting->id,
            'status' => 'uploaded',
        ]);
    }
}
