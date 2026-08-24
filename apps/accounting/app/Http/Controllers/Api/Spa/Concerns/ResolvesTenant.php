<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Spa\Concerns;

use Akunta\Rbac\Models\Entity;
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
            $entity = Entity::find($tenantSlug);
        }
        $entity ??= Auth::user()?->getDefaultTenant();

        if (! $entity instanceof Entity) {
            throw ValidationException::withMessages(['tenant' => 'Tenant not resolvable.']);
        }

        $user = Auth::user();
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
