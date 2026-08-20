<?php

namespace App\Models;

use Akunta\Rbac\Models\Entity;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Account extends Model
{
    public const AVAILABILITY_INTERN = 'intern';

    public const AVAILABILITY_FISKAL = 'fiskal';

    public const AVAILABILITY_BOTH = 'both';

    use HasUlids;

    protected $fillable = [
        'entity_id',
        'code',
        'name',
        'description',
        'parent_account_id',
        'type',
        'normal_balance',
        'is_postable',
        'is_active',
        'availability',
        'legal_basis',
    ];

    protected $casts = [
        'is_postable' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function isAvailableFor(string $journalMode): bool
    {
        return $this->availability === self::AVAILABILITY_BOTH
            || ($journalMode === Journal::MODE_INTERNAL
                && $this->availability === self::AVAILABILITY_INTERN)
            || ($journalMode === Journal::MODE_FISCAL
                && $this->availability === self::AVAILABILITY_FISKAL);
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_account_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_account_id');
    }

    public function fakeDataRecords(): MorphMany
    {
        return $this->morphMany(FakeDataRecord::class, 'model');
    }
}
