<?php

namespace App\Services;

use App\Jobs\DispatchWebhook;
use App\Models\Organization;
use App\Models\WebhookDelivery;
use Illuminate\Support\Str;

class WebhookService
{
    public const EVENT_MEETING_UPLOADED = 'meeting.uploaded';
    public const EVENT_MEETING_NEEDS_REVIEW = 'meeting.needs_review';
    public const EVENT_TASKS_APPROVED = 'tasks.approved';
    public const EVENT_TASK_COMPLETED = 'task.completed';
    public const EVENT_TASK_BLOCKED = 'task.blocked';

    /**
     * Map event types to their env/config keys.
     */
    protected static array $urlConfigMap = [
        self::EVENT_MEETING_UPLOADED   => 'autopilot.webhooks.meeting_uploaded',
        self::EVENT_MEETING_NEEDS_REVIEW => 'autopilot.webhooks.meeting_needs_review',
        self::EVENT_TASKS_APPROVED     => 'autopilot.webhooks.tasks_approved',
        self::EVENT_TASK_COMPLETED     => 'autopilot.webhooks.task_completed',
        self::EVENT_TASK_BLOCKED       => 'autopilot.webhooks.task_blocked',
    ];

    /**
     * Map event types to their org_settings.webhook_urls keys.
     * These mirror the keys stored by AdminController.
     */
    protected static array $urlSettingsMap = [
        self::EVENT_MEETING_UPLOADED   => 'meeting_uploaded',
        self::EVENT_MEETING_NEEDS_REVIEW => 'meeting_needs_review',
        self::EVENT_TASKS_APPROVED     => 'tasks_approved',
        self::EVENT_TASK_COMPLETED     => 'task_completed',
        self::EVENT_TASK_BLOCKED       => 'task_blocked',
    ];

    /**
     * Resolve target URL for an event.
     *
     * Priority:
     *  1. org_settings.webhook_urls.<key> (set via Admin Center UI)
     *  2. .env / config fallback
     *
     * @param  string   $eventType
     * @param  int|null $orgId     When provided, org settings override .env.
     */
    public function resolveTargetUrl(string $eventType, ?int $orgId = null): ?string
    {
        // Try org-level override stored via Admin Center
        if ($orgId) {
            $settingsKey = self::$urlSettingsMap[$eventType] ?? null;
            if ($settingsKey) {
                $org = Organization::find($orgId);
                $orgUrl = data_get($org?->settings, "webhook_urls.{$settingsKey}");
                if (!empty($orgUrl)) {
                    return $orgUrl;
                }
            }
        }

        // Fall back to .env / config
        $configKey = self::$urlConfigMap[$eventType] ?? null;

        return $configKey ? config($configKey) : null;
    }

    /**
     * Format payload envelope with standard fields: event, event_id, fired_at.
     */
    public function formatEnvelope(string $eventType, array $payload): array
    {
        $envelope = [
            'event' => $eventType,
            'event_id' => (string) Str::uuid(),
            'fired_at' => now()->toIso8601String(),
        ];

        return array_merge($envelope, $payload);
    }

    /**
     * Compute HMAC-SHA256 signature for exact raw body bytes.
     */
    public function sign(string $rawBody, ?string $secret = null): string
    {
        $signingSecret = $secret ?? (string) config('autopilot.webhooks.signing_secret', '');

        return hash_hmac('sha256', $rawBody, $signingSecret);
    }

    /**
     * Verify an HMAC signature against the exact received body bytes.
     */
    public function verifySignature(string $rawBody, string $signature, ?string $secret = null): bool
    {
        $expectedSignature = $this->sign($rawBody, $secret);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Dispatch an outbound webhook event.
     */
    public function dispatch(string $eventType, array $payload, ?string $targetUrl = null, ?int $orgId = null): WebhookDelivery
    {
        $envelopePayload = $this->formatEnvelope($eventType, $payload);
        $url = $targetUrl ?: ($this->resolveTargetUrl($eventType, $orgId) ?? '');

        $delivery = WebhookDelivery::create([
            'event_type' => $eventType,
            'payload' => $envelopePayload,
            'target_url' => $url,
            'attempt_count' => 0,
            'last_status_code' => null,
            'last_error' => null,
            'delivered_at' => null,
        ]);

        DispatchWebhook::dispatch($delivery->id);

        return $delivery;
    }

    /**
     * Resend an existing webhook delivery attempt.
     */
    public function resend(WebhookDelivery $delivery): WebhookDelivery
    {
        DispatchWebhook::dispatch($delivery->id);

        return $delivery;
    }

    /**
     * Dispatch meeting.uploaded webhook.
     */
    public function dispatchMeetingUploaded(
        int $orgId,
        int $meetingId,
        string $title,
        string $meetingDate,
        string $timezone,
        string $source,
        string|array $mediaOrTranscript
    ): WebhookDelivery {
        $payload = [
            'org_id' => $orgId,
            'meeting_id' => $meetingId,
            'title' => $title,
            'meeting_date' => $meetingDate,
            'timezone' => $timezone,
            'source' => $source,
        ];

        if (is_array($mediaOrTranscript)) {
            $payload['audio_chunks'] = $mediaOrTranscript;
        } elseif (str_starts_with($mediaOrTranscript, 'http://') || str_starts_with($mediaOrTranscript, 'https://')) {
            $payload['audio_url'] = $mediaOrTranscript;
        } else {
            $payload['transcript'] = $mediaOrTranscript;
        }

        return $this->dispatch(self::EVENT_MEETING_UPLOADED, $payload, null, $orgId);
    }

    /**
     * Dispatch meeting.needs_review webhook.
     */
    public function dispatchMeetingNeedsReview(
        int $orgId,
        int $meetingId,
        string $title,
        int $taskCount,
        string $approverEmail,
        string $approverName,
        string $reviewUrl
    ): WebhookDelivery {
        $payload = [
            'org_id' => $orgId,
            'meeting_id' => $meetingId,
            'title' => $title,
            'task_count' => $taskCount,
            'approver_email' => $approverEmail,
            'approver_name' => $approverName,
            'review_url' => $reviewUrl,
        ];

        return $this->dispatch(self::EVENT_MEETING_NEEDS_REVIEW, $payload, null, $orgId);
    }

    /**
     * Dispatch tasks.approved webhook.
     */
    public function dispatchTasksApproved(
        int $orgId,
        int $meetingId,
        array $tasks
    ): WebhookDelivery {
        $payload = [
            'org_id' => $orgId,
            'meeting_id' => $meetingId,
            'tasks' => $tasks,
        ];

        return $this->dispatch(self::EVENT_TASKS_APPROVED, $payload, null, $orgId);
    }

    /**
     * Dispatch task.completed webhook.
     */
    public function dispatchTaskCompleted(
        int $orgId,
        int $taskId,
        string $title,
        int|string $completedBy,
        string $completedAt,
        ?string $managerEmail = null
    ): WebhookDelivery {
        $payload = [
            'org_id' => $orgId,
            'task_id' => $taskId,
            'title' => $title,
            'completed_by' => $completedBy,
            'completed_at' => $completedAt,
            'manager_email' => $managerEmail,
        ];

        return $this->dispatch(self::EVENT_TASK_COMPLETED, $payload, null, $orgId);
    }

    /**
     * Dispatch task.blocked webhook.
     */
    public function dispatchTaskBlocked(
        int $orgId,
        int $taskId,
        string $title,
        string $reasonCode,
        ?string $description,
        int|string $blockedBy,
        ?int $dependsOnTaskId = null,
        ?string $managerEmail = null
    ): WebhookDelivery {
        $payload = [
            'org_id' => $orgId,
            'task_id' => $taskId,
            'title' => $title,
            'reason_code' => $reasonCode,
            'description' => $description,
            'blocked_by' => $blockedBy,
            'depends_on_task_id' => $dependsOnTaskId,
            'manager_email' => $managerEmail,
        ];

        return $this->dispatch(self::EVENT_TASK_BLOCKED, $payload, null, $orgId);
    }
}
