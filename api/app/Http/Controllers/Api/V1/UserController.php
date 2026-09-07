<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    /**
     * GET /api/v1/users?org_id=1
     * Returns roster of users with their manager details.
     */
    public function index(Request $request): JsonResponse
    {
        $orgId = $request->query('org_id');

        if (!$orgId) {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'The org_id query parameter is required.',
                422,
                'org_id'
            );
        }

        $organization = Organization::find($orgId);
        if (!$organization) {
            return ApiResponse::error(
                'NOT_FOUND',
                'Organization not found.',
                404,
                'org_id'
            );
        }

        $users = User::withoutGlobalScopes()
            ->where('org_id', $orgId)
            ->with('manager')
            ->orderBy('id', 'asc')
            ->get()
            ->map(function (User $user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'manager_id' => $user->manager_id,
                    'manager_name' => $user->manager?->name,
                    'manager_email' => $user->manager?->email,
                ];
            });

        return response()->json($users);
    }
}
