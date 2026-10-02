<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Spa;

use App\Http\Controllers\Api\Spa\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\CronRunLog;
use App\Services\SchedulerStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class SchedulerHealthController extends Controller
{
    use ResolvesTenant;

    /** @var array<int, array{key: string, command: string, label: string, schedule: string, hour: int, minute: int}> */
    private const TASKS = [
        [
            'key' => 'prune_trashed_journals',
            'command' => 'accounting:prune-trashed-journals',
            'label' => 'Hapus permanen jurnal Trashed',
            'schedule' => 'Setiap hari pukul 00:25',
            'hour' => 0,
            'minute' => 25,
        ],
        [
            'key' => 'run_recurring_journals',
            'command' => 'accounting:run-recurring',
            'label' => 'Jurnal berulang',
            'schedule' => 'Setiap hari pukul 00:05',
            'hour' => 0,
            'minute' => 5,
        ],
        [
            'key' => 'run_auto_reversals',
            'command' => 'accounting:run-auto-reversals',
            'label' => 'Auto-reversal jurnal',
            'schedule' => 'Setiap hari pukul 00:10',
            'hour' => 0,
            'minute' => 10,
        ],
    ];

    public function index(Request $request, SchedulerStatus $schedulerStatus): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        abort_unless(
            session('ecopa.app_role') === 'admin'
                || $request->user()?->hasPermission('settings.cron.manage', $entity->id),
            403,
            'Anda tidak memiliki akses untuk melihat status scheduler.',
        );

        $logs = CronRunLog::query()
            ->latest('started_at')
            ->limit(100)
            ->get();
        $now = now();

        return response()->json([
            'data' => [
                'scheduler' => [
                    ...$schedulerStatus->status(),
                    'working_directory' => base_path(),
                    'cron_command' => $this->cronCommand(),
                ],
                'tasks' => collect(self::TASKS)
                    ->map(fn (array $task): array => $this->taskStatus($task, $now))
                    ->values()
                    ->all(),
                'recent_runs' => $logs->take(20)
                    ->map(fn (CronRunLog $log): array => $this->runPayload($log))
                    ->values()
                    ->all(),
                'checked_at' => $now->toIso8601String(),
            ],
        ]);
    }

    private function cronCommand(): string
    {
        return "* * * * * cd '".str_replace("'", "'\\''", base_path())."' && php artisan schedule:run >> /dev/null 2>&1";
    }

    /**
     * @param  array{key: string, command: string, label: string, schedule: string, hour: int, minute: int}  $task
     * @return array<string, mixed>
     */
    private function taskStatus(array $task, Carbon $now): array
    {
        $taskLogs = CronRunLog::query()
            ->where('command', 'like', '%'.$task['command'].'%')
            ->latest('started_at')
            ->limit(50)
            ->get();
        $latest = $taskLogs->first();
        $lastSuccess = $taskLogs->first(
            fn (CronRunLog $log): bool => ! $log->failed && $log->finished_at !== null,
        );
        $lastFailure = $taskLogs->first(fn (CronRunLog $log): bool => $log->failed);
        $expectedAt = $now->copy()->setTime($task['hour'], $task['minute']);
        if ($now->lt($expectedAt)) {
            $expectedAt->subDay();
        }

        $status = 'never';
        if ($latest?->finished_at === null && $latest !== null) {
            $status = 'running';
        } elseif ($latest?->failed === true) {
            $status = 'failed';
        } elseif ($lastSuccess === null || $lastSuccess->finished_at?->lt($expectedAt)) {
            $status = 'overdue';
        } elseif ($latest !== null) {
            $status = 'healthy';
        }

        return [
            'key' => $task['key'],
            'command' => $task['command'],
            'label' => $task['label'],
            'schedule' => $task['schedule'],
            'status' => $status,
            'expected_since' => $expectedAt->toIso8601String(),
            'last_run' => $latest ? $this->runPayload($latest) : null,
            'last_success' => $lastSuccess ? $this->runPayload($lastSuccess) : null,
            'last_failure' => $lastFailure ? $this->runPayload($lastFailure) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function runPayload(CronRunLog $log): array
    {
        return [
            'id' => $log->id,
            'command' => $log->command,
            'started_at' => $log->started_at?->toIso8601String(),
            'finished_at' => $log->finished_at?->toIso8601String(),
            'duration_ms' => $log->duration_ms,
            'exit_code' => $log->exit_code,
            'failed' => (bool) $log->failed,
            'output' => $log->output,
            'exception' => $log->exception,
        ];
    }
}
