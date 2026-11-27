<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Akunta\Rbac\Support\TenantContext;

/**
 * Cache keys for tenant-owned data must never be shared between organisations
 * (the cache store is shared by the whole installation). Wrap every such key:
 * `Cache::get(TenantCache::key('dashboard.summary'))`.
 *
 * Installation-level entries (for example the scheduler heartbeat) are not
 * tenant data and keep their plain key.
 */
final class TenantCache
{
    public static function key(string $key, ?string $tenantId = null): string
    {
        $tenantId ??= TenantContext::id();
        if ($tenantId === null) {
            throw new \LogicException("Cache key [{$key}] is tenant data but no TenantContext is active.");
        }

        return "tenant:{$tenantId}:{$key}";
    }
}
