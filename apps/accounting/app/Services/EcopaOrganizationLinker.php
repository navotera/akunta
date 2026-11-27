<?php

declare(strict_types=1);

namespace App\Services;

use Akunta\Core\Contracts\AuditLogger;
use Akunta\Rbac\Models\Tenant;
use App\Support\Tenancy\TenantOwnership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Links Akunta tenants to Ecopa organisations (one organisation = one tenant).
 *
 * Flow 1 of docs/plans/ecopa-multi-org-workspaces.md: tenants that existed
 * before Ecopa became multi-organisation belong to Ecopa's default
 * organisation. The organisation id comes only from a signed Platform Owner
 * login (whose home is always the default organisation) or from an explicit
 * operator command — never from e-mail, names, or "the first tenant".
 *
 * Until per-organisation scoping is implemented (plan steps 2–8) only ONE
 * organisation may be linked; linking a second one is refused so an admin of
 * another organisation can never reach these books.
 */
final class EcopaOrganizationLinker
{
    public const STATUS_LINKED = 'linked';

    public const STATUS_ALREADY_LINKED = 'already_linked';

    public const STATUS_NO_LEGACY_TENANT = 'no_legacy_tenant';

    public const STATUS_AMBIGUOUS = 'ambiguous';

    public const STATUS_OTHER_ORGANIZATION_LINKED = 'other_organization_linked';

    public const STATUS_TENANT_NOT_FOUND = 'tenant_not_found';

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * Normalise the Ecopa `organization` claim to {id, slug, name}.
     *
     * @return array{id: string, slug: string|null, name: string|null}|null
     */
    public static function normalize(mixed $organization): ?array
    {
        if (! is_array($organization)) {
            return null;
        }

        $id = $organization['id'] ?? null;
        if (! is_scalar($id) || trim((string) $id) === '') {
            return null;
        }

        $slug = $organization['slug'] ?? null;
        $name = $organization['name'] ?? null;

        return [
            'id' => trim((string) $id),
            'slug' => is_string($slug) && $slug !== '' ? $slug : null,
            'name' => is_string($name) && $name !== '' ? $name : null,
        ];
    }

    /**
     * Whether a user of this organisation may sign in today. Before any
     * tenant is linked the installation behaves as single-organisation
     * (legacy); afterwards only the linked organisation is accepted.
     */
    public function organizationMayEnter(string $organizationId): bool
    {
        if (config('tenancy.multi_organization')) {
            return true;
        }

        $linked = Tenant::query()->whereNotNull('ecopa_organization_id');

        return ! $linked->exists()
            || Tenant::query()->where('ecopa_organization_id', $organizationId)->exists();
    }

    /**
     * Link the legacy (unlinked) tenant to the given organisation.
     *
     * @param  array{id: string, slug: string|null, name: string|null}  $organization
     * @return array{status: string, tenant_id: string|null, assignments_backfilled: int, counts_before: array<string, int>, counts_after: array<string, int>, dry_run: bool}
     */
    public function linkLegacyTenants(
        array $organization,
        string $source,
        ?string $tenantId = null,
        bool $dryRun = false,
    ): array {
        $before = $this->counts();

        try {
            $result = $this->linkInTransaction($organization, $source, $tenantId, $dryRun);
        } catch (EcopaOrganizationLinkDryRun $dryRunResult) {
            $result = $dryRunResult->result;
        }

        $after = $this->counts();
        if ($before !== $after) {
            // Linking only adds metadata; a changed row count means something
            // else wrote concurrently. Surface it instead of hiding it.
            throw new RuntimeException('Jumlah data berubah selama penautan organisasi Ecopa: '.json_encode(['before' => $before, 'after' => $after]));
        }

        if ($result['status'] === self::STATUS_OTHER_ORGANIZATION_LINKED || $result['status'] === self::STATUS_AMBIGUOUS) {
            Log::warning('ecopa.organization_link_refused', [
                'status' => $result['status'],
                'organization_id' => $organization['id'],
                'source' => $source,
            ]);
        }

        return $result + ['counts_before' => $before, 'counts_after' => $after, 'dry_run' => $dryRun];
    }

    /**
     * @param  array{id: string, slug: string|null, name: string|null}  $organization
     * @return array{status: string, tenant_id: string|null, assignments_backfilled: int}
     */
    private function linkInTransaction(array $organization, string $source, ?string $tenantId, bool $dryRun): array
    {
        return DB::transaction(function () use ($organization, $source, $tenantId, $dryRun): array {
            $tenants = Tenant::query()->lockForUpdate()->get();

            $result = fn (string $status, ?Tenant $tenant = null, int $backfilled = 0): array => [
                'status' => $status,
                'tenant_id' => $tenant?->id,
                'assignments_backfilled' => $backfilled,
            ];

            $linkedOther = $tenants->first(fn (Tenant $t): bool => $t->ecopa_organization_id !== null
                && $t->ecopa_organization_id !== $organization['id']);
            if ($linkedOther !== null) {
                return $result(self::STATUS_OTHER_ORGANIZATION_LINKED, $linkedOther);
            }

            $alreadyLinked = $tenants->firstWhere('ecopa_organization_id', $organization['id']);
            if ($alreadyLinked !== null) {
                return $result(self::STATUS_ALREADY_LINKED, $alreadyLinked);
            }

            $candidates = $tenants->whereNull('ecopa_organization_id');
            if ($tenantId !== null) {
                $candidates = $candidates->where('id', $tenantId);
                if ($candidates->isEmpty()) {
                    return $result(self::STATUS_TENANT_NOT_FOUND);
                }
            }

            if ($candidates->isEmpty()) {
                return $result(self::STATUS_NO_LEGACY_TENANT);
            }

            if ($candidates->count() > 1) {
                return $result(self::STATUS_AMBIGUOUS);
            }

            /** @var Tenant $tenant */
            $tenant = $candidates->first();
            $backfilled = $this->backfillAssignments($tenant, $tenants->count() === 1);

            if ($dryRun) {
                // Roll back the backfill; report what a real run would do.
                throw new EcopaOrganizationLinkDryRun($result(self::STATUS_LINKED, $tenant, $backfilled));
            }

            $tenant->forceFill([
                'ecopa_organization_id' => $organization['id'],
                'ecopa_organization_slug' => $organization['slug'],
                'ecopa_organization_linked_at' => now(),
            ])->save();

            $this->auditLogger->record(
                action: 'tenant.ecopa_organization_linked',
                resourceType: Tenant::class,
                resourceId: $tenant->id,
                metadata: [
                    'ecopa_organization_id' => $organization['id'],
                    'ecopa_organization_slug' => $organization['slug'],
                    'source' => $source,
                    'assignments_backfilled' => $backfilled,
                ],
            );

            return $result(self::STATUS_LINKED, $tenant, $backfilled);
        });
    }

    /**
     * Link a tenant created by installation onboarding to the installer's
     * organisation, unless that organisation or another one is already linked.
     *
     * @param  array{id: string, slug: string|null, name: string|null}  $organization
     */
    public function linkNewTenant(Tenant $tenant, array $organization, string $source): bool
    {
        // Single-organisation mode links only one tenant; multi-organisation mode links one per organisation.
        $alreadyLinked = Tenant::query()->whereNotNull('ecopa_organization_id');
        if (config('tenancy.multi_organization')) {
            $alreadyLinked->where('ecopa_organization_id', $organization['id']);
        }
        if ($alreadyLinked->exists()) {
            return false;
        }

        $tenant->forceFill([
            'ecopa_organization_id' => $organization['id'],
            'ecopa_organization_slug' => $organization['slug'],
            'ecopa_organization_linked_at' => now(),
        ])->save();

        $this->auditLogger->record(
            action: 'tenant.ecopa_organization_linked',
            resourceType: Tenant::class,
            resourceId: $tenant->id,
            metadata: [
                'ecopa_organization_id' => $organization['id'],
                'ecopa_organization_slug' => $organization['slug'],
                'source' => $source,
            ],
        );

        return true;
    }

    /**
     * Give unscoped assignments of this tenant their tenant_id (entity first,
     * then tenant-custom role, then — single-tenant installations only — all
     * remaining ones). Only NULL tenant_id rows are touched.
     */
    private function backfillAssignments(Tenant $tenant, bool $onlyTenant): int
    {
        $count = DB::table('user_app_assignments')
            ->whereNull('tenant_id')
            ->whereIn('entity_id', DB::table('entities')->where('tenant_id', $tenant->id)->select('id'))
            ->update(['tenant_id' => $tenant->id]);

        $count += DB::table('user_app_assignments')
            ->whereNull('tenant_id')
            ->whereIn('role_id', DB::table('roles')->where('tenant_id', $tenant->id)->select('id'))
            ->update(['tenant_id' => $tenant->id]);

        if ($onlyTenant) {
            $count += DB::table('user_app_assignments')
                ->whereNull('tenant_id')
                ->update(['tenant_id' => $tenant->id]);
        }

        return $count;
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        $counts = [];
        // Append-only logs are excluded: linking writes its own audit entry.
        $tables = array_diff(
            ['tenants', 'entities', 'users', 'roles', 'user_app_assignments', ...TenantOwnership::tables()],
            EcopaMigrationReport::APPEND_ONLY_TABLES,
        );
        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                $counts[$table] = DB::table($table)->count();
            }
        }

        return $counts;
    }
}
