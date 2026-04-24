<?php

namespace App\Http\Middleware;

use Akunta\Rbac\Models\Entity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cross-app entity sync (step 13, spec §8.3).
 *
 * Every request that visits a tenant-scoped Filament route (URL carries {tenant})
 * writes `akunta_entity` cookie pointing at the visited entity id. Cookie domain
 * is ECOSYSTEM_BASE_DOMAIN so sibling apps (accounting/payroll/cash-mgmt) all
 * read + respect the same value.
 *
 * Read side lives in App\Models\User::getDefaultTenant — when Filament resolves
 * "/admin-{app}" without an explicit tenant, it reads the cookie and picks that
 * entity if the user still has access.
 *
 * Cookie shape:
 *   - name: akunta_entity
 *   - value: Entity ULID (26 chars)
 *   - lifetime: 30 days (rolling on every write)
 *   - httpOnly: true (JS can't read)
 *   - sameSite: lax (survives cross-app navigation)
 *   - secure: true in prod
 *   - domain: ECOSYSTEM_BASE_DOMAIN (e.g. .akunta.local); null = same-origin only
 */
class SharedEntitySelector
{
    public const COOKIE_NAME = 'akunta_entity';

    public const COOKIE_LIFETIME_MINUTES = 60 * 24 * 30;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $entityId = $this->extractTenantId($request);
        if ($entityId === null) {
            return $response;
        }

        if ($request->cookie(self::COOKIE_NAME) === $entityId) {
            return $response;
        }

        $response->headers->setCookie($this->makeCookie($entityId));

        return $response;
    }

    private function extractTenantId(Request $request): ?string
    {
        $route = $request->route();
        if ($route === null) {
            return null;
        }

        $tenant = $route->parameter('tenant');
        if ($tenant === null) {
            return null;
        }

        if ($tenant instanceof Entity) {
            return $tenant->id;
        }

        if (is_string($tenant) && $tenant !== '') {
            return $tenant;
        }

        return null;
    }

    private function makeCookie(string $entityId): Cookie
    {
        $domain = config('tenancy.ecosystem_base_domain');

        return Cookie::create(
            name: self::COOKIE_NAME,
            value: $entityId,
            expire: now()->addMinutes(self::COOKIE_LIFETIME_MINUTES)->getTimestamp(),
            path: '/',
            domain: is_string($domain) && $domain !== '' ? $domain : null,
            secure: (bool) config('session.secure', false),
            httpOnly: true,
            raw: false,
            sameSite: Cookie::SAMESITE_LAX,
        );
    }
}
