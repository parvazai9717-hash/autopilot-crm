<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

class ApiResponse
{
    /**
     * Standardized error response conforming to Section 13 of SPEC.md.
     */
    public static function error(string $code, string $message, int $status = 400, ?string $field = null): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
                'field' => $field,
            ],
        ], $status);
    }

    /**
     * Standardized success response.
     */
    public static function success(mixed $data = null, int $status = 200): JsonResponse
    {
        if ($data === null) {
            return response()->json(['ok' => true], $status);
        }

        if (is_array($data)) {
            return response()->json($data, $status);
        }

        return response()->json(['data' => $data], $status);
    }
}
