<?php

declare(strict_types=1);

use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Tenant;
use App\Models\Account;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\Period;
use App\Services\JournalNumberGenerator;
use Database\Seeders\FiscalJournalSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $tenant = Tenant::create(['name' => 'Fiscal Seeder Tenant', 'slug' => 'fiscal-seeder-'.uniqid()]);
    $this->entity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'Fiscal Seeder Entity']);
    $this->period = Period::create([
        'entity_id' => $this->entity->id,
        'name' => '2026',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
    ]);

    foreach ([
        ['code' => '1101', 'name' => 'Kas', 'type' => 'asset', 'normal_balance' => 'debit'],
        ['code' => '6101', 'name' => 'Beban Gaji', 'type' => 'expense', 'normal_balance' => 'debit'],
        ['code' => '6201', 'name' => 'Beban Sewa', 'type' => 'expense', 'normal_balance' => 'debit'],
        ['code' => '6202', 'name' => 'Beban Listrik', 'type' => 'expense', 'normal_balance' => 'debit'],
        ['code' => '7001', 'name' => 'Beban Non Fiskal', 'type' => 'expense', 'normal_balance' => 'debit', 'is_postable' => false],
    ] as $data) {
        Account::create(array_merge([
            'entity_id' => $this->entity->id,
            'is_postable' => true,
        ], $data));
    }
});

it('marks postable expense accounts and creates posted fiscal journals for every entity', function () {
    app(FiscalJournalSeeder::class)->run(app(JournalNumberGenerator::class));

    expect(Account::where('entity_id', $this->entity->id)->where('type', 'expense')->where('is_postable', true)->where('availability', 'both')->count())
        ->toBe(3)
        ->and(Account::where('entity_id', $this->entity->id)->where('code', '7001')->value('availability'))
        ->toBe('intern');

    $journals = Journal::where('entity_id', $this->entity->id)->get();
    expect($journals)->toHaveCount(6);

    expect($journals->where('journal_mode', Journal::MODE_FISCAL))->toHaveCount(3)
        ->and($journals->where('journal_mode', Journal::MODE_INTERNAL))->toHaveCount(3)
        ->and($journals->pluck('number')->unique())->toHaveCount(6);

    foreach ($journals as $journal) {
        expect($journal->number)->toMatch('/^JU\/'.Carbon::today()->format('y').'\/'.Carbon::today()->format('n').'\/\d+$/')
            ->and($journal->status)->toBe(Journal::STATUS_POSTED)
            ->and($journal->entries()->sum('debit'))->toBe($journal->entries()->sum('credit'));
    }
});

it('is idempotent when run more than once', function () {
    $seeder = app(FiscalJournalSeeder::class);
    $generator = app(JournalNumberGenerator::class);

    $seeder->run($generator);
    $seeder->run($generator);

    expect(Journal::where('entity_id', $this->entity->id)->count())->toBe(6)
        ->and(JournalEntry::whereIn('journal_id', Journal::where('entity_id', $this->entity->id)->pluck('id'))->count())->toBe(12);
});
