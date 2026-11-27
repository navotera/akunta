<?php

declare(strict_types=1);

use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\UserAppAssignment;
use App\Models\Account;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\Period;
use App\Models\User;
use App\Services\EcopaMigrationReport;
use App\Services\EcopaOrganizationLinker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * docs/ecopa-multi-org-migration.md §4: the report proves that the v1 → v2
 * migration lost nothing and gave every legacy assignment the default
 * organisation's tenant.
 */
beforeEach(function () {
    $this->snapshotPath = sys_get_temp_dir().'/akunta-migration-report-'.uniqid().'/before.json';

    $tenant = Tenant::query()->create(['name' => 'Akunta Lama', 'slug' => 'akunta-lama-'.uniqid(), 'status' => 'active']);
    $this->tenant = $tenant;
    $this->entity = Entity::query()->create(['tenant_id' => $tenant->id, 'name' => 'PT Lama']);
    $app = RbacApp::query()->firstOrCreate(['code' => 'accounting'], ['name' => 'Accounting', 'version' => '1.0']);
    $operator = Role::query()->firstOrCreate(['tenant_id' => null, 'code' => 'operator'], ['name' => 'Operator', 'is_preset' => true]);
    $custom = Role::query()->create(['tenant_id' => $tenant->id, 'code' => 'auditor_lama', 'name' => 'Auditor Lama']);

    $this->admin = User::query()->create(['name' => 'Admin Lama', 'email' => 'admin-lama-'.uniqid().'@example.test', 'main_tier_user_id' => 'ecopa-admin']);
    $this->member = User::query()->create(['name' => 'Anggota Lama', 'email' => 'anggota-lama-'.uniqid().'@example.test', 'main_tier_user_id' => 'ecopa-member']);
    $this->idle = User::query()->create(['name' => 'Tanpa Akses', 'email' => 'tanpa-akses-'.uniqid().'@example.test']);

    UserAppAssignment::query()->create(['user_id' => $this->admin->id, 'app_id' => $app->id, 'entity_id' => null, 'role_id' => $custom->id, 'ecopa_role' => 'admin']);
    $this->memberAssignment = UserAppAssignment::query()->create(['user_id' => $this->member->id, 'app_id' => $app->id, 'entity_id' => $this->entity->id, 'role_id' => $operator->id]);
    DB::table('user_app_assignments')->update(['tenant_id' => null]);

    $period = Period::query()->create(['entity_id' => $this->entity->id, 'name' => 'Apr 2026', 'start_date' => '2026-04-01', 'end_date' => '2026-04-30']);
    $cash = Account::query()->create(['entity_id' => $this->entity->id, 'code' => '1101', 'name' => 'Kas', 'type' => 'asset', 'normal_balance' => 'debit']);
    $capital = Account::query()->create(['entity_id' => $this->entity->id, 'code' => '3101', 'name' => 'Modal', 'type' => 'equity', 'normal_balance' => 'credit']);

    $this->journal = Journal::query()->create([
        'entity_id' => $this->entity->id, 'period_id' => $period->id, 'type' => Journal::TYPE_GENERAL,
        'number' => 'JRN-1', 'date' => '2026-04-05', 'status' => Journal::STATUS_POSTED,
    ]);
    JournalEntry::query()->create(['journal_id' => $this->journal->id, 'line_no' => 1, 'account_id' => $cash->id, 'debit' => '1500000.00', 'credit' => '0']);
    JournalEntry::query()->create(['journal_id' => $this->journal->id, 'line_no' => 2, 'account_id' => $capital->id, 'debit' => '0', 'credit' => '1500000.00']);
});

afterEach(function () {
    if (is_file($this->snapshotPath)) {
        unlink($this->snapshotPath);
        @rmdir(dirname($this->snapshotPath));
    }
});

it('passes when linking the default organisation changes nothing but tenant ids', function () {
    $this->artisan('ecopa:migration-report', ['--snapshot' => $this->snapshotPath])->assertExitCode(1);
    expect(is_file($this->snapshotPath))->toBeTrue();

    $this->artisan('ecopa:link-organization', ['organization_id' => '1', '--slug' => 'default'])->assertSuccessful();

    $this->artisan('ecopa:migration-report', ['--compare' => $this->snapshotPath])
        ->expectsOutputToContain('Tidak ada perbedaan dengan snapshot')
        ->expectsOutputToContain('Verifikasi migrasi lulus.')
        ->assertSuccessful();

    expect(DB::table('user_app_assignments')->whereNull('tenant_id')->count())->toBe(0);
});

it('flags legacy assignments that still have no tenant', function () {
    $checks = app(EcopaMigrationReport::class)->checks();

    expect($checks['tenant_columns']['user_app_assignments'])->toMatchArray(['status' => 'failed', 'null_tenant_rows' => 2])
        ->and($checks['failures'])->toContain('user_app_assignments: 2 baris tanpa tenant_id.');
});

it('reports the user categories without creating assignments for users who had none', function () {
    $this->artisan('ecopa:link-organization', ['organization_id' => '1'])->assertSuccessful();

    $users = app(EcopaMigrationReport::class)->checks()['users'];

    expect($users)->toMatchArray([
        'total' => 3,
        'with_assignment' => 2,
        'without_assignment' => 1,
        'with_ecopa_sub' => 2,
        'local_only' => 1,
    ])->and(UserAppAssignment::query()->where('user_id', $this->idle->id)->exists())->toBeFalse();
});

it('fails when a money total changes after the snapshot', function () {
    $report = app(EcopaMigrationReport::class);
    $before = $report->snapshot();

    DB::table('journal_entries')->where('line_no', 1)->update(['debit' => '1400000.00']);

    $keys = array_column($report->compare($before, $report->snapshot()), 'key');
    expect($keys)->toContain("entities.{$this->entity->id}.entries.internal.debit");
});

it('fails when a journal disappears after the snapshot', function () {
    $this->artisan('ecopa:link-organization', ['organization_id' => '1'])->assertSuccessful();
    $this->artisan('ecopa:migration-report', ['--snapshot' => $this->snapshotPath])->assertSuccessful();

    DB::table('journal_entries')->where('journal_id', $this->journal->id)->delete();
    DB::table('journals')->where('id', $this->journal->id)->delete();

    $this->artisan('ecopa:migration-report', ['--compare' => $this->snapshotPath])
        ->expectsOutputToContain('Verifikasi migrasi GAGAL.')
        ->assertExitCode(1);
});

it('lets append-only logs grow but not shrink', function () {
    $report = app(EcopaMigrationReport::class);
    $before = $report->snapshot();
    $before['data']['tables']['audit_log'] = 5;

    $grown = $before;
    $grown['data']['tables']['audit_log'] = 6;
    $shrunk = $before;
    $shrunk['data']['tables']['audit_log'] = 4;

    expect($report->compare($before, $grown))->toBe([])
        ->and(array_column($report->compare($before, $shrunk), 'key'))->toBe(['tables.audit_log']);
});

it('accepts a table the migration itself created while it is still empty, but reports one that holds rows', function () {
    $report = app(EcopaMigrationReport::class);
    $after = $report->snapshot();
    $before = $after;
    unset($before['data']['tables']['coa_templates'], $before['data']['tables']['installation_settings']);
    $after['data']['tables']['coa_templates'] = 0;
    $after['data']['tables']['installation_settings'] = 2;

    expect(array_column($report->compare($before, $after), 'key'))->toBe(['tables.installation_settings']);
});

it('fails when a user role or revocation changes, but not when only tenant_id is filled', function () {
    $report = app(EcopaMigrationReport::class);
    $before = $report->snapshot();

    DB::table('user_app_assignments')->update(['tenant_id' => $this->tenant->id]);
    expect($report->compare($before, $report->snapshot()))->toBe([]);

    $this->memberAssignment->forceFill(['revoked_at' => now()])->save();
    expect(array_column($report->compare($before, $report->snapshot()), 'key'))
        ->toContain("users.{$this->member->id}.fingerprint");
});

it('fails when an assignment points at another tenant than its entity', function () {
    $other = Tenant::query()->create(['name' => 'Lain', 'slug' => 'lain-'.uniqid(), 'status' => 'active']);
    DB::table('user_app_assignments')->update(['tenant_id' => $this->tenant->id]);
    DB::table('user_app_assignments')->where('id', $this->memberAssignment->id)->update(['tenant_id' => $other->id]);

    $checks = app(EcopaMigrationReport::class)->checks();

    expect($checks['tenant_columns']['user_app_assignments']['tenant_mismatch_rows'])->toBe(1)
        ->and($checks['failures'])->toContain('user_app_assignments: 1 baris dengan tenant_id berbeda dari entity induknya.');
});

it('verifies attachment files only when asked', function () {
    Storage::fake('local');
    Storage::disk('local')->put('attachments/ada.pdf', 'x');
    foreach (['ada.pdf', 'hilang.pdf'] as $name) {
        DB::table('attachments')->insert([
            'id' => (string) Str::ulid(), 'attachable_type' => Journal::class, 'attachable_id' => $this->journal->id,
            'tenant_id' => $this->tenant->id, 'entity_id' => $this->entity->id, 'filename' => $name, 'disk' => 'local', 'path' => "attachments/{$name}",
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $report = app(EcopaMigrationReport::class);
    expect($report->checks()['attachments'])->toBeNull();

    $files = $report->checks(checkFiles: true)['attachments'];
    expect($files)->toMatchArray(['total' => 2, 'missing' => 1]);
});

it('never writes to the database', function () {
    $this->artisan('ecopa:link-organization', ['organization_id' => '1'])->assertSuccessful();
    $before = app(EcopaOrganizationLinker::class)->counts() + ['audit_log' => DB::table('audit_log')->count()];

    $this->artisan('ecopa:migration-report', ['--snapshot' => $this->snapshotPath, '--check-files' => true])->assertSuccessful();

    $after = app(EcopaOrganizationLinker::class)->counts() + ['audit_log' => DB::table('audit_log')->count()];
    expect($after)->toBe($before);
});

it('refuses an unreadable comparison snapshot', function () {
    @mkdir(dirname($this->snapshotPath), 0775, true);
    file_put_contents($this->snapshotPath, '{"format": 99, "data": {}}');

    $this->artisan('ecopa:migration-report', ['--compare' => $this->snapshotPath])
        ->expectsOutputToContain('Format snapshot pembanding tidak dikenal')
        ->assertExitCode(1);
});
