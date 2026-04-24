<?php

declare(strict_types=1);

namespace App\Tenancy\Contracts;

/**
 * Allocate / deallocate a tenant database per arch §4.3.
 *
 * Implementations differ by driver: `pgsql` issues CREATE/DROP DATABASE on the
 * control connection; `sqlite` creates/removes a file on disk. Downstream logic
 * (ProvisionTenantAction, TenantResolver, future ProvisionTenantJob) stays
 * driver-agnostic by depending on this contract.
 *
 * Implementations MUST:
 *   - Reject identifiers that don't match `IDENTIFIER_PATTERN` (prevents SQL
 *     injection via $dbName — we do NOT quote identifiers across all drivers).
 *   - Be idempotent on `exists()`; safe to call `create()` on an existing DB =
 *     no-op or throw `TenantDatabaseAlreadyExists` (impl choice, document).
 *   - Never drop the control DB or the default connection's DB.
 */
interface TenantProvisioner
{
    /** Valid tenant DB identifier: alphanumeric + underscore, 1-63 chars (PG limit). */
    public const IDENTIFIER_PATTERN = '/^[a-zA-Z][a-zA-Z0-9_]{0,62}$/';

    public function create(string $dbName): void;

    public function drop(string $dbName): void;

    public function exists(string $dbName): bool;

    /**
     * Return a Laravel database connection config array that points at the
     * tenant DB. Consumers use this with `Config::set('database.connections.tenant', ...)`
     * + `DB::purge('tenant')` to swap the active connection.
     *
     * @return array<string, mixed>
     */
    public function connectionConfig(string $dbName): array;
}
