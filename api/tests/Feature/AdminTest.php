<?php

namespace Tests\Feature;

use App\Jobs\DispatchWebhook;
use App\Models\Organization;
use App\Models\User;
use App\Models\WebhookDelivery;
use Database\Seeders\OrganizationSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Phase 12 — Admin Configuration tests per Section 17, 14, and 7 of SPEC.md.
 */
class AdminTest extends TestCase
{
    protected Organization $org;
    protected User         $admin;     // Ahmad Ameen (ID 6)
    protected User         $executive; // Imran Malik (ID 7)
    protected User         $manager;   // Bilal Sheikh (ID 5)
    protected User         $employee;  // Ahmed Raza (ID 1)

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(OrganizationSeeder::class);
        $this->seed(UserSeeder::class);

        // Clean up any test users created outside seeded roster (1-7)
        User::withoutGlobalScopes()->whereNotIn('id', [1, 2, 3, 4, 5, 6, 7])->delete();

        $this->org       = Organization::find(1);
        $this->admin     = User::find(6);
        $this->executive = User::find(7);
        $this->manager   = User::find(5);
        $this->employee  = User::find(1);
    }

    // -------------------------------------------------------------------------
    // Auth & Role Gates
    // -------------------------------------------------------------------------

    public function test_unauthenticated_requests_return_401(): void
    {
        $this->getJson('/api/admin/users')->assertStatus(401);
        $this->getJson('/api/admin/settings')->assertStatus(401);
        $this->getJson('/api/admin/webhooks')->assertStatus(401);
    }

    public function test_non_admin_roles_receive_403(): void
    {
        // Employee gets 403
        $this->actingAs($this->employee)->getJson('/api/admin/users')->assertStatus(403);
        $this->actingAs($this->employee)->getJson('/api/admin/settings')->assertStatus(403);
        $this->actingAs($this->employee)->getJson('/api/admin/webhooks')->assertStatus(403);

        // Manager gets 403
        $this->actingAs($this->manager)->getJson('/api/admin/users')->assertStatus(403);
        $this->actingAs($this->manager)->getJson('/api/admin/settings')->assertStatus(403);

        // Executive gets 403 (read-only for tasks, not admin configuration)
        $this->actingAs($this->executive)->getJson('/api/admin/users')->assertStatus(403);
        $this->actingAs($this->executive)->getJson('/api/admin/settings')->assertStatus(403);
    }

    public function test_admin_can_access_admin_endpoints(): void
    {
        $this->actingAs($this->admin)->getJson('/api/admin/users')->assertStatus(200);
        $this->actingAs($this->admin)->getJson('/api/admin/settings')->assertStatus(200);
        $this->actingAs($this->admin)->getJson('/api/admin/webhooks')->assertStatus(200);
    }

    // -------------------------------------------------------------------------
    // User Management
    // -------------------------------------------------------------------------

    public function test_admin_can_list_users_with_manager_relationships(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/admin/users');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'users' => [
                    '*' => [
                        'id',
                        'name',
                        'email',
                        'role',
                        'status',
                        'manager_id',
                        'manager',
                        'direct_reports_count',
                    ],
                ],
            ]);

        // Ahmed Raza reports to Bilal (ID 5)
        $ahmed = collect($response->json('users'))->firstWhere('email', 'ahmed@test.com');
        $this->assertEquals(5, $ahmed['manager_id']);
        $this->assertEquals('Bilal Sheikh', $ahmed['manager']['name']);

        // Bilal manages 4 direct reports
        $bilal = collect($response->json('users'))->firstWhere('email', 'bilal@test.com');
        $this->assertGreaterThanOrEqual(4, $bilal['direct_reports_count']);
    }

    public function test_admin_can_create_new_user(): void
    {
        $testEmail = 'zainab_' . time() . '@test.com';
        $payload = [
            'name'       => 'Zainab Qazi',
            'email'      => $testEmail,
            'password'   => 'password123',
            'role'       => 'employee',
            'manager_id' => $this->manager->id,
            'phone'      => '+923001234567',
            'status'     => 'active',
        ];

        $response = $this->actingAs($this->admin)->postJson('/api/admin/users', $payload);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', [
            'email'      => $testEmail,
            'role'       => 'employee',
            'manager_id' => $this->manager->id,
            'org_id'     => $this->org->id,
        ]);
    }

    public function test_admin_can_update_user_role_and_manager(): void
    {
        $payload = [
            'role'       => 'manager',
            'manager_id' => $this->admin->id,
            'status'     => 'active',
        ];

        $response = $this->actingAs($this->admin)
            ->putJson("/api/admin/users/{$this->employee->id}", $payload);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', [
            'id'         => $this->employee->id,
            'role'       => 'manager',
            'manager_id' => $this->admin->id,
        ]);
    }

    public function test_user_cannot_be_assigned_as_their_own_manager(): void
    {
        $response = $this->actingAs($this->admin)
            ->putJson("/api/admin/users/{$this->employee->id}", [
                'manager_id' => $this->employee->id,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    // -------------------------------------------------------------------------
    // Organization Policy & Settings (Escalation Thresholds)
    // -------------------------------------------------------------------------

    public function test_admin_can_get_settings_with_masked_api_key(): void
    {
        $response = $this->actingAs($this->admin)->getJson('/api/admin/settings');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'organization' => ['id', 'name', 'timezone'],
                'settings'     => [
                    'reminder_windows_days',
                    'escalation_days_overdue',
                    'working_hours',
                    'working_days',
                    'notification_channels',
                ],
                'api_key' => ['has_key', 'masked'],
            ]);

        // Key must not be in plaintext
        $masked = $response->json('api_key.masked');
        if ($masked) {
            $this->assertStringContainsString('•', $masked);
        }
    }

    public function test_admin_can_update_org_settings(): void
    {
        $payload = [
            'name'     => 'Updated Demo Org',
            'timezone' => 'Asia/Karachi',
            'settings' => [
                'reminder_windows_days' => [
                    'high'   => [4, 3, 2, 1, 0],
                    'medium' => [2, 1, 0],
                    'low'    => [1, 0],
                ],
                'escalation_days_overdue' => [
                    'high'   => ['manager' => 3, 'executive' => 6],
                    'medium' => ['manager' => 5, 'executive' => 9],
                    'low'    => ['manager' => 8, 'executive' => 15],
                ],
                'working_hours' => [
                    'start' => '08:30',
                    'end'   => '17:30',
                ],
                'working_days' => [1, 2, 3, 4, 5],
                'notification_channels' => ['email'],
            ],
        ];

        $response = $this->actingAs($this->admin)->putJson('/api/admin/settings', $payload);

        $response->assertStatus(200);

        $this->assertDatabaseHas('organizations', [
            'id'   => $this->org->id,
            'name' => 'Updated Demo Org',
        ]);

        // Verify machine endpoint /api/v1/orgs/1/settings returns updated policy
        $machineKey = config('autopilot.n8n_api_key', 'test-api-key');
        $machineRes = $this->withHeader('X-API-Key', $machineKey)
            ->getJson('/api/v1/orgs/1/settings');

        $machineRes->assertStatus(200);
        $this->assertEquals(3, $machineRes->json('escalation_days_overdue.high.manager'));
        $this->assertEquals(6, $machineRes->json('escalation_days_overdue.high.executive'));
    }

    public function test_escalation_threshold_validation_rejects_manager_greater_or_equal_executive(): void
    {
        // High priority: manager (5) >= executive (2) is backwards!
        $payload = [
            'settings' => [
                'escalation_days_overdue' => [
                    'high'   => ['manager' => 5, 'executive' => 2], // Backward!
                    'medium' => ['manager' => 4, 'executive' => 8],
                    'low'    => ['manager' => 7, 'executive' => 14],
                ],
            ],
        ];

        $response = $this->actingAs($this->admin)->putJson('/api/admin/settings', $payload);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $errorMsg = $response->json('error.message');
        $this->assertStringContainsString('manager escalation threshold', $errorMsg);
        $this->assertStringContainsString('must be less than the executive threshold', $errorMsg);
    }

    public function test_escalation_threshold_validation_rejects_non_positive_integers(): void
    {
        $payload = [
            'settings' => [
                'escalation_days_overdue' => [
                    'high'   => ['manager' => 0, 'executive' => 5], // 0 is invalid
                    'medium' => ['manager' => 4, 'executive' => 8],
                    'low'    => ['manager' => 7, 'executive' => 14],
                ],
            ],
        ];

        $response = $this->actingAs($this->admin)->putJson('/api/admin/settings', $payload);

        $response->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $errorMsg = $response->json('error.message');
        $this->assertStringContainsString('positive integer', $errorMsg);
    }

    public function test_admin_can_regenerate_api_key_and_it_authenticates_machine_api(): void
    {
        $response = $this->actingAs($this->admin)->postJson('/api/admin/api-key/regenerate');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'ok',
                'message',
                'api_key',
                'masked',
            ]);

        $newKey = $response->json('api_key');
        $this->assertNotEmpty($newKey);
        $this->assertStringStartsWith('autopilot_', $newKey);

        // Verify that the new key immediately authenticates against machine API /api/v1/ping
        $pingRes = $this->withHeader('X-API-Key', $newKey)->getJson('/api/v1/ping');
        $pingRes->assertStatus(200)->assertJson(['ok' => true]);

        // Subsequent read on /api/admin/settings shows masked key ending with last 4
        $settingsRes = $this->actingAs($this->admin)->getJson('/api/admin/settings');
        $this->assertTrue($settingsRes->json('api_key.has_key'));
        $this->assertStringEndsWith(substr($newKey, -4), $settingsRes->json('api_key.masked'));
    }

    // -------------------------------------------------------------------------
    // Webhook Delivery Log & Resend
    // -------------------------------------------------------------------------

    public function test_admin_can_view_webhook_deliveries_and_resend(): void
    {
        Queue::fake();

        // Create a dummy delivery record
        $delivery = WebhookDelivery::create([
            'event_type'       => 'tasks.approved',
            'target_url'       => 'https://n8n.example.com/webhook/tasks-approved',
            'payload'          => ['event' => 'tasks.approved', 'tasks' => []],
            'attempt_count'    => 1,
            'last_status_code' => 500,
            'last_error'       => 'Internal Server Error',
            'delivered_at'     => null,
        ]);

        $logRes = $this->actingAs($this->admin)->getJson('/api/admin/webhooks');
        $logRes->assertStatus(200)
            ->assertJsonStructure([
                'deliveries' => [
                    '*' => [
                        'id',
                        'event_type',
                        'target_url',
                        'attempt_count',
                        'last_status_code',
                        'payload',
                    ],
                ],
            ]);

        // Test Resend button endpoint
        $resendRes = $this->actingAs($this->admin)
            ->postJson("/api/admin/webhooks/{$delivery->id}/resend");

        $resendRes->assertStatus(200)
            ->assertJson(['ok' => true]);

        Queue::assertPushed(DispatchWebhook::class, function ($job) use ($delivery) {
            return $job->deliveryId === $delivery->id;
        });
    }
}
