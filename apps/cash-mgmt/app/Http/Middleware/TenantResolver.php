<?php

namespace App\Http\Middleware;

use Akunta\Rbac\Models\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TenantResolver
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isExempt($request)) {
            return $next($request);
        }

        $slug = $this->resolveSlug($request);

        if ($slug === null) {
            throw new HttpException(400, 'Unable to resolve tenant context.');
        }

        $tenant = Tenant::on(config('tenancy.control_connection'))
            ->where('slug', $slug)
            ->first();

        if ($tenant === null) {
            throw new HttpException(404, "Tenant [{$slug}] not found.");
        }

        $this->bindTenantConnection($tenant);

        app()->instance(Tenant::class, $tenant);
        app()->instance('tenant', $tenant);

        return $next($request);
    }

    protected function resolveSlug(Request $request): ?string
    {
        if (($header = config('tenancy.header')) && $request->hasHeader($header)) {
            $value = trim((string) $request->header($header));
            if ($value !== '') {
                return $value;
            }
        }

        if (config('tenancy.subdomain.enabled')) {
            $base = config('tenancy.subdomain.base_domain');
            $host = $request->getHost();

            if (str_ends_with($host, '.'.$base)) {
                $candidate = substr($host, 0, -1 * (strlen($base) + 1));
                if ($candidate !== '' && ! in_array($candidate, config('tenancy.subdomain.reserved'), true)) {
                    return $candidate;
                }
            }
        }

        $claim = config('tenancy.jwt_claim');
        if ($claim && $request->attributes->has("jwt.{$claim}")) {
            return (string) $request->attributes->get("jwt.{$claim}");
        }

        return null;
    }

    protected function bindTenantConnection(Tenant $tenant): void
    {
        $connection = config('tenancy.tenant_connection');
        $database = config('tenancy.db_prefix').$tenant->id;

        Config::set("database.connections.{$connection}.database", $database);
        DB::purge($connection);
        DB::setDefaultConnection($connection);
    }

    protected function isExempt(Request $request): bool
    {
        $path = '/'.ltrim($request->path(), '/');
        foreach ((array) config('tenancy.exempt_paths', []) as $pattern) {
            if ($request->is(ltrim($pattern, '/'))) {
                return true;
            }
            if ($path === $pattern) {
                return true;
            }
        }

        return false;
    }
}
