<?php

namespace App\Http\Controllers\Internal\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrgsController extends Controller
{
    /**
     * GET /internal/v1/orgs/active
     * Returns all active tenant organizations for the n8n automation runner.
     */
    public function active(Request $request): JsonResponse
    {
        $orgs = Organization::withoutGlobalScopes()
            ->select(['id', 'name', 'timezone', 'created_at'])
            ->get();

        return response()->json([
            'ok'    => true,
            'count' => $orgs->count(),
            'orgs'  => $orgs->map(fn ($org) => [
                'id'       => $org->id,
                'name'     => $org->name,
                'timezone' => $org->timezone ?? 'Asia/Karachi',
            ]),
        ]);
    }
}
