# Multi-Tenant Architecture & Tenancy Inventory

Last Updated: 2026-09-22
Phase: Phase 3 (Multi-Tenant Isolation & Security Lockdown)

---

## 1. Overview & Security Mandate

Autopilot CRM is a multi-tenant application serving distinct customer organizations. The primary vulnerability addressed in Phase 3 is **cross-tenant data leakage** caused by fail-open global scopes, client-controlled query parameters (`?org_id=X`), and hardcoded platform credentials.

### Non-Negotiable Tenancy Rules:
1. **Fail-Closed Global Scope (`OrgScope`)**:
   - If no authenticated user session, token, or verified `TenantContext` exists, queries on tenant-owned models MUST append `WHERE 1 = 0` and emit a warning log.
   - Global scopes NEVER fail open.
2. **Zero Client Trust (`BelongsToOrg`)**:
   - The `creating` model lifecycle hook ignores `request()->query('org_id')` and `request()->input('org_id')`.
   - The model's `org_id` is derived strictly from the verified `TenantContext` or `Auth::user()->org_id`.
3. **Request-Scoped Context (`SetTenantContext`)**:
   - The `SetTenantContext` middleware initializes `TenantContext::set($user->org_id)` on authenticated requests and guarantees `TenantContext::clear()` in a `finally` block upon request termination.
4. **Decoupled Machine API (`ValidateN8nApiKey`)**:
   - Machine requests from automation orchestrators (n8n) must authenticate using `X-API-Key`.
   - Machine calls NEVER default to `Organization::find(1)`. Multi-tenant calls must explicitly declare `X-Tenant-ID`.

---

## 2. Table & Model Tenancy Inventory

| Table | Model | Scoped By | Trait / Mechanism | Policy & Access Rules |
| :--- | :--- | :--- | :--- | :--- |
| `organizations` | `Organization` | Primary Key (`id`) | N/A | Root tenant entity. Can only be accessed by admins of that org or internal platform principal. |
| `users` | `User` | `org_id` | `BelongsToOrg` | Users can only see roster of their own organization. Login endpoint bypasses during unauthenticated credential verification. |
| `meetings` | `Meeting` | `org_id` | `BelongsToOrg` | Meetings, recordings, transcripts, and summaries are strictly scoped to the tenant. |
| `meeting_participants`| `MeetingParticipant`| `meeting_id` | Via parent `Meeting` | Inherits tenant ownership from meeting. |
| `tasks` | `Task` | `org_id` | `BelongsToOrg` | Tasks and action items belong to the tenant. Employee and manager dashboard queries are isolated. |
| `task_events` | `TaskEvent` | `org_id` | `BelongsToOrg` | Audit log of task mutations and state transitions. |
| `task_blockers` | `TaskBlocker` | `task_id` | Via parent `Task` | Escalations and blockers linked to tenant tasks. |
| `task_dependencies` | `TaskDependency` | `task_id` | Via parent `Task` | Task graph edges linked to tenant tasks. |
| `task_comments` | `TaskComment` | `task_id` | Via parent `Task` | Discussion threads on tenant tasks. |
| `notifications` | `Notification` | `org_id` | `BelongsToOrg` | User alerts and reminder notifications. |
| `webhook_deliveries` | `WebhookDelivery` | `org_id` | `BelongsToOrg` | Outbound webhook audit log per tenant. |
| `idempotency_keys` | `IdempotencyKey` | `key` hash | System table | Unique request deduplication keys. |

---

## 3. Tenant Boundary Verification Endpoints

### 1. `POST /internal/v1/runs/claim`
- **Auth**: `X-API-Key` (`ValidateN8nApiKey`)
- **Purpose**: Atomically claims an ingestion run for a meeting. Ensures duplicate webhook dispatches or worker retries do not process concurrently.
- **Payload**:
  ```json
  {
    "meeting_id": 105,
    "run_id": "run-f19b-4682",
    "timestamp": 1726000000
  }
  ```
- **Response**:
  ```json
  {
    "status": "claimed",
    "run_id": "run-f19b-4682",
    "run_token": "hmac-sha256-...",
    "meeting_id": 105,
    "org_id": 1,
    "org_timezone": "Asia/Karachi"
  }
  ```

### 2. `GET /internal/v1/orgs/active`
- **Auth**: Platform `X-API-Key` (`ValidateN8nApiKey`)
- **Purpose**: Lists all active tenant organizations with their timezone configurations for n8n cron workers.
- **Response**:
  ```json
  {
    "ok": true,
    "count": 2,
    "orgs": [
      { "id": 1, "name": "Acme Corp", "timezone": "Asia/Karachi" },
      { "id": 2, "name": "Beta LLC", "timezone": "America/New_York" }
    ]
  }
  ```
