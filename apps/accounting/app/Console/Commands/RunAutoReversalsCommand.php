<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\ReverseJournalAction;
use App\Models\Journal;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class RunAutoReversalsCommand extends Command
{
    protected $signature = 'accounting:run-auto-reversals
        {--date= : Override "today" (YYYY-MM-DD)}
        {--dry-run : List candidates without executing}';

    protected $description = 'Reverse journals whose auto_reverse_on date has arrived.';

    public function handle(ReverseJournalAction $reverser): int
    {
        $today = $this->option('date') ?? Carbon::today()->toDateString();
        $dryRun = (bool) $this->option('dry-run');

        $due = Journal::query()
            ->whereNotNull('auto_reverse_on')
            ->whereDate('auto_reverse_on', '<=', $today)
            ->where('status', Journal::STATUS_POSTED)
            ->whereNull('reversed_by_journal_id')
            ->orderBy('auto_reverse_on')
            ->get();

        if ($due->isEmpty()) {
            $this->info("No auto-reverse journals pending on {$today}.");

            return self::SUCCESS;
        }

        $this->info("{$due->count()} journal(s) pending auto-reverse on {$today}.");

        $runs = 0;
        foreach ($due as $j) {
            $line = sprintf('[%s] %s · auto_reverse_on=%s', $j->id, $j->number, $j->auto_reverse_on?->toDateString());

            if ($dryRun) {
                $this->line('  DRY '.$line);

                continue;
            }

            try {
                $reversal = $reverser->execute($j, null, 'Auto-reverse on '.$j->auto_reverse_on?->toDateString());
                $this->line(sprintf('  RUN  %s → %s', $line, $reversal->number));
                $runs++;
            } catch (\Throwable $e) {
                $this->error('  FAIL '.$line.' :: '.$e->getMessage());
            }
        }

        $this->info("Done. {$runs} reversal(s) created.");

        return self::SUCCESS;
    }
}
