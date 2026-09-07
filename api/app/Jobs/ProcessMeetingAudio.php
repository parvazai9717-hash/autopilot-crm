<?php

namespace App\Jobs;

use App\Models\Meeting;
use App\Services\AudioProcessingService;
use App\Services\WebhookService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessMeetingAudio implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 3;

    public function __construct(
        public int $meetingId
    ) {}

    public function handle(
        AudioProcessingService $audioService,
        WebhookService $webhookService
    ): void {
        $meeting = Meeting::withoutGlobalScopes()->find($this->meetingId);

        if (!$meeting) {
            Log::error("ProcessMeetingAudio: Meeting {$this->meetingId} not found.");
            return;
        }

        // Set status to processing
        $meeting->update([
            'status' => 'processing',
            'processing_started_at' => now(),
            'error_message' => null,
        ]);

        try {
            $originalRelativePath = $meeting->audio_path;

            if (empty($originalRelativePath)) {
                throw new \Exception("Meeting has no original audio file attached.");
            }

            // Resolve full disk path
            $inputPath = Storage::disk('local')->exists($originalRelativePath)
                ? Storage::disk('local')->path($originalRelativePath)
                : storage_path('app/' . ltrim($originalRelativePath, '/\\'));

            if (!file_exists($inputPath)) {
                throw new \Exception("Original audio file not found at path: {$inputPath}");
            }

            $processedRelativeDir = 'meetings/processed';
            $processedFileName = "meeting_{$meeting->id}.mp3";
            $processedOutputPath = storage_path("app/{$processedRelativeDir}/{$processedFileName}");

            // Step 1: Convert audio to mono MP3 at 32kbps 16kHz
            $audioService->convertToMonoMp3($inputPath, $processedOutputPath);

            // Step 2: Read audio duration with ffprobe
            $durationSeconds = $audioService->getAudioDuration($processedOutputPath);
            $meeting->audio_duration_seconds = (int) round($durationSeconds);

            // Step 3: Check if file exceeds AUDIO_CHUNK_MAX_MB
            $maxMb = (float) config('autopilot.audio.audio_chunk_max_mb', 24);
            $maxBytes = (int) ($maxMb * 1024 * 1024);
            $fileSize = filesize($processedOutputPath);

            $mediaPayload = null;

            if ($fileSize > $maxBytes) {
                // Split into 30-minute chunks (1800s)
                $chunkDir = storage_path("app/{$processedRelativeDir}");
                $chunks = $audioService->splitIntoChunks(
                    $processedOutputPath,
                    $chunkDir,
                    "meeting_{$meeting->id}_chunk",
                    1800
                );

                $chunkUrls = [];
                foreach ($chunks as $chunkPath) {
                    $chunkFilename = basename($chunkPath);
                    $chunkUrls[] = $audioService->generateSignedUrl($meeting->id, $chunkFilename);
                }

                $mediaPayload = $chunkUrls;
            } else {
                $mediaPayload = $audioService->generateSignedUrl($meeting->id, $processedFileName);
            }

            // Save meeting state with processed audio path
            $meeting->audio_path = "{$processedRelativeDir}/{$processedFileName}";
            $meeting->save();

            // Step 4: Fire meeting.uploaded outbound webhook
            $meetingDate = $meeting->meeting_date
                ? Carbon::parse($meeting->meeting_date)->format('Y-m-d')
                : now()->toDateString();

            $webhookService->dispatchMeetingUploaded(
                orgId: $meeting->org_id,
                meetingId: $meeting->id,
                title: $meeting->title,
                meetingDate: $meetingDate,
                timezone: $meeting->timezone ?: 'Asia/Karachi',
                source: $meeting->source ?: 'upload',
                mediaOrTranscript: $mediaPayload
            );

        } catch (Throwable $e) {
            Log::error("ProcessMeetingAudio failed for meeting {$meeting->id}: " . $e->getMessage());

            $meeting->update([
                'status' => 'failed',
                'error_message' => mb_substr($e->getMessage(), 0, 1000),
            ]);

            // Do NOT re-throw so the job doesn't endlessly retry corrupted media
        }
    }
}
