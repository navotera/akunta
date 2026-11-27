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
 * code that deliberately works across tenants) nothing is filtered by the
 * application; such code must iterate tenants with {@see run()} or be an
 * explicit, documented cross-tenant path ({@see without()}, {@see platformRead()}).
 *
 * PostgreSQL Row-Level Security (stage 9) makes the database enforce the same
 * thing: the context is copied into a transaction-local setting, and "no
 * context" means "no rows". The two cross-tenant doors are both read-only and
 * both explicit: {@see platformRead()} for installation-level lookups (which
 * tenant does this person belong to?) and {@see without()} for installation-
 * level aggregates (the Platform Owner overview).
 *
 * Process-wide state is fine here: PHP-FPM serves one request per process and
 * queue workers run one job at a time; every entry point sets or clears it.
 * Observers registered with {@see onChange()} are told whenever the effective
 * context changes (the database layer uses this to refresh its local setting).
 */
final class TenantContext
{
    private static ?string $tenantId = null;

    private static bool $platformRead = false;

    /** @var list<callable(): void> */
    private static array $listeners = [];

    public static function id(): ?string
    {
        return self::$tenantId;
    }

    public static function active(): bool
    {
        return self::$tenantId !== null;
    }

    /** Whether the explicit, read-only cross-tenant mode is on. */
    public static function platform(): bool
    {
        return self::$platformRead;
    }

    public static function set(?string $tenantId): void
    {
        self::$tenantId = $tenantId === '' ? null : $tenantId;
        self::notify();
    }

    public static function clear(): void
    {
        self::$tenantId = null;
        self::$platformRead = false;
        self::notify();
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
        return self::scoped($tenantId, false, $callback);
    }

    /**
     * Explicit cross-tenant path for installation-level aggregates (the Platform
     * Owner overview): no tenant, and every tenant's rows may be READ. It never
     * allows a write, and callers must not return tenant business data through it.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function without(callable $callback): mixed
    {
        return self::scoped(null, true, $callback);
    }

    /**
     * Explicit read-only cross-tenant path for lookups that happen before a
     * context exists (which tenant does this person or this entity belong to?).
     * The current tenant, if any, is kept. Reads only; writes still need a tenant.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function platformRead(callable $callback): mixed
    {
        return self::scoped(self::$tenantId, true, $callback);
    }

    /**
     * Be told whenever the effective context (tenant or platform-read mode) changes.
     *
     * @param  callable(): void  $listener
     */
    public static function onChange(callable $listener): void
    {
        self::$listeners[] = $listener;
    }

    /** @param  callable(): mixed  $callback */
    private static function scoped(?string $tenantId, bool $platform, callable $callback): mixed
    {
        $previousTenant = self::$tenantId;
        $previousPlatform = self::$platformRead;
        self::$tenantId = $tenantId;
        self::$platformRead = $platform;
        self::notify();

        try {
            return $callback();
        } finally {
            self::$tenantId = $previousTenant;
            self::$platformRead = $previousPlatform;
            self::notify();
        }
    }

    private static function notify(): void
    {
        foreach (self::$listeners as $listener) {
            $listener();
        }
    }
}
