<?php

return [
    'n8n_api_key' => env('N8N_API_KEY'),

    'webhooks' => [
        'meeting_uploaded' => env('N8N_WEBHOOK_MEETING_UPLOADED'),
        'meeting_needs_review' => env('N8N_WEBHOOK_MEETING_NEEDS_REVIEW'),
        'tasks_approved' => env('N8N_WEBHOOK_TASKS_APPROVED'),
        'task_completed' => env('N8N_WEBHOOK_TASK_COMPLETED'),
        'task_blocked' => env('N8N_WEBHOOK_TASK_BLOCKED'),
        'signing_secret' => env('WEBHOOK_SIGNING_SECRET'),
    ],

    'audio' => [
        'ffmpeg_path' => env('FFMPEG_PATH', 'ffmpeg'),
        'ffprobe_path' => env('FFPROBE_PATH', 'ffprobe'),
        'max_upload_mb' => (int) env('MAX_UPLOAD_MB', 500),
        'audio_chunk_max_mb' => (int) env('AUDIO_CHUNK_MAX_MB', 24),
    ],

    'meeting_processing_timeout' => (int) env('MEETING_PROCESSING_TIMEOUT', 30),
];
