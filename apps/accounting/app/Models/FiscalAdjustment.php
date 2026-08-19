<?php

declare(strict_types=1);

namespace App\Models;

use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\User;
use App\Concerns\HasAttachments;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FiscalAdjustment extends Model
{
    use HasAttachments;
    use HasUlids;

    public const DIRECTION_POSITIVE = 'positive';

    public const DIRECTION_NEGATIVE = 'negative';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    protected $fillable = [
        'entity_id',
        'journal_id',
        'account_id',
        'date',
        'direction',
        'amount',
        'reason',
        'legal_basis',
        'status',
        'created_by',
        'approved_by',
        'approved_at',
    ];

    protected $casts = [
        'date' => 'date',
        'amount' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
