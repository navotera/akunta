<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CronRunLog;
use App\Models\CronSetting;
use Illuminate\Console\Command;

class PruneCronLogsCommand extends Command
{
    protected $signature = 'accounting:prune-cron-logs';

    protected $description = 'Delete cron_run_logs rows older than the configured retention window.';

    public function handle(): int
    {
        $days = CronSetting::instance()->retention_days;
        $cutoff = now()->subDays($days);

        $deleted = CronRunLog::where('started_at', '<', $cutoff)->delete();

        $this->info("Pruned {$deleted} cron_run_logs older than {$days} days (cutoff {$cutoff->toIso8601String()}).");

        return self::SUCCESS;
    }
}
