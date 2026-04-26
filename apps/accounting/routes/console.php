<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Every minute — heartbeat so the UI can confirm cron + scheduler are wired up.
Schedule::command('accounting:scheduler-heartbeat')
    ->everyMinute()
    ->withoutOverlapping()
    ->name('accounting:scheduler-heartbeat');

// Daily 00:05 — instantiate due recurring journals (rent, salary accrual, depresiasi).
Schedule::command('accounting:run-recurring')
    ->dailyAt('00:05')
    ->withoutOverlapping()
    ->onOneServer()
    ->name('accounting:run-recurring');

// Daily 00:10 — auto-reverse accrual journals whose auto_reverse_on date arrived.
Schedule::command('accounting:run-auto-reversals')
    ->dailyAt('00:10')
    ->withoutOverlapping()
    ->onOneServer()
    ->name('accounting:run-auto-reversals');

// Hourly — drop cron_run_logs older than configured retention window.
Schedule::command('accounting:prune-cron-logs')
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer()
    ->name('accounting:prune-cron-logs');
