<?php

declare(strict_types=1);

use App\Tenancy\Contracts\TenantProvisioner;
use App\Tenancy\SqliteTenantProvisioner;

beforeEach(function () {
    $this->storage = sys_get_temp_dir().'/akunta-tenant-dbs-cli-'.uniqid();

    config()->set('tenancy.provisioner.force_driver', 'sqlite');
    config()->set('tenancy.provisioner.sqlite_storage_path', $this->storage);

    app()->forgetInstance(TenantProvisioner::class);
});

afterEach(function () {
    if (is_dir($this->storage)) {
        foreach (glob($this->storage.'/*.sqlite') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->storage);
    }
});

it('create → exists → drop CLI roundtrip', function () {
    $this->artisan('tenant:db', ['action' => 'exists', '--name' => 'tenant_cli_rt'])
        ->expectsOutput('no')
        ->assertExitCode(0);

    $this->artisan('tenant:db', ['action' => 'create', '--name' => 'tenant_cli_rt'])
        ->expectsOutputToContain('created')
        ->assertExitCode(0);

    expect(app(TenantProvisioner::class)->exists('tenant_cli_rt'))->toBeTrue();

    $this->artisan('tenant:db', ['action' => 'exists', '--name' => 'tenant_cli_rt'])
        ->expectsOutput('yes')
        ->assertExitCode(0);

    $this->artisan('tenant:db', ['action' => 'drop', '--name' => 'tenant_cli_rt'])
        ->expectsOutputToContain('dropped')
        ->assertExitCode(0);

    expect(app(TenantProvisioner::class)->exists('tenant_cli_rt'))->toBeFalse();
});

it('CLI fails on missing name', function () {
    $this->artisan('tenant:db', ['action' => 'create'])
        ->expectsOutputToContain('--name is required')
        ->assertExitCode(2);
});

it('CLI fails on unknown action', function () {
    $this->artisan('tenant:db', ['action' => 'nuke', '--name' => 'tenant_x'])
        ->expectsOutputToContain('Unknown action')
        ->assertExitCode(2);
});

it('CLI surfaces invalid identifier error', function () {
    $this->artisan('tenant:db', ['action' => 'create', '--name' => 'has space'])
        ->expectsOutputToContain('invalid')
        ->assertExitCode(1);
});
