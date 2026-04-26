<?php

declare(strict_types=1);

namespace App\Services;

use App\Console\Commands\SchedulerHeartbeatCommand;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Reads the heartbeat cache key written by `accounting:scheduler-heartbeat`
 * (scheduled `everyMinute()`) to determine whether the OS-level cron is
 * actually invoking `php artisan schedule:run`.
 */
class SchedulerStatus
{
    /** Maximum tolerable gap (seconds) between heartbeats before alerting. */
    public const STALE_THRESHOLD_SECONDS = 180;

    public function lastHeartbeat(): ?Carbon
    {
        $value = Cache::get(SchedulerHeartbeatCommand::CACHE_KEY);

        if ($value === null) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    public function isHealthy(): bool
    {
        $last = $this->lastHeartbeat();

        return $last !== null
            && $last->diffInSeconds(now()) <= self::STALE_THRESHOLD_SECONDS;
    }

    /** @return array{healthy: bool, last: ?string, age_seconds: ?int, threshold_seconds: int} */
    public function status(): array
    {
        $last = $this->lastHeartbeat();
        $age = $last ? (int) abs($last->diffInSeconds(now())) : null;

        return [
            'healthy'           => $this->isHealthy(),
            'last'              => $last?->toIso8601String(),
            'age_seconds'       => $age,
            'threshold_seconds' => self::STALE_THRESHOLD_SECONDS,
        ];
    }
}
