<?php

declare(strict_types=1);

use App\Actions\PostJournalAction;
use App\Services\AutoMappingEngine;

it('creates the same structure hash regardless of key order', function () {
    $engine = new AutoMappingEngine(Mockery::mock(PostJournalAction::class));

    expect($engine->structureHash(['amount' => 10, 'customer' => ['code' => 'C1'], 'date' => '2026-01-01']))
        ->toBe($engine->structureHash(['date' => '2026-01-01', 'customer' => ['code' => 'C1'], 'amount' => 10]));
});

it('includes nested JSON paths in the pattern', function () {
    $engine = new AutoMappingEngine(Mockery::mock(PostJournalAction::class));

    expect($engine->structureHash(['transaction' => ['amount' => 10]]))
        ->not->toBe($engine->structureHash(['transaction' => ['total' => 10]]));
});
