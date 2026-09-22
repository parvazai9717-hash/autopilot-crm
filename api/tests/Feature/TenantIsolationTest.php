<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\Scopes\OrgScope;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org1;
    private Organization $org2;
    private User $user1;
    private User $user2;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        OrgScope::flush();
        TenantContext::clear();

        // Create Org 1
        $this->org1 = Organization::create([
            'name'     => 'Acme Corp',
            'timezone' => 'Asia/Karachi',
            'settings' => '{}',
        ]);

        // Create Org 2
        $this->org2 = Organization::create([
            'name'     => 'Beta Global',
            'timezone' => 'America/New_York',
            'settings' => '{}',
        ]);

        // Create User in Org 1
        $this->user1 = User::create([
            'org_id'    => $this->org1->id,
            'name'      => 'Alice Acme',
            'email'     => 'alice@acme.com',
            'password'  => bcrypt('password123'),
            'role'      => 'admin',
            'status'    => 'active',
            'is_active' => true,
        ]);

        // Create User in Org 2
        $this->user2 = User::create([
            'org_id'    => $this->org2->id,
            'name'      => 'Bob Beta',
            'email'     => 'bob@beta.com',
            'password'  => bcrypt('password123'),
            'role'      => 'admin',
            'status'    => 'active',
            'is_active' => true,
        ]);
    }

    public function test_org_scope_fails_closed_when_unauthenticated(): void
    {
        // Seed a task in org1 using explicit context
        TenantContext::withTenant($this->org1->id, function () {
            Task::create([
                'title'       => 'Secret Task',
                'owner_id'    => $this->user1->id,
                'status'      => 'pending_approval',
                'owner_state' => 'resolved',
            ]);
        });

        // Outside tenant context and without authenticated user, query fails closed
        TenantContext::clear();
        OrgScope::flush();

        $this->assertEquals(0, Task::count());
    }

    public function test_tenant_data_is_strictly_isolated_between_organizations(): void
    {
        // Task in Org 1
        $task1 = TenantContext::withTenant($this->org1->id, function () {
            return Task::create([
                'title'       => 'Org 1 Task',
                'owner_id'    => $this->user1->id,
                'status'      => 'assigned',
                'owner_state' => 'resolved',
            ]);
        });

        // Task in Org 2
        $task2 = TenantContext::withTenant($this->org2->id, function () {
            return Task::create([
                'title'       => 'Org 2 Task',
                'owner_id'    => $this->user2->id,
                'status'      => 'assigned',
                'owner_state' => 'resolved',
            ]);
        });

        // Acting as User 1
        $this->actingAs($this->user1);
        $tasksForUser1 = Task::all();
        $this->assertTrue($tasksForUser1->contains('id', $task1->id));
        $this->assertFalse($tasksForUser1->contains('id', $task2->id));

        // Acting as User 2
        $this->actingAs($this->user2);
        OrgScope::flush();
        $tasksForUser2 = Task::all();
        $this->assertTrue($tasksForUser2->contains('id', $task2->id));
        $this->assertFalse($tasksForUser2->contains('id', $task1->id));
    }

    public function test_model_creation_tamper_proofing_overwrites_caller_supplied_org_id(): void
    {
        // Authenticated as User 1 (Org 1)
        $this->actingAs($this->user1);
        OrgScope::flush();

        // Attempt to create a task targeting Org 2
        $task = Task::create([
            'org_id'      => $this->org2->id,
            'title'       => 'Tampered Org Task',
            'owner_id'    => $this->user1->id,
            'status'      => 'assigned',
            'owner_state' => 'resolved',
        ]);

        // BelongsToOrg must strictly enforce User 1's org_id
        $this->assertEquals($this->org1->id, $task->org_id);
    }

    public function test_n8n_middleware_rejects_missing_or_invalid_api_key(): void
    {
        $responseMissing = $this->getJson('/internal/v1/orgs/active');
        $responseMissing->assertStatus(401);

        $responseInvalid = $this->withHeader('X-API-Key', 'wrong-key')
            ->getJson('/internal/v1/orgs/active');
        $responseInvalid->assertStatus(401);
    }

    public function test_internal_orgs_active_endpoint_returns_all_tenants(): void
    {
        $totalActive = Organization::count();

        // Test prefixless endpoint
        $response = $this->withHeader('X-API-Key', config('autopilot.n8n_api_key'))
            ->getJson('/internal/v1/orgs/active');

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('count', $totalActive);

        // Test with /api prefix as well
        $responseApi = $this->withHeader('X-API-Key', config('autopilot.n8n_api_key'))
            ->getJson('/api/internal/v1/orgs/active');

        $responseApi->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('count', $totalActive);
    }

    public function test_internal_run_claim_lifecycle(): void
    {
        // Create meeting in Org 1
        $meeting = Meeting::create([
            'org_id'       => $this->org1->id,
            'title'        => 'Quarterly Kickoff',
            'meeting_date' => now()->format('Y-m-d'),
            'status'       => 'uploaded',
            'created_by'   => $this->user1->id,
        ]);

        $apiKey = config('autopilot.n8n_api_key');

        // Claim 1: Should succeed
        $claimResponse = $this->withHeader('X-API-Key', $apiKey)
            ->postJson('/internal/v1/runs/claim', [
                'meeting_id' => $meeting->id,
                'run_id'     => 'run-test-101',
            ]);

        $claimResponse->assertStatus(200)
            ->assertJsonPath('status', 'claimed')
            ->assertJsonPath('meeting_id', $meeting->id)
            ->assertJsonPath('org_id', $this->org1->id)
            ->assertJsonPath('org_timezone', 'Asia/Karachi');

        $this->assertEquals('processing', $meeting->fresh()->status);

        // Claim 2: Immediate duplicate claim should return 409 duplicate
        $duplicateResponse = $this->withHeader('X-API-Key', $apiKey)
            ->postJson('/internal/v1/runs/claim', [
                'meeting_id' => $meeting->id,
                'run_id'     => 'run-test-102',
            ]);

        $duplicateResponse->assertStatus(409)
            ->assertJsonPath('status', 'duplicate')
            ->assertJsonPath('current_status', 'processing');
    }
}
