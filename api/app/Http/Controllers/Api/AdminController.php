<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Services\WebhookService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AdminController extends Controller
{
    /**
     * Ensure the authenticated user has admin privileges.
     */
    protected function authorizeAdmin(Request $request): ?JsonResponse
    {
        $user = $request->user();
        if (!$user || !$user->isAdmin()) {
            return ApiResponse::error('FORBIDDEN', 'Administrator access required.', 403);
        }
        return null;
    }

    // =========================================================================
    // 1. USER MANAGEMENT
    // =========================================================================

    /**
     * GET /api/admin/users
     * List all users in the organization with their roles and manager assignments.
     */
    public function users(Request $request): JsonResponse
    {
        if ($deny = $this->authorizeAdmin($request)) {
            return $deny;
        }

        $user = $request->user();

        $users = User::withoutGlobalScopes()
            ->where('org_id', $user->org_id)
            ->with(['manager:id,name,email'])
            ->withCount('directReports')
            ->orderBy('id', 'asc')
            ->get()
            ->map(function (User $u) {
                return [
                    'id'                   => $u->id,
                    'name'                 => $u->name,
                    'email'                => $u->email,
                    'phone'                => $u->phone,
                    'role'                 => $u->role,
                    'status'               => $u->status ?? 'active',
                    'manager_id'           => $u->manager_id,
                    'manager'              => $u->manager ? [
                        'id'    => $u->manager->id,
                        'name'  => $u->manager->name,
                        'email' => $u->manager->email,
                    ] : null,
                    'direct_reports_count' => $u->direct_reports_count,
                    'created_at'           => $u->created_at?->toIso8601String(),
                ];
            });

        return response()->json([
            'users' => $users,
        ]);
    }

    /**
     * POST /api/admin/users
     * Create a new user in the organization.
     */
    public function storeUser(Request $request): JsonResponse
    {
        if ($deny = $this->authorizeAdmin($request)) {
            return $deny;
        }

        $authUser = $request->user();

        $validator = Validator::make($request->all(), [
            'name'       => 'required|string|max:255',
            'email'      => 'required|email|max:255|unique:users,email',
            'password'   => 'required|string|min:8',
            'role'       => 'required|in:employee,manager,admin,executive',
            'manager_id' => 'nullable|integer|exists:users,id',
            'phone'      => 'nullable|string|max:50',
            'status'     => 'nullable|in:active,inactive',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                $validator->errors()->first(),
                422,
                array_key_first($validator->errors()->toArray())
            );
        }

        $data = $validator->validated();

        if (!empty($data['manager_id'])) {
            $manager = User::withoutGlobalScopes()->find($data['manager_id']);
            if (!$manager || $manager->org_id !== $authUser->org_id) {
                return ApiResponse::error('VALIDATION_ERROR', 'Assigned manager must belong to your organization.', 422, 'manager_id');
            }
        }

        $newUser = User::withoutGlobalScopes()->create([
            'org_id'     => $authUser->org_id,
            'name'       => $data['name'],
            'email'      => $data['email'],
            'password'   => Hash::make($data['password']),
            'role'       => $data['role'],
            'manager_id' => $data['manager_id'] ?? null,
            'phone'      => $data['phone'] ?? null,
            'status'     => $data['status'] ?? 'active',
        ]);

        return response()->json([
            'user' => [
                'id'         => $newUser->id,
                'name'       => $newUser->name,
                'email'      => $newUser->email,
                'role'       => $newUser->role,
                'manager_id' => $newUser->manager_id,
                'status'     => $newUser->status,
            ],
            'message' => 'User created successfully.',
        ], 201);
    }

    /**
     * PUT /api/admin/users/{id}
     * Update an existing user's details, role, or manager assignment.
     */
    public function updateUser(Request $request, int $id): JsonResponse
    {
        if ($deny = $this->authorizeAdmin($request)) {
            return $deny;
        }

        $authUser = $request->user();
        $targetUser = User::withoutGlobalScopes()->where('org_id', $authUser->org_id)->find($id);

        if (!$targetUser) {
            return ApiResponse::error('NOT_FOUND', 'User not found.', 404);
        }

        $validator = Validator::make($request->all(), [
            'name'       => 'sometimes|required|string|max:255',
            'email'      => 'sometimes|required|email|max:255|unique:users,email,' . $id,
            'password'   => 'nullable|string|min:8',
            'role'       => 'sometimes|required|in:employee,manager,admin,executive',
            'manager_id' => 'nullable|integer',
            'phone'      => 'nullable|string|max:50',
            'status'     => 'sometimes|required|in:active,inactive',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                $validator->errors()->first(),
                422,
                array_key_first($validator->errors()->toArray())
            );
        }

        $data = $validator->validated();

        if (array_key_exists('manager_id', $data)) {
            if ($data['manager_id'] === $targetUser->id) {
                return ApiResponse::error('VALIDATION_ERROR', 'A user cannot be their own manager.', 422, 'manager_id');
            }

            if (!empty($data['manager_id'])) {
                $manager = User::withoutGlobalScopes()->find($data['manager_id']);
                if (!$manager || $manager->org_id !== $authUser->org_id) {
                    return ApiResponse::error('VALIDATION_ERROR', 'Assigned manager must belong to your organization.', 422, 'manager_id');
                }
            }
        }

        if (isset($data['name']))       $targetUser->name = $data['name'];
        if (isset($data['email']))      $targetUser->email = $data['email'];
        if (!empty($data['password']))   $targetUser->password = Hash::make($data['password']);
        if (isset($data['role']))       $targetUser->role = $data['role'];
        if (array_key_exists('manager_id', $data)) $targetUser->manager_id = $data['manager_id'];
        if (array_key_exists('phone', $data))      $targetUser->phone = $data['phone'];
        if (isset($data['status']))     $targetUser->status = $data['status'];

        $targetUser->save();

        return response()->json([
            'user' => [
                'id'         => $targetUser->id,
                'name'       => $targetUser->name,
                'email'      => $targetUser->email,
                'role'       => $targetUser->role,
                'manager_id' => $targetUser->manager_id,
                'status'     => $targetUser->status,
            ],
            'message' => 'User updated successfully.',
        ]);
    }

    // =========================================================================
    // 2. ORGANIZATION POLICY & ESCALATION SETTINGS
    // =========================================================================

    /**
     * GET /api/admin/settings
     * Retrieve organization settings, masked API key, and webhook configs.
     */
    public function settings(Request $request): JsonResponse
    {
        if ($deny = $this->authorizeAdmin($request)) {
            return $deny;
        }

        $authUser = $request->user();
        $org = Organization::withoutGlobalScopes()->find($authUser->org_id);

        if (!$org) {
            return ApiResponse::error('NOT_FOUND', 'Organization not found.', 404);
        }

        $settings = $org->settings ?? [];

        // Mask the API key: show only last 4 characters, e.g. "••••••••••••3f8a"
        $rawKey = $settings['api_key'] ?? config('autopilot.n8n_api_key', '');
        $hasKey = !empty($rawKey);
        $maskedKey = null;

        if ($hasKey) {
            $last4 = substr($rawKey, -4);
            $maskedKey = str_repeat('•', max(12, strlen($rawKey) - 4)) . $last4;
        }

        return response()->json([
            'organization' => [
                'id'       => $org->id,
                'name'     => $org->name,
                'timezone' => $org->timezone,
            ],
            'settings' => [
                'reminder_windows_days'  => $settings['reminder_windows_days'] ?? [
                    'high'   => [3, 2, 1, 0],
                    'medium' => [2, 1, 0],
                    'low'    => [1, 0],
                ],
                'escalation_days_overdue' => $settings['escalation_days_overdue'] ?? [
                    'high'   => ['manager' => 2, 'executive' => 5],
                    'medium' => ['manager' => 4, 'executive' => 8],
                    'low'    => ['manager' => 7, 'executive' => 14],
                ],
                'working_hours' => $settings['working_hours'] ?? [
                    'start' => '09:00',
                    'end'   => '18:00',
                ],
                'working_days' => $settings['working_days'] ?? [1, 2, 3, 4, 5],
                'notification_channels' => $settings['notification_channels'] ?? ['email'],
                'webhook_urls' => $settings['webhook_urls'] ?? [
                    'meeting_uploaded'     => config('autopilot.webhooks.meeting_uploaded'),
                    'meeting_needs_review' => config('autopilot.webhooks.meeting_needs_review'),
                    'tasks_approved'       => config('autopilot.webhooks.tasks_approved'),
                    'task_completed'       => config('autopilot.webhooks.task_completed'),
                    'task_blocked'         => config('autopilot.webhooks.task_blocked'),
                ],
            ],
            'api_key' => [
                'has_key' => $hasKey,
                'masked'  => $maskedKey,
            ],
        ]);
    }

    /**
     * PUT /api/admin/settings
     * Update organization policy and settings.
     * Enforces strict validation on escalation thresholds:
     * Manager < Executive, and both must be positive integers (> 0).
     */
    public function updateSettings(Request $request): JsonResponse
    {
        if ($deny = $this->authorizeAdmin($request)) {
            return $deny;
        }

        $authUser = $request->user();
        $org = Organization::withoutGlobalScopes()->find($authUser->org_id);

        if (!$org) {
            return ApiResponse::error('NOT_FOUND', 'Organization not found.', 404);
        }

        $input = $request->all();

        // Validate basic structure
        $validator = Validator::make($input, [
            'name'                                    => 'sometimes|required|string|max:255',
            'timezone'                                => 'sometimes|required|string|timezone',
            'settings.reminder_windows_days'          => 'sometimes|required|array',
            'settings.escalation_days_overdue'        => 'sometimes|required|array',
            'settings.working_hours.start'            => 'sometimes|required|date_format:H:i',
            'settings.working_hours.end'              => 'sometimes|required|date_format:H:i|after:settings.working_hours.start',
            'settings.working_days'                   => 'sometimes|required|array',
            'settings.working_days.*'                 => 'integer|min:1|max:7',
            'settings.notification_channels'          => 'sometimes|required|array',
            'settings.webhook_urls'                   => 'nullable|array',
            'settings.webhook_urls.meeting_uploaded'     => 'nullable|url',
            'settings.webhook_urls.meeting_needs_review' => 'nullable|url',
            'settings.webhook_urls.tasks_approved'       => 'nullable|url',
            'settings.webhook_urls.task_completed'       => 'nullable|url',
            'settings.webhook_urls.task_blocked'         => 'nullable|url',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                $validator->errors()->first(),
                422,
                array_key_first($validator->errors()->toArray())
            );
        }

        // ---------------------------------------------------------------------
        // STRICT ESCALATION THRESHOLD VALIDATION
        // Manager must be less than Executive for each priority, and both > 0.
        // ---------------------------------------------------------------------
        $escalation = $request->input('settings.escalation_days_overdue', $org->settings['escalation_days_overdue'] ?? []);
        $priorities = ['high', 'medium', 'low'];

        foreach ($priorities as $pri) {
            if (!isset($escalation[$pri])) {
                return ApiResponse::error(
                    'VALIDATION_ERROR',
                    "Escalation thresholds must define settings for '{$pri}' priority.",
                    422,
                    "settings.escalation_days_overdue.{$pri}"
                );
            }

            $mgr = $escalation[$pri]['manager'] ?? null;
            $exec = $escalation[$pri]['executive'] ?? null;

            if (!is_numeric($mgr) || (int) $mgr <= 0) {
                return ApiResponse::error(
                    'VALIDATION_ERROR',
                    "Manager escalation threshold for {$pri} priority must be a positive integer greater than 0.",
                    422,
                    "settings.escalation_days_overdue.{$pri}.manager"
                );
            }

            if (!is_numeric($exec) || (int) $exec <= 0) {
                return ApiResponse::error(
                    'VALIDATION_ERROR',
                    "Executive escalation threshold for {$pri} priority must be a positive integer greater than 0.",
                    422,
                    "settings.escalation_days_overdue.{$pri}.executive"
                );
            }

            $mgrInt = (int) $mgr;
            $execInt = (int) $exec;

            if ($mgrInt >= $execInt) {
                return ApiResponse::error(
                    'VALIDATION_ERROR',
                    "For {$pri} priority, the manager escalation threshold ({$mgrInt} days) must be less than the executive threshold ({$execInt} days).",
                    422,
                    "settings.escalation_days_overdue.{$pri}.manager"
                );
            }

            // Normalise as integers
            $escalation[$pri]['manager'] = $mgrInt;
            $escalation[$pri]['executive'] = $execInt;
        }

        // Update Organization Model
        if ($request->has('name')) {
            $org->name = $request->input('name');
        }
        if ($request->has('timezone')) {
            $org->timezone = $request->input('timezone');
        }

        $existingSettings = $org->settings ?? [];
        $newSettings = $request->input('settings', []);

        // Merge existing settings with validated fields
        $mergedSettings = array_merge($existingSettings, $newSettings);
        $mergedSettings['escalation_days_overdue'] = $escalation;

        $org->settings = $mergedSettings;
        $org->save();

        return response()->json([
            'ok'           => true,
            'message'      => 'Organization settings updated successfully.',
            'organization' => [
                'id'       => $org->id,
                'name'     => $org->name,
                'timezone' => $org->timezone,
            ],
            'settings'     => $org->settings,
        ]);
    }

    /**
     * POST /api/admin/api-key/regenerate
     * Generate a new static API key, save it to org settings, and return the plaintext
     * key ONCE so the admin can copy it.
     */
    public function regenerateApiKey(Request $request): JsonResponse
    {
        if ($deny = $this->authorizeAdmin($request)) {
            return $deny;
        }

        $authUser = $request->user();
        $org = Organization::withoutGlobalScopes()->find($authUser->org_id);

        if (!$org) {
            return ApiResponse::error('NOT_FOUND', 'Organization not found.', 404);
        }

        // Generate 40-character secure alphanumeric token
        $newKey = 'autopilot_' . Str::random(32);

        $settings = $org->settings ?? [];
        $settings['api_key'] = $newKey;
        $org->settings = $settings;
        $org->save();

        $last4 = substr($newKey, -4);
        $masked = str_repeat('•', strlen($newKey) - 4) . $last4;

        return response()->json([
            'ok'        => true,
            'message'   => 'New API key generated. Please copy it now; it will not be displayed again.',
            'api_key'   => $newKey,
            'masked'    => $masked,
        ]);
    }

    // =========================================================================
    // 3. WEBHOOK DELIVERY LOG & RESEND
    // =========================================================================

    /**
     * GET /api/admin/webhooks
     * List recent outbound webhook deliveries.
     */
    public function webhooks(Request $request): JsonResponse
    {
        if ($deny = $this->authorizeAdmin($request)) {
            return $deny;
        }

        $limit = min(100, max(10, (int) $request->query('limit', 50)));

        $deliveries = WebhookDelivery::orderBy('id', 'desc')
            ->limit($limit)
            ->get()
            ->map(function (WebhookDelivery $d) {
                return [
                    'id'               => $d->id,
                    'event_type'       => $d->event_type,
                    'target_url'       => $d->target_url,
                    'attempt_count'    => $d->attempt_count,
                    'last_status_code' => $d->last_status_code,
                    'last_error'       => $d->last_error,
                    'delivered_at'     => $d->delivered_at?->toIso8601String(),
                    'created_at'       => $d->created_at?->toIso8601String(),
                    'payload'          => $d->payload,
                ];
            });

        return response()->json([
            'deliveries' => $deliveries,
        ]);
    }

    /**
     * POST /api/admin/webhooks/{id}/resend
     * Resend an existing webhook delivery attempt.
     */
    public function resendWebhook(Request $request, int $id, WebhookService $webhookService): JsonResponse
    {
        if ($deny = $this->authorizeAdmin($request)) {
            return $deny;
        }

        $delivery = WebhookDelivery::find($id);

        if (!$delivery) {
            return ApiResponse::error('NOT_FOUND', 'Webhook delivery record not found.', 404);
        }

        $webhookService->resend($delivery);

        return response()->json([
            'ok'      => true,
            'message' => "Webhook #{$delivery->id} ({$delivery->event_type}) queued for redelivery.",
            'delivery' => [
                'id'         => $delivery->id,
                'event_type' => $delivery->event_type,
                'target_url' => $delivery->target_url,
            ],
        ]);
    }
}
