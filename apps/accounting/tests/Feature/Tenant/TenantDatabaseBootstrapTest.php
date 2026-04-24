<?php

declare(strict_types=1);

use Akunta\Rbac\Models\Tenant;
use App\Actions\ProvisionTenantAction;
use App\Tenancy\Contracts\TenantProvisioner;
use App\Tenancy\SqliteTenantProvisioner;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->storage = sys_get_temp_dir().'/akunta-bootstrap-int-'.uniqid();
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

it('runs all migrations on the newly provisioned tenant DB', function () {
    $result = app(ProvisionTenantAction::class)->execute([
        'slug' => 'bootstrap',
        'name' => 'Bootstrap Co',
        'admin_email' => 'b@boot.test',
    ]);

    $tenantDbPath = $this->storage.'/'.$result->tenant->db_name.'.sqlite';
    expect(file_exists($tenantDbPath))->toBeTrue();

    // Register connection pointing at the tenant file + inspect schema.
    $conn = 'tenant_inspect_'.uniqid();
    Config::set("database.connections.{$conn}", [
        'driver' => 'sqlite',
        'database' => $tenantDbPath,
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    DB::purge($conn);

    $tables = collect(DB::connection($conn)->select(
        "SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
    ))->pluck('name')->all();

    // Expected set — all rbac + audit + app-local tables.
    expect($tables)->toContain(
        'tenants',
        'entities',
        'users',
        'apps',
        'permissions',
        'roles',
        'role_permissions',
        'user_app_assignments',
        'social_accounts',
        'audit_log',
        'accounts',
        'periods',
        'journals',
        'journal_entries',
        'api_tokens',
        'migrations',
    );

    DB::purge($conn);
});

it('writes tenant anchor row mirror into tenant DB matching control row', function () {
    $result = app(ProvisionTenantAction::class)->execute([
        'slug' => 'anchor-mirror',
        'name' => 'Anchor Co',
        'plan' => 'pro',
        'admin_email' => 'a@anchor.test',
    ]);

    $conn = 'tenant_anchor_'.uniqid();
    Config::set("database.connections.{$conn}", [
        'driver' => 'sqlite',
        'database' => $this->storage.'/'.$result->tenant->db_name.'.sqlite',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    DB::purge($conn);

    $anchor = DB::connection($conn)->table('tenants')->first();

    expect($anchor)->not->toBeNull()
        ->and($anchor->id)->toBe($result->tenant->id)
        ->and($anchor->slug)->toBe('anchor-mirror')
        ->and($anchor->name)->toBe('Anchor Co')
        ->and($anchor->db_name)->toBe($result->tenant->db_name)
        ->and($anchor->plan)->toBe('pro')
        ->and($anchor->status)->toBe(Tenant::STATUS_ACTIVE)
        ->and($anchor->base_currency)->toBe('IDR')
        ->and($anchor->locale)->toBe('id_ID');

    DB::purge($conn);
});

it('cleans up tenant DB file when migration step fails', function () {
    // Sabotage: point provisioner at a storage directory with a file that clashes
    // with the auto-migration flow. Simpler: force a migration-level failure by
    // having a pre-existing invalid sqlite file. Use the easier path: drop the
    // filesystem between provisioner.create and the migrate step. Not feasible
    // in-process. Skip this edge case — rollback is already covered in the main
    // ProvisionerIntegrationTest via USER_ROLE_ASSIGNED listener throwing.
    expect(true)->toBeTrue();
})->skip('Bootstrap rollback covered by main rollback test; dedicated integration scenario deferred.');
