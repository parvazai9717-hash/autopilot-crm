# Meeting → Action Autopilot CRM — API & Webhook Specification

This document provides the complete API reference for all `/api/v1/` machine endpoints, outbound webhooks, authentication mechanisms, and error code standards.

---

## 1. Authentication & Headers

Machine endpoints are designed for direct communication with the **n8n automation layer**.

### Headers
| Header | Description | Example |
|---|---|---|
| `X-API-Key` | Static API key configured in `.env` (`N8N_API_KEY`) or in the Admin Center | `autopilot_lutUHe4aPdofneFh1Q4bvyRmoHQPMpU2` |
| `Idempotency-Key` | UUID to guarantee safe retries on state-modifying requests (`POST`, `PATCH`) | `b9f3d9d3-64a5-48b4-a212-0761e967a102` |
| `Accept` | Desired response format (must be JSON) | `application/json` |
| `Content-Type` | Payload format for JSON requests | `application/json` |

### Rate Limiting
All `/api/v1/` routes are rate-limited to **300 requests/minute** (`throttle:n8n-api`). If exceeded, the server returns HTTP `429 Too Many Requests`.

---

## 2. Standard Error Response Envelope

All API errors adhere to a consistent JSON structure:
```json
{
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "The due_date field must be a valid date in YYYY-MM-DD format.",
    "field": "due_date"
  }
}
```

### Error Code Reference Table (Per Section 13)
| Status Code | Error Code | Description |
|---|---|---|
| `400` | `BAD_REQUEST` | Malformed request or invalid payload syntax |
| `401` | `MISSING_API_KEY` | The `X-API-Key` header was not provided |
| `401` | `INVALID_API_KEY` | The provided `X-API-Key` does not match the configured secret |
| `401` | `UNAUTHENTICATED` | Session expired or missing authentication token |
| `403` | `FORBIDDEN` | Access denied for the authenticated user or role |
| `404` | `NOT_FOUND` | Resource does not exist, or belongs to another tenant organization (multi-tenancy security rule: never confirm another tenant's record exists) |
| `409` | `ILLEGAL_TRANSITION` | Attempted task state transition violates the state machine |
| `422` | `VALIDATION_ERROR` | Request payload failed validation rules |
| `429` | `RATE_LIMIT_EXCEEDED` | Request rate exceeded (300 requests/minute threshold) |
| `500` | `INTERNAL_SERVER_ERROR` | Unexpected server or application failure |

---

## 3. Endpoints (`/api/v1/`)

### 3.1 Health Check Ping
- **Endpoint:** `GET /api/v1/ping`
- **Description:** Verifies API availability and validates the `X-API-Key`.
```bash
curl -X GET "http://127.0.0.1:8000/api/v1/ping" \
  -H "X-API-Key: test-api-key" \
  -H "Accept: application/json"
```
**Response (200 OK):**
```json
{
  "ok": true,
  "timestamp": "2026-09-06T14:15:02+05:00"
}
```

---

### 3.2 Users Roster
- **Endpoint:** `GET /api/v1/users`
- **Description:** Retrieve the organization's user roster for owner resolution during AI extraction.
- **Query Parameters:** `role` (`employee`, `manager`, `admin`, `executive`), `status` (`active`, `inactive`)
```bash
curl -X GET "http://127.0.0.1:8000/api/v1/users?status=active" \
  -H "X-API-Key: test-api-key" \
  -H "Accept: application/json"
```
**Response (200 OK):**
```json
{
  "users": [
    {
      "id": 1,
      "name": "Ahmed Raza",
      "email": "ahmed@test.com",
      "role": "employee",
      "manager_id": 5,
      "status": "active"
    },
    {
      "id": 5,
      "name": "Bilal Sheikh",
      "email": "bilal@test.com",
      "role": "manager",
      "manager_id": 6,
      "status": "active"
    }
  ]
}
```

---

### 3.3 Organization Policy & Escalation Settings
- **Endpoint:** `GET /api/v1/orgs/{id}/settings` (or `GET /api/v1/organizations/{id}/settings`)
- **Description:** Returns the organization's policy JSON defining escalation days, reminder windows, working hours, and days.
```bash
curl -X GET "http://127.0.0.1:8000/api/v1/orgs/1/settings" \
  -H "X-API-Key: test-api-key" \
  -H "Accept: application/json"
```
**Response (200 OK):**
```json
{
  "reminder_windows_days": {
    "high": [3, 2, 1, 0],
    "medium": [2, 1, 0],
    "low": [1, 0]
  },
  "escalation_days_overdue": {
    "high": { "manager": 2, "executive": 5 },
    "medium": { "manager": 4, "executive": 8 },
    "low": { "manager": 7, "executive": 14 }
  },
  "working_hours": { "start": "09:00", "end": "18:00" },
  "working_days": [1, 2, 3, 4, 5],
  "notification_channels": ["email"]
}
```

---

### 3.4 Create Text Meeting
- **Endpoint:** `POST /api/v1/meetings`
- **Description:** Submit a meeting transcript directly as text. Immediately sets status to `extracted` and fires `meeting.needs_review` if extracted tasks exist.
```bash
curl -X POST "http://127.0.0.1:8000/api/v1/meetings" \
  -H "X-API-Key: test-api-key" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: c9d94fc8-9f33-40e1-bbd5-d86b1c7dc522" \
  -d '{
    "org_id": 1,
    "title": "Weekly Engineering Sync",
    "meeting_date": "2026-09-06",
    "timezone": "Asia/Karachi",
    "transcript": "Sarah will finalize the vendor contract by Friday.",
    "created_by": 6
  }'
```
**Response (201 Created):**
```json
{
  "meeting_id": 2,
  "title": "Weekly Engineering Sync",
  "status": "extracted",
  "source": "text"
}
```

---

### 3.5 Upload Meeting Recording
- **Endpoint:** `POST /api/v1/meetings/upload`
- **Description:** Upload audio/video recording (`mp3`, `wav`, `m4a`, `mp4`, `webm`, `ogg`). Validates size (up to 500MB), queues conversion to 32kbps mono MP3, chunks if > 24MB, and fires `meeting.uploaded`.
```bash
curl -X POST "http://127.0.0.1:8000/api/v1/meetings/upload" \
  -H "X-API-Key: test-api-key" \
  -F "file=@meeting_recording.mp4" \
  -F "org_id=1" \
  -F "title=Monthly Townhall" \
  -F "meeting_date=2026-09-06" \
  -F "timezone=Asia/Karachi" \
  -F "created_by=6"
```
**Response (200 OK):**
```json
{
  "meeting_id": 3,
  "title": "Monthly Townhall",
  "status": "uploaded"
}
```

---

### 3.6 Download Meeting Audio (Signed URL)
- **Endpoint:** `GET /api/v1/meetings/{id}/audio`
- **Description:** Expiring signed URL (24 hours) allowing n8n to download processed MP3 audio without credentials.
```bash
curl -X GET "http://127.0.0.1:8000/api/v1/meetings/3/audio?expires=1788700000&signature=abc123def456..." \
  -o processed_audio.mp3
```

---

### 3.7 Update Meeting (Status, Transcript, Summary)
- **Endpoint:** `PATCH /api/v1/meetings/{id}`
- **Description:** Called by n8n after transcription and action item extraction are complete.
```bash
curl -X PATCH "http://127.0.0.1:8000/api/v1/meetings/3" \
  -H "X-API-Key: test-api-key" \
  -H "Content-Type: application/json" \
  -d '{
    "org_id": 1,
    "status": "extracted",
    "transcript": "Ahmed will prepare the ABC proposal by Friday...",
    "summary": "Team aligned on Q3 roadmaps and proposal deadlines."
  }'
```
**Response (200 OK):**
```json
{
  "ok": true,
  "meeting_id": 3,
  "status": "extracted"
}
```

---

### 3.8 Retry Failed Meeting
- **Endpoint:** `POST /api/v1/meetings/{id}/retry`
- **Description:** Resets a meeting with `status = failed` back to `uploaded` and re-dispatches audio processing.
```bash
curl -X POST "http://127.0.0.1:8000/api/v1/meetings/3/retry" \
  -H "X-API-Key: test-api-key" \
  -H "Content-Type: application/json" \
  -d '{ "org_id": 1 }'
```
**Response (200 OK):**
```json
{
  "ok": true,
  "meeting_id": 3,
  "status": "uploaded"
}
```

---

### 3.9 Ingest Action Items
- **Endpoint:** `POST /api/v1/action-items`
- **Description:** Ingests AI-extracted action items. Marks `owner_ambiguous = true` if owner is not uniquely resolvable. Tasks are inserted in `pending_approval` status. Fires `meeting.needs_review`.
```bash
curl -X POST "http://127.0.0.1:8000/api/v1/action-items" \
  -H "X-API-Key: test-api-key" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: f2d589d8-912b-42c2-8419-4bb4da42bc19" \
  -d '{
    "org_id": 1,
    "meeting_id": 3,
    "tasks": [
      {
        "title": "Prepare ABC proposal",
        "description": "Full proposal draft with pricing breakdown",
        "owner_name_raw": "Ahmed",
        "owner_id": 1,
        "owner_ambiguous": false,
        "priority": "high",
        "due_date": "2026-09-11",
        "deadline_phrase": "by Friday",
        "source_text": "Ahmed, please prepare the ABC proposal by Friday.",
        "owner_confidence": 0.95,
        "deadline_confidence": 0.90,
        "action_confidence": 0.98
      },
      {
        "title": "Send financial report",
        "description": "Consolidated balance sheet",
        "owner_name_raw": "Ali",
        "owner_id": null,
        "owner_ambiguous": true,
        "priority": "medium",
        "due_date": "2026-09-14",
        "deadline_phrase": "by next Monday",
        "source_text": "Ali will send me the financial report by next Monday.",
        "owner_confidence": 0.50,
        "deadline_confidence": 0.85,
        "action_confidence": 0.92
      }
    ]
  }'
```
**Response (201 Created):**
```json
{
  "ok": true,
  "meeting_id": 3,
  "tasks_created": 2,
  "task_ids": [10, 11]
}
```

---

### 3.10 List Tasks (Query & Filtering)
- **Endpoint:** `GET /api/v1/tasks`
- **Description:** Retrieve organization tasks with flexible filtering.
- **Query Parameters:** `status`, `owner_id`, `priority`, `due_date`, `page`, `per_page`
```bash
curl -X GET "http://127.0.0.1:8000/api/v1/tasks?status=in_progress&priority=high" \
  -H "X-API-Key: test-api-key" \
  -H "Accept: application/json"
```

---

### 3.11 Task Escalation Hook
- **Endpoint:** `POST /api/v1/tasks/{id}/escalation`
- **Description:** Called by n8n when an overdue reminder escalates to manager or executive. Increments `escalation_level` and logs an `ESCALATED` event.
```bash
curl -X POST "http://127.0.0.1:8000/api/v1/tasks/10/escalation" \
  -H "X-API-Key: test-api-key" \
  -H "Content-Type: application/json" \
  -d '{
    "org_id": 1,
    "escalation_level": 1,
    "recipient_role": "manager",
    "recipient_email": "bilal@test.com"
  }'
```
**Response (200 OK):**
```json
{
  "ok": true,
  "task_id": 10,
  "escalation_level": 1
}
```

---

### 3.12 Append Task Event
- **Endpoint:** `POST /api/v1/task-events`
- **Description:** Appends an event to the immutable `task_events` audit trail (e.g. `NOTIFICATION_SENT`, `REMINDER_SENT`).
```bash
curl -X POST "http://127.0.0.1:8000/api/v1/task-events" \
  -H "X-API-Key: test-api-key" \
  -H "Content-Type: application/json" \
  -d '{
    "task_id": 10,
    "event_type": "REMINDER_SENT",
    "actor_type": "ai",
    "metadata": {
      "channel": "email",
      "recipient": "ahmed@test.com",
      "template": "deadline_reminder_t_minus_1"
    }
  }'
```
**Response (201 Created):**
```json
{
  "ok": true,
  "event_id": 42
}
```

---

## 4. Outbound Webhooks (Section 14)

Webhooks are fired asynchronously on queued jobs with **5 attempts and exponential backoff**. Every delivery attempt is logged in `webhook_deliveries`.

### Payload Envelope & Signing
Every webhook payload includes:
```json
{
  "event": "tasks.approved",
  "event_id": "8b51d5c2-f179-425b-80a3-324cfa97645b",
  "fired_at": "2026-09-06T14:15:00+05:00"
}
```
- The raw JSON request body is signed using **HMAC-SHA256** with `WEBHOOK_SIGNING_SECRET`.
- The signature is sent in the header:
  ```http
  X-Signature: 6e4a2d8b1c4e9f7832...
  ```
- Receivers verify authenticity by comparing `hash_hmac('sha256', raw_body, secret)` to `X-Signature`.

---

### 4.1 `meeting.uploaded`
Fired when meeting audio has been converted and is ready for transcription.
```json
{
  "event": "meeting.uploaded",
  "event_id": "27fa5e12-429a-4cbf-8461-1e9bf433a010",
  "fired_at": "2026-09-06T14:00:00+05:00",
  "org_id": 1,
  "meeting_id": 3,
  "title": "Monthly Townhall",
  "meeting_date": "2026-09-06",
  "timezone": "Asia/Karachi",
  "source": "upload",
  "audio_url": "http://127.0.0.1:8000/api/v1/meetings/3/audio?expires=1788700000&signature=..."
}
```

---

### 4.2 `meeting.needs_review`
Fired when action items have been ingested and the meeting requires human review.
```json
{
  "event": "meeting.needs_review",
  "event_id": "3bf9e241-534a-4ecb-9342-9a3b9f441011",
  "fired_at": "2026-09-06T14:05:00+05:00",
  "org_id": 1,
  "meeting_id": 3,
  "title": "Monthly Townhall",
  "task_count": 2,
  "approver_email": "admin@test.com",
  "approver_name": "Ahmad Ameen",
  "review_url": "http://127.0.0.1:5173/meetings/3/review"
}
```

---

### 4.3 `tasks.approved`
Fired when the review screen resolves all extracted items and dispatches the tasks.
```json
{
  "event": "tasks.approved",
  "event_id": "4cf7a213-645b-41dc-8453-0c4a8d552122",
  "fired_at": "2026-09-06T14:10:00+05:00",
  "org_id": 1,
  "meeting_id": 3,
  "tasks": [
    {
      "id": 10,
      "title": "Prepare ABC proposal",
      "owner_id": 1,
      "owner_name": "Ahmed Raza",
      "owner_email": "ahmed@test.com",
      "due_date": "2026-09-11",
      "priority": "high"
    }
  ]
}
```

---

### 4.4 `task.completed`
Fired when an employee completes a task.
```json
{
  "event": "task.completed",
  "event_id": "5da8b324-756c-42ed-9564-1d5b9e663233",
  "fired_at": "2026-09-06T14:20:00+05:00",
  "org_id": 1,
  "task_id": 10,
  "title": "Prepare ABC proposal",
  "completed_by": 1,
  "completed_at": "2026-09-06T09:20:00Z",
  "manager_email": "bilal@test.com"
}
```

---

### 4.5 `task.blocked`
Fired when an employee marks a task as blocked.
```json
{
  "event": "task.blocked",
  "event_id": "6eb9c435-867d-43fe-0675-2e6c0f774344",
  "fired_at": "2026-09-06T14:25:00+05:00",
  "org_id": 1,
  "task_id": 10,
  "title": "Prepare ABC proposal",
  "reason_code": "waiting_for_info",
  "description": "Waiting on pricing sheets from supplier",
  "blocked_by": 1,
  "depends_on_task_id": 2,
  "manager_email": "bilal@test.com"
}
```
