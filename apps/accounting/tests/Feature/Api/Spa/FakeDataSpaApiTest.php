<?php

declare(strict_types=1);

use Akunta\Audit\Models\AuditLog;
use Akunta\Core\Contracts\AuditLogger as AuditLoggerContract;
use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Permission;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\Tenant;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\FakeDataRecord;
use App\Models\Journal;
use App\Models\Period;
use App\Models\User;
use App\Services\NativeFakeDataProvisioner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $tenant = Tenant::create(['name' => 'Fake API', 'slug' => 'fake-api-'.uniqid()]);
    $this->entity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'Fake API Co']);
    $this->user = User::create([
        'name' => 'Admin Fake',
        'email' => 'fake-admin-'.uniqid().'@example.test',
        'password_hash' => bcrypt('x'),
    ]);
    $app = RbacApp::create(['code' => 'accounting', 'name' => 'Accounting', 'version' => '1.0', 'enabled' => true]);
    $role = Role::create(['code' => 'fake-admin-'.uniqid(), 'name' => 'Fake Admin', 'is_preset' => false]);
    $role->permissions()->attach(collect(['journal.update', 'journal.reverse'])->map(
        fn (string $code) => Permission::create(['app_id' => $app->id, 'code' => $code])->id,
    ));
    $this->user->assignments()->create([
        'entity_id' => $this->entity->id,
        'app_id' => $app->id,
        'role_id' => $role->id,
    ]);
});

it('only exposes COA and impersonation users for a regular entity', function () {
    $response = $this->actingAs($this->user)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/fake-data')
        ->assertOk()
        ->assertJsonCount(2, 'data.groups');

    expect(collect($response->json('data.groups'))->pluck('key')->all())
        ->toBe(['accounts', 'users']);
});

it('imports COA and provisions scoped impersonation accounts for User & Roles', function () {
    $this->actingAs($this->user)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson('/api/v1/spa/fake-data/accounts/import')
        ->assertOk();

    $users = $this->actingAs($this->user)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson('/api/v1/spa/fake-data/users/import')
        ->assertOk()
        ->assertJsonCount(3, 'data.users');

    $fakeUserId = $users->json('data.users.0.id');
    expect(Account::where('entity_id', $this->entity->id)->exists())->toBeTrue()
        ->and(FakeDataRecord::query()
            ->where('entity_id', $this->entity->id)
            ->where('group_key', 'users')
            ->where('model_id', $fakeUserId)
            ->exists())->toBeTrue();

    $this->actingAs($this->user)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson('/api/v1/spa/fake-data/impersonate/'.$fakeUserId)
        ->assertNotFound();
});

it('rejects Import All and financial demo groups for a regular entity', function () {
    Period::create([
        'entity_id' => $this->entity->id,
        'name' => '2028',
        'start_date' => '2028-01-01',
        'end_date' => '2028-12-31',
        'status' => Period::STATUS_OPEN,
    ]);

    $this->actingAs($this->user)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson('/api/v1/spa/fake-data/import-all')
        ->assertConflict();

    $this->actingAs($this->user)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson('/api/v1/spa/fake-data/journals/import')
        ->assertConflict();
});

it('protects the built-in native fake entity from import and clear operations', function () {
    $this->entity->update(['is_fake_data' => true]);

    $this->actingAs($this->user)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson('/api/v1/spa/fake-data/import-all', ['period_id' => str_repeat('0', 26)])
        ->assertConflict();

    $this->actingAs($this->user)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->deleteJson('/api/v1/spa/fake-data/accounts')
        ->assertConflict();
});

it('previews and resets only the versioned native dataset with an audit trail', function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');
    $this->entity->forceFill([
        'is_fake_data' => true,
        'workspace_settings' => ['bookkeeping_mode' => 'independent_books', 'native_fake_data' => true],
    ])->save();
    app(NativeFakeDataProvisioner::class)->provision($this->entity, $this->user);

    $manualAccount = Account::create([
        'entity_id' => $this->entity->id,
        'code' => 'MANUAL-RESET-SAFE',
        'name' => 'Akun Manual Tetap Ada',
        'type' => 'asset',
        'normal_balance' => 'debit',
        'is_postable' => true,
        'is_active' => true,
        'availability' => 'both',
    ]);
    $posted = Journal::query()
        ->where('entity_id', $this->entity->id)
        ->where('status', Journal::STATUS_POSTED)
        ->firstOrFail();
    $posted->forceFill(['memo' => 'Diubah sebelum reset'])->saveQuietly();

    $preview = $this->actingAs($this->user)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/fake-data/reset-preview')
        ->assertOk()
        ->assertJsonPath('data.target_version', NativeFakeDataProvisioner::DATASET_VERSION)
        ->assertJsonPath('data.confirmation_phrase', 'RESET DEMO 2026')
        ->assertJsonPath('data.period.name', 'Demo 2026');

    expect($preview->json('data.managed_records.total'))->toBeGreaterThan(0)
        ->and($preview->json('data.preserved_manual_records.accounts'))->toBeGreaterThan(0);

    $this->actingAs($this->user)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson('/api/v1/spa/fake-data/reset', [
            'confirmation' => 'RESET DEMO 2026',
            'expected_version' => NativeFakeDataProvisioner::DATASET_VERSION,
            'preview_token' => $preview->json('data.preview_token'),
        ])
        ->assertOk()
        ->assertJsonPath('data.version', NativeFakeDataProvisioner::DATASET_VERSION)
        ->assertJsonPath('data.dataset.label', NativeFakeDataProvisioner::DATASET_LABEL);

    expect(Account::query()->whereKey($manualAccount->id)->exists())->toBeTrue()
        ->and(Journal::query()->whereKey($posted->id)->exists())->toBeFalse()
        ->and(Period::query()->where('entity_id', $this->entity->id)->count())->toBe(1)
        ->and(Period::query()->where('entity_id', $this->entity->id)->sole()->name)->toBe('Demo 2026')
        ->and(data_get($this->entity->refresh()->workspace_settings, 'native_fake_data_version'))
        ->toBe(NativeFakeDataProvisioner::DATASET_VERSION)
        ->and(AuditLog::query()
            ->where('entity_id', $this->entity->id)
            ->where('action', 'fake_data.dataset_reset')
            ->exists())->toBeTrue();
});

it('rolls back before reset mutations when the audit trail cannot be written', function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');
    $this->entity->forceFill([
        'is_fake_data' => true,
        'workspace_settings' => ['bookkeeping_mode' => 'independent_books', 'native_fake_data' => true],
    ])->save();
    app(NativeFakeDataProvisioner::class)->provision($this->entity, $this->user);

    $preview = $this->actingAs($this->user)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/fake-data/reset-preview')
        ->assertOk();
    $markerCount = FakeDataRecord::query()->where('entity_id', $this->entity->id)->count();
    $journalIds = Journal::query()->where('entity_id', $this->entity->id)->pluck('id')->all();

    app()->bind(AuditLoggerContract::class, fn () => new class implements AuditLoggerContract
    {
        public function record(
            string $action,
            string $resourceType,
            string $resourceId,
            ?string $entityId = null,
            array $metadata = [],
            ?string $actorUserId = null,
        ): string {
            throw new RuntimeException('Audit storage unavailable.');
        }
    });

    $this->actingAs($this->user)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson('/api/v1/spa/fake-data/reset', [
            'confirmation' => 'RESET DEMO 2026',
            'expected_version' => NativeFakeDataProvisioner::DATASET_VERSION,
            'preview_token' => $preview->json('data.preview_token'),
        ])
        ->assertServerError();

    expect(FakeDataRecord::query()->where('entity_id', $this->entity->id)->count())->toBe($markerCount)
        ->and(Journal::query()->where('entity_id', $this->entity->id)->pluck('id')->all())->toBe($journalIds)
        ->and(AuditLog::query()
            ->where('entity_id', $this->entity->id)
            ->where('action', 'fake_data.dataset_reset')
            ->exists())->toBeFalse();
});

it('rejects stale reset previews and locks native periods and recorded journals', function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');
    $this->entity->forceFill([
        'is_fake_data' => true,
        'workspace_settings' => ['bookkeeping_mode' => 'independent_books', 'native_fake_data' => true],
    ])->save();
    app(NativeFakeDataProvisioner::class)->provision($this->entity, $this->user);

    $preview = $this->actingAs($this->user)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/fake-data/reset-preview')
        ->assertOk();
    FakeDataRecord::create([
        'entity_id' => $this->entity->id,
        'group_key' => 'journals',
        'model_type' => Journal::class,
        'model_id' => str_repeat('0', 26),
    ]);

    $this->actingAs($this->user)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson('/api/v1/spa/fake-data/reset', [
            'confirmation' => 'RESET DEMO 2026',
            'expected_version' => NativeFakeDataProvisioner::DATASET_VERSION,
            'preview_token' => $preview->json('data.preview_token'),
        ])
        ->assertConflict();

    $period = Period::query()->where('entity_id', $this->entity->id)->sole();
    $attachment = Attachment::query()
        ->where('entity_id', $this->entity->id)
        ->where('attachable_type', Journal::class)
        ->firstOrFail();
    $posted = Journal::query()
        ->where('entity_id', $this->entity->id)
        ->where('status', Journal::STATUS_POSTED)
        ->findOrFail($attachment->attachable_id);
    $headers = ['X-Tenant-Slug' => $this->entity->id];

    $this->actingAs($this->user)->withHeaders($headers)
        ->postJson('/api/v1/spa/periods', [
            'name' => 'Demo 2027',
            'start_date' => '2027-01-01',
            'end_date' => '2027-12-31',
        ])->assertConflict();
    $this->actingAs($this->user)->withHeaders($headers)
        ->patchJson('/api/v1/spa/periods/'.$period->id, [
            'name' => 'Diubah',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ])->assertConflict();
    $this->actingAs($this->user)->withHeaders($headers)
        ->deleteJson('/api/v1/spa/periods/'.$period->id)
        ->assertConflict();
    $this->actingAs($this->user)->withHeaders($headers)
        ->postJson('/api/v1/spa/periods/'.$period->id.'/close')
        ->assertConflict();
    $this->actingAs($this->user)->withHeaders($headers)
        ->postJson('/api/v1/spa/periods/'.$period->id.'/reopen')
        ->assertConflict();
    $this->actingAs($this->user)->withHeaders($headers)
        ->patchJson('/api/v1/spa/journals/'.$posted->id, [])
        ->assertConflict();
    $this->actingAs($this->user)->withHeaders($headers)
        ->postJson('/api/v1/spa/journals/'.$posted->id.'/reverse', ['reason' => 'Tidak boleh'])
        ->assertConflict();
    $this->actingAs($this->user)->withHeaders($headers)
        ->post('/api/v1/spa/attachments', [
            'attachable_type' => Journal::class,
            'attachable_id' => $posted->id,
            'file' => UploadedFile::fake()->create('locked.pdf', 10, 'application/pdf'),
        ], $headers)
        ->assertConflict();

    $this->actingAs($this->user)->withHeaders($headers)
        ->deleteJson('/api/v1/spa/attachments/'.$attachment->id)
        ->assertConflict();
});
