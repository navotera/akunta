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
            $entity = Entity::where('slug', $tenantSlug)->first()
                  ?? Entity::find($tenantSlug);
        }
        $entity ??= Auth::user()?->getDefaultTenant();

        if (! $entity instanceof Entity) {
            throw ValidationException::withMessages(['tenant' => 'Tenant not resolvable.']);
        }

        return $entity;
    }
}
