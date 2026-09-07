<?php

namespace Tests\Feature;

use App\Jobs\ProcessMeetingAudio;
use App\Models\Meeting;
use App\Models\Organization;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Services\AudioProcessingService;
use App\Services\WebhookService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AudioProcessingTest extends TestCase
{
    protected string $apiKey = 'test-n8n-api-key-secret-2026';
    protected AudioProcessingService $audioService;
    protected WebhookService $webhookService;
    protected Organization $org;
    protected User $user;
    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->audioService = new AudioProcessingService();
        $this->webhookService = new WebhookService();
        $this->org = Organization::firstOrCreate(
            ['id' => 1],
            ['name' => 'Demo Company', 'timezone' => 'Asia/Karachi']
        );
        $this->user = User::withoutGlobalScopes()->first();

        Config::set('autopilot.n8n_api_key', $this->apiKey);
        Config::set('autopilot.webhooks.meeting_uploaded', 'http://localhost:5678/webhook/meeting-uploaded');
        Config::set('autopilot.audio.ffmpeg_path', $this->audioService->getFfmpegBinary());
        Config::set('autopilot.audio.ffprobe_path', $this->audioService->getFfprobeBinary());

        $this->tempDir = storage_path('framework/testing/audio_' . uniqid());
        if (!File::isDirectory($this->tempDir)) {
            File::makeDirectory($this->tempDir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->tempDir)) {
            File::deleteDirectory($this->tempDir);
        }

        parent::tearDown();
    }

    /**
     * Helper to generate an actual playable sine wave audio file using ffmpeg.
     */
    private function generateRealAudioFile(int $durationSeconds = 3, string $format = 'wav'): string
    {
        $ffmpeg = $this->audioService->getFfmpegBinary();
        $outputPath = $this->tempDir . DIRECTORY_SEPARATOR . "test_sine_{$durationSeconds}s.{$format}";

        $command = [
            $ffmpeg,
            '-f', 'lavfi',
            '-i', "sine=frequency=1000:duration={$durationSeconds}",
            '-y',
            $outputPath,
        ];

        $process = new Process($command);
        $process->setTimeout(60);
        $process->run();

        $this->assertTrue($process->isSuccessful(), "Failed to generate real audio with ffmpeg: " . $process->getErrorOutput());
        $this->assertFileExists($outputPath);

        return $outputPath;
    }

    public function test_audio_upload_endpoint_stores_file_and_dispatches_job(): void
    {
        Queue::fake();

        $realAudioPath = $this->generateRealAudioFile(2, 'wav');
        $uploadedFile = new UploadedFile(
            $realAudioPath,
            'meeting_recording.wav',
            'audio/wav',
            null,
            true
        );

        $response = $this->withHeaders(['X-API-Key' => $this->apiKey])
            ->post('/api/v1/meetings/upload', [
                'org_id' => $this->org->id,
                'title' => 'Q3 Strategy Meeting',
                'meeting_date' => '2026-09-01',
                'file' => $uploadedFile,
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['meeting_id', 'status'])
            ->assertJsonPath('status', 'uploaded');

        $meetingId = $response->json('meeting_id');
        $meeting = Meeting::withoutGlobalScopes()->find($meetingId);

        $this->assertNotNull($meeting);
        $this->assertEquals('uploaded', $meeting->status);
        $this->assertEquals('upload', $meeting->source);
        $this->assertNotEmpty($meeting->audio_path);

        Queue::assertPushed(ProcessMeetingAudio::class, function (ProcessMeetingAudio $job) use ($meetingId) {
            return $job->meetingId === $meetingId;
        });
    }

    public function test_process_meeting_audio_executes_real_ffmpeg_conversion(): void
    {
        Http::fake([
            'http://localhost:5678/webhook/meeting-uploaded' => Http::response(['status' => 'received'], 200),
        ]);

        // Generate a 4-second sine wave audio
        $realAudioPath = $this->generateRealAudioFile(4, 'wav');

        // Store into storage/app/meetings/original/
        $originalRelative = 'meetings/original/test_real_' . uniqid() . '.wav';
        Storage::disk('local')->put($originalRelative, file_get_contents($realAudioPath));

        $meeting = Meeting::withoutGlobalScopes()->create([
            'org_id' => $this->org->id,
            'title' => 'Executive Sync',
            'meeting_date' => '2026-09-01',
            'timezone' => 'Asia/Karachi',
            'source' => 'upload',
            'created_by' => $this->user->id,
            'audio_path' => $originalRelative,
            'status' => 'uploaded',
        ]);

        // Execute the job directly
        $job = new ProcessMeetingAudio($meeting->id);
        $job->handle($this->audioService, $this->webhookService);

        $meeting->refresh();

        // Status updated to processing
        $this->assertEquals('processing', $meeting->status);
        $this->assertNotNull($meeting->processing_started_at);
        $this->assertNull($meeting->error_message);

        // Duration probed via ffprobe
        $this->assertGreaterThanOrEqual(3, $meeting->audio_duration_seconds);
        $this->assertLessThanOrEqual(5, $meeting->audio_duration_seconds);

        // Processed file exists on disk
        $processedPath = storage_path('app/' . $meeting->audio_path);
        $this->assertFileExists($processedPath);
        $this->assertStringEndsWith('.mp3', $processedPath);

        // Webhook delivery created
        $delivery = WebhookDelivery::where('event_type', 'meeting.uploaded')->latest('id')->first();
        $this->assertNotNull($delivery);
        $this->assertStringContainsString('/api/v1/meetings/' . $meeting->id . '/audio', $delivery->payload['audio_url']);
    }

    public function test_audio_chunking_when_exceeding_max_chunk_size(): void
    {
        Http::fake([
            'http://localhost:5678/webhook/meeting-uploaded' => Http::response(['ok' => true], 200),
        ]);

        // Generate an 8-second audio file
        $realAudioPath = $this->generateRealAudioFile(8, 'wav');
        $originalRelative = 'meetings/original/test_chunk_' . uniqid() . '.wav';
        Storage::disk('local')->put($originalRelative, file_get_contents($realAudioPath));

        $meeting = Meeting::withoutGlobalScopes()->create([
            'org_id' => $this->org->id,
            'title' => 'Long Workshop',
            'meeting_date' => '2026-09-01',
            'timezone' => 'Asia/Karachi',
            'source' => 'upload',
            'created_by' => $this->user->id,
            'audio_path' => $originalRelative,
            'status' => 'uploaded',
        ]);

        // Set threshold very low (e.g. 0.005 MB = 5KB) so the 8s 32kbps MP3 (which is ~32KB) exceeds the limit
        Config::set('autopilot.audio.audio_chunk_max_mb', 0.005);

        $job = new ProcessMeetingAudio($meeting->id);
        $job->handle($this->audioService, $this->webhookService);

        $meeting->refresh();
        $this->assertEquals('processing', $meeting->status);

        // Webhook payload should contain an array of chunk URLs
        $delivery = WebhookDelivery::where('event_type', 'meeting.uploaded')->latest('id')->first();
        $this->assertNotNull($delivery);
        $this->assertArrayHasKey('audio_chunks', $delivery->payload);
        $this->assertIsArray($delivery->payload['audio_chunks']);
        $this->assertNotEmpty($delivery->payload['audio_chunks']);
    }

    public function test_signed_url_allows_audio_download(): void
    {
        $realAudioPath = $this->generateRealAudioFile(2, 'wav');
        $convertedRelative = 'meetings/processed/test_download_' . uniqid() . '.mp3';
        $fullPath = storage_path('app/' . $convertedRelative);

        $this->audioService->convertToMonoMp3($realAudioPath, $fullPath);

        $meeting = Meeting::withoutGlobalScopes()->create([
            'org_id' => $this->org->id,
            'title' => 'Downloadable Meeting',
            'meeting_date' => '2026-09-01',
            'timezone' => 'Asia/Karachi',
            'source' => 'upload',
            'created_by' => $this->user->id,
            'audio_path' => $convertedRelative,
            'status' => 'processing',
        ]);

        $signedUrl = $this->audioService->generateSignedUrl($meeting->id, basename($fullPath));

        $response = $this->get($signedUrl);
        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'audio/mpeg');
    }

    public function test_tampered_signed_url_is_rejected(): void
    {
        $meeting = Meeting::withoutGlobalScopes()->create([
            'org_id' => $this->org->id,
            'title' => 'Secure Meeting',
            'meeting_date' => '2026-09-01',
            'timezone' => 'Asia/Karachi',
            'source' => 'upload',
            'created_by' => $this->user->id,
            'status' => 'processing',
        ]);

        $tamperedUrl = "/api/v1/meetings/{$meeting->id}/audio?signature=invalid_signature_hash";

        $response = $this->get($tamperedUrl);
        // Laravel signed middleware rejects invalid signature with 403
        $response->assertStatus(403);
    }

    public function test_check_stuck_meetings_command_marks_timed_out_meetings_as_failed(): void
    {
        $timeout = (int) config('autopilot.meeting_processing_timeout', 30);

        // Stuck meeting (started 45 minutes ago)
        $stuckMeeting = Meeting::withoutGlobalScopes()->create([
            'org_id' => $this->org->id,
            'title' => 'Stuck Meeting',
            'meeting_date' => '2026-09-01',
            'timezone' => 'Asia/Karachi',
            'source' => 'upload',
            'created_by' => $this->user->id,
            'status' => 'processing',
            'processing_started_at' => now()->subMinutes($timeout + 15),
        ]);

        // Recent meeting (started 5 minutes ago)
        $recentMeeting = Meeting::withoutGlobalScopes()->create([
            'org_id' => $this->org->id,
            'title' => 'Active Processing Meeting',
            'meeting_date' => '2026-09-01',
            'timezone' => 'Asia/Karachi',
            'source' => 'upload',
            'created_by' => $this->user->id,
            'status' => 'processing',
            'processing_started_at' => now()->subMinutes(5),
        ]);

        Artisan::call('meetings:check-stuck');

        $stuckMeeting->refresh();
        $recentMeeting->refresh();

        $this->assertEquals('failed', $stuckMeeting->status);
        $this->assertStringContainsString("timed out", $stuckMeeting->error_message);

        $this->assertEquals('processing', $recentMeeting->status);
        $this->assertNull($recentMeeting->error_message);
    }

    public function test_admin_retry_endpoint_resets_failed_meeting_and_dispatches_job(): void
    {
        Queue::fake();

        $meeting = Meeting::withoutGlobalScopes()->create([
            'org_id' => $this->org->id,
            'title' => 'Failed Meeting',
            'meeting_date' => '2026-09-01',
            'timezone' => 'Asia/Karachi',
            'source' => 'upload',
            'created_by' => $this->user->id,
            'audio_path' => 'meetings/original/failed.wav',
            'status' => 'failed',
            'error_message' => 'Previous conversion error',
            'processing_started_at' => now()->subMinutes(60),
        ]);

        $response = $this->withHeaders(['X-API-Key' => $this->apiKey])
            ->postJson("/api/v1/meetings/{$meeting->id}/retry", [
                'org_id' => $this->org->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('status', 'uploaded');

        $meeting->refresh();
        $this->assertEquals('uploaded', $meeting->status);
        $this->assertNull($meeting->error_message);
        $this->assertNull($meeting->processing_started_at);

        Queue::assertPushed(ProcessMeetingAudio::class, function (ProcessMeetingAudio $job) use ($meeting) {
            return $job->meetingId === $meeting->id;
        });
    }
}
