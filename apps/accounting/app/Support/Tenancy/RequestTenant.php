<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Illuminate\Support\Facades\DB;

/**
 * Which tenant an authenticated request belongs to — from the identity, never
 * from a header (docs/plans/ecopa-multi-org-agent-prompt.md stage 5 step 1):
 *
 *  - Ecopa session: the signed `organization` claim stored at login
 *    (session `ecopa.organization`) -> tenants.ecopa_organization_id.
 *  - Standalone / pre-Ecopa session (no claim): the tenant all of the user's
 *    assignments agree on, else the installation's only tenant.
 *  - API token: the token's own tenant (see ApiTokenAuth).
 *
 * `X-Tenant-Slug` only selects an entity inside the tenant resolved here.
 */
final class RequestTenant
{
    /**
     * TenantContext value for a signed-in session whose organisation has no tenant
     * yet. It matches no row, so every scoped query answers "nothing" instead of
     * running unscoped. (A ULID-shaped value no real tenant can have.)
     */
    public const NO_TENANT = '00000000000000000000000000';

    /** The Ecopa organisation id signed into the current session, if any. */
    public static function sessionOrganizationId(): ?string
    {
        $organization = session('ecopa.organization');
        $id = is_array($organization) ? ($organization['id'] ?? null) : null;

        return (is_string($id) || is_int($id)) && (string) $id !== '' ? (string) $id : null;
    }

    public static function tenantOfOrganization(string $organizationId): ?string
    {
        $id = DB::table('tenants')->where('ecopa_organization_id', $organizationId)->value('id');

        return $id === null ? null : (string) $id;
    }

    /**
     * Ecopa admin rights (`app_role = admin`) are rights inside ONE organisation:
     * the one signed into the session. True only for that organisation's tenant.
     * Without Ecopa claims (standalone) the same rule applies to the single tenant.
     */
    public static function isSessionAdminOf(?string $userId, ?string $tenantId): bool
    {
        if ($tenantId === null || session('ecopa.app_role') !== 'admin') {
            return false;
        }

        return self::forSession($userId) === $tenantId;
    }

    /**
     * The tenant of the current session. `null` means it cannot be determined:
     * an organisation without a tenant, or no single tenant to fall back on.
     */
    public static function forSession(?string $userId): ?string
    {
        $organizationId = self::sessionOrganizationId();
        if ($organizationId !== null) {
            return self::tenantOfOrganization($organizationId);
        }

        return ($userId !== null ? TenantOwnership::userTenant($userId) : null) ?? TenantOwnership::onlyTenant();
    }
}
