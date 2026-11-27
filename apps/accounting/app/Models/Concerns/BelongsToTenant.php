<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Akunta\Rbac\Scopes\TenantScope;
use App\Support\Tenancy\TenantOwnership;

/**
 * Fills `tenant_id` when a tenant-owned row is created, from the parent row (or
 * the rule documented on TenantOwnership::tenantFor). An explicit tenant_id is
 * kept. The full query scope per tenant is added with TenantContext (stage 5);
 * this trait only guarantees every new row carries the right tenant.
 *
 * The column is hidden from serialization: API payloads do not change.
 */
trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model): void {
            if ($model->getAttribute('tenant_id') !== null || ! TenantOwnership::hasTenantColumn($model->getTable())) {
                return;
            }

            $tenantId = TenantOwnership::tenantFor($model->getTable(), $model->getAttributes());
            if ($tenantId !== null) {
                $model->setAttribute('tenant_id', $tenantId);
            }
        });
    }

    protected function initializeBelongsToTenant(): void
    {
        $this->makeHidden('tenant_id');
    }
}
