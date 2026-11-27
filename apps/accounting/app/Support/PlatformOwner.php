<?php

declare(strict_types=1);

namespace App\Support;

use Akunta\Rbac\Models\User as RbacUser;
use Akunta\Rbac\Models\UserAppAssignment;
use App\Services\EcopaIntegrationService;
use Illuminate\Support\Facades\Auth;

/**
 * Single source of the Platform Owner (PO) status — decisions.md "Locked
 * 2026-11-19 — Platform Owner, Organization Owner, dan Member di Akunta".
 * Every PO check (middleware, controllers, SPA bootstrap) goes through here.
 *
 * Ecopa mode (integration on): PO only when the signed SSO claim stored in
 * session('ecopa.platform_admin') is true AND the value stored on the user
 * still agrees. app_role, the super_admin preset, and role names never make
 * a PO, and a session without the claim is not a PO.
 *
 * Standalone mode (integration not on): the installation super admin is the
 * PO — the user with an active application-wide (entity_id NULL) preset
 * `super_admin` assignment for Accounting, as granted by installation
 * onboarding, SuperAdminSeeder, or `akunta:set-superadmin`.
 *
 * PO rights never apply while impersonating, to disabled users, or to guests.
 * They are not permissions: they are not in the Permission Management catalog
 * and cannot be given to a custom role.
 */
final class PlatformOwner
{
    public static function check(?RbacUser $user = null): bool
    {
        $user ??= Auth::guard('web')->user();
        if (! $user instanceof RbacUser || $user->disabled_at !== null) {
            return false;
        }

        $session = session();
        if ($session->has('impersonator_id')) {
            return false;
        }

        if (self::ecopaMode()) {
            return $session->get('ecopa.platform_admin') === true
                && $user->ecopa_platform_admin === true;
        }

        return self::isInstallationSuperAdmin($user);
    }

    public static function ecopaMode(): bool
    {
        return app(EcopaIntegrationService::class)->status()['integration_status']
            === EcopaIntegrationService::STATUS_ON;
    }

    private static function isInstallationSuperAdmin(RbacUser $user): bool
    {
        return UserAppAssignment::query()
            ->where('user_id', $user->id)
            ->whereNull('entity_id')
            ->whereNull('revoked_at')
            ->where(fn ($query) => $query->whereNull('valid_from')->orWhere('valid_from', '<=', now()))
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhere('valid_until', '>', now()))
            ->whereHas('app', fn ($query) => $query->where('code', 'accounting'))
            ->whereHas('role', fn ($query) => $query->whereNull('tenant_id')->where('code', 'super_admin'))
            ->exists();
    }
}
