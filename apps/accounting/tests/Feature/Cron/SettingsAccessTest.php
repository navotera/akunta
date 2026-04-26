<?php

declare(strict_types=1);

use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Permission;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\UserAppAssignment;
use App\Filament\Clusters\Settings;
use App\Filament\Clusters\Settings\Pages\Cron;
use App\Filament\Pages\Onboarding\CoaTemplate;
use App\Models\User;
use Database\Seeders\PresetRolesSeeder;
use Database\Seeders\SettingsPermissionsSeeder;

function seedAccountingApp(): RbacApp
{
    return RbacApp::firstOrCreate(
        ['code' => 'accounting'],
        ['name' => 'Accounting', 'version' => '0.1', 'enabled' => true],
    );
}

function makeUserWithRole(string $roleCode, ?string $appId = null): User
{
    $user = User::create([
        'name'              => 'Test '.$roleCode,
        'email'             => $roleCode.'@example.test',
        'password_hash'     => bcrypt('secret'),
        'email_verified_at' => now(),
    ]);

    $role = Role::whereNull('tenant_id')->where('code', $roleCode)->firstOrFail();

    UserAppAssignment::create([
        'user_id' => $user->id,
        'app_id'  => $appId ?? seedAccountingApp()->id,
        'role_id' => $role->id,
        'assigned_at' => now(),
    ]);

    return $user;
}

beforeEach(function () {
    (new PresetRolesSeeder)->run();
    seedAccountingApp();
    (new SettingsPermissionsSeeder)->run();
});

it('seeds the two settings permissions under the accounting app', function () {
    expect(Permission::where('code', 'settings.coa_template.manage')->exists())->toBeTrue()
        ->and(Permission::where('code', 'settings.cron.manage')->exists())->toBeTrue();
});

it('grants access to super_admin on both pages without explicit permission grant', function () {
    $admin = makeUserWithRole('super_admin');

    $this->actingAs($admin);

    expect(CoaTemplate::canAccess())->toBeTrue()
        ->and(Cron::canAccess())->toBeTrue()
        ->and(Settings::canAccess())->toBeTrue();
});

it('denies access to a role with no settings permissions assigned', function () {
    $accountant = makeUserWithRole('accountant');

    $this->actingAs($accountant);

    expect(CoaTemplate::canAccess())->toBeFalse()
        ->and(Cron::canAccess())->toBeFalse()
        ->and(Settings::canAccess())->toBeFalse();
});

it('grants access to a non-admin role once the permission is attached to it', function () {
    $accountant = makeUserWithRole('accountant');
    $role = Role::whereNull('tenant_id')->where('code', 'accountant')->firstOrFail();
    $perm = Permission::where('code', 'settings.coa_template.manage')->firstOrFail();
    $role->permissions()->syncWithoutDetaching([$perm->id]);

    $this->actingAs($accountant);

    expect(CoaTemplate::canAccess())->toBeTrue()
        ->and(Cron::canAccess())->toBeFalse()
        ->and(Settings::canAccess())->toBeTrue();
});

it('denies access entirely when no user is authenticated', function () {
    expect(CoaTemplate::canAccess())->toBeFalse()
        ->and(Cron::canAccess())->toBeFalse()
        ->and(Settings::canAccess())->toBeFalse();
});

it('grants access to a user logged in via Ecopa SSO with admin role on the app', function () {
    $user = User::create([
        'name'              => 'Ecopa SSO Admin',
        'email'             => 'ecopa-admin@example.test',
        'password_hash'     => bcrypt('secret'),
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user);
    session()->put('ecopa.app_role', 'admin');

    expect(CoaTemplate::canAccess())->toBeTrue()
        ->and(Cron::canAccess())->toBeTrue()
        ->and(Settings::canAccess())->toBeTrue();
});
