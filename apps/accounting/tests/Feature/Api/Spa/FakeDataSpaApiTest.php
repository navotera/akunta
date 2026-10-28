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
use App\Models\FakeDataRecord;
use App\Models\Journal;
use App\Models\JournalTemplate;
use App\Models\Period;
use App\Models\User;
use App\Services\NativeFakeDataProvisioner;
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
        ->assertJsonCount(3, 'data.users')
        ->assertJsonPath('data.users.0.roles.0', 'Accountant');

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

it('restores edited deleted and Trashed demo data repeatedly while preserving manual practice records', function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');
    $this->entity->forceFill(['is_fake_data' => true, 'workspace_settings' => ['bookkeeping_mode' => 'independent_books']])->save();
    app(NativeFakeDataProvisioner::class)->provision($this->entity, $this->user);
    $journalCount = Journal::where('entity_id', $this->entity->id)->count();
    $draft = Journal::where('entity_id', $this->entity->id)->where('status', 'draft')->firstOrFail();
    $draftNumber = $draft->number;
    $draftMemo = $draft->memo;
    $draft->delete();
    $edited = Journal::where('entity_id', $this->entity->id)->where('journal_mode', 'fiscal')->firstOrFail();
    $editedNumber = $edited->number;
    $editedMemo = $edited->memo;
    $edited->update(['memo' => 'Perubahan untuk uji coba bug']);
    $template = JournalTemplate::where('entity_id', $this->entity->id)->whereDoesntHave('recurringSchedules')->firstOrFail();
    $templateCode = $template->code;
    $template->delete();

    $manualAccount = Account::create([
        'entity_id' => $this->entity->id, 'code' => 'MANUAL-PRACTICE', 'name' => 'Latihan manual',
        'type' => 'asset', 'normal_balance' => 'debit', 'availability' => 'both',
    ]);
    $manual = Journal::create([
        'entity_id' => $this->entity->id, 'period_id' => $draft->period_id,
        'number' => 'MANUAL-PRACTICE', 'date' => '2026-10-01', 'memo' => 'Latihan tambahan tetap ada',
    ]);
    $manual->entries()->createMany([
        ['line_no' => 1, 'account_id' => $manualAccount->id, 'debit' => 100, 'credit' => 0],
        ['line_no' => 2, 'account_id' => $manualAccount->id, 'debit' => 0, 'credit' => 100],
    ]);
    $manual->delete();

    $this->actingAs($this->user)->withSession(['ecopa.app_role' => 'admin'])->withHeader('X-Tenant-Slug', $this->entity->id);
    foreach ([1, 2] as $attempt) {
        $preview = $this->getJson('/api/v1/spa/fake-data/reset-preview')->assertOk();
        $preview->assertJsonPath('data.managed_records.trashed_journals', $attempt === 1 ? 1 : 0)
            ->assertJsonPath('data.preserved_manual_records.journals', 1);
        $this->postJson('/api/v1/spa/fake-data/reset', [
            'confirmation' => 'RESET DEMO 2026', 'expected_version' => NativeFakeDataProvisioner::DATASET_VERSION,
            'preview_token' => $preview->json('data.preview_token'),
        ])->assertOk()->assertJsonPath('data.dataset.last_restored_by', $this->user->name);
        expect(Journal::withTrashed()->where('entity_id', $this->entity->id)->count())->toBe($journalCount + 1)
            ->and(Journal::where('entity_id', $this->entity->id)->where('number', $draftNumber)->sole()->memo)->toBe($draftMemo)
            ->and(Journal::where('entity_id', $this->entity->id)->where('number', $editedNumber)->sole()->memo)->toBe($editedMemo)
            ->and(JournalTemplate::where('entity_id', $this->entity->id)->where('code', $templateCode)->exists())->toBeTrue()
            ->and(Journal::withTrashed()->find($manual->id)?->trashed())->toBeTrue()
            ->and(Journal::withTrashed()->find($manual->id)?->entries()->count())->toBe(2);
    }
    expect(Journal::withTrashed()->find($draft->id))->toBeNull()
        ->and(AuditLog::where('entity_id', $this->entity->id)->where('action', 'fake_data.dataset_reset')->count())->toBe(2);
});

it('requires a new restore preview after a demo journal is changed or deleted', function (string $change) {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');
    $this->entity->forceFill(['is_fake_data' => true, 'workspace_settings' => ['bookkeeping_mode' => 'independent_books']])->save();
    app(NativeFakeDataProvisioner::class)->provision($this->entity, $this->user);
    $this->actingAs($this->user)->withSession(['ecopa.app_role' => 'admin'])->withHeader('X-Tenant-Slug', $this->entity->id);
    $preview = $this->getJson('/api/v1/spa/fake-data/reset-preview')->assertOk();
    $draft = Journal::where('entity_id', $this->entity->id)->where('status', 'draft')->firstOrFail();
    if ($change === 'deleted') {
        $draft->delete();
    } else {
        $draft->update(['memo' => 'Aktivitas setelah ringkasan pemulihan']);
    }
    $this->postJson('/api/v1/spa/fake-data/reset', [
        'confirmation' => 'RESET DEMO 2026', 'expected_version' => NativeFakeDataProvisioner::DATASET_VERSION,
        'preview_token' => $preview->json('data.preview_token'),
    ])->assertConflict();
    expect(AuditLog::where('action', 'fake_data.dataset_reset')->exists())->toBeFalse();
})->with(['edited', 'deleted']);

it('preserves foreign records even when corrupt demo markers refer to them during restore', function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');
    $this->entity->forceFill(['is_fake_data' => true, 'workspace_settings' => ['bookkeeping_mode' => 'independent_books']])->save();
    app(NativeFakeDataProvisioner::class)->provision($this->entity, $this->user);
    $foreign = Entity::create(['tenant_id' => $this->entity->tenant_id, 'name' => 'Foreign restore fixture']);
    $parent = Account::create(['entity_id' => $foreign->id, 'code' => '1000', 'name' => 'Parent', 'type' => 'asset', 'normal_balance' => 'debit']);
    $child = Account::create(['entity_id' => $foreign->id, 'code' => '1001', 'name' => 'Child', 'type' => 'asset', 'normal_balance' => 'debit', 'parent_account_id' => $parent->id]);
    $foreignPeriod = Period::create(['entity_id' => $foreign->id, 'name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
    $foreignJournal = Journal::create(['entity_id' => $foreign->id, 'period_id' => $foreignPeriod->id, 'number' => 'FOREIGN-RESTORE', 'date' => '2026-10-01', 'memo' => 'Do not change']);
    $foreignJournal->update(['source_id' => $foreignJournal->id]);
    foreach ([$child, $foreignJournal] as $foreignModel) {
        FakeDataRecord::create(['entity_id' => $this->entity->id, 'group_key' => 'native_fake_entity', 'model_type' => $foreignModel::class, 'model_id' => $foreignModel->id]);
    }
    $this->actingAs($this->user)->withSession(['ecopa.app_role' => 'admin'])->withHeader('X-Tenant-Slug', $this->entity->id);
    $preview = $this->getJson('/api/v1/spa/fake-data/reset-preview')->assertOk();
    $this->postJson('/api/v1/spa/fake-data/reset', [
        'confirmation' => 'RESET DEMO 2026', 'expected_version' => NativeFakeDataProvisioner::DATASET_VERSION,
        'preview_token' => $preview->json('data.preview_token'),
    ])->assertOk();
    expect($child->refresh()->parent_account_id)->toBe($parent->id)
        ->and($foreignJournal->refresh()->source_id)->toBe($foreignJournal->id)
        ->and($foreignJournal->memo)->toBe('Do not change');
});

it('limits native restore to authorized users in the demo workspace', function (string $access, int $status) {
    if ($access !== 'normal') {
        $this->entity->update(['is_fake_data' => true]);
    }
    $this->actingAs($this->user)->withHeader('X-Tenant-Slug', $this->entity->id);
    if ($access !== 'denied') {
        $this->withSession(['ecopa.app_role' => 'admin']);
    }
    if ($access === 'inspector') {
        $this->user->assignments()->delete();
        $role = Role::create(['code' => 'inspector', 'name' => 'Inspector', 'is_preset' => true]);
        $this->user->assignments()->create(['entity_id' => $this->entity->id, 'app_id' => RbacApp::where('code', 'accounting')->sole()->id, 'role_id' => $role->id]);
    }
    $this->getJson('/api/v1/spa/fake-data/reset-preview')->assertStatus($status);
    $this->postJson('/api/v1/spa/fake-data/reset', [
        'confirmation' => 'RESET DEMO 2026', 'expected_version' => NativeFakeDataProvisioner::DATASET_VERSION,
        'preview_token' => str_repeat('a', 64),
    ])->assertStatus($status);
    expect(AuditLog::where('action', 'fake_data.dataset_reset')->exists())->toBeFalse();
})->with([['normal', 409], ['denied', 403], ['inspector', 403]]);

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

it('rejects stale reset previews before changing sandbox data', function () {
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
});
