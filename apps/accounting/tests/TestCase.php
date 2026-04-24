<?php

namespace Tests;

use App\Tenancy\Contracts\TenantProvisioner;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Force sqlite tenant provisioner in tests. Control DB config defaults to
        // pgsql in prod/dev, which the PG provisioner would try to contact —
        // tests need a disk-backed sqlite driver with isolated per-run storage.
        config()->set('tenancy.provisioner.force_driver', 'sqlite');
        config()->set(
            'tenancy.provisioner.sqlite_storage_path',
            sys_get_temp_dir().'/akunta-test-tenant-dbs'
        );

        app()->forgetInstance(TenantProvisioner::class);
    }
}
