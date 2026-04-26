<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SchedulerHeartbeatCommand extends Command
{
    protected $signature = 'accounting:scheduler-heartbeat';

    protected $description = 'Write a timestamp into cache so the UI can detect that Laravel scheduler is running.';

    public const CACHE_KEY = 'akunta:scheduler:last_heartbeat';

    public function handle(): int
    {
        Cache::put(self::CACHE_KEY, now()->toIso8601String(), now()->addDays(7));
        $this->info('Heartbeat written: '.now()->toIso8601String());

        return self::SUCCESS;
    }
}
