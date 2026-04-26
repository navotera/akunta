<?php

declare(strict_types=1);

use App\Console\Commands\SchedulerHeartbeatCommand;
use App\Services\SchedulerStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::forget(SchedulerHeartbeatCommand::CACHE_KEY);
});

it('reports unhealthy when no heartbeat exists', function () {
    $s = app(SchedulerStatus::class)->status();
    expect($s['healthy'])->toBeFalse()
        ->and($s['last'])->toBeNull()
        ->and($s['age_seconds'])->toBeNull();
});

it('writes a heartbeat via the artisan command', function () {
    Carbon::setTestNow('2026-04-26 12:00:00');

    $this->artisan('accounting:scheduler-heartbeat')->assertSuccessful();

    $s = app(SchedulerStatus::class)->status();
    expect($s['healthy'])->toBeTrue()
        ->and($s['last'])->not->toBeNull()
        ->and($s['age_seconds'])->toBe(0);

    Carbon::setTestNow();
});

it('flips to unhealthy once the heartbeat is older than the threshold', function () {
    Carbon::setTestNow('2026-04-26 12:00:00');
    $this->artisan('accounting:scheduler-heartbeat')->assertSuccessful();

    // Advance past threshold (180s)
    Carbon::setTestNow(Carbon::parse('2026-04-26 12:05:00'));

    $s = app(SchedulerStatus::class)->status();
    expect($s['healthy'])->toBeFalse()
        ->and($s['age_seconds'])->toBeGreaterThan(SchedulerStatus::STALE_THRESHOLD_SECONDS);

    Carbon::setTestNow();
});
