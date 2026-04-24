<?php

declare(strict_types=1);

namespace Akunta\Rbac\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property string      $id
 * @property string|null $tenant_id
 * @property string      $code
 * @property string      $name
 * @property string|null $parent_role_id
 * @property bool        $is_preset
 */
class Role extends Model
{
    use HasUlids;

    protected $table = 'roles';

    protected $guarded = [];

    protected $casts = [
        'is_preset' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_role_id');
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions');
    }
}
