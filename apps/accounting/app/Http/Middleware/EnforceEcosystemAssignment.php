<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\UserAppAssignment;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cross-app RBAC ladder (per docs/cross-app-rbac.md §2):
 *
 *   1. Authenticated? Sanctum middleware already enforced upstream.
 *   2. Has a non-revoked UserAppAssignment for (user, app=accounting, entity)?
 *   3. ecopa_role = admin → allow (superuser bypass).
 *   4. ecopa_role = operator → allow entry; downstream code uses local
 *      role_id (finance/tax/auditor) for fine-grained gates.
 *
 * Tenant is resolved from `X-Tenant-Slug` header (Spa pattern). The
 * accounting app code is `accounting`.
 */
class EnforceEcosystemAssignment
{
    public const APP_CODE = 'accounting';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        $entity = $this->resolveEntity($request);
        if (! $entity) {
            return response()->json(['error' => 'tenant_not_resolvable'], 422);
        }

        $app = RbacApp::query()->where('code', self::APP_CODE)->first();
        if (! $app) {
            return response()->json(['error' => 'app_not_provisioned'], 503);
        }

        $assignment = UserAppAssignment::query()
            ->where('user_id', $user->id)
            ->where('app_id', $app->id)
            ->where('entity_id', $entity->id)
            ->whereNull('revoked_at')
            ->first();

        if (! $assignment) {
            return response()->json([
                'error' => 'not_assigned',
                'message' => 'User belum di-assign ke entitas ini di Ecopa.',
                'entity_id' => $entity->id,
                'app_code' => self::APP_CODE,
            ], 403);
        }

        // Stash on request so controllers can read without re-querying.
        $request->attributes->set('ecopa_role', $assignment->ecopa_role);
        $request->attributes->set('local_role_id', $assignment->role_id);
        $request->attributes->set('resolved_entity', $entity);

        return $next($request);
    }

    private function resolveEntity(Request $request): ?Entity
    {
        $slug = $request->header('X-Tenant-Slug');
        if (! $slug) {
            // Fall back to user's default tenant.
            return $request->user()?->getDefaultTenant();
        }

        return Entity::where('slug', $slug)->first()
            ?? Entity::find($slug);
    }
}
