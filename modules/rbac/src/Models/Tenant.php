<?php

declare(strict_types=1);

namespace Akunta\Rbac\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string      $id
 * @property string      $name
 * @property string      $slug
 * @property string      $accounting_method
 * @property string      $base_currency
 * @property string      $locale
 * @property string      $timezone
 * @property int         $audit_retention_days
 * @property string|null $db_name
 * @property string|null $plan
 * @property string      $status
 * @property \Illuminate\Support\Carbon|null $provisioned_at
 * @property string|null $license_key
 */
class Tenant extends Model
{
    use HasUlids;

    public const STATUS_PROVISIONING = 'provisioning';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_ARCHIVED = 'archived';

    protected $table = 'tenants';

    protected $guarded = [];

    protected $casts = [
        'audit_retention_days' => 'integer',
        'provisioned_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => self::STATUS_PROVISIONING,
    ];

    public function entities(): HasMany
    {
        return $this->hasMany(Entity::class);
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
