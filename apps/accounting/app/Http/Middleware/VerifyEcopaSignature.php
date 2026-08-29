<?php

namespace App\Http\Middleware;

use App\Services\EcopaIntegrationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies X-Ecopa-Signature header matches HMAC-SHA256 of raw request body
 * using the permanent webhook secret. Registration approval/rejection uses
 * the encrypted one-time registration secret as a bootstrap trust anchor.
 *
 * Rejects with 401 if signature missing or mismatched.
 */
class VerifyEcopaSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('ecopa_signature_valid', null);
        $payload = json_decode($request->getContent(), true);
        $event = is_array($payload) && is_string($payload['event'] ?? null)
            ? $payload['event']
            : null;
        $secret = app(EcopaIntegrationService::class)->signatureSecretForEvent($event);
        if (! $secret) {
            abort(503, 'Ecopa webhook verification secret not configured');
        }

        $sigHeader = $request->header('X-Ecopa-Signature', '');
        if (! str_starts_with($sigHeader, 'sha256=')) {
            $request->attributes->set('ecopa_signature_valid', false);
            abort(401, 'Missing or malformed X-Ecopa-Signature');
        }

        $providedHash = substr($sigHeader, 7);
        $computedHash = hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals($computedHash, $providedHash)) {
            $request->attributes->set('ecopa_signature_valid', false);
            abort(401, 'Invalid signature');
        }

        $request->attributes->set('ecopa_signature_valid', true);

        return $next($request);
    }
}
