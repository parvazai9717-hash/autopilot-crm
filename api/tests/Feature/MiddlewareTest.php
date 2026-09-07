<?php

namespace Tests\Feature;

use App\Models\IdempotencyKey;
use Tests\TestCase;

class MiddlewareTest extends TestCase
{
    public function test_machine_api_rejects_missing_api_key(): void
    {
        $response = $this->getJson('/api/v1/ping');

        $response->assertStatus(401)
            ->assertJson([
                'error' => [
                    'code' => 'MISSING_API_KEY',
                    'message' => 'X-API-Key header is missing.',
                    'field' => null,
                ],
            ]);
    }

    public function test_machine_api_rejects_invalid_api_key(): void
    {
        $response = $this->withHeaders([
            'X-API-Key' => 'incorrect-api-key',
        ])->getJson('/api/v1/ping');

        $response->assertStatus(401)
            ->assertJson([
                'error' => [
                    'code' => 'INVALID_API_KEY',
                    'message' => 'Invalid API key provided.',
                    'field' => null,
                ],
            ]);
    }

    public function test_machine_api_accepts_valid_api_key(): void
    {
        $response = $this->withHeaders([
            'X-API-Key' => 'test-n8n-api-key-secret-2026',
        ])->getJson('/api/v1/ping');

        $response->assertStatus(200)
            ->assertJsonPath('ok', true);
    }

    public function test_idempotency_middleware_stores_and_replays_response(): void
    {
        $idempotencyKey = 'test-idem-key-' . uniqid();

        // Perform login with idempotency key
        $firstResponse = $this->withHeaders([
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson('/api/auth/login', [
            'email' => 'ahmed@test.com',
            'password' => 'password123',
        ]);

        $firstResponse->assertStatus(200);
        $firstToken = $firstResponse->json('token');

        // Verify key was saved to database
        $this->assertDatabaseHas('idempotency_keys', [
            'key' => $idempotencyKey,
        ]);

        // Replay the exact same request with same idempotency key
        $secondResponse = $this->withHeaders([
            'Idempotency-Key' => $idempotencyKey,
        ])->postJson('/api/auth/login', [
            'email' => 'ahmed@test.com',
            'password' => 'password123',
        ]);

        $secondResponse->assertStatus(200);
        $secondResponse->assertHeader('X-Idempotent-Replayed', 'true');
        $this->assertEquals($firstToken, $secondResponse->json('token'));
    }

    public function test_404_error_follows_spec_format(): void
    {
        $response = $this->getJson('/api/v1/non-existent-endpoint');

        $response->assertStatus(404)
            ->assertJson([
                'error' => [
                    'code' => 'NOT_FOUND',
                    'message' => 'Resource not found.',
                    'field' => null,
                ],
            ]);
    }
}
