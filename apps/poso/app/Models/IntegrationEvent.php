<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class IntegrationEvent extends Model
{
    use HasUlids;

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'tenant_id',
        'destination_app',
        'event_type',
        'aggregate_type',
        'aggregate_id',
        'idempotency_key',
        'payload',
        'status',
        'attempts',
        'available_at',
        'sent_at',
        'last_error',
    ];

    protected $casts = [
        'payload' => 'array',
        'attempts' => 'integer',
        'available_at' => 'datetime',
        'sent_at' => 'datetime',
    ];
}

