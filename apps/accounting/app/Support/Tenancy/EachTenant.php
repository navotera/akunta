<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Akunta\Rbac\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Scheduled work visits every tenant with that tenant's own TenantContext
 * (stage 5 step 6): a recurring-journal run, an auto reversal, or a purge sees
 * one organisation's rows at a time. One tenant failing is logged and does not
 * stop the others; the caller learns about it from the returned failures.
 */
final class EachTenant
{
    /** @return list<string> */
    public static function ids(): array
    {
        return DB::table('tenants')->orderBy('id')->pluck('id')->map(fn ($id): string => (string) $id)->all();
    }

    /**
     * @param  callable(string): void  $callback  receives the tenant id, runs inside its context
     * @return array<string, string> tenant id => error message, for the tenants that failed
     */
    public static function run(callable $callback): array
    {
        $failures = [];
        foreach (self::ids() as $tenantId) {
            try {
                TenantContext::run($tenantId, fn () => $callback($tenantId));
            } catch (Throwable $exception) {
                $failures[$tenantId] = $exception->getMessage();
                Log::error('Scheduled work failed for a tenant', ['tenant_id' => $tenantId, 'error' => $exception->getMessage()]);
            }
        }

        return $failures;
    }
}
