<?php

declare(strict_types=1);

use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Tenant;
use App\Models\Account;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\Period;
use Database\Seeders\JournalVolumeSeeder;

beforeEach(function () {
    $tenant = Tenant::create(['name' => 'Volume Seeder Tenant', 'slug' => 'volume-seeder-'.uniqid()]);
    $this->entity = Entity::create(['tenant_id' => $tenant->id, 'name' => 'Volume Seeder Entity']);
    Period::create([
        'entity_id' => $this->entity->id,
        'name' => (string) now()->year,
        'start_date' => now()->startOfYear()->toDateString(),
        'end_date' => now()->endOfYear()->toDateString(),
    ]);

    foreach ([
        ['code' => '1101', 'name' => 'Kas', 'type' => 'asset'],
        ['code' => '2101', 'name' => 'Hutang Usaha', 'type' => 'liability'],
        ['code' => '4101', 'name' => 'Penjualan', 'type' => 'revenue'],
        ['code' => '6101', 'name' => 'Biaya Gaji', 'type' => 'expense'],
    ] as $data) {
        Account::create(array_merge($data, [
            'entity_id' => $this->entity->id,
            'normal_balance' => in_array($data['type'], ['asset', 'expense'], true) ? 'debit' : 'credit',
            'is_postable' => true,
            'is_active' => true,
            'availability' => Account::AVAILABILITY_INTERN,
        ]));
    }
});

it('seeds balanced fiscal and internal journals for the current year', function () {
    app(JournalVolumeSeeder::class)->run();

    $journals = Journal::where('entity_id', $this->entity->id)->get();

    expect($journals)->toHaveCount(5000)
        ->and($journals->where('journal_mode', Journal::MODE_FISCAL))->toHaveCount(2500)
        ->and($journals->where('journal_mode', Journal::MODE_INTERNAL))->toHaveCount(2500);

    foreach ($journals->random(20) as $journal) {
        expect($journal->status)->toBe(Journal::STATUS_POSTED)
            ->and($journal->date->year)->toBe(now()->year)
            ->and($journal->entries()->sum('debit'))->toBe($journal->entries()->sum('credit'))
            ->and($journal->number)->toStartWith($journal->journal_mode === Journal::MODE_FISCAL ? 'JF-' : 'JI-');
    }
});

it('is idempotent', function () {
    $seeder = app(JournalVolumeSeeder::class);
    $seeder->run();
    $seeder->run();

    expect(Journal::where('entity_id', $this->entity->id)->count())->toBe(5000)
        ->and(JournalEntry::whereIn('journal_id', Journal::where('entity_id', $this->entity->id)->pluck('id'))->count())->toBe(10000);
});
