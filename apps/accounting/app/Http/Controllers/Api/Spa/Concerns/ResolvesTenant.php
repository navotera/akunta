<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Spa\Concerns;

use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Support\TenantContext;
use App\Models\User;
use App\Support\Tenancy\RequestTenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

trait ResolvesTenant
{
    protected function resolveEntity(Request $request): Entity
    {
        $tenantSlug = $request->header('X-Tenant-Slug');
        $entity = null;
        if ($tenantSlug) {
            // The header only selects a workspace inside the tenant of the
            // authenticated identity (Entity is tenant-scoped). A workspace of
            // another organisation is refused outright, not silently replaced
            // by this user's default one.
            $entity = Entity::find($tenantSlug);
            // The existence check reads across tenants on purpose (a boolean only): with
            // Row-Level Security the other organisation's workspace is invisible to this
            // context, which must not turn "refused" into "fall back to the default".
            if ($entity === null && TenantContext::active()
                && TenantContext::platformRead(fn (): bool => Entity::withoutGlobalScopes()->whereKey($tenantSlug)->exists())) {
                throw ValidationException::withMessages(['tenant' => 'Tenant is not accessible.']);
            }
        }
        $entity ??= Auth::user()?->getDefaultTenant();

        if (! $entity instanceof Entity) {
            throw ValidationException::withMessages(['tenant' => 'Tenant not resolvable.']);
        }

        $user = Auth::user();

        // An Ecopa admin (Platform Owner or the organisation's admin) may have
        // no local assignment yet — the account is created on its first SSO
        // login. User::getTenants() and AccountingPermissions already grant
        // it every workspace; resolving the workspace must agree, otherwise
        // the Platform Owner sees the existing workspaces but every SPA call
        // fails. Only the linked Ecopa organisation can sign in once a tenant
        // is linked (EcopaController), so this stays within that organisation.
        if ($user instanceof User && $user->disabled_at === null
            && RequestTenant::isSessionAdminOf($user->id, $entity->tenant_id)) {
            return $entity;
        }

        $canAccess = $user?->assignments()
            ->whereNull('revoked_at')
            ->where(function ($query) use ($entity): void {
                $query->whereNull('entity_id')->orWhere('entity_id', $entity->id);
            })
            ->exists() ?? false;

        if (! $canAccess) {
            throw ValidationException::withMessages(['tenant' => 'Tenant is not accessible.']);
        }

        return $entity;
    }
}
