<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

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

        if (!Auth::attempt($credentials, (bool) $request->input('remember', false))) {
            return ApiResponse::error(
                'INVALID_CREDENTIALS',
                'These credentials do not match our records.',
                401,
                'email'
            );
        }

        /** @var User $user */
        $user = Auth::user();

        if ($user->status !== 'active') {
            Auth::guard('web')->logout();
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

        return response()->json([
            'user' => [
                'id' => $user->id,
                'org_id' => $user->org_id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'status' => $user->status,
                'manager_id' => $user->manager_id,
                'organization' => $user->organization ? [
                    'id' => $user->organization->id,
                    'name' => $user->organization->name,
                    'timezone' => $user->organization->timezone,
                ] : null,
            ],
            'token' => $token,
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
                'organization' => $user->organization ? [
                    'id' => $user->organization->id,
                    'name' => $user->organization->name,
                    'timezone' => $user->organization->timezone,
                    'settings' => $user->organization->settings,
                ] : null,
            ],
        ]);
    }
}
