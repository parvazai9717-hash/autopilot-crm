<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessMeetingAudio;
use App\Models\Meeting;
use App\Models\Organization;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MeetingController extends Controller
{
    /**
     * POST /api/v1/meetings
     * Creates a meeting record from machine/text input.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'org_id' => ['required', 'integer', 'exists:organizations,id'],
            'title' => ['required', 'string', 'max:255'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'meeting_date' => ['nullable', 'date_format:Y-m-d'],
            'transcript' => ['nullable', 'string'],
            'summary' => ['nullable', 'string'],
            'source' => ['nullable', 'string'],
            'created_by' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $meetingDate = $validated['meeting_date'] ?? $validated['date'] ?? now()->toDateString();
        $org = Organization::find($validated['org_id']);

        // Default creator to first admin in org if not provided
        $createdBy = $validated['created_by'] ?? User::withoutGlobalScopes()
            ->where('org_id', $validated['org_id'])
            ->where('role', 'admin')
            ->value('id') ?? 1;

        $meeting = Meeting::withoutGlobalScopes()->create([
            'org_id' => $validated['org_id'],
            'title' => $validated['title'],
            'meeting_date' => $meetingDate,
            'timezone' => $org?->timezone ?? config('app.timezone', 'Asia/Karachi'),
            'source' => $validated['source'] ?? 'text',
            'created_by' => $createdBy,
            'transcript' => $validated['transcript'] ?? null,
            'summary' => $validated['summary'] ?? null,
            'status' => 'processing',
            'processing_started_at' => now(),
        ]);

        return response()->json([
            'meeting_id' => $meeting->id,
        ], 201);
    }

    /**
     * POST /api/v1/meetings/upload
     * Accepts meeting audio/video file upload and dispatches asynchronous conversion.
     */
    public function upload(Request $request): JsonResponse
    {
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
            'org_id' => ['nullable', 'integer', 'exists:organizations,id'],
            'timezone' => ['nullable', 'string', 'max:50'],
            'created_by' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $orgId = $validated['org_id'] ?? 1;
        $org = Organization::find($orgId);
        $meetingDate = $validated['meeting_date'] ?? $validated['date'] ?? now()->toDateString();
        $timezone = $validated['timezone'] ?? $org?->timezone ?? config('app.timezone', 'Asia/Karachi');

        $createdBy = $validated['created_by'] ?? User::withoutGlobalScopes()
            ->where('org_id', $orgId)
            ->where('role', 'admin')
            ->value('id') ?? 1;

        // Store original uploaded file in storage/app/meetings/original/
        $uploadedFile = $request->file('file');
        $storedPath = $uploadedFile->store('meetings/original', 'local');

        $meeting = Meeting::withoutGlobalScopes()->create([
            'org_id' => $orgId,
            'title' => $validated['title'],
            'meeting_date' => $meetingDate,
            'timezone' => $timezone,
            'source' => 'upload',
            'created_by' => $createdBy,
            'audio_path' => $storedPath,
            'status' => 'uploaded',
        ]);

        // Dispatch background processing job
        ProcessMeetingAudio::dispatch($meeting->id);

        return response()->json([
            'meeting_id' => $meeting->id,
            'status' => 'uploaded',
        ], 201);
    }

    /**
     * GET /api/v1/meetings/{id}/audio
     * Public signed expiring URL endpoint for downloading processed audio file or chunks.
     */
    public function downloadAudio(Request $request, int $id): BinaryFileResponse|JsonResponse
    {
        $meeting = Meeting::withoutGlobalScopes()->find($id);

        if (!$meeting) {
            return ApiResponse::error(
                'NOT_FOUND',
                'Meeting not found.',
                404
            );
        }

        $filename = $request->query('file');
        if ($filename) {
            $sanitizedFilename = basename($filename);
            $filePath = storage_path("app/meetings/processed/{$sanitizedFilename}");
        } else {
            $filePath = storage_path('app/' . ltrim((string) $meeting->audio_path, '/\\'));
        }

        if (!file_exists($filePath)) {
            return ApiResponse::error(
                'NOT_FOUND',
                'Audio file not found on server.',
                404
            );
        }

        return response()->file($filePath, [
            'Content-Type' => 'audio/mpeg',
            'Content-Disposition' => 'inline; filename="' . basename($filePath) . '"',
        ]);
    }

    /**
     * POST /api/v1/meetings/{id}/retry
     * Resets a failed meeting and re-dispatches the audio processing job.
     * Requires org_id in the request body and verifies ownership (404 on mismatch).
     */
    public function retry(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'org_id' => ['required', 'integer'],
        ]);

        $meeting = Meeting::withoutGlobalScopes()
            ->where('id', $id)
            ->where('org_id', $validated['org_id'])
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

    /**
     * PATCH /api/v1/meetings/{id}
     * Updates meeting transcript, summary, or status from n8n automation.
     * Requires org_id in the request body and verifies ownership (404 on mismatch).
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'org_id'     => ['required', 'integer'],
            'transcript' => ['nullable', 'string'],
            'summary'    => ['nullable', 'string'],
            'status'     => ['nullable', 'string', 'in:uploaded,processing,extracted,reviewed,failed'],
        ]);

        $meeting = Meeting::withoutGlobalScopes()
            ->where('id', $id)
            ->where('org_id', $validated['org_id'])
            ->first();

        if (!$meeting) {
            return ApiResponse::error(
                'NOT_FOUND',
                'Meeting not found.',
                404
            );
        }

        $fields = array_filter(
            array_intersect_key($validated, array_flip(['transcript', 'summary', 'status'])),
            fn ($val) => !is_null($val)
        );

        $meeting->update($fields);

        return response()->json([
            'ok' => true,
        ]);
    }
}
