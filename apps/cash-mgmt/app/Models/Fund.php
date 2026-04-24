<?php

namespace App\Models;

use Akunta\Rbac\Models\Entity;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Fund extends Model
{
    use HasUlids;

    protected $fillable = [
        'entity_id',
        'name',
        'account_code',
        'balance',
        'is_active',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'is_active' => true,
        'balance' => 0,
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }
}
