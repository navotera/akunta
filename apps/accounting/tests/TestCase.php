<?php

namespace Tests;

use App\Support\Tenancy\TenantRls;
use App\Tenancy\Contracts\TenantProvisioner;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    private bool $rlsInstalled = false;

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

    /**
     * Opt-in diagnostic mode (AKUNTA_RLS_TEST_HTTP=1, PostgreSQL only): every HTTP
     * request of a test runs as the non-owner database role with the Row-Level
     * Security policies installed, so the database enforces tenant isolation on the
     * whole application surface. Fixtures and assertions stay with the owner role.
     * The role needs SELECT/INSERT/UPDATE/DELETE on the tables (default `akunta_app`).
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        if (! $this->rlsHttpMode()) {
            return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        }

        if (! $this->rlsInstalled) {
            Artisan::call('akunta:rls', ['action' => 'enable']);
            $this->rlsInstalled = true;
        }
        config()->set('tenancy.rls.enabled', true);
        DB::statement('SET LOCAL ROLE '.(env('AKUNTA_RLS_TEST_ROLE') ?: 'akunta_app'));

        try {
            return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
        } finally {
            DB::statement('RESET ROLE');
        }
    }

    private function rlsHttpMode(): bool
    {
        return env('AKUNTA_RLS_TEST_HTTP') === '1' && TenantRls::supported();
    }
}
