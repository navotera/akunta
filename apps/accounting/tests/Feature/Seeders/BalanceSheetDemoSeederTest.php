<?php

declare(strict_types=1);

use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Tenant;
use App\Models\Account;
use App\Models\Journal;
use App\Models\Period;
use App\Services\Reporting\BalanceSheetService;
use Database\Seeders\BalanceSheetDemoSeeder;

beforeEach(function () {
    $tenant = Tenant::create(['name' => 'Balance Sheet Demo Tenant', 'slug' => 'balance-sheet-demo-'.uniqid()]);
    $this->entity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'Balance Sheet Demo Entity']);
    Period::create([
        'entity_id' => $this->entity->id,
        'name' => now()->translatedFormat('F Y'),
        'start_date' => now()->startOfMonth()->toDateString(),
        'end_date' => now()->endOfMonth()->toDateString(),
        'status' => Period::STATUS_OPEN,
    ]);

    foreach ([
        ['1101', 'Kas', 'asset', 'debit'], ['1102', 'Bank', 'asset', 'debit'],
        ['1103', 'Piutang Usaha', 'asset', 'debit'], ['1104', 'Persediaan', 'asset', 'debit'],
        ['1105', 'PPN Masukan', 'asset', 'debit'], ['1204', 'Peralatan', 'asset', 'debit'],
        ['2101', 'Hutang Usaha', 'liability', 'credit'], ['2103', 'PPN Keluaran', 'liability', 'credit'],
        ['2201', 'Hutang Bank', 'liability', 'credit'], ['3101', 'Modal Pemilik', 'equity', 'credit'],
        ['4101', 'Penjualan', 'revenue', 'credit'],
    ] as [$code, $name, $type, $normalBalance]) {
        Account::create([
            'entity_id' => $this->entity->id,
            'code' => $code,
            'name' => $name,
            'type' => $type,
            'normal_balance' => $normalBalance,
            'is_postable' => true,
            'is_active' => true,
            'availability' => Account::AVAILABILITY_INTERN,
        ]);
    }
});

it('seeds representative internal and fiscal balance sheet rows', function () {
    app(BalanceSheetDemoSeeder::class)->run(app(\App\Services\JournalNumberGenerator::class));

    expect(Journal::where('entity_id', $this->entity->id)->where('status', Journal::STATUS_POSTED)->count())
        ->toBe(14);

    $report = app(BalanceSheetService::class)->compute($this->entity->id, today()->toDateString());
    expect($report['assets']['lines'])->toHaveCount(6)
        ->and($report['liabilities']['lines'])->toHaveCount(3)
        ->and($report['equity']['lines'])->toHaveCount(1)
        ->and($report['balanced'])->toBeTrue();
});

it('is idempotent for the current day', function () {
    $seeder = app(BalanceSheetDemoSeeder::class);
    $generator = app(\App\Services\JournalNumberGenerator::class);
    $seeder->run($generator);
    $seeder->run($generator);

    expect(Journal::where('entity_id', $this->entity->id)->count())->toBe(14);
});
