<?php

declare(strict_types=1);

namespace Akunta\Rbac\Scopes;

use Akunta\Rbac\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\DB;

/**
 * An assignment only exists for the tenant it belongs to. This is what keeps an
 * application-wide assignment (entity_id NULL) of organisation B from granting
 * anything inside organisation A, and a user who belongs to two organisations
 * from carrying one organisation's roles into the other.
 *
 * Assignments that carry no tenant yet (rows older than the tenant_id column
 * that nobody has attributed) are only honoured in a single-tenant
 * installation, where there is exactly one possible owner.
 */
final class AssignmentTenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = TenantContext::id();
        if ($tenantId === null) {
            return;
        }

        $table = $model->getTable();
        $builder->where(function (Builder $query) use ($table, $tenantId): void {
            $query->where($table.'.tenant_id', $tenantId);
            if (self::isOnlyTenant($tenantId)) {
                $query->orWhereNull($table.'.tenant_id');
            }
        });
    }

    private static function isOnlyTenant(string $tenantId): bool
    {
        $ids = DB::table('tenants')->limit(2)->pluck('id');

        return $ids->count() === 1 && (string) $ids->first() === $tenantId;
    }
}
