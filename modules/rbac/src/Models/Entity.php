<?php

declare(strict_types=1);

namespace Akunta\Rbac\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string              $id
 * @property string              $tenant_id
 * @property string              $name
 * @property string|null         $legal_form
 * @property string|null         $npwp
 * @property string|null         $parent_entity_id
 * @property string              $relation_type
 * @property array<mixed>|null   $address
 */
class Entity extends Model
{
    use HasUlids;

    protected $table = 'entities';

    protected $guarded = [];

    protected $casts = [
        'address' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_entity_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_entity_id');
    }
}
