<?php

declare(strict_types=1);

namespace App\Models;

use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\User as RbacUser;
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

    public function canAccessTenant(Entity $entity): bool
    {
        if ($this->isSsoAdmin()) {
            return true;
        }

        return $this->assignments()
            ->whereNull('revoked_at')
            ->where(function ($q) use ($entity) {
                $q->whereNull('entity_id')->orWhere('entity_id', $entity->id);
            })
            ->exists();
    }

    /** True if current session was provisioned via Ecopa with app_role=admin. */
    public function isSsoAdmin(): bool
    {
        return session('ecopa.app_role') === 'admin';
    }

    public function getDefaultTenant(): ?Entity
    {
        $cookieEntityId = request()?->cookie('akunta_entity');

        if (is_string($cookieEntityId) && $cookieEntityId !== '') {
            $entity = Entity::find($cookieEntityId);
            if ($entity instanceof Entity && $this->canAccessTenant($entity)) {
                return $entity;
            }
        }

        return $this->getTenants()->first();
    }

    protected function accessibleEntityIdsFromTenantWide(): Collection
    {
        if ($this->assignments()->whereNull('revoked_at')->whereNull('entity_id')->exists()) {
            return Entity::query()->pluck('id');
        }

        return collect();
    }
}
