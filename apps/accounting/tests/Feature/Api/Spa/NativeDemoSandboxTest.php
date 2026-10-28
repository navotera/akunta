<?php

declare(strict_types=1);

use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\Tenant;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\FakeDataRecord;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\Period;
use App\Models\User;
use App\Services\NativeFakeDataProvisioner;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');
    $tenant = Tenant::create(['name' => 'Sandbox', 'slug' => 'sandbox-'.uniqid()]);
    $this->entity = Entity::create([
        'tenant_id' => $tenant->id, 'name' => 'PT. Fake Data', 'is_fake_data' => true,
        'workspace_settings' => ['bookkeeping_mode' => 'independent_books'],
    ]);
    $this->sandboxApp = RbacApp::firstOrCreate(['code' => 'accounting'], ['name' => 'Accounting', 'version' => '1', 'enabled' => true]);
    $adminRole = Role::firstOrCreate(['code' => 'admin', 'tenant_id' => null], ['name' => 'Admin', 'is_preset' => true]);
    $this->admin = User::create(['name' => 'Sandbox Admin', 'email' => uniqid().'@sandbox.test', 'password_hash' => bcrypt('x')]);
    $this->admin->assignments()->create(['app_id' => $this->sandboxApp->id, 'entity_id' => $this->entity->id, 'role_id' => $adminRole->id]);
    app(NativeFakeDataProvisioner::class)->provision($this->entity, $this->admin);
    $this->actingAs($this->admin)->withHeader('X-Tenant-Slug', $this->entity->id);
});

function sandboxJournalPayload(Journal $journal, array $changes = []): array
{
    return array_merge([
        'journal_mode' => $journal->journal_mode, 'type' => $journal->type,
        'date' => $journal->date->format('Y-m-d'), 'memo' => $journal->memo,
        'reference' => $journal->reference,
        'entries_debit' => $journal->entries()->where('debit', '>', 0)->get()->map(fn ($entry) => [
            'account_id' => $entry->account_id, 'amount' => (string) $entry->debit, 'memo' => $entry->memo,
        ])->all(),
        'entries_credit' => $journal->entries()->where('credit', '>', 0)->get()->map(fn ($entry) => [
            'account_id' => $entry->account_id, 'amount' => (string) $entry->credit, 'memo' => $entry->memo,
        ])->all(),
    ], $changes);
}

function restoreSandboxData($test): void
{
    $preview = $test->getJson('/api/v1/spa/fake-data/reset-preview')->assertOk();
    $test->postJson('/api/v1/spa/fake-data/reset', [
        'confirmation' => 'RESET DEMO 2026', 'expected_version' => NativeFakeDataProvisioner::DATASET_VERSION,
        'preview_token' => $preview->json('data.preview_token'),
    ])->assertOk();
}

it('restores a verified soft deleted demo account while preserving a soft deleted manual account', function () {
    $owned = Account::where('entity_id', $this->entity->id)->whereNull('system_key')->where('is_postable', true)
        ->whereHas('fakeDataRecords', fn ($query) => $query->where('entity_id', $this->entity->id))->firstOrFail();
    $code = $owned->code;
    $manualCount = $this->getJson('/api/v1/spa/fake-data/reset-preview')->assertOk()->json('data.preserved_manual_records.accounts');
    $manual = Account::create(['entity_id' => $this->entity->id, 'code' => 'MANUAL-ARCHIVED', 'name' => 'Manual tetap disimpan', 'type' => 'asset', 'normal_balance' => 'debit']);
    $this->deleteJson('/api/v1/spa/accounts/'.$owned->id)->assertNoContent();
    $this->deleteJson('/api/v1/spa/accounts/'.$manual->id)->assertNoContent();
    $this->assertSoftDeleted('accounts', ['id' => $owned->id]);
    $this->getJson('/api/v1/spa/fake-data/reset-preview')->assertOk()->assertJsonPath('data.preserved_manual_records.accounts', $manualCount + 1);
    restoreSandboxData($this);
    expect(Account::where('entity_id', $this->entity->id)->where('code', $code)->exists())->toBeTrue();
    $this->assertSoftDeleted('accounts', ['id' => $manual->id, 'name' => 'Manual tetap disimpan']);
});

it('allows editing a saved demo journal and restores its original data without changing the other book', function (string $mode) {
    $journal = Journal::where('entity_id', $this->entity->id)->where('journal_mode', $mode)->where('status', 'posted')->firstOrFail();
    $number = $journal->number;
    $original = sandboxJournalPayload($journal);
    $opposite = $mode === 'internal' ? 'fiscal' : 'internal';
    $otherEntries = fn () => JournalEntry::whereHas('journal', fn ($query) => $query->where('entity_id', $this->entity->id)->where('journal_mode', $opposite))
        ->orderBy('id')->get(['id', 'account_id', 'debit', 'credit'])->toArray();
    $beforeOther = $otherEntries();
    $replacement = Account::where('entity_id', $this->entity->id)->where('is_postable', true)->where('is_active', true)
        ->where('availability', 'both')->where('id', '!=', $original['entries_credit'][0]['account_id'])->firstOrFail();
    $edited = $original;
    $edited['memo'] = 'Latihan mengganti Utang Usaha';
    $edited['entries_credit'][0]['account_id'] = $replacement->id;
    $this->patchJson('/api/v1/spa/journals/'.$journal->id, $edited)->assertOk()->assertJsonPath('data.status', 'posted');
    expect(sandboxJournalPayload($journal->fresh())['entries_credit'][0]['account_id'])->toBe($replacement->id)
        ->and($otherEntries())->toBe($beforeOther)
        ->and(FakeDataRecord::where('entity_id', $this->entity->id)->where('model_type', Journal::class)->where('model_id', $journal->id)->exists())->toBeTrue();
    $manual = Journal::create([
        'entity_id' => $this->entity->id, 'period_id' => $journal->period_id, 'date' => '2026-10-01',
        'journal_mode' => $mode, 'number' => 'MANUAL-SANDBOX-'.$mode, 'memo' => 'Latihan tambahan tetap disimpan',
    ]);
    restoreSandboxData($this);
    $restored = Journal::where('entity_id', $this->entity->id)->where('journal_mode', $mode)->where('number', $number)->firstOrFail();
    $restoredPayload = sandboxJournalPayload($restored);
    expect($restoredPayload['memo'])->toBe($original['memo'])
        ->and(array_sum(array_column($restoredPayload['entries_debit'], 'amount')))->toBe(array_sum(array_column($restoredPayload['entries_credit'], 'amount')))
        ->and(Journal::find($manual->id)?->memo)->toBe('Latihan tambahan tetap disimpan');
    $this->getJson('/api/v1/spa/fake-data')->assertOk()->assertJsonPath('data.dataset.immutable_posted_journals', false)
        ->assertJsonPath('data.dataset.immutable_period', false);
})->with(['internal', 'fiscal']);

it('allows normal period management in the demo and restores the owned baseline period', function () {
    $created = $this->postJson('/api/v1/spa/periods', [
        'name' => 'Latihan 2027', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31',
    ])->assertCreated()->json('data.id');
    $this->postJson('/api/v1/spa/periods/'.$created.'/reopen', ['reason' => 'Latihan periode', 'confirmed' => true])->assertOk();
    $this->patchJson('/api/v1/spa/periods/'.$created, [
        'name' => 'Uji periode', 'start_date' => '2027-01-01', 'end_date' => '2027-12-31',
    ])->assertOk();
    $this->postJson('/api/v1/spa/periods/'.$created.'/close')->assertOk();
    $this->postJson('/api/v1/spa/periods/'.$created.'/reopen', ['reason' => 'Latihan periode', 'confirmed' => true])->assertOk();
    $this->deleteJson('/api/v1/spa/periods/'.$created)->assertNoContent();
    $period = Period::where('entity_id', $this->entity->id)->firstOrFail();
    $this->patchJson('/api/v1/spa/periods/'.$period->id, [
        'name' => 'Diubah untuk latihan', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
    ])->assertOk();
    restoreSandboxData($this);
    expect(Period::where('entity_id', $this->entity->id)->whereDate('start_date', '2026-01-01')->firstOrFail()->name)->toBe('Demo 2026');
});

it('allows demo attachment changes and replacing Trashed demo examples through dataset restore', function () {
    $attachment = Attachment::where('entity_id', $this->entity->id)->where('attachable_type', Journal::class)->firstOrFail();
    $journal = Journal::findOrFail($attachment->attachable_id);
    $this->deleteJson('/api/v1/spa/attachments/'.$attachment->id)->assertNoContent();
    $this->post('/api/v1/spa/attachments', [
        'attachable_type' => Journal::class, 'attachable_id' => $journal->id,
        'file' => UploadedFile::fake()->create('latihan.pdf', 10, 'application/pdf'),
    ])->assertCreated();
    $trashed = Journal::where('entity_id', $this->entity->id)->where('status', 'posted')->where('id', '!=', $journal->id)->firstOrFail();
    $number = $trashed->number;
    $this->deleteJson('/api/v1/spa/journals/'.$trashed->id)->assertNoContent();
    restoreSandboxData($this);
    expect(Journal::where('entity_id', $this->entity->id)->where('number', $number)->whereNull('deleted_at')->exists())->toBeTrue();
});

it('preserves operator restrictions and fiscal-only Inspector access inside the demo', function () {
    $operatorRole = Role::firstOrCreate(['code' => 'operator', 'tenant_id' => null], ['name' => 'Operator', 'is_preset' => true]);
    $operator = User::create(['name' => 'Operator Latihan', 'email' => uniqid().'@sandbox.test', 'password_hash' => bcrypt('x')]);
    $operator->assignments()->create(['app_id' => $this->sandboxApp->id, 'entity_id' => $this->entity->id, 'role_id' => $operatorRole->id]);
    $attachment = Attachment::where('entity_id', $this->entity->id)->where('attachable_type', Journal::class)->firstOrFail();
    $journal = Journal::findOrFail($attachment->attachable_id);
    $this->actingAs($operator)->patchJson('/api/v1/spa/journals/'.$journal->id, sandboxJournalPayload($journal))->assertForbidden();
    $this->deleteJson('/api/v1/spa/attachments/'.$attachment->id)->assertForbidden();
    $inspectorRole = Role::firstOrCreate(['code' => 'inspector', 'tenant_id' => null], ['name' => 'Inspector', 'is_preset' => true]);
    $operator->assignments()->first()->update(['role_id' => $inspectorRole->id]);
    $internal = Journal::where('entity_id', $this->entity->id)->where('journal_mode', 'internal')->firstOrFail();
    $fiscal = Journal::where('entity_id', $this->entity->id)->where('journal_mode', 'fiscal')->firstOrFail();
    $this->getJson('/api/v1/spa/journals/'.$internal->id)->assertForbidden();
    $this->getJson('/api/v1/spa/journals/'.$fiscal->id)->assertOk();
    $this->patchJson('/api/v1/spa/journals/'.$fiscal->id, [])->assertForbidden();
});
