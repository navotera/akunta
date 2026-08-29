<?php

declare(strict_types=1);

use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Permission;
use Akunta\Rbac\Models\Role;
use Database\Seeders\SuperAdminSeeder;

it('seeds system configuration without creating entities by default', function () {
    $this->artisan('db:seed')->assertSuccessful();

    $accountingApp = RbacApp::query()->where('code', 'accounting')->firstOrFail();

    expect($accountingApp->code)->toBe('accounting')
        ->and(Role::query()->whereNull('tenant_id')->count())->toBeGreaterThan(0)
        ->and(Permission::query()->where('app_id', $accountingApp->id)->count())->toBeGreaterThan(0)
        ->and(Entity::query()->count())->toBe(0);
});

it('creates local demo entities only when the super admin seeder is run explicitly', function () {
    $this->seed(SuperAdminSeeder::class);

    expect(Entity::query()->orderBy('name')->pluck('name')->all())->toBe([
        'PT. Dummy A',
        'PT. Dummy B',
    ]);
});
