<?php

declare(strict_types=1);

use App\Tenancy\Contracts\TenantProvisioner;
use App\Tenancy\Exceptions\InvalidTenantIdentifier;
use App\Tenancy\Exceptions\TenantDatabaseAlreadyExists;
use App\Tenancy\SqliteTenantProvisioner;

beforeEach(function () {
    $this->storage = sys_get_temp_dir().'/akunta-tenant-dbs-'.uniqid();
    $this->provisioner = new SqliteTenantProvisioner($this->storage);
});

afterEach(function () {
    if (is_dir($this->storage)) {
        foreach (glob($this->storage.'/*.sqlite') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->storage);
    }
});

it('creates a tenant DB file on create and reports exists=true', function () {
    $name = 'tenant_'.strtolower(bin2hex(random_bytes(8)));

    expect($this->provisioner->exists($name))->toBeFalse();

    $this->provisioner->create($name);

    expect($this->provisioner->exists($name))->toBeTrue()
        ->and(file_exists($this->provisioner->pathFor($name)))->toBeTrue();
});

it('drops the tenant DB file and reports exists=false afterwards', function () {
    $name = 'tenant_'.strtolower(bin2hex(random_bytes(8)));
    $this->provisioner->create($name);

    $this->provisioner->drop($name);

    expect($this->provisioner->exists($name))->toBeFalse();
});

it('drop is idempotent on non-existent DB', function () {
    $this->provisioner->drop('tenant_does_not_exist');
    expect($this->provisioner->exists('tenant_does_not_exist'))->toBeFalse();
});

it('rejects invalid identifiers with InvalidTenantIdentifier', function () {
    expect(fn () => $this->provisioner->create('1starts-with-digit'))
        ->toThrow(InvalidTenantIdentifier::class);

    expect(fn () => $this->provisioner->create('has space'))
        ->toThrow(InvalidTenantIdentifier::class);

    expect(fn () => $this->provisioner->create("'; DROP DATABASE x; --"))
        ->toThrow(InvalidTenantIdentifier::class);

    expect(fn () => $this->provisioner->create(''))
        ->toThrow(InvalidTenantIdentifier::class);

    expect(fn () => $this->provisioner->create(str_repeat('a', 64)))
        ->toThrow(InvalidTenantIdentifier::class);
});

it('accepts 63-char identifiers (PG limit)', function () {
    $name = 'a'.str_repeat('b', 62);
    $this->provisioner->create($name);
    expect($this->provisioner->exists($name))->toBeTrue();
});

it('throws TenantDatabaseAlreadyExists when create called twice for same name', function () {
    $name = 'tenant_dup_test';
    $this->provisioner->create($name);

    expect(fn () => $this->provisioner->create($name))
        ->toThrow(TenantDatabaseAlreadyExists::class);
});

it('connectionConfig returns sqlite driver with correct path and fk constraints', function () {
    $name = 'tenant_cfg';
    $config = $this->provisioner->connectionConfig($name);

    expect($config['driver'])->toBe('sqlite')
        ->and($config['database'])->toBe($this->provisioner->pathFor($name))
        ->and($config['foreign_key_constraints'])->toBeTrue();
});

it('implements TenantProvisioner contract', function () {
    expect($this->provisioner)->toBeInstanceOf(TenantProvisioner::class);
});
