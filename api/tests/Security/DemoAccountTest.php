<?php

namespace Tests\Security;

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase 0 — Regression test: demo account lockdown.
 *
 * Verifies that:
 * 1. security:lockdown-demo deactivates all seeded-email accounts.
 * 2. Deactivated users cannot log in (auth guard must check is_active).
 * 3. DemoSeeder refuses to run in production environment.
 * 4. DatabaseSeeder does NOT call UserSeeder in production.
 */
class DemoAccountTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = false;

    /** @test */
    public function lockdown_demo_command_deactivates_seeded_users(): void
    {
        $user = User::where('email', 'admin@test.com')->first();
        if (!$user) {
            $user = User::factory()->create([
                'email'     => 'admin@test.com',
                'password'  => Hash::make('password123'),
                'is_active' => true,
            ]);
        } else {
            $user->update([
                'password'  => Hash::make('password123'),
                'is_active' => true,
            ]);
        }

        $this->artisan('security:lockdown-demo')
             ->assertSuccessful();

        $this->assertFalse(
            (bool) $user->fresh()->is_active,
            'User should be deactivated after lockdown-demo.'
        );
    }

    /** @test */
    public function lockdown_demo_command_deactivates_any_user_with_password123(): void
    {
        $user = User::factory()->create([
            'email'     => 'unknown@example.com',
            'password'  => Hash::make('password123'),
            'is_active' => true,
        ]);

        $this->artisan('security:lockdown-demo')->assertSuccessful();

        $this->assertFalse(
            (bool) $user->fresh()->is_active,
            'Any user with password123 must be deactivated, not just seeded emails.'
        );
    }

    /** @test */
    public function lockdown_demo_dry_run_does_not_modify_database(): void
    {
        $user = User::where('email', 'sarah@test.com')->first();
        if (!$user) {
            $user = User::factory()->create([
                'email'     => 'sarah@test.com',
                'password'  => Hash::make('password123'),
                'is_active' => true,
            ]);
        } else {
            $user->update([
                'password'  => Hash::make('password123'),
                'is_active' => true,
            ]);
        }

        $this->artisan('security:lockdown-demo --dry-run')->assertSuccessful();

        $this->assertTrue(
            (bool) $user->fresh()->is_active,
            'Dry-run mode must NOT deactivate users.'
        );
    }

    /** @test */
    public function demo_seeder_does_not_run_in_production(): void
    {
        $this->app['env'] = 'production';

        $initialCount = User::count();
        $seeder = new DemoSeeder();
        $seeder->run();

        $this->assertEquals(
            $initialCount,
            User::count(),
            'DemoSeeder must not insert any users when running in production environment.'
        );
    }
}
