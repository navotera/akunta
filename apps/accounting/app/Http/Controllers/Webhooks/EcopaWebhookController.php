<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Receives Ecopa lifecycle events.
 *
 * Endpoint: POST /webhooks/ecopa
 * Auth:     X-Ecopa-Signature header (handled by VerifyEcopaSignature middleware)
 * Body:     { event, event_id, occurred_at, subject }
 */
class EcopaWebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        $event   = $request->input('event');
        $subject = $request->input('subject', []);
        $eventId = $request->input('event_id');

        Log::info('Ecopa webhook received', compact('event', 'eventId'));

        match (true) {
            $event === 'user.disabled'        => $this->onUserDisabled($subject),
            $event === 'user.enabled'         => $this->onUserEnabled($subject),
            $event === 'user.updated'         => $this->onUserUpdated($subject),
            $event === 'user.deleted'         => $this->onUserDeleted($subject),
            str_starts_with((string) $event, 'app_permission.') => $this->onAppPermission($event, $subject),
            default => null, // unknown event — accept to avoid retry storms
        };

        return response()->json(['status' => 'received', 'event' => $event], 200);
    }

    protected function onUserDisabled(array $subject): void
    {
        $user = $this->findUser($subject);
        if (! $user) {
            return;
        }
        // Mark locally — we don't have a "disabled" column yet, so logout sessions
        // by clearing remember_token + revoking RBAC assignments via your own logic.
        // Minimum viable: invalidate remember_token to force re-login (which will fail SSO).
        $user->forceFill(['remember_token' => null])->save();
        Log::info('User disabled via Ecopa webhook', ['user_id' => $user->id]);
    }

    protected function onUserEnabled(array $subject): void
    {
        // No-op: re-enable handled via fresh SSO login
    }

    protected function onUserUpdated(array $subject): void
    {
        $user = $this->findUser($subject);
        if (! $user) {
            return;
        }
        $changes = [];
        if (! empty($subject['name']) && $user->name !== $subject['name']) {
            $changes['name'] = $subject['name'];
        }
        if (! empty($subject['email']) && $user->email !== $subject['email']) {
            $changes['email'] = $subject['email'];
        }
        if ($changes) {
            $user->fill($changes)->save();
        }
    }

    protected function onUserDeleted(array $subject): void
    {
        $user = $this->findUser($subject);
        if (! $user) {
            return;
        }
        // Don't hard-delete (audit trail). Disable instead.
        $user->forceFill([
            'remember_token' => null,
            // 'disabled_at' => now(), // add if/when column exists
        ])->save();
    }

    protected function onAppPermission(string $event, array $subject): void
    {
        $userIdEcopa = (string) ($subject['user_id'] ?? '');
        if (! $userIdEcopa) {
            return;
        }

        $user = User::query()->where('main_tier_user_id', $userIdEcopa)->first();
        if (! $user) {
            return; // user not yet provisioned locally
        }

        // For revoke/role-changed events: clear remember_token so user must re-auth
        // and pick up new claims on next login. Avoids stale role within active session.
        if (in_array($event, ['app_permission.revoked', 'app_permission.role_changed'], true)) {
            $user->forceFill(['remember_token' => null])->save();
        }

        Log::info('Ecopa app_permission event applied', [
            'event'   => $event,
            'user_id' => $user->id,
        ]);
    }

    protected function findUser(array $subject): ?User
    {
        $sub = (string) ($subject['id'] ?? '');
        if ($sub) {
            $u = User::query()->where('main_tier_user_id', $sub)->first();
            if ($u) {
                return $u;
            }
        }
        $email = $subject['email'] ?? null;
        if ($email) {
            return User::query()->where('email', $email)->first();
        }

        return null;
    }
}
