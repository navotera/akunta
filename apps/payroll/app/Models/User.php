<?php

namespace App\Models;

use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\User as RbacUser;
use App\Http\Middleware\SharedEntitySelector;
use Filament\Models\Contracts\HasDefaultTenant;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class User extends RbacUser implements HasDefaultTenant, HasTenants
{
    public function getTenants(Panel $panel): array|Collection
    {
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

        return $this->assignments()
            ->whereNull('revoked_at')
            ->where(function ($q) use ($tenant) {
                $q->whereNull('entity_id')->orWhere('entity_id', $tenant->id);
            })
            ->exists();
    }

    protected function accessibleEntityIdsFromTenantWide(): Collection
    {
        if ($this->assignments()->whereNull('revoked_at')->whereNull('entity_id')->exists()) {
            return Entity::query()->pluck('id');
        }

        return collect();
    }

    public function getDefaultTenant(Panel $panel): ?Model
    {
        $cookieEntityId = request()?->cookie(SharedEntitySelector::COOKIE_NAME);

        if (is_string($cookieEntityId) && $cookieEntityId !== '') {
            $entity = Entity::find($cookieEntityId);
            if ($entity instanceof Entity && $this->canAccessTenant($entity)) {
                return $entity;
            }
        }

        $tenants = $this->getTenants($panel);

        return $tenants instanceof Collection ? $tenants->first() : ($tenants[0] ?? null);
    }
}
