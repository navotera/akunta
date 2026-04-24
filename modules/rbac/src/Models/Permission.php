<?php

declare(strict_types=1);

namespace Akunta\Rbac\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property string      $id
 * @property string      $app_id
 * @property string      $code
 * @property string|null $description
 * @property string|null $category
 */
class Permission extends Model
{
    use HasUlids;

    protected $table = 'permissions';

    protected $guarded = [];

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_permissions');
    }
}
