<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Akunta\Rbac\Support\TenantContext;
use App\Support\Tenancy\RequestTenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the TenantContext for an authenticated session request, from the
 * identity (RequestTenant) — never from a header. Must run after the auth
 * middleware. A session whose tenant cannot be determined is refused: serving
 * it unscoped would be a cross-organisation leak.
 *
 * The context is always cleared again, so a long-lived worker or the next test
 * request never inherits it.
 */
class EstablishTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null) {
            return $next($request);
        }

        $tenantId = RequestTenant::forSession((string) $user->getAuthIdentifier());
        if ($tenantId === null && DB::table('tenants')->doesntExist()) {
            // A fresh installation has no tenant yet (installation wizard): nothing to leak.
            return $next($request);
        }
        if ($tenantId === null) {
            return response()->json([
                'message' => RequestTenant::sessionOrganizationId() !== null
                    ? 'Workspace organisasi Anda belum disiapkan. Hubungi Organization Owner.'
                    : 'Tenant tidak dapat ditentukan untuk sesi ini.',
            ], 403);
        }

        TenantContext::set($tenantId);

        try {
            return $next($request);
        } finally {
            TenantContext::clear();
        }
    }
}
