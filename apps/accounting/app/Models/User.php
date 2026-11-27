<?php

namespace App\Models;

use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\User as RbacUser;
use App\Http\Middleware\SharedEntitySelector;
use App\Services\AccountingPermissions;
use App\Support\Tenancy\RequestTenant;
use App\Support\Tenancy\TenantOwnership;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class User extends RbacUser
{
    public function hasPermission(string $permissionCode, ?string $entityId = null): bool
    {
        $permissions = app(AccountingPermissions::class);
        if ($entityId !== null && $permissions->knows($permissionCode)) {
            $entity = Entity::find($entityId);

            return $entity !== null && $permissions->allows($this, $permissionCode, $entity);
        }

        return parent::hasPermission($permissionCode, $entityId);
    }

    /** @return Collection<int, Entity> */
    public function getTenants(): Collection
    {
        // An Ecopa admin gets every workspace of the organisation in the session —
        // never those of another organisation.
        if ($this->isSsoAdmin()) {
            $tenantId = RequestTenant::forSession($this->id);

            return $tenantId === null ? new Collection : Entity::query()->where('tenant_id', $tenantId)->get();
        }

        return Entity::query()
            ->whereIn('id', $this->assignments()->whereNull('revoked_at')->pluck('entity_id')->filter()->unique()->values())
            ->orWhereIn('id', $this->accessibleEntityIdsFromTenantWide())
            ->get();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        if (! $tenant instanceof Entity) {
            return false;
        }

        if ($this->isSsoAdmin() && RequestTenant::isSessionAdminOf($this->id, $tenant->tenant_id)) {
            return true;
        }

        // Assignments are tenant-scoped (AssignmentTenantScope): an application-wide
        // one only counts for entities of its own tenant.
        return $this->assignments()
            ->whereNull('revoked_at')
            ->where(function ($q) use ($tenant) {
                $q->where('entity_id', $tenant->id)
                    ->orWhere(function ($q) use ($tenant) {
                        $q->whereNull('entity_id')->whereNotNull('role_id')
                            ->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenant->tenant_id));
                    });
            })
            ->exists();
    }

    /** True if current session was provisioned via Ecopa with app_role=admin. */
    public function isSsoAdmin(): bool
    {
        return session('ecopa.app_role') === 'admin';
    }

    /**
     * An app-level Ecopa assignment is a role-less local shadow record: it
     * establishes identity but must not confer tenant-wide access. Only a role
     * explicitly assigned by an Akunta admin may be tenant-wide.
     *
     * @return Collection<int, string>
     */
    protected function accessibleEntityIdsFromTenantWide(): Collection
    {
        // ...and only inside the tenant(s) those assignments belong to.
        $tenantIds = $this->assignments()
            ->whereNull('revoked_at')
            ->whereNull('entity_id')
            ->whereNotNull('role_id')
            ->get(['tenant_id'])
            ->map(fn ($assignment) => $assignment->tenant_id ?? TenantOwnership::onlyTenant())
            ->filter()
            ->unique()
            ->values();

        return $tenantIds->isEmpty() ? collect() : Entity::query()->whereIn('tenant_id', $tenantIds)->pluck('id');
    }

    /**
     * Cross-app entity sync: prefer the entity pointed at by the shared
     * SharedEntitySelector cookie so switching in one app propagates to
     * siblings. Fallback = first accessible entity if cookie absent / invalid.
     */
    public function getDefaultTenant(): ?Entity
    {
        $cookieEntityId = request()?->cookie(SharedEntitySelector::COOKIE_NAME);

        if (is_string($cookieEntityId) && $cookieEntityId !== '') {
            $entity = Entity::find($cookieEntityId);
            if ($entity instanceof Entity && $this->canAccessTenant($entity)) {
                return $entity;
            }
        }

        return $this->getTenants()->first();
    }
}
