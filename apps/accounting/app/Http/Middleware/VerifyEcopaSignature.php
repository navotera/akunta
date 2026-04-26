<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies X-Ecopa-Signature header matches HMAC-SHA256 of raw request body
 * using the shared webhook secret (env ECOPA_WEBHOOK_SECRET).
 *
 * Rejects with 401 if signature missing or mismatched.
 */
class VerifyEcopaSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('ecopa.webhook_secret');
        if (! $secret) {
            abort(503, 'Webhook secret not configured');
        }

        $sigHeader = $request->header('X-Ecopa-Signature', '');
        if (! str_starts_with($sigHeader, 'sha256=')) {
            abort(401, 'Missing or malformed X-Ecopa-Signature');
        }

        $providedHash = substr($sigHeader, 7);
        $computedHash = hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals($computedHash, $providedHash)) {
            abort(401, 'Invalid signature');
        }

        return $next($request);
    }
}
