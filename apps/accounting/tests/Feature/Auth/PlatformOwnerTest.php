<?php

declare(strict_types=1);

use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\UserAppAssignment;
use App\Models\EcopaConfigIntegration;
use App\Models\User;
use App\Support\PlatformOwner;

/**
 * Prompt stage 2a: one source for the Platform Owner status. Ecopa mode trusts
 * only the signed platform_admin claim; standalone mode uses the installation
 * super admin. Never while impersonating, disabled, or signed out.
 */
function poUser(?bool $stored = null): User
{
    $user = User::query()->create([
        'name' => 'User PO',
        'email' => 'po-helper-'.uniqid().'@example.test',
        'main_tier_user_id' => 'ecopa-'.uniqid(),
        'email_verified_at' => now(),
    ]);
    $user->forceFill(['ecopa_platform_admin' => $stored])->save();

    return $user;
}

function grantRole(User $user, string $code, ?string $entityId = null, ?string $roleTenantId = null, array $extra = []): UserAppAssignment
{
    $app = RbacApp::query()->firstOrCreate(['code' => 'accounting'], ['name' => 'Accounting', 'version' => '1.0', 'enabled' => true]);
    $role = Role::query()->firstOrCreate(
        ['tenant_id' => $roleTenantId, 'code' => $code],
        ['name' => $code, 'is_preset' => $roleTenantId === null],
    );

    return UserAppAssignment::query()->create(array_merge([
        'user_id' => $user->id,
        'app_id' => $app->id,
        'entity_id' => $entityId,
        'role_id' => $role->id,
        'assigned_at' => now(),
    ], $extra));
}

function ecopaModeOn(): void
{
    EcopaConfigIntegration::query()->create(['name' => 'integration_status', 'value' => 'on']);
}

describe('Ecopa mode', function () {
    beforeEach(fn () => ecopaModeOn());

    it('recognises the Platform Owner from the signed claim', function () {
        $user = poUser(true);
        $this->actingAs($user)->withSession(['ecopa.platform_admin' => true]);

        expect(PlatformOwner::ecopaMode())->toBeTrue()
            ->and(PlatformOwner::check())->toBeTrue();
    });

    it('is not a Platform Owner without the claim, or when the stored value disagrees', function (array $session, ?bool $stored) {
        $user = poUser($stored);
        $this->actingAs($user)->withSession($session);

        expect(PlatformOwner::check())->toBeFalse();
    })->with([
        'no claim' => [[], true],
        'claim false' => [['ecopa.platform_admin' => false], true],
        'claim not a boolean' => [['ecopa.platform_admin' => 'true'], true],
        'stored value revoked' => [['ecopa.platform_admin' => true], false],
        'stored value never reported' => [['ecopa.platform_admin' => true], null],
    ]);

    it('never derives the Platform Owner from app_role or the super_admin preset', function () {
        $user = poUser(false);
        grantRole($user, 'super_admin');
        $this->actingAs($user)->withSession(['ecopa.app_role' => 'admin', 'ecopa.platform_admin' => false]);

        expect(PlatformOwner::check())->toBeFalse();
    });

    it('drops Platform Owner rights while impersonating', function () {
        $owner = poUser(true);
        $member = poUser(null);
        $this->actingAs($member)->withSession([
            'ecopa.platform_admin' => true,
            'impersonator_id' => $owner->id,
        ]);

        expect(PlatformOwner::check())->toBeFalse()
            ->and(PlatformOwner::check($owner))->toBeFalse();
    });

    it('refuses a disabled user', function () {
        $user = poUser(true);
        $user->forceFill(['disabled_at' => now()])->save();
        $this->actingAs($user)->withSession(['ecopa.platform_admin' => true]);

        expect(PlatformOwner::check())->toBeFalse();
    });
});

describe('standalone mode', function () {
    it('treats the installation super admin as the Platform Owner', function () {
        $user = poUser();
        grantRole($user, 'super_admin');
        $this->actingAs($user);

        expect(PlatformOwner::ecopaMode())->toBeFalse()
            ->and(PlatformOwner::check())->toBeTrue();
    });

    it('does not treat other admins as the Platform Owner', function (Closure $grant) {
        $user = poUser();
        $grant($user);
        $this->actingAs($user);

        expect(PlatformOwner::check())->toBeFalse();
    })->with([
        'workspace super admin only' => [function (User $user) {
            $tenant = Tenant::query()->create(['name' => 'T', 'slug' => 't-'.uniqid()]);
            $entity = Entity::query()->create(['tenant_id' => $tenant->id, 'name' => 'E']);
            grantRole($user, 'super_admin', $entity->id);
        }],
        'tenant custom role named super_admin' => [function (User $user) {
            $tenant = Tenant::query()->create(['name' => 'T', 'slug' => 't-'.uniqid()]);
            grantRole($user, 'super_admin', null, $tenant->id);
        }],
        'revoked super admin' => [fn (User $user) => grantRole($user, 'super_admin', extra: ['revoked_at' => now()])],
        'expired super admin' => [fn (User $user) => grantRole($user, 'super_admin', extra: ['valid_until' => now()->subDay()])],
        'app-wide admin preset' => [fn (User $user) => grantRole($user, 'admin')],
        'no assignment' => [fn (User $user) => null],
    ]);

    it('ignores a leftover Ecopa claim in standalone mode', function () {
        $user = poUser(true);
        $this->actingAs($user)->withSession(['ecopa.platform_admin' => true]);

        expect(PlatformOwner::check())->toBeFalse();
    });

    it('drops Platform Owner rights while impersonating', function () {
        $user = poUser();
        grantRole($user, 'super_admin');
        $this->actingAs($user)->withSession(['impersonator_id' => poUser()->id]);

        expect(PlatformOwner::check())->toBeFalse();
    });
});

it('is false for guests', function () {
    expect(PlatformOwner::check())->toBeFalse();
});
