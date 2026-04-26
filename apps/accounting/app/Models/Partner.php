<?php

namespace App\Models;

use Akunta\Rbac\Models\Entity;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Partner extends Model
{
    use HasUlids;

    public const TYPE_CUSTOMER = 'customer';
    public const TYPE_VENDOR   = 'vendor';
    public const TYPE_EMPLOYEE = 'employee';
    public const TYPE_OTHER    = 'other';

    public const TYPES = [
        self::TYPE_CUSTOMER,
        self::TYPE_VENDOR,
        self::TYPE_EMPLOYEE,
        self::TYPE_OTHER,
    ];

    protected $fillable = [
        'entity_id',
        'type',
        'code',
        'name',
        'npwp',
        'tax_id',
        'email',
        'phone',
        'address',
        'city',
        'country',
        'receivable_account_id',
        'payable_account_id',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'metadata'  => 'array',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function receivableAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'receivable_account_id');
    }

    public function payableAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'payable_account_id');
    }

    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }
}
