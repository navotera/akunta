<?php

declare(strict_types=1);

use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\UserAppAssignment;
use App\Models\EcopaConfigIntegration;
use App\Models\User;

it('lets the Platform Owner create the initial entity and local admin assignment', function () {
    $admin = User::query()->create([
        'name' => 'Ecopa Installer',
        'email' => 'installer@example.test',
        'main_tier_user_id' => 'ecopa-installer',
        'email_verified_at' => now(),
    ]);

    // An Organization Owner (Ecopa admin without platform_admin) may not install.
    $this->actingAs($admin)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->postJson('/api/v1/spa/installation-onboarding/entity', ['name' => 'PT Bukan Installer'])
        ->assertForbidden();

    $this->actingAs($admin)
        ->withSession(platformOwnerSession($admin))
        ->postJson('/api/v1/spa/installation-onboarding/entity', [
            'name' => 'PT Instalasi Baru',
            'legal_form' => 'PT',
        ])->assertCreated()
        ->assertJsonPath('data.name', 'PT Instalasi Baru');

    $entity = Entity::query()->where('name', 'PT Instalasi Baru')->firstOrFail();
    $assignment = UserAppAssignment::query()
        ->where('user_id', $admin->id)
        ->whereNull('entity_id')
        ->with('role')
        ->firstOrFail();

    expect($entity->tenant)->not->toBeNull()
        ->and($assignment->role?->code)->toBe('super_admin')
        ->and($assignment->ecopa_role)->toBe('admin')
        ->and(Entity::query()->where('name', 'PT Bukan Installer')->exists())->toBeFalse();
    $this->assertDatabaseHas('platform_audit_log', [
        'action' => 'installation.entity_created',
        'actor_kind' => 'platform_owner',
        'subject_id' => $entity->id,
    ]);
});

it('sends only the Platform Owner to the installation wizard after SSO', function (array $session, string $target) {
    config()->set('app.spa_url', 'http://spa.test');
    $admin = User::query()->create([
        'name' => 'Ecopa Admin',
        'email' => 'wizard-'.uniqid().'@example.test',
        'main_tier_user_id' => 'ecopa-wizard-'.uniqid(),
        'email_verified_at' => now(),
    ]);
    $session = $session === [] ? platformOwnerSession($admin) : $session;

    $this->actingAs($admin)
        ->withSession($session)
        ->get('/sso/login')
        ->assertRedirect('http://spa.test'.$target);
})->with([
    'Platform Owner' => [[], '/onboarding'],
    'Organization Owner' => [['ecopa.app_role' => 'admin', 'ecopa.platform_admin' => false], '/dashboard'],
]);

it('rejects initial entity creation by a regular Ecopa user', function () {
    $user = User::query()->create([
        'name' => 'Regular User',
        'email' => 'regular-installer@example.test',
        'main_tier_user_id' => 'ecopa-regular',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->withSession(['ecopa.app_role' => 'user'])
        ->postJson('/api/v1/spa/installation-onboarding/entity', ['name' => 'PT Tidak Sah'])
        ->assertForbidden();
});

it('redirects later Ecopa admins to the dashboard after installation onboarding', function () {
    config()->set('app.spa_url', 'http://spa.test');
    EcopaConfigIntegration::query()->create([
        'name' => 'installation_onboarding_completed_at',
        'value' => '2026-08-31T00:00:00+00:00',
    ]);
    $admin = User::query()->create([
        'name' => 'Second Ecopa Admin',
        'email' => 'second-admin@example.test',
        'main_tier_user_id' => 'ecopa-second-admin',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->get('/sso/login')
        ->assertRedirect('http://spa.test/dashboard');
});
