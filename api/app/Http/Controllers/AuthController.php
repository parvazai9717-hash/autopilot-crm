<?php

namespace App\Http\Controllers;

use App\Models\Invitation;
use App\Models\LoginEvent;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    /**
     * Authenticate user and initiate SPA session / token.
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $throttleKey = Str::lower($request->input('email')) . '|' . $request->ip();

        // 1. Rate limiter check (5 attempts per minute per email+IP)
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return ApiResponse::error(
                'TOO_MANY_REQUESTS',
                "Too many login attempts. Please try again in {$seconds} seconds.",
                429
            );
        }

        // 2. Account lockout check
        /** @var User|null $existingUser */
        $existingUser = User::withoutGlobalScopes()
            ->where('email', $request->input('email'))
            ->first();

        if ($existingUser && $existingUser->isLocked()) {
            $minutes = max(1, (int) ceil(now()->diffInSeconds($existingUser->locked_until) / 60));
            return ApiResponse::error(
                'ACCOUNT_LOCKED',
                "Account is temporarily locked due to multiple failed login attempts. Please try again in {$minutes} minutes.",
                429
            );
        }

        // 3. Attempt authentication
        if (!Auth::attempt($credentials, (bool) $request->input('remember', false))) {
            RateLimiter::hit($throttleKey, 60);

            if ($existingUser) {
                $existingUser->incrementFailedLogins($request);
            } else {
                LoginEvent::record('login_failed', (string) $request->input('email'), null, null, $request);
            }

            return ApiResponse::error(
                'INVALID_CREDENTIALS',
                'These credentials do not match our records.',
                401,
                'email'
            );
        }

        // 4. Successful credentials verification
        RateLimiter::clear($throttleKey);

        /** @var User $user */
        $user = Auth::user();
        $user->resetFailedLogins();

        // 5. Account active status check
        if ($user->status !== 'active' || (isset($user->is_active) && !$user->is_active)) {
            Auth::guard('web')->logout();

            LoginEvent::record(
                'login_failed',
                $user->email,
                $user->id,
                $user->org_id,
                $request,
                ['reason' => 'account_inactive']
            );

            return ApiResponse::error(
                'ACCOUNT_INACTIVE',
                'Your account is deactivated. Please contact an administrator.',
                403
            );
        }

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        // 6. Record login success audit event
        LoginEvent::record('login_success', $user->email, $user->id, $user->org_id, $request);

        return response()->json([
            'user' => [
                'id' => $user->id,
                'org_id' => $user->org_id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'status' => $user->status,
                'manager_id' => $user->manager_id,
                'must_change_password' => (bool) $user->must_change_password,
                'organization' => $user->organization ? [
                    'id' => $user->organization->id,
                    'name' => $user->organization->name,
                    'timezone' => $user->organization->timezone,
                ] : null,
            ],
            'token' => $token,
            'must_change_password' => (bool) $user->must_change_password,
        ]);
    }

    /**
     * Terminate the session / revoke auth token.
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user) {
            $token = $user->currentAccessToken();
            if ($token && method_exists($token, 'delete')) {
                $token->delete();
            }

            LoginEvent::record('token_revoked', $user->email, $user->id, $user->org_id, $request);
        }

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json([
            'ok' => true,
            'message' => 'Logged out successfully.',
        ]);
    }

    /**
     * Get the authenticated user's profile and organization.
     */
    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'user' => [
                'id' => $user->id,
                'org_id' => $user->org_id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'status' => $user->status,
                'manager_id' => $user->manager_id,
                'must_change_password' => (bool) $user->must_change_password,
                'password_changed_at' => $user->password_changed_at?->toIso8601String(),
                'organization' => $user->organization ? [
                    'id' => $user->organization->id,
                    'name' => $user->organization->name,
                    'timezone' => $user->organization->timezone,
                    'settings' => $user->organization->settings,
                ] : null,
            ],
        ]);
    }

    /**
     * Initiate password reset flow.
     * Always returns success message to avoid email enumeration.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $email = (string) $request->input('email');
        $user = User::withoutGlobalScopes()->where('email', $email)->first();

        $debugToken = null;

        if ($user && $user->status === 'active' && ($user->is_active ?? true)) {
            $plainToken = Str::random(64);

            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $email],
                [
                    'token' => Hash::make($plainToken),
                    'created_at' => now(),
                ]
            );

            LoginEvent::record('password_reset_requested', $email, $user->id, $user->org_id, $request);

            if (app()->environment('testing', 'local')) {
                $debugToken = $plainToken;
            }
        }

        $payload = [
            'ok' => true,
            'message' => 'If that email address is in our system, a password reset link has been sent.',
        ];

        if ($debugToken) {
            $payload['reset_token'] = $debugToken;
        }

        return response()->json($payload);
    }

    /**
     * Reset user password using verified reset token.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $email = (string) $request->input('email');
        $token = (string) $request->input('token');

        $record = DB::table('password_reset_tokens')->where('email', $email)->first();

        if (!$record || !Hash::check($token, $record->token)) {
            return ApiResponse::error(
                'INVALID_RESET_TOKEN',
                'This password reset token is invalid or has expired.',
                422,
                'token'
            );
        }

        // Check token expiry (60 minutes)
        if ($record->created_at && now()->diffInMinutes($record->created_at) > 60) {
            DB::table('password_reset_tokens')->where('email', $email)->delete();
            return ApiResponse::error(
                'EXPIRED_RESET_TOKEN',
                'This password reset token has expired. Please request a new one.',
                422,
                'token'
            );
        }

        $user = User::withoutGlobalScopes()->where('email', $email)->first();
        if (!$user) {
            return ApiResponse::error(
                'INVALID_RESET_TOKEN',
                'This password reset token is invalid or has expired.',
                422,
                'token'
            );
        }

        $user->password = Hash::make($request->input('password'));
        $user->password_changed_at = now();
        $user->must_change_password = false;
        $user->failed_login_count = 0;
        $user->locked_until = null;
        $user->save();

        // Invalidate all active access tokens
        $user->tokens()->delete();

        // Clear used reset token
        DB::table('password_reset_tokens')->where('email', $email)->delete();

        LoginEvent::record('password_reset_completed', $user->email, $user->id, $user->org_id, $request);

        return response()->json([
            'ok' => true,
            'message' => 'Password has been successfully reset. Please log in with your new password.',
        ]);
    }

    /**
     * Authenticated password change.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed', 'different:current_password'],
        ]);

        /** @var User $user */
        $user = $request->user();

        if (!Hash::check($request->input('current_password'), $user->password)) {
            return ApiResponse::error(
                'INVALID_PASSWORD',
                'The current password you provided is incorrect.',
                422,
                'current_password'
            );
        }

        $user->password = Hash::make($request->input('password'));
        $user->password_changed_at = now();
        $user->must_change_password = false;
        $user->save();

        // Revoke other tokens except current session token
        $currentTokenId = $user->currentAccessToken()?->id;
        if ($currentTokenId) {
            $user->tokens()->where('id', '!=', $currentTokenId)->delete();
        }

        LoginEvent::record('password_changed', $user->email, $user->id, $user->org_id, $request);

        return response()->json([
            'ok' => true,
            'message' => 'Password has been successfully updated.',
        ]);
    }

    /**
     * Accept organization invitation.
     */
    public function acceptInvitation(Request $request, string $token): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $tokenHash = hash('sha256', $token);

        /** @var Invitation|null $invitation */
        $invitation = Invitation::withoutGlobalScopes()->where('token_hash', $tokenHash)->first();

        if (!$invitation || $invitation->isAccepted() || $invitation->isExpired()) {
            return ApiResponse::error(
                'INVALID_INVITATION',
                'This invitation link is invalid or has expired.',
                422
            );
        }

        \App\Services\TenantContext::set($invitation->org_id);

        // Find or create user
        $user = User::withoutGlobalScopes()->where('email', $invitation->email)->first();

        if (!$user) {
            $user = User::create([
                'org_id' => $invitation->org_id,
                'name' => $request->input('name'),
                'email' => $invitation->email,
                'password' => Hash::make($request->input('password')),
                'role' => $invitation->role,
                'manager_id' => $invitation->manager_id,
                'status' => 'active',
                'is_active' => true,
                'password_changed_at' => now(),
                'must_change_password' => false,
            ]);
        } else {
            $user->update([
                'org_id' => $invitation->org_id,
                'name' => $request->input('name'),
                'password' => Hash::make($request->input('password')),
                'role' => $invitation->role,
                'manager_id' => $invitation->manager_id,
                'status' => 'active',
                'is_active' => true,
                'password_changed_at' => now(),
                'must_change_password' => false,
            ]);
        }

        $invitation->update(['accepted_at' => now()]);

        $token = $user->createToken('auth_token')->plainTextToken;

        LoginEvent::record('invitation_accepted', $user->email, $user->id, $user->org_id, $request);

        return response()->json([
            'ok' => true,
            'message' => 'Invitation accepted successfully.',
            'user' => [
                'id' => $user->id,
                'org_id' => $user->org_id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'status' => $user->status,
            ],
            'token' => $token,
        ]);
    }
}
