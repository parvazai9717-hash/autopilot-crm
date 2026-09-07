<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\Organization;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class PolicyTest extends TestCase
{
    public function test_employee_can_view_and_update_own_task(): void
    {
        $employee = User::withoutGlobalScopes()->where('email', 'ahmed@test.com')->first();
        $ownTask = Task::withoutGlobalScopes()->where('owner_id', $employee->id)->first();

        if (!$ownTask) {
            $ownTask = Task::withoutGlobalScopes()->create([
                'org_id' => $employee->org_id,
                'title' => 'Ahmed Test Task',
                'owner_id' => $employee->id,
                'priority' => 'high',
                'status' => 'assigned',
            ]);
        }

        $this->assertTrue(Gate::forUser($employee)->allows('view', $ownTask));
        $this->assertTrue(Gate::forUser($employee)->allows('update', $ownTask));
    }

    public function test_employee_cannot_view_or_update_other_employee_task(): void
    {
        $ahmed = User::withoutGlobalScopes()->where('email', 'ahmed@test.com')->first();
        $sarah = User::withoutGlobalScopes()->where('email', 'sarah@test.com')->first();

        $sarahTask = Task::withoutGlobalScopes()->where('owner_id', $sarah->id)->first();

        if (!$sarahTask) {
            $sarahTask = Task::withoutGlobalScopes()->create([
                'org_id' => $sarah->org_id,
                'title' => 'Sarah Test Task',
                'owner_id' => $sarah->id,
                'priority' => 'medium',
                'status' => 'assigned',
            ]);
        }

        $this->assertFalse(Gate::forUser($ahmed)->allows('view', $sarahTask));
        $this->assertFalse(Gate::forUser($ahmed)->allows('update', $sarahTask));
    }

    public function test_manager_can_view_and_update_direct_reports_tasks(): void
    {
        $manager = User::withoutGlobalScopes()->where('email', 'bilal@test.com')->first();
        $ahmed = User::withoutGlobalScopes()->where('email', 'ahmed@test.com')->first();

        $ahmedTask = Task::withoutGlobalScopes()->where('owner_id', $ahmed->id)->first();

        $this->assertTrue(Gate::forUser($manager)->allows('view', $ahmedTask));
        $this->assertTrue(Gate::forUser($manager)->allows('update', $ahmedTask));
    }

    public function test_executive_can_view_all_tasks_but_cannot_update_others(): void
    {
        $exec = User::withoutGlobalScopes()->where('email', 'exec@test.com')->first();
        $ahmed = User::withoutGlobalScopes()->where('email', 'ahmed@test.com')->first();
        $ahmedTask = Task::withoutGlobalScopes()->where('owner_id', $ahmed->id)->first();

        $this->assertTrue(Gate::forUser($exec)->allows('view', $ahmedTask));
        $this->assertFalse(Gate::forUser($exec)->allows('update', $ahmedTask));
    }

    public function test_admin_can_view_update_and_delete_all_tasks(): void
    {
        $admin = User::withoutGlobalScopes()->where('email', 'admin@test.com')->first();
        $ahmed = User::withoutGlobalScopes()->where('email', 'ahmed@test.com')->first();
        $ahmedTask = Task::withoutGlobalScopes()->where('owner_id', $ahmed->id)->first();

        $this->assertTrue(Gate::forUser($admin)->allows('view', $ahmedTask));
        $this->assertTrue(Gate::forUser($admin)->allows('update', $ahmedTask));
        $this->assertTrue(Gate::forUser($admin)->allows('delete', $ahmedTask));
    }

    public function test_admin_can_review_and_retry_meetings(): void
    {
        $admin = User::withoutGlobalScopes()->where('email', 'admin@test.com')->first();
        $meeting = Meeting::withoutGlobalScopes()->first();

        $this->assertTrue(Gate::forUser($admin)->allows('review', $meeting));
        $this->assertTrue(Gate::forUser($admin)->allows('retry', $meeting));
    }

    public function test_admin_can_update_organization_settings(): void
    {
        $admin = User::withoutGlobalScopes()->where('email', 'admin@test.com')->first();
        $employee = User::withoutGlobalScopes()->where('email', 'ahmed@test.com')->first();
        $org = Organization::first();

        $this->assertTrue(Gate::forUser($admin)->allows('update', $org));
        $this->assertFalse(Gate::forUser($employee)->allows('update', $org));
    }

    public function test_meeting_creator_can_review_meeting(): void
    {
        $sarah = User::withoutGlobalScopes()->where('email', 'sarah@test.com')->first();
        $ahmed = User::withoutGlobalScopes()->where('email', 'ahmed@test.com')->first();

        $meeting = Meeting::withoutGlobalScopes()->create([
            'org_id' => $sarah->org_id,
            'title' => 'Sarahs Uploaded Meeting',
            'meeting_date' => now()->toDateString(),
            'timezone' => 'Asia/Karachi',
            'source' => 'upload',
            'created_by' => $sarah->id,
            'status' => 'extracted',
        ]);

        $this->assertTrue(Gate::forUser($sarah)->allows('review', $meeting));
        $this->assertFalse(Gate::forUser($ahmed)->allows('review', $meeting));
    }

    public function test_cross_tenant_access_denied(): void
    {
        $admin = User::withoutGlobalScopes()->where('email', 'admin@test.com')->first();

        $otherOrg = Organization::create([
            'name' => 'Other Tenant Corp',
            'timezone' => 'UTC',
            'settings' => [],
        ]);

        $otherOrgTask = Task::withoutGlobalScopes()->create([
            'org_id' => $otherOrg->id,
            'title' => 'Other Org Task',
            'priority' => 'high',
            'status' => 'assigned',
        ]);

        $this->assertFalse(Gate::forUser($admin)->allows('view', $otherOrgTask));
        $this->assertFalse(Gate::forUser($admin)->allows('update', $otherOrgTask));
    }
}
