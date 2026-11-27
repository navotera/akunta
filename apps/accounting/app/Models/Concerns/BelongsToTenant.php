<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Akunta\Rbac\Scopes\TenantScope;
use Akunta\Rbac\Support\TenantContext;
use App\Support\Tenancy\TenantOwnership;
use Illuminate\Auth\Access\AuthorizationException;

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
            if (! TenantOwnership::hasTenantColumn($model->getTable())) {
                return;
            }

            $tenantId = $model->getAttribute('tenant_id')
                ?? TenantOwnership::tenantFor($model->getTable(), $model->getAttributes());
            if ($tenantId !== null) {
                $model->setAttribute('tenant_id', $tenantId);
            }

            // Last line of defence behind every controller check: while a request, job, or
            // command works for one tenant it can never create a row that belongs to another
            // (for example by naming another organisation's workspace as the parent).
            $context = TenantContext::id();
            if ($context !== null && $tenantId !== null && $context !== $tenantId) {
                throw new AuthorizationException('Baris ini milik organisasi lain dan tidak dapat dibuat dari konteks ini.');
            }
        });
    }

    protected function initializeBelongsToTenant(): void
    {
        $this->makeHidden('tenant_id');
    }
}
