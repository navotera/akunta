<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CronRunLog;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event as SchedulerEvent;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Persists every Laravel scheduler invocation into cron_run_logs so the
 * Pengaturan → Cron page can render an activity log. Pairs Starting with
 * Finished/Failed via an in-memory map keyed by mutex name.
 *
 * @internal
 */
class CronLogger
{
    /** @var array<string, string> mutex_name → cron_run_logs.id */
    private array $pending = [];

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(ScheduledTaskStarting::class, [$this, 'onStarting']);
        $events->listen(ScheduledTaskFinished::class, [$this, 'onFinished']);
        $events->listen(ScheduledTaskFailed::class, [$this, 'onFailed']);
    }

    public function onStarting(ScheduledTaskStarting $event): void
    {
        $task = $event->task;

        if (! $this->isLoggable($task)) {
            return;
        }

        $log = CronRunLog::create([
            'command'    => $this->describe($task),
            'mutex_name' => $task->mutexName(),
            'started_at' => now(),
        ]);

        $this->pending[$task->mutexName()] = $log->id;
    }

    public function onFinished(ScheduledTaskFinished $event): void
    {
        $this->finalise($event->task, exitCode: 0, exception: null, runtime: $event->runtime);
    }

    public function onFailed(ScheduledTaskFailed $event): void
    {
        $this->finalise(
            task: $event->task,
            exitCode: 1,
            exception: (string) $event->exception,
            runtime: null,
        );
    }

    private function finalise(SchedulerEvent $task, int $exitCode, ?string $exception, ?float $runtime): void
    {
        if (! $this->isLoggable($task)) {
            return;
        }

        $mutex = $task->mutexName();
        $logId = $this->pending[$mutex] ?? null;

        $log = $logId !== null
            ? CronRunLog::find($logId)
            : null;

        if ($log === null) {
            return;
        }

        $output = $this->captureOutput($task);
        $started = $log->started_at;
        $duration = $runtime !== null
            ? (int) round($runtime * 1000)
            : ($started ? (int) round(now()->getTimestampMs() - $started->getTimestampMs()) : null);

        $log->forceFill([
            'finished_at' => now(),
            'duration_ms' => $duration,
            'exit_code'   => $exitCode,
            'failed'      => $exitCode !== 0 || $exception !== null,
            'output'      => CronRunLog::truncateOutput($output),
            'exception'   => CronRunLog::truncateOutput($exception),
        ])->save();

        unset($this->pending[$mutex]);
    }

    private function isLoggable(SchedulerEvent $task): bool
    {
        // The prune command runs hourly; logging it would balloon the table.
        return ! str_contains((string) $task->command, 'accounting:prune-cron-logs');
    }

    private function describe(SchedulerEvent $task): string
    {
        if (is_string($task->command) && $task->command !== '') {
            return preg_replace('/\s+/', ' ', $task->command) ?? $task->command;
        }

        return $task->description ?? $task->mutexName();
    }

    private function captureOutput(SchedulerEvent $task): ?string
    {
        $path = $task->output ?? null;

        if (! is_string($path) || $path === '' || $path === '/dev/null') {
            return null;
        }

        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $size = filesize($path) ?: 0;
        if ($size === 0) {
            return null;
        }

        $bytes = min($size, CronRunLog::OUTPUT_LIMIT_BYTES);
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        if ($size > $bytes) {
            fseek($handle, $size - $bytes);
        }

        $contents = stream_get_contents($handle, $bytes);
        fclose($handle);

        return $contents === false ? null : $contents;
    }
}
