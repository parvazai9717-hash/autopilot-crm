<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WebhookDelivery extends Model
{
    use HasFactory;

    protected $table = 'webhook_deliveries';

    protected $fillable = [
        'event_type',
        'payload',
        'target_url',
        'attempt_count',
        'last_status_code',
        'last_error',
        'delivered_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'attempt_count' => 'integer',
        'last_status_code' => 'integer',
        'delivered_at' => 'datetime',
    ];
}
