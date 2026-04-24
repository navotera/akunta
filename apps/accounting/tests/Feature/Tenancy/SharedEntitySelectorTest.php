<?php

declare(strict_types=1);

use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\UserAppAssignment;
use App\Http\Middleware\SharedEntitySelector;
use App\Http\Middleware\TenantResolver;
use App\Models\User;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    // Global TenantResolver middleware would reject test URLs (no slug/subdomain/JWT);
    // disable for these unit-ish tests so we can exercise SharedEntitySelector in isolation.
    $this->withoutMiddleware(TenantResolver::class);

    $tenant = Tenant::create(['name' => 'T', 'slug' => 'test-'.uniqid()]);
    $this->entityA = Entity::create(['tenant_id' => $tenant->id, 'name' => 'A']);
    $this->entityB = Entity::create(['tenant_id' => $tenant->id, 'name' => 'B']);

    $this->user = User::create([
        'name' => 'Dev',
        'email' => 'dev+'.uniqid().'@example.test',
        'password_hash' => bcrypt('secret'),
    ]);

    $app = RbacApp::firstOrCreate(
        ['code' => 'accounting'],
        ['name' => 'Accounting', 'version' => '0.1', 'enabled' => true],
    );
    $role = Role::firstOrCreate(
        ['code' => 'super_admin', 'tenant_id' => null],
        ['name' => 'Super Admin', 'is_preset' => true],
    );

    UserAppAssignment::create([
        'user_id' => $this->user->id,
        'role_id' => $role->id,
        'app_id' => $app->id,
        'entity_id' => $this->entityA->id,
        'assigned_at' => now(),
    ]);
    UserAppAssignment::create([
        'user_id' => $this->user->id,
        'role_id' => $role->id,
        'app_id' => $app->id,
        'entity_id' => $this->entityB->id,
        'assigned_at' => now(),
    ]);

    // Isolated route that mimics Filament's {tenant} URL shape.
    Route::middleware(SharedEntitySelector::class)
        ->get('/test-entity/{tenant}/ping', fn () => 'pong')
        ->where('tenant', '.*');
});

it('writes akunta_entity cookie to visited entity id', function () {
    $response = $this->get('/test-entity/'.$this->entityA->id.'/ping');

    $response->assertStatus(200);

    $cookies = $response->headers->getCookies();
    $match = collect($cookies)->first(fn ($c) => $c->getName() === SharedEntitySelector::COOKIE_NAME);

    expect($match)->not->toBeNull()
        ->and($match->getValue())->toBe($this->entityA->id)
        ->and($match->isHttpOnly())->toBeTrue()
        ->and($match->getSameSite())->toBe('lax');
});

it('does not rewrite cookie when URL tenant matches existing cookie', function () {
    $response = $this->withUnencryptedCookie(SharedEntitySelector::COOKIE_NAME, $this->entityA->id)
        ->get('/test-entity/'.$this->entityA->id.'/ping');

    $cookies = $response->headers->getCookies();
    $match = collect($cookies)->first(fn ($c) => $c->getName() === SharedEntitySelector::COOKIE_NAME);

    expect($match)->toBeNull();
});

it('updates cookie when user navigates to a different entity', function () {
    $response = $this->withUnencryptedCookie(SharedEntitySelector::COOKIE_NAME, $this->entityA->id)
        ->get('/test-entity/'.$this->entityB->id.'/ping');

    $cookies = $response->headers->getCookies();
    $match = collect($cookies)->first(fn ($c) => $c->getName() === SharedEntitySelector::COOKIE_NAME);

    expect($match->getValue())->toBe($this->entityB->id);
});

it('does not set cookie on routes without {tenant} parameter', function () {
    Route::middleware(SharedEntitySelector::class)
        ->get('/test-entity/health', fn () => 'ok');

    $response = $this->get('/test-entity/health');

    $cookies = $response->headers->getCookies();
    $match = collect($cookies)->first(fn ($c) => $c->getName() === SharedEntitySelector::COOKIE_NAME);

    expect($match)->toBeNull();
});

it('getDefaultTenant returns cookie entity when user has access', function () {
    $this->app['request']->cookies->set(SharedEntitySelector::COOKIE_NAME, $this->entityB->id);

    $panel = \Filament\Facades\Filament::getPanel('accounting');
    $default = $this->user->getDefaultTenant($panel);

    expect($default?->id)->toBe($this->entityB->id);
});

it('getDefaultTenant falls back to first accessible entity when cookie entity is inaccessible', function () {
    $otherTenant = Tenant::create(['name' => 'Other', 'slug' => 'other-'.uniqid()]);
    $inaccessibleEntity = Entity::create(['tenant_id' => $otherTenant->id, 'name' => 'Out']);

    $this->app['request']->cookies->set(SharedEntitySelector::COOKIE_NAME, $inaccessibleEntity->id);

    $panel = \Filament\Facades\Filament::getPanel('accounting');
    $default = $this->user->getDefaultTenant($panel);

    expect($default?->id)->toBeIn([$this->entityA->id, $this->entityB->id]);
});

it('getDefaultTenant falls back when no cookie present', function () {
    $panel = \Filament\Facades\Filament::getPanel('accounting');
    $default = $this->user->getDefaultTenant($panel);

    expect($default)->not->toBeNull()
        ->and($default->id)->toBeIn([$this->entityA->id, $this->entityB->id]);
});

it('getDefaultTenant handles missing entity (stale cookie) gracefully', function () {
    $this->app['request']->cookies->set(SharedEntitySelector::COOKIE_NAME, 'non-existent-ulid');

    $panel = \Filament\Facades\Filament::getPanel('accounting');
    $default = $this->user->getDefaultTenant($panel);

    expect($default?->id)->toBeIn([$this->entityA->id, $this->entityB->id]);
});
