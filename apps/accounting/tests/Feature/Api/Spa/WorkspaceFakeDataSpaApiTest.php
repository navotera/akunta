<?php

declare(strict_types=1);

use Akunta\Audit\Models\AuditLog;
use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\Tenant;
use App\Jobs\PurgeArchivedWorkspace;
use App\Models\Account;
use App\Models\User;
use App\Services\RequiredAccountService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->tenant = Tenant::create(['name' => 'Workspace Tenant', 'slug' => 'workspace-'.uniqid()]);
    $this->regular = Entity::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'PT. Regular',
        'workspace_code' => 'REGULAR',
        'is_active' => true,
    ]);
    $this->fake = Entity::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'PT. Fake Data',
        'workspace_code' => 'FAKE-DATA',
        'is_active' => true,
        'is_fake_data' => true,
    ]);
    $this->user = User::create([
        'name' => 'Workspace Admin',
        'email' => 'workspace-'.uniqid().'@example.test',
        'password_hash' => bcrypt('secret'),
    ]);
    $app = RbacApp::create(['code' => 'accounting', 'name' => 'Accounting', 'version' => '1.0', 'enabled' => true]);
    $role = Role::create(['code' => 'workspace-admin', 'name' => 'Workspace Admin', 'is_preset' => false]);
    foreach ([$this->regular, $this->fake] as $entity) {
        $this->user->assignments()->create([
            'entity_id' => $entity->id,
            'app_id' => $app->id,
            'role_id' => $role->id,
        ]);
    }
});

it('exposes the fake badge contract and independently deactivates the demo workspace', function () {
    $this->actingAs($this->user)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->withHeader('X-Tenant-Slug', $this->regular->id)
        ->getJson('/api/v1/spa/workspaces')
        ->assertOk()
        ->assertJsonFragment([
            'id' => $this->fake->id,
            'name' => 'PT. Fake Data',
            'is_active' => true,
            'is_fake_data' => true,
        ]);

    $this->actingAs($this->user)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->withHeader('X-Tenant-Slug', $this->regular->id)
        ->patchJson('/api/v1/spa/workspaces/'.$this->fake->id, [
            'name' => 'PT. Fake Data',
            'is_active' => false,
        ])
        ->assertOk()
        ->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.is_fake_data', true);

    $this->actingAs($this->user)
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonFragment([
            'id' => $this->fake->id,
            'is_active' => false,
            'is_fake_data' => true,
            'can_manage_fake_data' => true,
        ]);

    expect($this->regular->refresh()->is_active)->toBeTrue();
});

it('does not allow the last active workspace in a tenant to be disabled', function () {
    $this->fake->update(['is_active' => false]);

    $this->actingAs($this->user)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->withHeader('X-Tenant-Slug', $this->regular->id)
        ->patchJson('/api/v1/spa/workspaces/'.$this->regular->id, [
            'name' => 'PT. Regular',
            'is_active' => false,
        ])
        ->assertUnprocessable();

    expect($this->regular->refresh()->is_active)->toBeTrue();
});

it('persists the issue report redirect URL in workspace settings and auth bootstrap', function () {
    $url = 'https://support.example.test/akunta/issues';

    $this->actingAs($this->user)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->withHeader('X-Tenant-Slug', $this->regular->id)
        ->patchJson('/api/v1/spa/workspaces/'.$this->regular->id, [
            'name' => 'PT. Regular',
            'issue_report_url' => $url,
        ])
        ->assertOk()
        ->assertJsonPath('data.issue_report_url', $url);

    $this->actingAs($this->user)
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonFragment([
            'id' => $this->regular->id,
            'issue_report_url' => $url,
        ]);
});

it('isolates general and number format settings per workspace', function () {
    $other = Entity::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'PT. Scope Lain',
        'is_active' => true,
        'workspace_settings' => [
            'date_format' => 'YYYY-MM-DD',
            'journal_number_formats' => ['general' => 'OTHER/{numbering}'],
            'journal_number_starts' => ['general' => 800],
            'transaction_number_format' => 'OTHER-TRX/{numbering}',
            'transaction_number_start' => 900,
        ],
    ]);

    $this->actingAs($this->user)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->withHeader('X-Tenant-Slug', $this->regular->id)
        ->patchJson('/api/v1/spa/workspaces/'.$this->regular->id, [
            'name' => $this->regular->name,
            'date_format' => 'DD/MM/YYYY',
            'journal_number_formats' => ['general' => 'REG/{numbering}'],
            'journal_number_starts' => ['general' => 100],
            'transaction_number_format' => 'REG-TRX/{numbering}',
            'transaction_number_start' => 200,
        ])
        ->assertOk()
        ->assertJsonPath('data.date_format', 'DD/MM/YYYY')
        ->assertJsonPath('data.journal_number_formats.general', 'REG/{numbering}')
        ->assertJsonPath('data.journal_number_starts.general', 100)
        ->assertJsonPath('data.transaction_number_start', 200);

    expect(data_get($this->regular->refresh()->workspace_settings, 'date_format'))->toBe('DD/MM/YYYY')
        ->and(data_get($this->regular->workspace_settings, 'transaction_number_format'))->toBe('REG-TRX/{numbering}')
        ->and(data_get($other->refresh()->workspace_settings, 'date_format'))->toBe('YYYY-MM-DD')
        ->and(data_get($other->workspace_settings, 'journal_number_formats.general'))->toBe('OTHER/{numbering}')
        ->and(data_get($other->workspace_settings, 'journal_number_starts.general'))->toBe(800)
        ->and(data_get($other->workspace_settings, 'transaction_number_start'))->toBe(900)
        ->and(data_get($other->workspace_settings, 'transaction_number_format'))->toBe('OTHER-TRX/{numbering}');
});

it('creates the required tax accounts with a new workspace', function () {
    $response = $this->actingAs($this->user)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->withHeader('X-Tenant-Slug', $this->regular->id)
        ->postJson('/api/v1/spa/workspaces', [
            'tenant_id' => $this->tenant->id,
            'name' => 'PT. Baru',
            'bookkeeping_mode' => 'independent_books',
        ])
        ->assertCreated();

    $entityId = $response->json('data.id');
    expect(Account::query()->where('entity_id', $entityId)->whereNotNull('system_key')->count())->toBe(4)
        ->and(Account::query()->where('entity_id', $entityId)->where('system_key', RequiredAccountService::PREPAID_TAX)->value('availability'))->toBe('both')
        ->and(Account::query()->where('entity_id', $entityId)->where('system_key', RequiredAccountService::CURRENT_TAX_PAYABLE_PROVISION)->value('availability'))->toBe('intern')
        ->and(Account::query()->where('entity_id', $entityId)->where('system_key', RequiredAccountService::CURRENT_TAX_PAYABLE_DEFINITIVE)->value('availability'))->toBe('both')
        ->and(Account::query()->where('entity_id', $entityId)->where('system_key', RequiredAccountService::CURRENT_TAX_EXPENSE)->value('availability'))->toBe('intern');
});

it('returns the latest workspace activity from the audit trail', function () {
    $activityAt = now()->addDay()->startOfSecond();
    AuditLog::query()->create([
        'actor_user_id' => $this->user->id,
        'action' => 'journal.create',
        'resource_type' => 'Journal',
        'resource_id' => (string) Str::ulid(),
        'entity_id' => $this->regular->id,
        'created_at' => $activityAt,
    ]);

    $this->actingAs($this->user)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->withHeader('X-Tenant-Slug', $this->regular->id)
        ->getJson('/api/v1/spa/workspaces')
        ->assertOk()
        ->assertJsonFragment([
            'id' => $this->regular->id,
            'last_activity_at' => $activityAt->toIso8601String(),
        ]);
});

it('archives an inactive workspace after its exact name is confirmed and records an audit event', function () {
    $inactive = Entity::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'PT. Arsip Lama',
        'is_active' => false,
    ]);

    $this->actingAs($this->user)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->withHeader('X-Tenant-Slug', $this->regular->id)
        ->deleteJson('/api/v1/spa/workspaces/'.$inactive->id, [
            'confirmation_name' => 'PT. Arsip Lama',
        ])
        ->assertOk()
        ->assertJsonPath('message', 'Workspace berhasil diarsipkan.')
        ->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.archived_at', fn ($value): bool => is_string($value));

    $this->assertDatabaseHas('entities', ['id' => $inactive->id, 'is_active' => false]);
    expect($inactive->refresh()->archived_at)->not->toBeNull();
    $this->assertDatabaseHas('audit_log', [
        'action' => 'workspace.archive',
        'resource_id' => $inactive->id,
        'entity_id' => $inactive->id,
    ]);

    $this->actingAs($this->user)
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonMissing(['id' => $inactive->id]);
});

it('restores an archived workspace to the active tab while keeping it inactive', function () {
    $archived = Entity::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'PT. Restore Saya',
        'is_active' => false,
        'archived_at' => now()->subMonth(),
    ]);

    $this->actingAs($this->user)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->withHeader('X-Tenant-Slug', $this->regular->id)
        ->postJson('/api/v1/spa/workspaces/'.$archived->id.'/restore')
        ->assertOk()
        ->assertJsonPath('data.archived_at', null)
        ->assertJsonPath('data.is_active', false);

    expect($archived->refresh()->archived_at)->toBeNull()
        ->and($archived->is_active)->toBeFalse();
    $this->assertDatabaseHas('audit_log', [
        'action' => 'workspace.restore',
        'resource_id' => $archived->id,
    ]);
});

it('rejects deletion while a workspace is active or its confirmation name is wrong', function () {
    $this->actingAs($this->user)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->withHeader('X-Tenant-Slug', $this->regular->id)
        ->deleteJson('/api/v1/spa/workspaces/'.$this->regular->id, [
            'confirmation_name' => $this->regular->name,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Nonaktifkan workspace sebelum menghapusnya.');

    $inactive = Entity::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'PT. Konfirmasi',
        'is_active' => false,
    ]);

    $this->actingAs($this->user)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->withHeader('X-Tenant-Slug', $this->regular->id)
        ->deleteJson('/api/v1/spa/workspaces/'.$inactive->id, [
            'confirmation_name' => 'PT. Nama Salah',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Nama workspace tidak sesuai.');

    expect($inactive->fresh())->not->toBeNull();
});

it('keeps the native fake workspace even when it is inactive', function () {
    $this->fake->update(['is_active' => false]);

    $this->actingAs($this->user)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->withHeader('X-Tenant-Slug', $this->regular->id)
        ->deleteJson('/api/v1/spa/workspaces/'.$this->fake->id, [
            'confirmation_name' => $this->fake->name,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Workspace PT. Fake Data bawaan tidak dapat dihapus.');

    $this->fake->update(['archived_at' => now()]);
    $this->actingAs($this->user)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->withHeader('X-Tenant-Slug', $this->regular->id)
        ->deleteJson('/api/v1/spa/workspaces/'.$this->fake->id.'/permanent', [
            'confirmation_name' => $this->fake->name,
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Workspace PT. Fake Data bawaan tidak dapat dihapus permanen.');

    expect($this->fake->fresh())->not->toBeNull();
});

it('queues only workspace purges whose one-year archive retention has expired', function () {
    Queue::fake();
    $expired = Entity::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'PT. Archive Expired',
        'is_active' => false,
        'archived_at' => now()->subYear()->subDay(),
    ]);
    $recent = Entity::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'PT. Archive Recent',
        'is_active' => false,
        'archived_at' => now()->subMonths(11),
    ]);
    $this->fake->update(['is_active' => false, 'archived_at' => now()->subYears(2)]);

    $this->artisan('accounting:queue-workspace-purges')->assertSuccessful();

    Queue::assertPushed(
        PurgeArchivedWorkspace::class,
        fn (PurgeArchivedWorkspace $job): bool => $job->workspaceId === $expired->id,
    );
    Queue::assertNotPushed(
        PurgeArchivedWorkspace::class,
        fn (PurgeArchivedWorkspace $job): bool => in_array($job->workspaceId, [$recent->id, $this->fake->id], true),
    );
});

it('permanently deletes an expired archive inside the queued background job', function () {
    $expired = Entity::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'PT. Purge Background',
        'is_active' => false,
        'archived_at' => now()->subYear()->subDay(),
    ]);

    app()->call([new PurgeArchivedWorkspace($expired->id), 'handle']);

    $this->assertDatabaseMissing('entities', ['id' => $expired->id]);
    $this->assertDatabaseHas('audit_log', [
        'action' => 'workspace.purge',
        'resource_id' => $expired->id,
    ]);
});

it('queues an explicitly confirmed permanent deletion for a recent archive', function () {
    Queue::fake();
    $archived = Entity::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'PT. Delete Now',
        'is_active' => false,
        'archived_at' => now(),
    ]);

    $this->actingAs($this->user)
        ->withSession(['ecopa.app_role' => 'admin'])
        ->withHeader('X-Tenant-Slug', $this->regular->id)
        ->deleteJson('/api/v1/spa/workspaces/'.$archived->id.'/permanent', [
            'confirmation_name' => $archived->name,
        ])
        ->assertAccepted()
        ->assertJsonPath('message', 'Penghapusan permanen workspace telah masuk antrean background.');

    Queue::assertPushed(
        PurgeArchivedWorkspace::class,
        fn (PurgeArchivedWorkspace $job): bool => $job->workspaceId === $archived->id
            && $job->ignoreRetention,
    );
    $this->assertDatabaseHas('entities', ['id' => $archived->id]);
    $this->assertDatabaseHas('audit_log', [
        'action' => 'workspace.purge_requested',
        'resource_id' => $archived->id,
    ]);
});

it('purges a recent archive only when the queued job explicitly bypasses retention', function () {
    $archived = Entity::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'PT. Forced Queue Purge',
        'is_active' => false,
        'archived_at' => now(),
    ]);

    app()->call([new PurgeArchivedWorkspace($archived->id), 'handle']);
    $this->assertDatabaseHas('entities', ['id' => $archived->id]);

    app()->call([new PurgeArchivedWorkspace($archived->id, ignoreRetention: true), 'handle']);
    $this->assertDatabaseMissing('entities', ['id' => $archived->id]);
});

it('does not purge a workspace that was restored before its queued job runs', function () {
    $restored = Entity::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'PT. Restored Before Queue',
        'is_active' => false,
        'archived_at' => now()->subYear()->subDay(),
    ]);
    $job = new PurgeArchivedWorkspace($restored->id);
    $restored->update(['archived_at' => null]);

    app()->call([$job, 'handle']);

    $this->assertDatabaseHas('entities', ['id' => $restored->id]);
    $this->assertDatabaseMissing('audit_log', [
        'action' => 'workspace.purge',
        'resource_id' => $restored->id,
    ]);
});
