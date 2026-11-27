<?php

declare(strict_types=1);

namespace App\Services;

use Akunta\Rbac\Models\User;
use Akunta\Rbac\Support\TenantContext;
use App\Models\ApiToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UserAccessRevoker
{
    public function disable(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $user->forceFill([
                'disabled_at' => $user->disabled_at ?? now(),
                'remember_token' => null,
            ])->save();

            // A disabled person loses access in every organisation. Writes belong to one tenant at a
            // time, so revoke inside each tenant that still holds a live assignment of theirs.
            $tenants = TenantContext::platformRead(fn () => DB::table('user_app_assignments')
                ->where('user_id', $user->id)
                ->whereNull('revoked_at')
                ->distinct()
                ->pluck('tenant_id'));
            foreach ($tenants as $tenant) {
                $revoke = fn () => $user->assignments()->whereNull('revoked_at')->update(['revoked_at' => now()]);
                $tenant === null ? $revoke() : TenantContext::run((string) $tenant, $revoke);
            }

            $this->revokeSessionsAndTokens($user);
        });
    }

    public function enable(User $user): void
    {
        $user->forceFill([
            'disabled_at' => null,
            'remember_token' => null,
        ])->save();
    }

    /**
     * @param  string|null  $tenantId  Revoke only the integration tokens of this tenant
     *                                 (an access change in one organisation must not
     *                                 revoke the same person's tokens in another).
     *                                 Null revokes all of the user's tokens.
     */
    public function revokeSessionsAndTokens(User $user, ?string $tenantId = null): void
    {
        // Revoking is a write, and writes belong to one tenant at a time: find which tenants hold
        // this person's live tokens (a read-only platform lookup), then revoke inside each.
        $tenants = TenantContext::platformRead(fn () => ApiToken::query()
            ->withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->whereNull('revoked_at')
            ->distinct()
            ->pluck('tenant_id'));
        foreach ($tenants as $tenant) {
            TenantContext::run((string) $tenant, fn () => ApiToken::query()
                ->where('user_id', $user->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]));
        }

        $this->endLoginSessions($user);
    }

    /**
     * Ends every local login of the user — database sessions, Sanctum personal
     * access tokens, and the remember-me token — so the next request needs a
     * fresh SSO login. Integration API tokens are left alone: their authority
     * comes from business permissions, which this does not change.
     *
     * Sessions kept outside the database (e.g. SESSION_DRIVER=redis) cannot be
     * listed per user; EndStalePlatformOwnerSession ends those on their next
     * request when the stored Ecopa claim no longer matches the session.
     */
    public function endLoginSessions(User $user): void
    {
        if ($user->remember_token !== null) {
            $user->forceFill(['remember_token' => null])->save();
        }

        if (Schema::hasTable('personal_access_tokens')) {
            DB::table('personal_access_tokens')
                ->where('tokenable_type', $user->getMorphClass())
                ->where('tokenable_id', $user->id)
                ->delete();
        }

        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }
    }
}
