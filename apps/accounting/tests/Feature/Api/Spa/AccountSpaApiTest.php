<?php

declare(strict_types=1);

use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\User;
use App\Models\Account;
use App\Models\FakeDataRecord;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\Period;
use App\Services\RequiredAccountService;

beforeEach(function () {
    $tenant = Tenant::create(['name' => 'Acc T', 'slug' => 'acc-'.uniqid()]);
    $this->entity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'Acc Co']);
    $this->user = User::create([
        'name' => 'AC',
        'email' => 'ac-'.uniqid().'@example.test',
        'password_hash' => bcrypt('x'),
    ]);
    $app = RbacApp::create(['code' => 'acc-'.uniqid(), 'name' => 'A', 'version' => '0.1', 'enabled' => true]);
    $role = Role::create(['code' => 'acc-r-'.uniqid(), 'name' => 'R', 'is_preset' => false]);
    $this->user->assignments()->create([
        'entity_id' => $this->entity->id, 'app_id' => $app->id, 'role_id' => $role->id,
    ]);
});

it('rejects unauthenticated /api/v1/spa/accounts', function () {
    $this->getJson('/api/v1/spa/accounts')->assertStatus(401);
});

it('creates an account via SPA', function () {
    $res = $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson('/api/v1/spa/accounts', [
            'code' => '1101', 'name' => 'Kas', 'type' => 'asset',
            'description' => "Uang tunai perusahaan. Digunakan saat transaksi tunai.\n\nContoh: pengisian kas kecil.",
            'normal_balance' => 'debit', 'is_postable' => true,
            'availability' => 'both',
            'legal_basis' => 'UU PPh Pasal 6 ayat (1)',
        ]);

    $res->assertCreated()
        ->assertJsonPath('data.code', '1101')
        ->assertJsonPath('data.name', 'Kas')
        ->assertJsonPath('data.description', "Uang tunai perusahaan. Digunakan saat transaksi tunai.\n\nContoh: pengisian kas kecil.")
        ->assertJsonPath('data.legal_basis', 'UU PPh Pasal 6 ayat (1)')
        ->assertJsonPath('data.is_fake', false);
    $created = Account::where('code', '1101')->firstOrFail();
    expect($created->availability)->toBe('both')
        ->and($created->legal_basis)->toBe('UU PPh Pasal 6 ayat (1)')
        ->and($created->description)->toContain('Contoh: pengisian kas kecil.');
    $res->assertJsonPath('data.availability', 'both');
});

it('exposes fake account provenance in the account list', function () {
    $fake = Account::create([
        'entity_id' => $this->entity->id, 'code' => '1109', 'name' => 'Akun Demo',
        'type' => 'asset', 'normal_balance' => 'debit', 'is_postable' => true,
    ]);
    FakeDataRecord::create([
        'entity_id' => $this->entity->id,
        'group_key' => 'accounts',
        'model_type' => Account::class,
        'model_id' => $fake->id,
    ]);

    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/accounts?postable_only=0')
        ->assertOk()
        ->assertJsonPath('data.0.id', $fake->id)
        ->assertJsonPath('data.0.is_fake', true);
});

it('updates an existing account', function () {
    $account = Account::create([
        'entity_id' => $this->entity->id, 'code' => '1102', 'name' => 'Bank',
        'type' => 'asset', 'normal_balance' => 'debit', 'is_postable' => true,
    ]);

    expect($account->refresh()->availability)->toBe('intern');

    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->patchJson("/api/v1/spa/accounts/{$account->id}", [
            'code' => '1102', 'name' => 'Bank Mandiri',
            'description' => 'Rekening bank operasional utama.',
            'type' => 'asset', 'normal_balance' => 'debit',
            'availability' => 'fiskal',
            'legal_basis' => 'UU PPh Pasal 6 ayat (1)',
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Bank Mandiri')
        ->assertJsonPath('data.description', 'Rekening bank operasional utama.')
        ->assertJsonPath('data.legal_basis', 'UU PPh Pasal 6 ayat (1)')
        ->assertJsonPath('data.availability', 'fiskal');
    expect($account->refresh()->availability)->toBe('fiskal')
        ->and($account->legal_basis)->toBe('UU PPh Pasal 6 ayat (1)')
        ->and($account->description)->toBe('Rekening bank operasional utama.');
});

it('requires a legal basis for fiscal account availability', function (string $availability) {
    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson('/api/v1/spa/accounts', [
            'code' => '1103', 'name' => 'Akun Pajak', 'type' => 'asset',
            'normal_balance' => 'debit', 'availability' => $availability,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('legal_basis');
})->with(['fiskal', 'both']);

it('keeps parent accounts non-postable', function () {
    $parent = Account::create([
        'entity_id' => $this->entity->id, 'code' => '1000', 'name' => 'Aktiva',
        'type' => 'asset', 'normal_balance' => 'debit', 'is_postable' => false,
    ]);
    Account::create([
        'entity_id' => $this->entity->id, 'code' => '1101', 'name' => 'Kas',
        'type' => 'asset', 'normal_balance' => 'debit', 'is_postable' => true,
        'parent_account_id' => $parent->id,
    ]);

    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->patchJson("/api/v1/spa/accounts/{$parent->id}", [
            'code' => '1000', 'name' => 'Aktiva', 'type' => 'asset',
            'normal_balance' => 'debit', 'is_postable' => true,
        ])
        ->assertOk()
        ->assertJsonPath('data.is_postable', false);

    expect($parent->refresh()->is_postable)->toBeFalse();
});

it('blocks delete when account has journal entries', function () {
    $period = Period::create([
        'entity_id' => $this->entity->id, 'name' => 'P',
        'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
    ]);
    $account = Account::create([
        'entity_id' => $this->entity->id, 'code' => '4101', 'name' => 'Penjualan',
        'type' => 'revenue', 'normal_balance' => 'credit', 'is_postable' => true,
    ]);
    $journal = Journal::create([
        'entity_id' => $this->entity->id, 'period_id' => $period->id,
        'type' => Journal::TYPE_GENERAL, 'number' => 'J-1', 'date' => '2026-01-05',
        'status' => Journal::STATUS_POSTED, 'posted_at' => now(),
    ]);
    JournalEntry::create([
        'journal_id' => $journal->id, 'line_no' => 1, 'account_id' => $account->id,
        'debit' => 0, 'credit' => 100,
    ]);

    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->deleteJson("/api/v1/spa/accounts/{$account->id}")
        ->assertStatus(422);
});

it('exposes and protects required system accounts', function () {
    $account = Account::create([
        'entity_id' => $this->entity->id,
        'code' => '6998',
        'name' => 'Beban Pajak Penghasilan Kini',
        'type' => 'expense',
        'normal_balance' => 'debit',
        'is_postable' => true,
        'is_active' => true,
        'availability' => Account::AVAILABILITY_INTERN,
    ]);
    $account->forceFill(['system_key' => RequiredAccountService::CURRENT_TAX_EXPENSE])->save();

    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->getJson('/api/v1/spa/accounts?postable_only=0')
        ->assertOk()
        ->assertJsonPath('data.0.system_key', RequiredAccountService::CURRENT_TAX_EXPENSE)
        ->assertJsonPath('data.0.is_system', true);

    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->patchJson("/api/v1/spa/accounts/{$account->id}", [
            'code' => '6998',
            'name' => 'Beban Pajak Penghasilan Kini',
            'description' => 'Deskripsi yang boleh diperbarui.',
            'type' => 'expense',
            'normal_balance' => 'debit',
            'is_postable' => true,
            'is_active' => true,
            'availability' => Account::AVAILABILITY_BOTH,
            'legal_basis' => 'UU Pajak Penghasilan',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('availability');

    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->patchJson("/api/v1/spa/accounts/{$account->id}", [
            'code' => '6998',
            'name' => 'Beban Pajak Penghasilan Kini',
            'description' => 'Deskripsi yang boleh diperbarui.',
            'type' => 'expense',
            'normal_balance' => 'debit',
            'is_postable' => true,
            'is_active' => true,
            'availability' => Account::AVAILABILITY_INTERN,
        ])
        ->assertOk()
        ->assertJsonPath('data.description', 'Deskripsi yang boleh diperbarui.');

    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->deleteJson("/api/v1/spa/accounts/{$account->id}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('account');

    expect($account->refresh()->exists)->toBeTrue()
        ->and($account->availability)->toBe(Account::AVAILABILITY_INTERN)
        ->and($account->description)->toBe('Deskripsi yang boleh diperbarui.');
});

it('rejects duplicate code in same tenant', function () {
    Account::create([
        'entity_id' => $this->entity->id, 'code' => '5101', 'name' => 'X',
        'type' => 'expense', 'normal_balance' => 'debit',
    ]);
    $this->actingAs($this->user)
        ->withHeader('X-Tenant-Slug', $this->entity->id)
        ->postJson('/api/v1/spa/accounts', [
            'code' => '5101', 'name' => 'Y', 'type' => 'expense', 'normal_balance' => 'debit',
        ])
        ->assertStatus(422);
});
