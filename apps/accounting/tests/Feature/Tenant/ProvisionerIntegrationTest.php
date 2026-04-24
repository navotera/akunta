<?php

declare(strict_types=1);

use Akunta\Rbac\Models\Tenant;
use App\Actions\ProvisionTenantAction;
use App\Exceptions\ProvisionException;
use App\Tenancy\Contracts\TenantProvisioner;
use App\Tenancy\SqliteTenantProvisioner;

beforeEach(function () {
    $this->storage = sys_get_temp_dir().'/akunta-provisioner-int-'.uniqid();
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

it('provision creates tenant DB file at expected path', function () {
    $result = app(ProvisionTenantAction::class)->execute([
        'slug' => 'filecheck',
        'name' => 'FC',
        'admin_email' => 'fc@test.test',
    ]);

    $expectedPath = $this->storage.'/'.$result->tenant->db_name.'.sqlite';
    expect(file_exists($expectedPath))->toBeTrue()
        ->and($result->tenant->db_name)->toBe('tenant_'.$result->tenant->id);
});

it('duplicate slug does NOT create a stray tenant DB file', function () {
    app(ProvisionTenantAction::class)->execute([
        'slug' => 'dup',
        'name' => 'First',
        'admin_email' => 'first@dup.test',
    ]);

    $filesBefore = count(glob($this->storage.'/*.sqlite') ?: []);

    expect(fn () => app(ProvisionTenantAction::class)->execute([
        'slug' => 'dup',
        'name' => 'Second',
        'admin_email' => 'second@dup.test',
    ]))->toThrow(ProvisionException::class);

    $filesAfter = count(glob($this->storage.'/*.sqlite') ?: []);

    expect($filesAfter)->toBe($filesBefore);
});

it('rolls back tenant DB file when inner transaction fails', function () {
    // Induce mid-tx failure by subscribing to USER_ROLE_ASSIGNED (fired by AssignmentService
    // inside the transaction) and throwing. This exercises the catch-branch that calls
    // provisioner->drop() on the already-allocated tenant DB.
    Event::listen(\Akunta\Core\Hooks::USER_ROLE_ASSIGNED, function () {
        throw new \RuntimeException('induced failure for rollback test');
    });

    $filesBefore = count(glob($this->storage.'/*.sqlite') ?: []);

    try {
        app(ProvisionTenantAction::class)->execute([
            'slug' => 'rollback',
            'name' => 'RB',
            'admin_email' => 'rb@rollback.test',
        ]);
        $this->fail('Expected exception');
    } catch (\Throwable) {
        // expected
    }

    $filesAfter = count(glob($this->storage.'/*.sqlite') ?: []);
    expect($filesAfter)->toBe($filesBefore);
});

it('archive CLI drops tenant DB file and marks status=archived', function () {
    $result = app(ProvisionTenantAction::class)->execute([
        'slug' => 'to-archive',
        'name' => 'ToArchive',
        'admin_email' => 'ta@archive.test',
    ]);

    $path = $this->storage.'/'.$result->tenant->db_name.'.sqlite';
    expect(file_exists($path))->toBeTrue();

    $this->artisan('tenant:archive', ['--slug' => 'to-archive', '--force' => true])
        ->expectsOutputToContain('archived')
        ->assertExitCode(0);

    expect(file_exists($path))->toBeFalse();
    expect(Tenant::where('slug', 'to-archive')->first()->status)
        ->toBe(Tenant::STATUS_ARCHIVED);
});

it('archive CLI rejects already-archived tenant', function () {
    app(ProvisionTenantAction::class)->execute([
        'slug' => 'arc2',
        'name' => 'Arc2',
        'admin_email' => 'a2@arc.test',
    ]);
    $this->artisan('tenant:archive', ['--slug' => 'arc2', '--force' => true])->assertExitCode(0);

    $this->artisan('tenant:archive', ['--slug' => 'arc2', '--force' => true])
        ->expectsOutputToContain('already archived')
        ->assertExitCode(1);
});

it('archive CLI fails on missing slug', function () {
    $this->artisan('tenant:archive')
        ->expectsOutputToContain('required')
        ->assertExitCode(2);
});

it('archive CLI fails on unknown slug', function () {
    $this->artisan('tenant:archive', ['--slug' => 'ghost', '--force' => true])
        ->expectsOutputToContain('not found')
        ->assertExitCode(1);
});
