<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * "Desktop OS" auth model:
 *
 *   Ecopa is the desktop. Akunta is a module living inside it.
 *   If a guest hits any Akunta panel route while Ecopa is configured,
 *   we silently bounce to Ecopa's SSO authorize endpoint. Ecopa decides:
 *     - User has session + permission → returns auth code → Akunta logs in.
 *     - User no session → Ecopa shows login → repeats above.
 *     - User lacks permission → Ecopa shows access-denied (handled there).
 *
 * If ECOPA_CLIENT_ID is not configured, this middleware is a no-op so the
 * panel can run standalone (legacy / dev mode).
 */
class RedirectGuestToEcopa
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('ecopa.client_id')) {
            return $next($request);
        }

        if (Auth::guard('web')->check()) {
            return $next($request);
        }

        // Avoid redirect loops on the SSO callback route itself
        if ($request->is('auth/ecopa/*')) {
            return $next($request);
        }

        // Stash intended URL so post-login lands user where they meant to go
        if ($request->method() === 'GET' && ! $request->expectsJson()) {
            session()->put('url.intended', $request->fullUrl());
        }

        return redirect()->route('ecopa.login');
    }
}
