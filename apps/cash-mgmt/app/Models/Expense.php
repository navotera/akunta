<?php

namespace App\Models;

use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    use HasUlids;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PAID = 'paid';

    protected $fillable = [
        'entity_id',
        'fund_id',
        'expense_date',
        'amount',
        'category_code',
        'reference',
        'memo',
        'status',
        'journal_id',
        'approved_at',
        'approved_by',
        'paid_at',
        'paid_by',
        'created_by',
    ];

    protected $casts = [
        'expense_date' => 'date',
        'amount' => 'decimal:2',
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
        'amount' => 0,
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function fund(): BelongsTo
    {
        return $this->belongsTo(Fund::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function idempotencyKeyForPay(): string
    {
        return 'cashmgmt-expense-'.$this->id.'-pay';
    }
}
