<?php

namespace App\Models;

use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\User as RbacUser;
use App\Http\Middleware\SharedEntitySelector;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class User extends RbacUser
{
    /** @return Collection<int, Entity> */
    public function getTenants(): Collection
    {
        if ($this->isSsoAdmin()) {
            return Entity::query()->get();
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

        if ($this->isSsoAdmin()) {
            return true;
        }

        return $this->assignments()
            ->whereNull('revoked_at')
            ->where(function ($q) use ($tenant) {
                $q->where('entity_id', $tenant->id)
                    ->orWhere(function ($q) {
                        $q->whereNull('entity_id')->whereNotNull('role_id');
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
        if ($this->assignments()
            ->whereNull('revoked_at')
            ->whereNull('entity_id')
            ->whereNotNull('role_id')
            ->exists()) {
            return Entity::query()->pluck('id');
        }

        return collect();
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
