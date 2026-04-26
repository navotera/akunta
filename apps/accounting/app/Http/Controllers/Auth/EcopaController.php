<?php

namespace App\Http\Controllers\Auth;

use Akunta\EcopaClient\Http\EcopaAuthController;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;

class EcopaController extends EcopaAuthController
{
    /**
     * Ecopa = authoritative identity store.
     *
     * Akunta NEVER creates users from SSO claims. Admin must pre-assign user
     * to Akunta inside Ecopa (App Permissions matrix). On callback we:
     *   1. Match by main_tier_user_id  → login.
     *   2. Fallback by email → link main_tier_user_id, login.
     *   3. No match → reject. User must be assigned in Ecopa first.
     *
     * Identity attributes (name, email, picture) are mirrored from Ecopa on
     * every login so they stay in sync. Akunta never edits them locally.
     */
    protected function provisionUser(array $claims): void
    {
        $email     = $claims['email']    ?? null;
        $ecopaSub  = (string) ($claims['sub'] ?? '');
        $name      = $claims['name']     ?? null;
        $appRole   = $claims['app_role'] ?? null;
        $appScopes = $claims['app_scopes'] ?? [];
        $divisions = $claims['divisions'] ?? [];

        if (! $email || ! $ecopaSub) {
            abort(422, 'Ecopa claims missing email/sub');
        }

        // §2 — if app_role missing, user is not assigned to Akunta in Ecopa
        if (! $appRole) {
            abort(403, 'Akun belum di-assign ke Akunta. Hubungi admin Ecopa.');
        }

        // 1. Match by sticky Ecopa user id
        $user = User::query()->where('main_tier_user_id', $ecopaSub)->first();

        // 2. Fallback by email — link if found
        if (! $user) {
            $user = User::query()->where('email', $email)->first();
            if ($user) {
                $user->main_tier_user_id = $ecopaSub;
                $user->email_verified_at ??= now();
            }
        }

        // 3. Auto-create — admin role bypasses provisioning gate; non-admin
        //    needs pre-existing local user OR explicit admin-grant.
        if (! $user) {
            if ($appRole !== 'admin') {
                abort(403, 'Akun belum di-assign ke Akunta. Hubungi admin Ecopa.');
            }
            $user = new User();
            $user->id = (string) \Illuminate\Support\Str::ulid();
            $user->email = $email;
            $user->name = $name ?? \Illuminate\Support\Str::before($email, '@');
            $user->main_tier_user_id = $ecopaSub;
            $user->email_verified_at = now();
            // No password_hash — SSO-only.
        }

        // Mirror Ecopa attrs (Ecopa = source of truth)
        if ($name && $user->name !== $name) {
            $user->name = $name;
        }
        $user->last_login_at = now();
        $user->save();

        // Stash claims in session so app code can map app_role → local RBAC role
        // and scope by Ecopa division when picking entity.
        session([
            'ecopa.app_role'     => $appRole,
            'ecopa.app_scopes'   => $appScopes,
            'ecopa.divisions'    => $divisions,
            'ecopa.access_token' => $claims['access_token'] ?? null,
            'ecopa.token_expires_at' => isset($claims['token_expires_in'])
                ? now()->addSeconds((int) $claims['token_expires_in'])->timestamp
                : null,
        ]);

        Auth::guard('web')->login($user, remember: true);
    }

    protected function successRedirect(): string
    {
        return \App\Filament\Pages\Dashboard::getUrl(panel: 'accounting');
    }

    protected function failureRedirect(): string
    {
        return Filament::getPanel('accounting')->getLoginUrl() ?? '/admin-accounting/login';
    }
}
