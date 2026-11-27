<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Akunta\Rbac\Support\TenantContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Single source of "which tenant owns this row" for the Ecopa multi-organisation
 * model (decisions.md "shared database multi-tenant (pooled)"; runbook §5).
 *
 * Every tenant-owned table either derives its tenant from a parent row
 * ({@see PARENTS}) or from one of the explicit rules documented on
 * {@see tenantFor()}. The same map drives the `tenant_id` migrations, the
 * model trait that fills the column on create, and the migration report, so
 * the three cannot drift apart.
 *
 * Nothing here guesses: a row whose tenant cannot be established yields null.
 */
final class TenantOwnership
{
    /**
     * table => [parent table, column that points at the parent].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const PARENTS = [
        // Direct children of a workspace (entity).
        'accounts' => ['entities', 'entity_id'],
        'periods' => ['entities', 'entity_id'],
        'journals' => ['entities', 'entity_id'],
        'attachments' => ['entities', 'entity_id'],
        'journal_templates' => ['entities', 'entity_id'],
        'recurring_journals' => ['entities', 'entity_id'],
        'fiscal_adjustments' => ['entities', 'entity_id'],
        'tax_provisions' => ['entities', 'entity_id'],
        'tax_codes' => ['entities', 'entity_id'],
        'branches' => ['entities', 'entity_id'],
        'cost_centers' => ['entities', 'entity_id'],
        'projects' => ['entities', 'entity_id'],
        'auto_mapping_rules' => ['entities', 'entity_id'],
        'auto_mapping_raw_data' => ['entities', 'entity_id'],
        'documentation_notes' => ['entities', 'entity_id'],
        'source_ref_registry' => ['entities', 'entity_id'],
        'fake_data_records' => ['entities', 'entity_id'],
        // Children of a tenant-owned row.
        'journal_entries' => ['journals', 'journal_id'],
        'journal_template_lines' => ['journal_templates', 'template_id'],
        'webhook_deliveries' => ['webhook_subscriptions', 'subscription_id'],
        'coa_template_accounts' => ['coa_templates', 'template_id'],
    ];

    /**
     * Rows with an optional entity: webhook subscriptions (a global one has no
     * entity) and the append-only audit log (platform-level actions have none).
     * api_tokens have no entity at all. Their tenant comes from {@see tenantFor()}.
     */
    public const RULE_BASED = ['webhook_subscriptions', 'api_tokens', 'audit_log', 'coa_templates'];

    /** Tables that gain tenant_id in migration stage 3, in dependency order. */
    public static function tables(): array
    {
        return [...array_keys(self::PARENTS), ...self::RULE_BASED];
    }

    /** @var array<string, string|null> */
    private static array $cache = [];

    /**
     * The tenant a new row belongs to, or null when it cannot be established.
     *
     * - Parent-derived tables: the tenant of the parent row.
     * - webhook_subscriptions: its entity's tenant; a global subscription (no
     *   entity) takes the tenant its creator belongs to.
     * - api_tokens: the tenant its user belongs to.
     * - audit_log: its entity's tenant; null for platform-level actions.
     * - "Belongs to" a user means: all the user's tenant-attributed assignments
     *   agree on one tenant. Otherwise a single-tenant installation (v1 data)
     *   has only one possible answer; with several tenants the caller must say.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function tenantFor(string $table, array $attributes): ?string
    {
        if (isset(self::PARENTS[$table])) {
            [$parent, $column] = self::PARENTS[$table];
            $id = $attributes[$column] ?? null;

            return is_string($id) && $id !== '' ? self::parentTenant($parent, $id) : null;
        }

        $entityId = $attributes['entity_id'] ?? null;
        if (is_string($entityId) && $entityId !== '') {
            $tenant = self::parentTenant('entities', $entityId);
            if ($tenant !== null || $table !== 'webhook_subscriptions') {
                return $tenant;
            }
        }

        return match ($table) {
            'webhook_subscriptions' => self::userTenant($attributes['created_by'] ?? null) ?? self::onlyTenant(),
            'api_tokens' => self::userTenant($attributes['user_id'] ?? null) ?? self::onlyTenant(),
            'audit_log' => self::aboutTenant($attributes),
            // An organisation's own Chart-of-Accounts template: the organisation the request works for.
            'coa_templates' => TenantContext::id() ?? self::onlyTenant(),
            default => null,
        };
    }

    /** Resource type of tenant-level audit entries (ProvisionTenantAction, EcopaOrganizationLinker). */
    public const TENANT_RESOURCE = 'Akunta\\Rbac\\Models\\Tenant';

    /** @param  array<string, mixed>  $attributes */
    private static function aboutTenant(array $attributes): ?string
    {
        $id = $attributes['resource_id'] ?? null;
        if (($attributes['resource_type'] ?? null) !== self::TENANT_RESOURCE || ! is_string($id) || $id === '') {
            return null;
        }

        return DB::table('tenants')->where('id', $id)->exists() ? $id : null;
    }

    /** The tenant all of a user's tenant-attributed assignments agree on. */
    public static function userTenant(mixed $userId): ?string
    {
        if (! is_string($userId) || $userId === '') {
            return null;
        }

        // Decided before any tenant context exists, so it reads across tenants on purpose:
        // the explicit read-only platform path (and it returns tenant ids only).
        $tenants = TenantContext::platformRead(fn () => DB::table('user_app_assignments as uaa')
            ->leftJoin('entities as ent', 'ent.id', '=', 'uaa.entity_id')
            ->where('uaa.user_id', $userId)
            ->selectRaw('COALESCE(uaa.tenant_id, ent.tenant_id) as tenant_id')
            ->distinct()
            ->pluck('tenant_id')
            ->filter()
            ->values());

        return $tenants->count() === 1 ? (string) $tenants->first() : null;
    }

    /** The tenant of a single-tenant installation, otherwise null. */
    public static function onlyTenant(): ?string
    {
        if (! Schema::hasTable('tenants')) {
            return null;
        }

        $ids = DB::table('tenants')->limit(2)->pluck('id');

        return $ids->count() === 1 ? (string) $ids->first() : null;
    }

    public static function parentTenant(string $parentTable, string $id): ?string
    {
        $key = $parentTable.':'.$id;
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        // Which tenant owns this parent row? Asked before (or without) a context: explicit platform read.
        $tenant = TenantContext::platformRead(fn () => DB::table($parentTable)->where('id', $id)->value('tenant_id'));
        if ($tenant === null) {
            // Not cached: the parent may be created (or backfilled) a moment later.
            return null;
        }

        if (count(self::$cache) >= 5000) {
            self::$cache = [];
        }

        return self::$cache[$key] = (string) $tenant;
    }

    /** @var array<string, true> */
    private static array $hasColumn = [];

    /**
     * Whether the table already has tenant_id. Older data migrations that run
     * before the stage-3 migrations create rows through the models; they must
     * not set a column that does not exist yet. Only "yes" is remembered.
     */
    public static function hasTenantColumn(string $table): bool
    {
        if (isset(self::$hasColumn[$table])) {
            return true;
        }
        if (! Schema::hasColumn($table, 'tenant_id')) {
            return false;
        }

        return self::$hasColumn[$table] = true;
    }

    /**
     * Defense in depth for raw query-builder reads of tenant-owned tables, which
     * bypass the model global scope: while a TenantContext is active, only that
     * tenant's rows. Callers still filter by their (already authorized) entity.
     */
    public static function restrict(Builder $query, string $alias): Builder
    {
        $tenantId = TenantContext::id();

        return $tenantId === null ? $query : $query->where($alias.'.tenant_id', $tenantId);
    }

    public static function flush(): void
    {
        self::$cache = [];
        self::$hasColumn = [];
    }
}
