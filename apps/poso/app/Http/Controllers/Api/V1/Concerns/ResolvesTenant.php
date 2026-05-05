<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Concerns;

use Akunta\Rbac\Models\Entity;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Resolves the active tenant + accounting entity for an authenticated request.
 *
 * Tenant scoping is derived from the authenticated user's RBAC assignments —
 * never from request headers/payload alone (which were spoofable in the old
 * `tenantId()` helpers with a `'dev-tenant'` fallback). The active entity
 * preference still flows through the `akunta_entity` cookie / `X-Entity-Id`
 * header, but it is validated against user assignments before use.
 */
trait ResolvesTenant
{
    protected function resolveEntity(Request $request): Entity
    {
        $user = $request->user();
        if (! $user instanceof User) {
            throw new HttpException(401, 'Not authenticated.');
        }

        $allowedIds = $user->assignments()
            ->whereNull('revoked_at')
            ->pluck('entity_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $tenantWide = $user->assignments()
            ->whereNull('revoked_at')
            ->whereNull('entity_id')
            ->exists();

        $preferred = $request->cookie('akunta_entity')
            ?? $request->header('X-Entity-Id')
            ?? $request->header('X-Akunta-Entity-Id');

        if (is_string($preferred) && $preferred !== '') {
            $entity = Entity::find($preferred);
            if ($entity instanceof Entity && ($tenantWide || in_array($entity->id, $allowedIds, true) || $user->isSsoAdmin())) {
                return $entity;
            }
        }

        if ($user->isSsoAdmin() || $tenantWide) {
            $entity = Entity::query()->orderBy('name')->first();
            if ($entity instanceof Entity) {
                return $entity;
            }
        }

        if (! empty($allowedIds)) {
            $entity = Entity::query()->whereIn('id', $allowedIds)->orderBy('name')->first();
            if ($entity instanceof Entity) {
                return $entity;
            }
        }

        throw new HttpException(403, 'No entity assigned to user.');
    }

    protected function tenantId(Request $request): string
    {
        return (string) $this->resolveEntity($request)->tenant_id;
    }

    protected function accountingEntityId(Request $request): string
    {
        return (string) $this->resolveEntity($request)->id;
    }
}
