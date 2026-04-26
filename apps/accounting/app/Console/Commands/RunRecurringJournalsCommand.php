<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\RunRecurringJournalAction;
use App\Models\RecurringJournal;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class RunRecurringJournalsCommand extends Command
{
    protected $signature = 'accounting:run-recurring
        {--date= : Override "today" (YYYY-MM-DD), useful for backfill}
        {--entity= : Restrict to a single entity_id}
        {--dry-run : List due schedules without executing}';

    protected $description = 'Instantiate journals for due RecurringJournal schedules.';

    public function handle(RunRecurringJournalAction $runner): int
    {
        $today = $this->option('date') ?? Carbon::today()->toDateString();
        $dryRun = (bool) $this->option('dry-run');

        $q = RecurringJournal::query()
            ->where('status', RecurringJournal::STATUS_ACTIVE)
            ->whereDate('next_run_at', '<=', $today);

        if ($entity = $this->option('entity')) {
            $q->where('entity_id', $entity);
        }

        $due = $q->orderBy('next_run_at')->get();

        if ($due->isEmpty()) {
            $this->info("No recurring journals due on {$today}.");

            return self::SUCCESS;
        }

        $this->info("{$due->count()} recurring schedule(s) due on {$today}.");

        $runs = 0;
        foreach ($due as $rec) {
            $line = sprintf(
                '[%s] %s · %s · next=%s',
                $rec->id,
                $rec->name,
                $rec->frequency,
                $rec->next_run_at?->toDateString(),
            );

            if ($dryRun) {
                $this->line('  DRY '.$line);

                continue;
            }

            try {
                $journal = $runner->execute($rec, $today);
                if ($journal === null) {
                    $this->warn('  SKIP '.$line);

                    continue;
                }
                $this->line(sprintf('  RUN  %s → %s', $line, $journal->number));
                $runs++;
            } catch (\Throwable $e) {
                $this->error('  FAIL '.$line.' :: '.$e->getMessage());
            }
        }

        $this->info("Done. {$runs} journal(s) created.");

        return self::SUCCESS;
    }
}
