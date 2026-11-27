<?php

namespace App\Models;

use Akunta\Rbac\Models\Entity;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'entity_id',
        'code',
        'name',
        'city',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }
}
