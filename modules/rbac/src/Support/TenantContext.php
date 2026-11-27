<?php

declare(strict_types=1);

namespace Akunta\Rbac\Support;

use Akunta\Rbac\Scopes\TenantScope;

/**
 * The tenant (= Ecopa organisation) the current request or job works for.
 *
 * decisions.md "Locked 2026-11-19 — shared database multi-tenant (pooled)":
 * isolation between organisations rests on tenant_id on every tenant-owned row
 * and on this context, which is derived from the *authenticated identity*
 * (signed SSO organisation claim, API-token owner, or the installation's only
 * tenant) — never from a client-supplied header or parameter.
 *
 * While a context is active, models that carry {@see TenantScope}
 * only see that tenant's rows. With no context (artisan commands, seeders,
 * code that deliberately works across tenants) nothing is filtered; such code
 * must iterate tenants with {@see run()} or be an explicit, documented
 * cross-tenant path ({@see without()}). PostgreSQL Row-Level Security (stage 9)
 * turns "no context" into "no rows" at the database level.
 *
 * Process-wide state is fine here: PHP-FPM serves one request per process and
 * queue workers run one job at a time; every entry point sets or clears it.
 */
final class TenantContext
{
    private static ?string $tenantId = null;

    public static function id(): ?string
    {
        return self::$tenantId;
    }

    public static function active(): bool
    {
        return self::$tenantId !== null;
    }

    public static function set(?string $tenantId): void
    {
        self::$tenantId = $tenantId === '' ? null : $tenantId;
    }

    public static function clear(): void
    {
        self::$tenantId = null;
    }

    /**
     * Run $callback for one tenant and restore the previous context after it,
     * even when it throws.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function run(string $tenantId, callable $callback): mixed
    {
        $previous = self::$tenantId;
        self::$tenantId = $tenantId;

        try {
            return $callback();
        } finally {
            self::$tenantId = $previous;
        }
    }

    /**
     * Explicit cross-tenant path (installation-level work such as the Platform
     * Owner usage overview or a scheduler that visits every tenant). Callers
     * must not return tenant business data through it.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function without(callable $callback): mixed
    {
        $previous = self::$tenantId;
        self::$tenantId = null;

        try {
            return $callback();
        } finally {
            self::$tenantId = $previous;
        }
    }
}
