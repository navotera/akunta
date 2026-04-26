<?php

declare(strict_types=1);

use App\Models\CronRunLog;
use App\Models\CronSetting;
use App\Services\CronLogger;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event as SchedulerEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;

function scheduleTask(string $command): SchedulerEvent
{
    return app(Schedule::class)->command($command);
}

it('writes a row when a scheduled task starts and finishes', function () {
    Carbon::setTestNow('2026-04-26 10:00:00');

    $task = scheduleTask('accounting:scheduler-heartbeat');
    $logger = app(CronLogger::class);

    $logger->onStarting(new ScheduledTaskStarting($task));
    expect(CronRunLog::count())->toBe(1);

    Carbon::setTestNow('2026-04-26 10:00:02');
    $logger->onFinished(new ScheduledTaskFinished($task, runtime: 1.234));

    $row = CronRunLog::first();

    expect($row->finished_at)->not->toBeNull()
        ->and($row->failed)->toBeFalse()
        ->and($row->exit_code)->toBe(0)
        ->and($row->duration_ms)->toBe(1234)
        ->and($row->command)->toContain('accounting:scheduler-heartbeat');

    Carbon::setTestNow();
});

it('marks the row failed and stores the exception when a task fails', function () {
    $task = scheduleTask('accounting:run-recurring');
    $logger = app(CronLogger::class);

    $logger->onStarting(new ScheduledTaskStarting($task));
    $logger->onFailed(new ScheduledTaskFailed($task, new RuntimeException('boom')));

    $row = CronRunLog::first();

    expect($row->failed)->toBeTrue()
        ->and($row->exit_code)->toBe(1)
        ->and($row->exception)->toContain('boom');
});

it('skips logging the prune command itself to avoid runaway growth', function () {
    $task = scheduleTask('accounting:prune-cron-logs');
    $logger = app(CronLogger::class);

    $logger->onStarting(new ScheduledTaskStarting($task));
    $logger->onFinished(new ScheduledTaskFinished($task, runtime: 0.1));

    expect(CronRunLog::count())->toBe(0);
});

it('truncates oversized output to the byte limit', function () {
    $blob = str_repeat('x', CronRunLog::OUTPUT_LIMIT_BYTES * 2);

    $result = CronRunLog::truncateOutput($blob);

    expect(strlen($result))->toBeLessThanOrEqual(CronRunLog::OUTPUT_LIMIT_BYTES)
        ->and($result)->toContain('[truncated]');
});

it('exposes a singleton settings row with default 30-day retention', function () {
    $setting = CronSetting::instance();

    expect($setting->id)->toBe(CronSetting::SINGLETON_ID)
        ->and($setting->retention_days)->toBe(30);
});

it('prunes rows older than the retention window', function () {
    Carbon::setTestNow('2026-04-26 12:00:00');

    CronRunLog::create([
        'command'    => 'old-cmd',
        'started_at' => now()->subDays(60),
    ]);
    CronRunLog::create([
        'command'    => 'recent-cmd',
        'started_at' => now()->subDays(5),
    ]);

    test()->artisan('accounting:prune-cron-logs')->assertSuccessful();

    expect(CronRunLog::count())->toBe(1)
        ->and(CronRunLog::first()->command)->toBe('recent-cmd');

    Carbon::setTestNow();
});

it('rejects retention values outside the 30-120 day window', function () {
    $setting = CronSetting::instance();
    $setting->retention_days = 30;
    $setting->save();

    expect(CronSetting::RETENTION_MIN)->toBe(30)
        ->and(CronSetting::RETENTION_MAX)->toBe(120);
});
