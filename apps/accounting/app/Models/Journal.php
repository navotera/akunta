<?php

namespace App\Models;

use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\User;
use App\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Journal extends Model
{
    use HasAttachments;
    use HasUlids;

    public const TYPE_GENERAL = 'general';

    public const TYPE_ADJUSTMENT = 'adjustment';

    public const TYPE_CLOSING = 'closing';

    public const TYPE_REVERSING = 'reversing';

    public const TYPE_OPENING = 'opening';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    public const STATUS_REVERSED = 'reversed';

    protected $fillable = [
        'entity_id',
        'period_id',
        'type',
        'number',
        'date',
        'reference',
        'memo',
        'source_app',
        'source_id',
        'idempotency_key',
        'status',
        'posted_at',
        'posted_by',
        'reversed_by_journal_id',
        'auto_reverse_on',
        'template_id',
        'created_by',
    ];

    protected $casts = [
        'date' => 'date',
        'posted_at' => 'datetime',
        'auto_reverse_on' => 'date',
    ];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
        'type' => self::TYPE_GENERAL,
        'source_app' => 'accounting',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_by_journal_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(JournalTemplate::class, 'template_id');
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    public function isReversed(): bool
    {
        return $this->status === self::STATUS_REVERSED;
    }

    public function totalDebit(): string
    {
        return (string) $this->entries->sum('debit');
    }

    public function totalCredit(): string
    {
        return (string) $this->entries->sum('credit');
    }

    public function isBalanced(): bool
    {
        return bccomp($this->totalDebit(), $this->totalCredit(), 2) === 0;
    }
}
