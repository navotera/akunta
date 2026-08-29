<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class EcopaWebhookLog extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $fillable = [
        'event_id',
        'event',
        'subject_reference',
        'outcome',
        'result_code',
        'http_status',
        'signature_valid',
        'retryable',
        'message',
        'duration_ms',
        'received_at',
        'completed_at',
    ];

    protected $casts = [
        'http_status' => 'integer',
        'signature_valid' => 'boolean',
        'retryable' => 'boolean',
        'duration_ms' => 'integer',
        'received_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
}
