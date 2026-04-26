<?php

namespace App\Models;

use Akunta\Rbac\Models\Entity;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecurringJournal extends Model
{
    use HasUlids;

    public const FREQUENCY_DAILY     = 'daily';
    public const FREQUENCY_WEEKLY    = 'weekly';
    public const FREQUENCY_MONTHLY   = 'monthly';
    public const FREQUENCY_QUARTERLY = 'quarterly';
    public const FREQUENCY_YEARLY    = 'yearly';

    public const FREQUENCIES = [
        self::FREQUENCY_DAILY,
        self::FREQUENCY_WEEKLY,
        self::FREQUENCY_MONTHLY,
        self::FREQUENCY_QUARTERLY,
        self::FREQUENCY_YEARLY,
    ];

    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_ENDED  = 'ended';

    protected $fillable = [
        'entity_id',
        'template_id',
        'name',
        'frequency',
        'day',
        'month',
        'start_date',
        'end_date',
        'next_run_at',
        'last_run_at',
        'last_journal_id',
        'status',
        'auto_post',
        'created_by',
    ];

    protected $casts = [
        'start_date'  => 'date',
        'end_date'    => 'date',
        'next_run_at' => 'date',
        'last_run_at' => 'datetime',
        'auto_post'   => 'boolean',
    ];

    protected $attributes = [
        'status'    => self::STATUS_ACTIVE,
        'auto_post' => false,
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(JournalTemplate::class, 'template_id');
    }

    public function lastJournal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'last_journal_id');
    }

    public function isPaused(): bool
    {
        return $this->status === self::STATUS_PAUSED;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
