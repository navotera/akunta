<?php

declare(strict_types=1);

use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Tenant;
use App\Models\TaxCode;

beforeEach(function () {
    $tenant = Tenant::create(['name' => 'PT', 'slug' => 't-'.uniqid()]);
    $this->entity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'T']);
});

it('computes tax amount on a base using rate', function () {
    $vat = TaxCode::create([
        'entity_id' => $this->entity->id, 'code' => 'PPN-OUT-11',
        'name' => 'PPN Keluaran 11%', 'kind' => TaxCode::KIND_OUTPUT_VAT,
        'rate' => '11.0000',
    ]);

    expect($vat->computeOn('1000000'))->toBe('110000.00')
        ->and($vat->computeOn('123456'))->toBe('13580.16');
});

it('classifies VAT vs PPh kinds correctly', function () {
    $out = TaxCode::create([
        'entity_id' => $this->entity->id, 'code' => 'PPN-OUT-11',
        'name' => 'X', 'kind' => TaxCode::KIND_OUTPUT_VAT, 'rate' => 11,
    ]);
    $in = TaxCode::create([
        'entity_id' => $this->entity->id, 'code' => 'PPN-IN-11',
        'name' => 'X', 'kind' => TaxCode::KIND_INPUT_VAT, 'rate' => 11,
    ]);
    $pph = TaxCode::create([
        'entity_id' => $this->entity->id, 'code' => 'PPH23-2',
        'name' => 'PPh 23 Jasa', 'kind' => TaxCode::KIND_WHT_PPH_23, 'rate' => 2,
    ]);

    expect($out->isOutputVat())->toBeTrue()
        ->and($in->isInputVat())->toBeTrue()
        ->and($out->isVat())->toBeTrue()
        ->and($pph->isVat())->toBeFalse();
});

it('rejects duplicate tax code per entity', function () {
    TaxCode::create([
        'entity_id' => $this->entity->id, 'code' => 'PPN-OUT-11',
        'name' => 'A', 'kind' => TaxCode::KIND_OUTPUT_VAT, 'rate' => 11,
    ]);
    expect(fn () => TaxCode::create([
        'entity_id' => $this->entity->id, 'code' => 'PPN-OUT-11',
        'name' => 'B', 'kind' => TaxCode::KIND_OUTPUT_VAT, 'rate' => 11,
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});
