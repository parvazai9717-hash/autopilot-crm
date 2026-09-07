<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IdempotencyKey extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $table = 'idempotency_keys';

    protected $fillable = [
        'key',
        'endpoint',
        'response_body',
        'created_at',
    ];

    protected $casts = [
        'response_body' => 'array',
        'created_at' => 'datetime',
    ];
}
