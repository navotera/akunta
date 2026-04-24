<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireTokenPermissions
{
    public function handle(Request $request, Closure $next, string ...$required): Response
    {
        $token = $request->attributes->get('api_token');

        if (! $token instanceof ApiToken) {
            return response()->json(['error' => 'token_missing'], 401);
        }

        if (! $token->hasAllPermissions($required)) {
            return response()->json([
                'error' => 'insufficient_permissions',
                'required' => $required,
                'granted' => $token->permissions,
            ], 403);
        }

        return $next($request);
    }
}
