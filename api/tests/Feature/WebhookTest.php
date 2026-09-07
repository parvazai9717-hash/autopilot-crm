<?php

namespace Tests\Feature;

use App\Jobs\DispatchWebhook;
use App\Models\WebhookDelivery;
use App\Services\WebhookService;
use Exception;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    protected WebhookService $webhookService;
    protected string $signingSecret = 'webhook-signing-secret-key-32chars-min';

    protected function setUp(): void
    {
        parent::setUp();
        $this->webhookService = new WebhookService();
        Config::set('autopilot.webhooks.signing_secret', $this->signingSecret);
        Config::set('autopilot.webhooks.meeting_uploaded', 'http://localhost:5678/webhook/meeting-uploaded');
        Config::set('autopilot.webhooks.meeting_needs_review', 'http://localhost:5678/webhook/meeting-needs-review');
        Config::set('autopilot.webhooks.tasks_approved', 'http://localhost:5678/webhook/tasks-approved');
        Config::set('autopilot.webhooks.task_completed', 'http://localhost:5678/webhook/task-completed');
        Config::set('autopilot.webhooks.task_blocked', 'http://localhost:5678/webhook/task-blocked');
    }

    public function test_payload_envelope_contains_event_uuid_and_fired_at(): void
    {
        $payload = $this->webhookService->formatEnvelope('task.completed', [
            'task_id' => 42,
            'title' => 'Test Task',
        ]);

        $this->assertEquals('task.completed', $payload['event']);
        $this->assertTrue(Str::isUuid($payload['event_id']));
        $this->assertNotEmpty($payload['fired_at']);
        $this->assertEquals(42, $payload['task_id']);
        $this->assertEquals('Test Task', $payload['title']);
    }

    public function test_signature_calculation_matches_expected_hmac_sha256(): void
    {
        $rawJson = '{"event":"task.completed","event_id":"12345","task_id":42}';
        $signature = $this->webhookService->sign($rawJson, $this->signingSecret);

        $expected = hash_hmac('sha256', $rawJson, $this->signingSecret);
        $this->assertEquals($expected, $signature);
        $this->assertTrue($this->webhookService->verifySignature($rawJson, $signature, $this->signingSecret));
    }

    public function test_signature_is_verified_against_literal_raw_body_received(): void
    {
        $targetUrl = 'http://localhost:5678/webhook/task-completed';
        $receivedBody = null;
        $receivedSignature = null;

        Http::fake([
            $targetUrl => function (Request $request) use (&$receivedBody, &$receivedSignature) {
                // Intercept the literal raw byte string transmitted in the HTTP request
                $receivedBody = $request->body();
                $receivedSignature = $request->header('X-Signature')[0] ?? null;

                return Http::response(['ok' => true], 200);
            },
        ]);

        $delivery = WebhookDelivery::create([
            'event_type' => 'task.completed',
            'payload' => [
                'event' => 'task.completed',
                'event_id' => (string) Str::uuid(),
                'fired_at' => now()->toIso8601String(),
                'task_id' => 101,
                'title' => 'Prepare Financial Report',
                'completed_by' => 1,
                'completed_at' => now()->toIso8601String(),
                'manager_email' => 'bilal@test.com',
            ],
            'target_url' => $targetUrl,
            'attempt_count' => 0,
        ]);

        $job = new DispatchWebhook($delivery->id);
        $job->handle();

        $this->assertNotNull($receivedBody, 'Request body should have been received by fake endpoint');
        $this->assertNotNull($receivedSignature, 'X-Signature header should have been present');

        // Verify the signature strictly against the literal raw bytes received
        $expectedHmac = hash_hmac('sha256', $receivedBody, $this->signingSecret);
        $this->assertEquals($expectedHmac, $receivedSignature);
    }

    public function test_successful_dispatch_updates_delivery_record(): void
    {
        $targetUrl = 'http://localhost:5678/webhook/task-completed';

        Http::fake([
            $targetUrl => Http::response(['status' => 'received'], 200),
        ]);

        $delivery = WebhookDelivery::create([
            'event_type' => 'task.completed',
            'payload' => [
                'event' => 'task.completed',
                'event_id' => (string) Str::uuid(),
                'fired_at' => now()->toIso8601String(),
                'task_id' => 1,
            ],
            'target_url' => $targetUrl,
            'attempt_count' => 0,
        ]);

        $job = new DispatchWebhook($delivery->id);
        $job->handle();

        $delivery->refresh();
        $this->assertEquals(1, $delivery->attempt_count);
        $this->assertEquals(200, $delivery->last_status_code);
        $this->assertNull($delivery->last_error);
        $this->assertNotNull($delivery->delivered_at);
    }

    public function test_failed_dispatch_logs_error_and_throws_for_queue_retry(): void
    {
        $targetUrl = 'http://localhost:5678/webhook/task-completed';

        Http::fake([
            $targetUrl => Http::response(['error' => 'Internal Server Error'], 500),
        ]);

        $delivery = WebhookDelivery::create([
            'event_type' => 'task.completed',
            'payload' => [
                'event' => 'task.completed',
                'event_id' => (string) Str::uuid(),
                'fired_at' => now()->toIso8601String(),
                'task_id' => 1,
            ],
            'target_url' => $targetUrl,
            'attempt_count' => 0,
        ]);

        $job = new DispatchWebhook($delivery->id);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Webhook delivery failed with HTTP status 500');

        try {
            $job->handle();
        } finally {
            $delivery->refresh();
            $this->assertEquals(1, $delivery->attempt_count);
            $this->assertEquals(500, $delivery->last_status_code);
            $this->assertStringContainsString('500', $delivery->last_error);
            $this->assertNull($delivery->delivered_at);
        }
    }

    public function test_dispatch_webhook_job_has_5_tries_and_exponential_backoff(): void
    {
        $job = new DispatchWebhook(1);

        $this->assertEquals(5, $job->tries);
        $this->assertEquals([10, 30, 60, 180, 300], $job->backoff());
    }

    public function test_failed_method_records_final_exhausted_error(): void
    {
        $delivery = WebhookDelivery::create([
            'event_type' => 'task.completed',
            'payload' => ['event' => 'task.completed'],
            'target_url' => 'http://localhost:5678/webhook/test',
            'attempt_count' => 5,
        ]);

        $job = new DispatchWebhook($delivery->id);
        $job->failed(new Exception('Connection timed out after 30s'));

        $delivery->refresh();
        $this->assertStringContainsString('Exhausted 5 attempts', $delivery->last_error);
        $this->assertStringContainsString('Connection timed out', $delivery->last_error);
    }

    public function test_webhook_service_event_helpers_dispatch_correctly(): void
    {
        Queue::fake();

        // 1. meeting.uploaded
        $d1 = $this->webhookService->dispatchMeetingUploaded(
            1, 10, 'Sync Meeting', '2026-09-01', 'Asia/Karachi', 'upload', 'http://localhost:8000/media/1.mp3'
        );
        $this->assertEquals('meeting.uploaded', $d1->event_type);
        $this->assertEquals('http://localhost:8000/media/1.mp3', $d1->payload['audio_url']);

        // 2. meeting.needs_review
        $d2 = $this->webhookService->dispatchMeetingNeedsReview(
            1, 10, 'Sync Meeting', 3, 'admin@test.com', 'Ahmad Ameen', 'http://localhost:3000/review/10'
        );
        $this->assertEquals('meeting.needs_review', $d2->event_type);
        $this->assertEquals(3, $d2->payload['task_count']);

        // 3. tasks.approved
        $d3 = $this->webhookService->dispatchTasksApproved(1, 10, [
            ['id' => 1, 'title' => 'Task 1', 'owner_id' => 1, 'owner_name' => 'Ahmed Raza', 'owner_email' => 'ahmed@test.com', 'due_date' => '2026-09-04', 'priority' => 'high'],
        ]);
        $this->assertEquals('tasks.approved', $d3->event_type);
        $this->assertCount(1, $d3->payload['tasks']);

        // 4. task.completed
        $d4 = $this->webhookService->dispatchTaskCompleted(1, 1, 'Task 1', 1, '2026-09-04T10:00:00Z', 'bilal@test.com');
        $this->assertEquals('task.completed', $d4->event_type);
        $this->assertEquals(1, $d4->payload['task_id']);

        // 5. task.blocked
        $d5 = $this->webhookService->dispatchTaskBlocked(1, 1, 'Task 1', 'waiting_for_person', 'Waiting on Ali', 1, null, 'bilal@test.com');
        $this->assertEquals('task.blocked', $d5->event_type);
        $this->assertEquals('waiting_for_person', $d5->payload['reason_code']);

        Queue::assertPushed(DispatchWebhook::class, 5);
    }

    public function test_resend_dispatches_existing_delivery(): void
    {
        Queue::fake();

        $delivery = WebhookDelivery::create([
            'event_type' => 'task.completed',
            'payload' => ['event' => 'task.completed'],
            'target_url' => 'http://localhost:5678/webhook/test',
            'attempt_count' => 1,
            'last_status_code' => 500,
        ]);

        $this->webhookService->resend($delivery);

        Queue::assertPushed(DispatchWebhook::class, function (DispatchWebhook $job) use ($delivery) {
            return $job->deliveryId === $delivery->id;
        });
    }
}
