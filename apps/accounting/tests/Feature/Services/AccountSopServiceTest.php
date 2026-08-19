<?php

declare(strict_types=1);

use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Tenant;
use App\Models\Account;
use App\Services\AccountSopService;

beforeEach(function () {
    $tenant = Tenant::create(['name' => 'PT SOP', 'slug' => 'sop-'.uniqid()]);
    $this->entity = Entity::create([
        'tenant_id' => $tenant->id,
        'name' => 'PT SOP Teknologi',
        'workspace_settings' => ['bookkeeping_mode' => 'independent_books'],
    ]);
    $this->service = app(AccountSopService::class);
});

it('fills curated SOP descriptions and reviews independent-book availability', function () {
    $cash = Account::create([
        'entity_id' => $this->entity->id,
        'code' => '1101',
        'name' => 'Kas',
        'type' => 'asset',
        'normal_balance' => 'debit',
        'availability' => Account::AVAILABILITY_INTERN,
    ]);
    $commercialDepreciation = Account::create([
        'entity_id' => $this->entity->id,
        'code' => '6105',
        'name' => 'Biaya Penyusutan',
        'type' => 'expense',
        'normal_balance' => 'debit',
        'availability' => Account::AVAILABILITY_BOTH,
    ]);
    $fiscalCash = Account::create([
        'entity_id' => $this->entity->id,
        'code' => '11012',
        'name' => 'Kas Kecil Fiskal',
        'type' => 'asset',
        'normal_balance' => 'debit',
        'availability' => Account::AVAILABILITY_INTERN,
    ]);

    $result = $this->service->backfill($this->entity->id, true);

    expect($result['unresolved'])->toBe([])
        ->and($result['descriptions_updated'])->toBe(3)
        ->and($result['availability_updated'])->toBe(3)
        ->and($cash->refresh()->availability)->toBe(Account::AVAILABILITY_BOTH)
        ->and($cash->description)->not->toStartWith('Definisi:')
        ->and($cash->description)->toContain('Digunakan', "\n\nContoh:")
        ->and($commercialDepreciation->refresh()->availability)->toBe(Account::AVAILABILITY_INTERN)
        ->and($fiscalCash->refresh()->availability)->toBe(Account::AVAILABILITY_FISKAL);
});

it('keeps all accounts Intern in an Intern-only workspace', function () {
    $this->entity->update(['workspace_settings' => ['bookkeeping_mode' => 'internal_only']]);
    $cash = Account::create([
        'entity_id' => $this->entity->id,
        'code' => '1101',
        'name' => 'Kas',
        'type' => 'asset',
        'normal_balance' => 'debit',
        'availability' => Account::AVAILABILITY_BOTH,
    ]);

    $this->service->backfill($this->entity->id, true);

    expect($cash->refresh()->availability)->toBe(Account::AVAILABILITY_INTERN)
        ->and($cash->description)->not->toBeNull();
});

it('refuses to mutate any account when one description is unresolved', function () {
    $cash = Account::create([
        'entity_id' => $this->entity->id,
        'code' => '1101',
        'name' => 'Kas',
        'type' => 'asset',
        'normal_balance' => 'debit',
        'availability' => Account::AVAILABILITY_INTERN,
    ]);
    Account::create([
        'entity_id' => $this->entity->id,
        'code' => '9999',
        'name' => 'Akun Tidak Dikenal',
        'type' => 'expense',
        'normal_balance' => 'debit',
        'availability' => Account::AVAILABILITY_INTERN,
    ]);

    $result = $this->service->backfill($this->entity->id, true);

    expect($result['unresolved'])->toHaveCount(1)
        ->and($cash->refresh()->description)->toBeNull()
        ->and($cash->availability)->toBe(Account::AVAILABILITY_INTERN);
});
