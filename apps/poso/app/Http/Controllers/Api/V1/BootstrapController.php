<?php

namespace App\Http\Controllers\Api\V1;

use Akunta\Rbac\Models\Entity;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

class BootstrapController extends Controller
{
    private const ENTITY_COOKIE = 'akunta_entity';

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $entities = $this->loadUserEntities($user);
        $activeEntity = $this->resolveActiveEntity($request, $entities);

        return response()->json([
            'data' => [
                'app' => 'poso',
                'tier' => config('poso.tier'),
                'main_tier' => config('poso.main_tier.name'),
                'accounting_tier' => config('poso.accounting_tier.name'),
                'entities' => $this->entityList($entities),
                'active_entity' => $activeEntity,
                'tenant' => [
                    'id' => $activeEntity['tenant_id'] ?? null,
                    'slug' => $activeEntity['tenant_slug'] ?? null,
                    'name' => $activeEntity['tenant_name'] ?? null,
                ],
                'user' => [
                    'id' => $user->id,
                    'email' => $user->email,
                    'name' => $user->name,
                    'role' => session('ecopa.app_role'),
                ],
            ],
        ]);
    }

    public function selectEntity(Request $request): JsonResponse
    {
        $data = $request->validate([
            'entity_id' => ['required', 'string'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $entities = $this->loadUserEntities($user);
        $entity = $entities->firstWhere('id', $data['entity_id']);

        if (! $entity instanceof Entity) {
            return response()->json([
                'errors' => [[
                    'code' => 'entity_not_found',
                    'message' => 'Entitas tidak ditemukan atau tidak diizinkan untuk pengguna ini.',
                ]],
            ], 404);
        }

        return response()
            ->json(['data' => ['active_entity' => $this->serializeEntity($entity)]])
            ->withCookie($this->entityCookie($entity->id));
    }

    /**
     * Entities visible to this user. Mirrors Akunta's RBAC scoping so POSO
     * never exposes entities the user is not assigned to (or any entity if
     * they are tenant-wide / SSO-admin).
     *
     * @return EloquentCollection<int, Entity>
     */
    private function loadUserEntities(User $user): EloquentCollection
    {
        try {
            $tenants = $user->getTenants();

            return Entity::query()
                ->with('tenant')
                ->whereIn('id', $tenants->pluck('id'))
                ->orderBy('name')
                ->get();
        } catch (\Throwable) {
            return new EloquentCollection;
        }
    }

    /**
     * @param  EloquentCollection<int, Entity>  $entities
     */
    private function resolveActiveEntity(Request $request, EloquentCollection $entities): ?array
    {
        $preferredId = $request->cookie(self::ENTITY_COOKIE)
            ?? $request->header('X-Entity-Id')
            ?? $request->header('X-Akunta-Entity-Id');

        if (is_string($preferredId) && $preferredId !== '') {
            $entity = $entities->firstWhere('id', $preferredId);
            if ($entity instanceof Entity) {
                return $this->serializeEntity($entity);
            }
        }

        $first = $entities->first();

        return $first instanceof Entity ? $this->serializeEntity($first) : null;
    }

    /**
     * @param  EloquentCollection<int, Entity>  $entities
     * @return array<int, array<string, mixed>>
     */
    private function entityList(EloquentCollection $entities): array
    {
        return $entities
            ->map(fn (Entity $entity) => $this->serializeEntity($entity))
            ->values()
            ->all();
    }

    private function serializeEntity(Entity $entity): array
    {
        return [
            'id' => $entity->id,
            'name' => $entity->name,
            'legal_form' => $entity->legal_form,
            'relation_type' => $entity->relation_type,
            'tenant_id' => $entity->tenant_id,
            'tenant_name' => $entity->tenant?->name,
            'tenant_slug' => $entity->tenant?->slug,
        ];
    }

    private function entityCookie(string $entityId): Cookie
    {
        return Cookie::create(
            name: self::ENTITY_COOKIE,
            value: $entityId,
            expire: now()->addDays(30)->getTimestamp(),
            path: '/',
            domain: env('ECOSYSTEM_BASE_DOMAIN') ?: null,
            secure: (bool) config('session.secure', false),
            httpOnly: true,
            raw: false,
            sameSite: Cookie::SAMESITE_LAX,
        );
    }
}
