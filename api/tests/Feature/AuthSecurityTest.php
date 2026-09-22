<?php

namespace Tests\Feature;

use App\Models\Invitation;
use App\Models\LoginEvent;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthSecurityTest extends TestCase
{
    protected Organization $org;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear(strtolower('ahmed@test.com') . '|127.0.0.1');

        $this->org = Organization::firstOrCreate(
            ['id' => 1],
            ['name' => 'Acme Corp', 'timezone' => 'UTC', 'settings' => '{}']
        );

        $this->user = User::withoutGlobalScopes()->where('email', 'testuser@example.com')->first()
            ?? User::create([
                'org_id' => $this->org->id,
                'name' => 'Test User',
                'email' => 'testuser@example.com',
                'password' => Hash::make('CorrectPassword123!'),
                'role' => 'employee',
                'status' => 'active',
                'is_active' => true,
            ]);

        RateLimiter::clear(strtolower($this->user->email) . '|127.0.0.1');
    }

    public function test_uniform_error_for_non_existent_email(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'nonexistent@example.com',
            'password' => 'somePassword123!',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_CREDENTIALS')
            ->assertJsonPath('error.message', 'These credentials do not match our records.')
            ->assertJsonPath('error.field', 'email');
    }

    public function test_login_events_audit_trail_recorded(): void
    {
        // 1. Failed login
        $this->postJson('/api/auth/login', [
            'email' => $this->user->email,
            'password' => 'WrongPassword!',
        ]);

        $this->assertDatabaseHas('login_events', [
            'email' => $this->user->email,
            'event_type' => 'login_failed',
        ]);

        // 2. Successful login
        $response = $this->postJson('/api/auth/login', [
            'email' => $this->user->email,
            'password' => 'CorrectPassword123!',
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('login_events', [
            'email' => $this->user->email,
            'event_type' => 'login_success',
        ]);
    }

    public function test_login_rate_limiting_triggers_429_after_5_failures(): void
    {
        $throttleKey = strtolower($this->user->email) . '|127.0.0.1';
        RateLimiter::clear($throttleKey);

        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/auth/login', [
                'email' => $this->user->email,
                'password' => 'WrongPassword' . $i,
            ]);
            $response->assertStatus(401);
        }

        // 6th attempt should be rate limited
        $response = $this->postJson('/api/auth/login', [
            'email' => $this->user->email,
            'password' => 'CorrectPassword123!',
        ]);

        $response->assertStatus(429)
            ->assertJsonPath('error.code', 'TOO_MANY_REQUESTS');
    }

    public function test_account_lockout_after_consecutive_failures(): void
    {
        $throttleKey = strtolower($this->user->email) . '|127.0.0.1';
        RateLimiter::clear($throttleKey);

        // 5 consecutive failed attempts
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => $this->user->email,
                'password' => 'WrongPassword',
            ]);
        }

        $this->user->refresh();
        $this->assertTrue($this->user->isLocked());
        $this->assertNotNull($this->user->locked_until);

        // Clear IP rate limiter to test account lockout specifically
        RateLimiter::clear($throttleKey);

        $response = $this->postJson('/api/auth/login', [
            'email' => $this->user->email,
            'password' => 'CorrectPassword123!',
        ]);

        $response->assertStatus(429)
            ->assertJsonPath('error.code', 'ACCOUNT_LOCKED');
    }

    public function test_security_force_reset_command(): void
    {
        // Issue tokens
        $token1 = $this->user->createToken('token1')->plainTextToken;
        $token2 = $this->user->createToken('token2')->plainTextToken;

        $this->assertCount(2, $this->user->tokens);

        // Run artisan security:force-reset
        $this->artisan('security:force-reset', [
            '--user' => $this->user->id,
        ])->assertSuccessful();

        $this->user->refresh();
        $this->assertTrue((bool) $this->user->must_change_password);
        $this->assertCount(0, $this->user->tokens);

        $this->assertDatabaseHas('login_events', [
            'email' => $this->user->email,
            'event_type' => 'force_reset',
        ]);

        // Logging in indicates must_change_password = true
        RateLimiter::clear(strtolower($this->user->email) . '|127.0.0.1');
        $this->user->update(['failed_login_count' => 0, 'locked_until' => null]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $this->user->email,
            'password' => 'CorrectPassword123!',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('must_change_password', true);
    }

    public function test_forgot_password_and_reset_password_lifecycle(): void
    {
        $forgotResponse = $this->postJson('/api/auth/forgot-password', [
            'email' => $this->user->email,
        ]);

        $forgotResponse->assertStatus(200)
            ->assertJsonPath('ok', true);

        $token = $forgotResponse->json('reset_token');
        $this->assertNotEmpty($token);

        $this->assertDatabaseHas('login_events', [
            'email' => $this->user->email,
            'event_type' => 'password_reset_requested',
        ]);

        // Attempt reset with invalid token
        $badReset = $this->postJson('/api/auth/reset-password', [
            'email' => $this->user->email,
            'token' => 'invalid-token-123',
            'password' => 'NewSecurePassword123!',
            'password_confirmation' => 'NewSecurePassword123!',
        ]);
        $badReset->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_RESET_TOKEN');

        // Valid reset
        $goodReset = $this->postJson('/api/auth/reset-password', [
            'email' => $this->user->email,
            'token' => $token,
            'password' => 'NewSecurePassword123!',
            'password_confirmation' => 'NewSecurePassword123!',
        ]);
        $goodReset->assertStatus(200)
            ->assertJsonPath('ok', true);

        $this->user->refresh();
        $this->assertNotNull($this->user->password_changed_at);
        $this->assertFalse((bool) $this->user->must_change_password);

        // Old password fails
        RateLimiter::clear(strtolower($this->user->email) . '|127.0.0.1');
        $failLogin = $this->postJson('/api/auth/login', [
            'email' => $this->user->email,
            'password' => 'CorrectPassword123!',
        ]);
        $failLogin->assertStatus(401);

        // New password succeeds
        RateLimiter::clear(strtolower($this->user->email) . '|127.0.0.1');
        $this->user->update(['failed_login_count' => 0, 'locked_until' => null]);
        $successLogin = $this->postJson('/api/auth/login', [
            'email' => $this->user->email,
            'password' => 'NewSecurePassword123!',
        ]);
        $successLogin->assertStatus(200)
            ->assertJsonPath('must_change_password', false);
    }

    public function test_authenticated_password_change(): void
    {
        $token = $this->user->createToken('active-session')->plainTextToken;
        $otherToken = $this->user->createToken('mobile-session')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/auth/change-password', [
                'current_password' => 'CorrectPassword123!',
                'password' => 'BrandNewPassword456!',
                'password_confirmation' => 'BrandNewPassword456!',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('ok', true);

        $this->user->refresh();
        $this->assertTrue(Hash::check('BrandNewPassword456!', $this->user->password));

        // Other token revoked, current token preserved
        $this->assertCount(1, $this->user->tokens);
    }

    public function test_invitation_acceptance_lifecycle(): void
    {
        $tokenData = Invitation::generateToken();

        $invitation = Invitation::create([
            'org_id' => $this->org->id,
            'email' => 'newcolleague@example.com',
            'role' => 'employee',
            'token_hash' => $tokenData['hash'],
            'invited_by' => $this->user->id,
            'expires_at' => now()->addDays(7),
        ]);

        $response = $this->postJson('/api/auth/invitations/' . $tokenData['plain'] . '/accept', [
            'name' => 'New Colleague',
            'password' => 'InitialPassword789!',
            'password_confirmation' => 'InitialPassword789!',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('user.email', 'newcolleague@example.com');

        $this->assertNotNull($response->json('token'));

        $invitation->refresh();
        $this->assertTrue($invitation->isAccepted());

        $createdUser = User::withoutGlobalScopes()->where('email', 'newcolleague@example.com')->first();
        $this->assertNotNull($createdUser);
        $this->assertEquals($this->org->id, $createdUser->org_id);
    }
}
