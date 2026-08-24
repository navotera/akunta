<?php

declare(strict_types=1);

namespace Akunta\Rbac\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string|null $workspace_code
 * @property bool $is_active
 * @property bool $is_fake_data
 * @property Carbon|null $archived_at
 * @property array<mixed>|null $workspace_settings
 * @property string $theme_color
 * @property string|null $logo_path
 * @property string|null $director_name
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $legal_form
 * @property string|null $npwp
 * @property string|null $parent_entity_id
 * @property string $relation_type
 * @property array<mixed>|null $address
 */
class Entity extends Model
{
    use HasUlids;

    protected $table = 'entities';

    protected $guarded = [];

    protected $casts = [
        'address' => 'array',
        'is_active' => 'boolean',
        'is_fake_data' => 'boolean',
        'archived_at' => 'datetime',
        'workspace_settings' => 'array',
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
