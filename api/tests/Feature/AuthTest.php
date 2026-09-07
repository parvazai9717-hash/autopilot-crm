<?php

namespace Tests\Feature;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthTest extends TestCase
{
    public function test_user_can_login_successfully(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'ahmed@test.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'user' => [
                    'id',
                    'org_id',
                    'name',
                    'email',
                    'role',
                    'status',
                    'manager_id',
                    'organization' => ['id', 'name', 'timezone'],
                ],
                'token',
            ])
            ->assertJsonPath('user.email', 'ahmed@test.com')
            ->assertJsonPath('user.role', 'employee');
    }

    public function test_manager_login(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'bilal@test.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('user.email', 'bilal@test.com')
            ->assertJsonPath('user.role', 'manager');
    }

    public function test_admin_login(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'admin@test.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('user.email', 'admin@test.com')
            ->assertJsonPath('user.role', 'admin');
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'ahmed@test.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'error' => [
                    'code' => 'INVALID_CREDENTIALS',
                    'message' => 'These credentials do not match our records.',
                    'field' => 'email',
                ],
            ]);
    }

    public function test_inactive_user_cannot_login(): void
    {
        $user = User::withoutGlobalScopes()->where('email', 'ahmed@test.com')->first();
        $user->update(['status' => 'inactive']);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'ahmed@test.com',
            'password' => 'password123',
        ]);

        // Restore status
        $user->update(['status' => 'active']);

        $response->assertStatus(403)
            ->assertJson([
                'error' => [
                    'code' => 'ACCOUNT_INACTIVE',
                    'message' => 'Your account is deactivated. Please contact an administrator.',
                    'field' => null,
                ],
            ]);
    }

    public function test_login_validation_errors_follow_spec_format(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'not-an-email',
            'password' => '',
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'error' => [
                    'code',
                    'message',
                    'field',
                ],
            ])
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_authenticated_user_can_access_me_endpoint(): void
    {
        $user = User::withoutGlobalScopes()->where('email', 'ahmed@test.com')->first();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/auth/me');

        $response->assertStatus(200)
            ->assertJsonPath('user.email', 'ahmed@test.com')
            ->assertJsonPath('user.id', 1);
    }

    public function test_unauthenticated_request_returns_spec_401(): void
    {
        $response = $this->getJson('/api/auth/me');

        $response->assertStatus(401)
            ->assertJson([
                'error' => [
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Unauthenticated.',
                    'field' => null,
                ],
            ]);
    }

    public function test_user_can_logout(): void
    {
        $user = User::withoutGlobalScopes()->where('email', 'ahmed@test.com')->first();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/auth/logout');

        $response->assertStatus(200)
            ->assertJson([
                'ok' => true,
                'message' => 'Logged out successfully.',
            ]);
    }

    public function test_spa_session_user_can_logout(): void
    {
        $user = User::withoutGlobalScopes()->where('email', 'ahmed@test.com')->first();
        $this->actingAs($user, 'web');

        $response = $this->postJson('/api/auth/logout');

        $response->assertStatus(200)
            ->assertJson([
                'ok' => true,
                'message' => 'Logged out successfully.',
            ]);
    }
}
