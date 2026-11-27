<?php

declare(strict_types=1);

namespace App\Jobs\Middleware;

use Akunta\Rbac\Support\TenantContext;

/**
 * Runs a queued job inside the TenantContext it was dispatched for. The job
 * carries the tenant id in its payload (`$tenantId`, set in its constructor
 * from the dispatching context or from the row it is about), so a worker never
 * inherits a context from the previous job and never works unscoped for a
 * tenant-owned row. A job without a tenant runs as it always did.
 */
final class RunInTenant
{
    public function handle(object $job, callable $next): mixed
    {
        $tenantId = $job->tenantId ?? null;
        if (! is_string($tenantId) || $tenantId === '') {
            return TenantContext::without(fn () => $next($job));
        }

        return TenantContext::run($tenantId, fn () => $next($job));
    }
}
