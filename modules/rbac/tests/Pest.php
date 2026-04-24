<?php

declare(strict_types=1);

use Akunta\Rbac\Models\App;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Permission;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\User;
use Akunta\Rbac\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(TestCase::class, RefreshDatabase::class)->in(__DIR__);

function makeTenant(array $overrides = []): Tenant
{
    return Tenant::create(array_merge([
        'name' => 'Test Tenant',
        'slug' => 'test-tenant-' . uniqid(),
    ], $overrides));
}

function makeEntity(?Tenant $tenant = null, array $overrides = []): Entity
{
    return Entity::create(array_merge([
        'tenant_id' => ($tenant ?? makeTenant())->id,
        'name'      => 'Test Entity',
    ], $overrides));
}

function makeApp(array $overrides = []): App
{
    return App::create(array_merge([
        'code'    => 'test-app-' . uniqid(),
        'name'    => 'Test App',
        'version' => '1.0.0',
    ], $overrides));
}

function makeUser(array $overrides = []): User
{
    static $i = 0;
    $i++;

    return User::create(array_merge([
        'email' => "user{$i}_" . uniqid() . '@example.com',
        'name'  => 'Test User',
    ], $overrides));
}

function makeRole(?Tenant $tenant, array $overrides = []): Role
{
    static $i = 0;
    $i++;

    return Role::create(array_merge([
        'tenant_id' => $tenant?->id,
        'code'      => "role_{$i}_" . uniqid(),
        'name'      => 'Test Role',
    ], $overrides));
}

function makePermission(App $app, string $code, array $overrides = []): Permission
{
    return Permission::create(array_merge([
        'app_id' => $app->id,
        'code'   => $code,
    ], $overrides));
}
