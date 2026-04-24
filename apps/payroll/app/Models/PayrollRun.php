<?php

namespace App\Models;

use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollRun extends Model
{
    use HasUlids;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_PAID = 'paid';

    protected $fillable = [
        'entity_id',
        'period_label',
        'run_date',
        'status',
        'total_wages',
        'journal_id',
        'approved_at',
        'approved_by',
        'paid_at',
        'paid_by',
        'created_by',
    ];

    protected $casts = [
        'run_date' => 'date',
        'total_wages' => 'decimal:2',
        'approved_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
        'total_wages' => 0,
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
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
        return 'payroll-run-'.$this->id.'-pay';
    }
}
