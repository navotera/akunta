<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookDelivery extends Model
{
    use HasUlids;

    public const STATUS_PENDING    = 'pending';
    public const STATUS_SUCCESS    = 'success';
    public const STATUS_FAILED     = 'failed';
    public const STATUS_GIVING_UP  = 'giving_up';

    protected $fillable = [
        'subscription_id',
        'event',
        'payload',
        'status',
        'response_code',
        'response_body',
        'attempts',
        'last_tried_at',
        'sent_at',
        'error',
    ];

    protected $casts = [
        'payload'       => 'array',
        'last_tried_at' => 'datetime',
        'sent_at'       => 'datetime',
        'attempts'      => 'integer',
        'response_code' => 'integer',
    ];

    protected $attributes = [
        'status'   => self::STATUS_PENDING,
        'attempts' => 0,
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(WebhookSubscription::class, 'subscription_id');
    }
}
