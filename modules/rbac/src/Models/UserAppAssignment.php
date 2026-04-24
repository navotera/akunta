<?php

declare(strict_types=1);

namespace Akunta\Rbac\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string             $id
 * @property string             $user_id
 * @property string             $app_id
 * @property string|null        $entity_id
 * @property string             $role_id
 * @property Carbon|null        $valid_from
 * @property Carbon|null        $valid_until
 * @property string|null        $assigned_by
 * @property Carbon             $assigned_at
 * @property Carbon|null        $revoked_at
 * @property string|null        $revoked_by
 */
class UserAppAssignment extends Model
{
    use HasUlids;

    protected $table = 'user_app_assignments';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'valid_from'  => 'datetime',
        'valid_until' => 'datetime',
        'assigned_at' => 'datetime',
        'revoked_at'  => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(App::class);
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Entity::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function isActive(?Carbon $now = null): bool
    {
        $now ??= Carbon::now();

        if ($this->revoked_at !== null) {
            return false;
        }

        if ($this->valid_from !== null && $now->lessThan($this->valid_from)) {
            return false;
        }

        if ($this->valid_until !== null && $now->greaterThanOrEqualTo($this->valid_until)) {
            return false;
        }

        return true;
    }
}
