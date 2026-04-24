<?php

declare(strict_types=1);

use App\Tenancy\Contracts\TenantProvisioner;
use App\Tenancy\PostgresTenantProvisioner;
use App\Tenancy\SqliteTenantProvisioner;

it('binds SqliteTenantProvisioner when force_driver=sqlite', function () {
    config()->set('tenancy.provisioner.force_driver', 'sqlite');
    config()->set('tenancy.provisioner.sqlite_storage_path', sys_get_temp_dir());

    app()->forgetInstance(TenantProvisioner::class);

    $instance = app(TenantProvisioner::class);

    expect($instance)->toBeInstanceOf(SqliteTenantProvisioner::class);
});

it('binds PostgresTenantProvisioner when force_driver=pgsql', function () {
    config()->set('tenancy.provisioner.force_driver', 'pgsql');

    app()->forgetInstance(TenantProvisioner::class);

    $instance = app(TenantProvisioner::class);

    expect($instance)->toBeInstanceOf(PostgresTenantProvisioner::class);
});

it('throws on unsupported driver', function () {
    config()->set('tenancy.provisioner.force_driver', 'mongodb');

    app()->forgetInstance(TenantProvisioner::class);

    expect(fn () => app(TenantProvisioner::class))
        ->toThrow(\RuntimeException::class, 'Unsupported');
});

it('infers driver from control connection when no force_driver set', function () {
    config()->set('tenancy.provisioner.force_driver', null);
    config()->set('tenancy.control_connection', 'ecosystem_control');
    config()->set('database.connections.ecosystem_control.driver', 'sqlite');
    config()->set('tenancy.provisioner.sqlite_storage_path', sys_get_temp_dir());

    app()->forgetInstance(TenantProvisioner::class);

    $instance = app(TenantProvisioner::class);

    expect($instance)->toBeInstanceOf(SqliteTenantProvisioner::class);
});
