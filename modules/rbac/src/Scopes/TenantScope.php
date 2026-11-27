<?php

declare(strict_types=1);

namespace Akunta\Rbac\Scopes;

use Akunta\Rbac\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Restricts a tenant-owned model to the current {@see TenantContext}. Does
 * nothing when no context is active. Lift it deliberately with
 * `withoutGlobalScope(TenantScope::class)` only on an explicit cross-tenant path.
 */
final class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = TenantContext::id();
        if ($tenantId !== null) {
            $builder->where($model->getTable().'.tenant_id', $tenantId);
        }
    }
}
