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
 * Writes `akunta_entity` cookie at ECOSYSTEM_BASE_DOMAIN so sibling apps
 * (accounting/payroll/cash-mgmt) read + respect the same active entity.
 *
 * Read side lives in App\Models\User::getDefaultTenant — picks the cookie's
 * entity when present and accessible. The SvelteKit SPA also persists the
 * selection to localStorage and forwards it via X-Tenant-Slug header.
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
