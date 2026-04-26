<?php

declare(strict_types=1);

use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Tenant;
use App\Models\Account;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\Partner;
use App\Models\Period;
use App\Models\TaxCode;
use App\Services\EfakturCsvExporter;
use App\Services\Reporting\TaxReportService;

beforeEach(function () {
    $tenant = Tenant::create(['name' => 'PT', 'slug' => 'tx-'.uniqid()]);
    $this->entity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'X']);
    $this->period = Period::create([
        'entity_id' => $this->entity->id, 'name' => 'Apr 2026',
        'start_date' => '2026-04-01', 'end_date' => '2026-04-30',
    ]);

    $this->ar = Account::create([
        'entity_id' => $this->entity->id, 'code' => '1201', 'name' => 'Piutang',
        'type' => 'asset', 'normal_balance' => 'debit', 'is_postable' => true,
    ]);
    $this->rev = Account::create([
        'entity_id' => $this->entity->id, 'code' => '4101', 'name' => 'Penjualan',
        'type' => 'revenue', 'normal_balance' => 'credit', 'is_postable' => true,
    ]);
    $this->vatPayable = Account::create([
        'entity_id' => $this->entity->id, 'code' => '2102', 'name' => 'Hutang PPN Keluaran',
        'type' => 'liability', 'normal_balance' => 'credit', 'is_postable' => true,
    ]);

    $this->vatOut = TaxCode::create([
        'entity_id' => $this->entity->id, 'code' => 'PPN-OUT-11',
        'name' => 'PPN Keluaran 11%', 'kind' => TaxCode::KIND_OUTPUT_VAT,
        'rate' => '11.0000', 'tax_account_id' => $this->vatPayable->id,
    ]);

    $this->customer = Partner::create([
        'entity_id' => $this->entity->id, 'type' => Partner::TYPE_CUSTOMER,
        'code' => 'C-01', 'name' => 'PT Pembeli',
        'npwp' => '01.234.567.8-901.000', 'address' => 'Jl. Contoh No.1, Jakarta',
    ]);
});

function postSalesWithVat($entity, $period, $ar, $rev, $vatPayable, $vatOut, $customer, string $number, string $date, string $base, string $vat): void
{
    $j = Journal::create([
        'entity_id' => $entity->id, 'period_id' => $period->id,
        'type' => 'general', 'number' => $number, 'date' => $date,
        'reference' => $number, 'memo' => 'Sales',
        'status' => 'posted', 'posted_at' => now(),
    ]);
    $total = bcadd($base, $vat, 2);
    JournalEntry::create([
        'journal_id' => $j->id, 'line_no' => 1,
        'account_id' => $ar->id, 'partner_id' => $customer->id,
        'debit' => $total,
    ]);
    JournalEntry::create([
        'journal_id'  => $j->id, 'line_no' => 2,
        'account_id'  => $rev->id, 'partner_id' => $customer->id,
        'tax_code_id' => $vatOut->id, 'tax_base' => $base,
        'credit' => $base,
    ]);
    JournalEntry::create([
        'journal_id'  => $j->id, 'line_no' => 3,
        'account_id'  => $vatPayable->id, 'partner_id' => $customer->id,
        'tax_code_id' => $vatOut->id,
        'credit' => $vat,
    ]);
}

it('aggregates output VAT transactions per period', function () {
    postSalesWithVat($this->entity, $this->period, $this->ar, $this->rev,
        $this->vatPayable, $this->vatOut, $this->customer, 'INV-1', '2026-04-05', '1000000', '110000');
    postSalesWithVat($this->entity, $this->period, $this->ar, $this->rev,
        $this->vatPayable, $this->vatOut, $this->customer, 'INV-2', '2026-04-15', '500000', '55000');

    $r = app(TaxReportService::class)->compute(
        $this->entity->id, '2026-04-01', '2026-04-30', TaxCode::KIND_OUTPUT_VAT
    );

    // Each invoice contributes 2 tax-tagged lines (revenue with tax_base, VAT line)
    expect($r['rows']->count())->toBe(4)
        // Total tax = 110k + 55k = 165k. Each VAT line carries credit = tax amount;
        // tax_base lines also count their credit as "tax_amount" in service (carries 0 since
        // base side ledger amount = base, not tax). Check totals are consistent.
        ->and($r['totals']['base'])->toBe('1500000.00');
});

it('filters tax report by kind', function () {
    postSalesWithVat($this->entity, $this->period, $this->ar, $this->rev,
        $this->vatPayable, $this->vatOut, $this->customer, 'INV-A', '2026-04-05', '200000', '22000');

    $vatIn = TaxCode::create([
        'entity_id' => $this->entity->id, 'code' => 'PPN-IN-11',
        'name' => 'PPN Masukan 11%', 'kind' => TaxCode::KIND_INPUT_VAT, 'rate' => 11,
    ]);
    // No input-vat transactions posted

    $out = app(TaxReportService::class)->compute(
        $this->entity->id, '2026-04-01', '2026-04-30', TaxCode::KIND_OUTPUT_VAT
    );
    $in = app(TaxReportService::class)->compute(
        $this->entity->id, '2026-04-01', '2026-04-30', TaxCode::KIND_INPUT_VAT
    );

    expect($out['rows']->count())->toBeGreaterThan(0)
        ->and($in['rows']->count())->toBe(0);
});

it('exports e-Faktur CSV with header row + faktur rows for output VAT', function () {
    postSalesWithVat($this->entity, $this->period, $this->ar, $this->rev,
        $this->vatPayable, $this->vatOut, $this->customer, 'INV-EFK-1', '2026-04-10', '1000000', '110000');

    $payload = app(EfakturCsvExporter::class)->exportOutputVat(
        $this->entity->id, '2026-04-01', '2026-04-30'
    );

    expect($payload['filename'])->toBe('efaktur-keluaran-2026-04-01-2026-04-30.csv');
    $lines = array_filter(explode("\n", trim($payload['content'])));
    expect($lines[0])->toContain('FK,KD_JENIS_TRANSAKSI')
        ->and($lines[0])->toContain('NPWP,NAMA');
    expect(count($lines))->toBeGreaterThanOrEqual(2);
    // NPWP normalized to digits only
    expect($lines[1])->toContain('012345678901000')
        ->and($lines[1])->toContain('PT Pembeli');
});
