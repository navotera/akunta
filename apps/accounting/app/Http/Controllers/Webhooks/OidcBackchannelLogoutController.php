<?php

namespace App\Http\Controllers\Webhooks;

use Akunta\EcopaClient\EcopaClient;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * OIDC Back-channel Logout receiver.
 *
 * Spec: https://openid.net/specs/openid-connect-backchannel-1_0.html
 *
 * Flow:
 *   1. Ecopa POSTs `logout_token` (RS256 JWT) here.
 *   2. We verify signature via Ecopa JWKS.
 *   3. Match claim `sub` → local user.
 *   4. Wipe all sessions for that user (database driver: delete WHERE user_id).
 *   5. Clear remember_token.
 */
class OidcBackchannelLogoutController extends Controller
{
    public function handle(Request $request, EcopaClient $ecopa): JsonResponse
    {
        $logoutToken = (string) $request->input('logout_token', '');
        if (! $logoutToken) {
            return response()->json(['error' => 'invalid_request', 'error_description' => 'Missing logout_token'], 400);
        }

        try {
            // verifyIdToken accepts any RS256 JWT signed by Ecopa. iss check matches.
            $claims = $ecopa->verifyIdToken($logoutToken);
        } catch (\Throwable $e) {
            Log::warning('Backchannel logout token verify failed: ' . $e->getMessage());

            return response()->json(['error' => 'invalid_token'], 401);
        }

        // Spec: must contain the back-channel logout event claim
        $events = $claims['events'] ?? null;
        if (! is_array($events) || ! array_key_exists('http://schemas.openid.net/event/backchannel-logout', $events)) {
            return response()->json(['error' => 'invalid_request', 'error_description' => 'Missing events claim'], 400);
        }

        $sub = $claims['sub'] ?? null;
        if (! $sub) {
            return response()->json(['error' => 'invalid_request', 'error_description' => 'Missing sub'], 400);
        }

        $user = User::query()->where('main_tier_user_id', $sub)->first();
        if (! $user) {
            // Already gone — accept silently (idempotent)
            return response()->json(['status' => 'no_local_user'], 200);
        }

        // Wipe all DB-stored sessions for this user (Laravel default sessions table)
        $purged = 0;
        try {
            if (config('session.driver') === 'database') {
                $purged = DB::table(config('session.table', 'sessions'))
                    ->where('user_id', $user->id)
                    ->delete();
            }
        } catch (\Throwable $e) {
            Log::warning('Session purge failed during back-channel logout: ' . $e->getMessage());
        }

        // Always clear remember_token + null out current session signature
        $user->forceFill(['remember_token' => null])->save();

        Log::info('Back-channel logout applied', [
            'user_id' => $user->id,
            'sub'     => $sub,
            'purged'  => $purged,
        ]);

        return response()->json(['status' => 'ok', 'sessions_purged' => $purged], 200);
    }
}
