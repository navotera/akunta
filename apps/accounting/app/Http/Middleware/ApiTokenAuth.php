<?php

namespace App\Http\Middleware;

use Akunta\Rbac\Support\TenantContext;
use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class ApiTokenAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = $this->extractToken($request);
        if ($plain === null) {
            return response()->json(['error' => 'token_missing'], 401);
        }

        // The token decides the tenant, so it is looked up before any context exists: an explicit,
        // read-only cross-tenant read of one row by its hash.
        $token = TenantContext::platformRead(fn () => ApiToken::findByPlain($plain));
        if ($token === null) {
            return response()->json(['error' => 'token_invalid'], 401);
        }

        if (! $token->isActive()) {
            $reason = $token->revoked_at !== null ? 'token_revoked' : 'token_expired';

            return response()->json(['error' => $reason], 401);
        }

        $request->attributes->set('api_token', $token);

        // The token's tenant is the organisation this call works for; the entity it names in the
        // payload must live inside it (Entity is tenant-scoped). Everything that writes — including
        // the token's own bookkeeping — happens inside that context.
        TenantContext::set($token->getAttribute('tenant_id'));

        try {
            $token->touchLastUsed();

            if ($token->user_id !== null) {
                $user = $token->user;
                if ($user !== null) {
                    if ($user->disabled_at !== null) {
                        $token->forceFill(['revoked_at' => now()])->save();

                        return response()->json(['error' => 'account_disabled'], 401);
                    }
                    Auth::setUser($user);
                }
            }

            return $next($request);
        } finally {
            TenantContext::clear();
        }
    }

    private function extractToken(Request $request): ?string
    {
        $header = $request->headers->get('Authorization', '');
        if (! is_string($header) || $header === '') {
            return null;
        }

        if (! preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            return null;
        }

        $token = trim($matches[1]);

        return $token === '' ? null : $token;
    }
}
