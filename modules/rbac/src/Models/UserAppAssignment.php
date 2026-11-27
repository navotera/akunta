<?php

declare(strict_types=1);

namespace Akunta\Rbac\Models;

use Akunta\Rbac\Scopes\AssignmentTenantScope;
use Akunta\Rbac\Support\TenantContext;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string             $id
 * @property string|null        $tenant_id
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

    /** @var array<string, bool> */
    private static array $hasTenantColumn = [];

    protected static function booted(): void
    {
        static::addGlobalScope(new AssignmentTenantScope);

        static::creating(function (self $assignment): void {
            if ($assignment->getAttribute('tenant_id') === null && static::tenantColumnExists($assignment)) {
                $assignment->setAttribute('tenant_id', static::resolveTenantId($assignment));
            }
        });
    }

    /**
     * Tenant of an assignment: its entity's tenant, else the tenant of a
     * tenant-custom role, else the only tenant of a single-tenant
     * installation. Never "the first tenant" of several — NULL instead.
     * Transitional until TenantContext lands (docs/decisions.md, shared
     * database multi-tenant).
     */
    public static function resolveTenantId(self $assignment): ?string
    {
        // Look the parents up unscoped: the tenant must be the parent's own, even
        // when it differs from the current context.
        if ($assignment->entity_id !== null) {
            return Entity::withoutGlobalScopes()->whereKey($assignment->entity_id)->value('tenant_id');
        }

        if ($assignment->role_id !== null) {
            $roleTenant = Role::withoutGlobalScopes()->whereKey($assignment->role_id)->value('tenant_id');
            if ($roleTenant !== null) {
                return $roleTenant;
            }
        }

        // An application-wide assignment made while serving a request belongs to the
        // organisation that request works for.
        if (TenantContext::id() !== null) {
            return TenantContext::id();
        }

        $tenantIds = Tenant::query()->limit(2)->pluck('id');

        return $tenantIds->count() === 1 ? (string) $tenantIds->first() : null;
    }

    private static function tenantColumnExists(self $assignment): bool
    {
        $connection = $assignment->getConnectionName() ?? config('database.default');

        return self::$hasTenantColumn[$connection] ??= $assignment->getConnection()
            ->getSchemaBuilder()
            ->hasColumn($assignment->getTable(), 'tenant_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

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
