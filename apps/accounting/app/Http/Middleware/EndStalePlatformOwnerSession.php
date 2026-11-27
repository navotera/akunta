<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends a web session whose Ecopa `platform_admin` claim is no longer current.
 *
 * The SSO login stores the signed claim both in the session
 * (`ecopa.platform_admin`) and on the user (`users.ecopa_platform_admin`).
 * When Ecopa grants or removes Platform Owner status, the user.* webhook
 * updates the stored value and deletes database sessions. Sessions kept in
 * another store (production uses SESSION_DRIVER=redis) cannot be found per
 * user, so this check ends them on their next request instead.
 *
 * The claim belongs to the account that signed in through Ecopa. While an
 * admin impersonates someone, that is the impersonator, not Auth::user().
 * Sessions without the claim (local login, sessions created before the claim
 * was stored) are left alone; they never carry Platform Owner rights.
 */
class EndStalePlatformOwnerSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || ! $request->session()->has('ecopa.platform_admin')) {
            return $next($request);
        }

        $user = Auth::guard('web')->user();
        if (! $user instanceof User) {
            return $next($request);
        }

        $session = $request->session();
        $impersonatorId = $session->get('impersonator_id');
        $signedInUser = is_string($impersonatorId) && $impersonatorId !== ''
            ? User::query()->find($impersonatorId)
            : $user;

        $claimed = $session->get('ecopa.platform_admin') === true;
        $current = $signedInUser?->ecopa_platform_admin === true;

        if ($signedInUser !== null && $claimed === $current) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        $session->invalidate();
        $session->regenerateToken();

        $message = 'Status Platform Owner Anda di Ecopa berubah. Silakan masuk kembali.';

        return $request->expectsJson()
            ? response()->json(['error' => 'session_expired', 'message' => $message], 401)
            : redirect()->guest(route('login'));
    }
}
