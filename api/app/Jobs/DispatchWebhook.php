<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class DispatchWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 120;


    /**
     * Exponential / stepped backoff in seconds across the 5 attempts.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60, 180, 300];
    }

    public function __construct(
        public int $deliveryId
    ) {}

    public function handle(): void
    {
        $delivery = WebhookDelivery::find($this->deliveryId);

        if (!$delivery) {
            Log::warning("WebhookDelivery with ID {$this->deliveryId} not found. Skipping dispatch.");
            return;
        }

        $targetUrl = $delivery->target_url;
        if (empty($targetUrl)) {
            $delivery->update([
                'last_error' => 'Target URL is empty or unconfigured.',
            ]);
            return;
        }

        $payload = $delivery->payload;
        // Exact raw JSON serialization
        $rawBody = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($rawBody === false) {
            $rawBody = json_encode($payload);
        }

        $signingSecret = config('autopilot.webhooks.signing_secret', '');
        $signature = hash_hmac('sha256', $rawBody, (string) $signingSecret);

        // Increment attempt count on every attempt
        $delivery->increment('attempt_count');

        $n8nApiKey = config('autopilot.n8n_api_key', '');

        try {
            $headers = [
                'X-Signature'  => $signature,
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ];

            // Include the shared API key so n8n's headerAuth guard accepts
            // the request. n8n uses the same "Header Auth account" credential
            // on its incoming Webhook node as it does on outgoing HTTP Requests.
            if (!empty($n8nApiKey)) {
                $headers['X-API-Key'] = $n8nApiKey;
            }

            $response = Http::timeout($this->timeout)
                ->withHeaders($headers)
                ->withBody($rawBody, 'application/json')
                ->post($targetUrl);

            $statusCode = $response->status();

            if ($response->successful()) {
                $delivery->update([
                    'last_status_code' => $statusCode,
                    'last_error' => null,
                    'delivered_at' => now(),
                ]);
                return;
            }

            // HTTP error response (4xx or 5xx)
            $errorMessage = "HTTP {$statusCode}: " . mb_substr($response->body(), 0, 1000);
            $delivery->update([
                'last_status_code' => $statusCode,
                'last_error' => $errorMessage,
            ]);

            throw new Exception("Webhook delivery failed with HTTP status {$statusCode}");
        } catch (Throwable $e) {
            if (!$delivery->last_status_code || $delivery->last_status_code < 400) {
                $delivery->update([
                    'last_error' => mb_substr($e->getMessage(), 0, 1000),
                ]);
            }

            // Re-throw so Laravel queue manages exponential retry backoff
            throw $e;
        }
    }

    /**
     * Handle job failure when all 5 retries have been exhausted.
     */
    public function failed(?Throwable $exception): void
    {
        $delivery = WebhookDelivery::find($this->deliveryId);
        if ($delivery && $exception) {
            $delivery->update([
                'last_error' => 'Exhausted 5 attempts. Last error: ' . mb_substr($exception->getMessage(), 0, 1000),
            ]);
        }
    }
}
