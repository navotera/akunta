<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\PlatformOwner;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Installation-level endpoints (scheduler, Ecopa integration, installation
 * wizard, native Fake Data dataset, workspace purge) are for the Platform
 * Owner only. Organization Owners, custom roles with every permission, and
 * impersonation sessions are refused even when the menu is hidden.
 */
class EnsurePlatformOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(
            PlatformOwner::check($request->user()),
            403,
            'Hanya Platform Owner yang dapat mengakses pengaturan tingkat instalasi.',
        );

        return $next($request);
    }
}
