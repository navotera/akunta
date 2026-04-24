<?php

namespace App\Http\Middleware;

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

        $token = ApiToken::findByPlain($plain);
        if ($token === null) {
            return response()->json(['error' => 'token_invalid'], 401);
        }

        if (! $token->isActive()) {
            $reason = $token->revoked_at !== null ? 'token_revoked' : 'token_expired';

            return response()->json(['error' => $reason], 401);
        }

        $token->touchLastUsed();

        $request->attributes->set('api_token', $token);

        if ($token->user_id !== null) {
            $user = $token->user;
            if ($user !== null) {
                Auth::setUser($user);
            }
        }

        return $next($request);
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
