<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class OrganizationController extends Controller
{
    /**
     * GET /api/v1/orgs/{id}/settings
     * Returns org policy/settings JSON for the automation layer.
     */
    public function settings(int $id): JsonResponse
    {
        $organization = Organization::find($id);

        if (!$organization) {
            return ApiResponse::error(
                'NOT_FOUND',
                'Organization not found.',
                404
            );
        }

        return response()->json($organization->settings ?? []);
    }
}
