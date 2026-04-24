<?php

namespace App\Http\Middleware;

use Akunta\Rbac\Models\Entity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cross-app entity sync (step 13, spec §8.3). See accounting app's copy for
 * full docblock — kept as a simple per-app duplicate so apps stay independent.
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
