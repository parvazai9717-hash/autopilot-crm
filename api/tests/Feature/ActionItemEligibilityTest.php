<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use App\Services\ActionItemEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActionItemEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create([
            'name'     => 'Eligibility Org',
            'timezone' => 'Asia/Karachi',
            'settings' => '{}',
        ]);

        $this->user = User::create([
            'org_id'    => $this->org->id,
            'name'      => 'Eligible User',
            'email'     => 'eligible@test.com',
            'password'  => bcrypt('secret'),
            'role'      => 'employee',
            'status'    => 'active',
            'is_active' => true,
        ]);
    }

    public function test_eligible_task_passes_evaluation(): void
    {
        $task = Task::create([
            'org_id'      => $this->org->id,
            'owner_id'    => $this->user->id,
            'owner_state' => 'resolved',
            'title'       => 'Valid Task',
            'status'      => 'pending_approval',
            'priority'    => 'medium',
        ]);

        $eval = ActionItemEligibility::evaluate($task);
        $this->assertTrue($eval['eligible']);
        $this->assertEmpty($eval['blockers']);
    }

    public function test_missing_owner_is_blocked(): void
    {
        $task = Task::create([
            'org_id'      => $this->org->id,
            'owner_id'    => null,
            'owner_state' => 'missing',
            'title'       => 'No Owner Task',
            'status'      => 'pending_approval',
            'priority'    => 'medium',
        ]);

        $eval = ActionItemEligibility::evaluate($task);
        $this->assertFalse($eval['eligible']);
        $this->assertContains(ActionItemEligibility::OWNER_REQUIRED, $eval['blockers']);
    }

    public function test_ambiguous_owner_is_blocked(): void
    {
        $task = Task::create([
            'org_id'          => $this->org->id,
            'owner_id'        => null,
            'owner_ambiguous' => true,
            'owner_state'     => 'ambiguous',
            'title'           => 'Ambiguous Task',
            'status'          => 'pending_approval',
            'priority'        => 'medium',
        ]);

        $eval = ActionItemEligibility::evaluate($task);
        $this->assertFalse($eval['eligible']);
        $this->assertContains(ActionItemEligibility::OWNER_AMBIGUOUS, $eval['blockers']);
    }

    public function test_owner_from_different_org_is_blocked(): void
    {
        $otherOrg = Organization::create([
            'name'     => 'Other Org',
            'timezone' => 'Asia/Karachi',
            'settings' => '{}',
        ]);

        $alienUser = User::create([
            'org_id'    => $otherOrg->id,
            'name'      => 'Alien User',
            'email'     => 'alien@test.com',
            'password'  => bcrypt('secret'),
            'role'      => 'employee',
            'status'    => 'active',
            'is_active' => true,
        ]);

        $task = Task::create([
            'org_id'      => $this->org->id,
            'owner_id'    => $alienUser->id,
            'owner_state' => 'resolved',
            'title'       => 'Cross Org Task',
            'status'      => 'pending_approval',
            'priority'    => 'medium',
        ]);

        $eval = ActionItemEligibility::evaluate($task);
        $this->assertFalse($eval['eligible']);
        $this->assertContains(ActionItemEligibility::OWNER_NOT_IN_ORG, $eval['blockers']);
    }

    public function test_inactive_owner_is_blocked(): void
    {
        $inactiveUser = User::create([
            'org_id'    => $this->org->id,
            'name'      => 'Inactive User',
            'email'     => 'inactive@test.com',
            'password'  => bcrypt('secret'),
            'role'      => 'employee',
            'status'    => 'inactive',
            'is_active' => false,
        ]);

        $task = Task::create([
            'org_id'      => $this->org->id,
            'owner_id'    => $inactiveUser->id,
            'owner_state' => 'resolved',
            'title'       => 'Inactive Owner Task',
            'status'      => 'pending_approval',
            'priority'    => 'medium',
        ]);

        $eval = ActionItemEligibility::evaluate($task);
        $this->assertFalse($eval['eligible']);
        $this->assertContains(ActionItemEligibility::OWNER_INACTIVE, $eval['blockers']);
    }

    public function test_conditional_task_requires_acknowledgement(): void
    {
        $task = Task::create([
            'org_id'          => $this->org->id,
            'owner_id'        => $this->user->id,
            'owner_state'     => 'resolved',
            'title'           => 'Conditional Task',
            'status'          => 'pending_approval',
            'priority'        => 'medium',
            'conditional'     => true,
            'conditional_ack' => false,
        ]);

        $eval = ActionItemEligibility::evaluate($task);
        $this->assertFalse($eval['eligible']);
        $this->assertContains(ActionItemEligibility::CONDITIONAL_UNACKNOWLEDGED, $eval['blockers']);

        // Now acknowledge
        $task->conditional_ack = true;
        $evalAcknowledged = ActionItemEligibility::evaluate($task);
        $this->assertTrue($evalAcknowledged['eligible']);
    }
}
