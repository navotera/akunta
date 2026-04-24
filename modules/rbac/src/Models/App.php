<?php

declare(strict_types=1);

namespace Akunta\Rbac\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $code
 * @property string $name
 * @property string $version
 * @property bool   $enabled
 * @property array<mixed>|null $settings
 */
class App extends Model
{
    use HasUlids;

    protected $table = 'apps';

    protected $guarded = [];

    protected $casts = [
        'enabled'      => 'boolean',
        'settings'     => 'array',
        'installed_at' => 'datetime',
    ];

    public function permissions(): HasMany
    {
        return $this->hasMany(Permission::class);
    }
}
