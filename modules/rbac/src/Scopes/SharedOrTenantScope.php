<?php

declare(strict_types=1);

namespace Akunta\Rbac\Scopes;

use Akunta\Rbac\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * For rows that are either global (tenant_id NULL, e.g. preset roles) or owned
 * by one tenant: the current tenant sees the global rows and its own, never
 * another tenant's.
 */
final class SharedOrTenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = TenantContext::id();
        if ($tenantId === null) {
            return;
        }

        $table = $model->getTable();
        $builder->where(function (Builder $query) use ($table, $tenantId): void {
            $query->whereNull($table.'.tenant_id')->orWhere($table.'.tenant_id', $tenantId);
        });
    }
}
