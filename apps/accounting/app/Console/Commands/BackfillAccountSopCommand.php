<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AccountSopService;
use Illuminate\Console\Command;

class BackfillAccountSopCommand extends Command
{
    protected $signature = 'accounting:backfill-account-sop
        {--entity= : Restrict review to one entity ULID}
        {--apply : Persist descriptions and reviewed availability}';

    protected $description = 'Review stored COA accounts and backfill curated SOP descriptions and book availability.';

    public function handle(AccountSopService $service): int
    {
        $apply = (bool) $this->option('apply');
        $result = $service->backfill($this->option('entity') ?: null, $apply);

        $this->info(($apply ? 'APPLY' : 'DRY RUN')." — reviewed {$result['reviewed']} account(s)");
        $this->line("Descriptions to fill: {$result['descriptions_updated']}");
        $this->line("Availability changes: {$result['availability_updated']}");

        foreach ($result['changes'] as $change) {
            $this->line(sprintf(
                '  %s | %s %s | %s -> %s',
                $change['entity'],
                $change['code'],
                $change['name'],
                $change['from'],
                $change['to'],
            ));
        }

        if ($result['unresolved'] !== []) {
            $this->error('No curated SOP description exists for these accounts; no database changes were applied:');
            foreach ($result['unresolved'] as $account) {
                $this->line("  {$account['entity']} | {$account['code']} | {$account['name']}");
            }

            return self::FAILURE;
        }

        if (! $apply) {
            $this->warn('Dry run only. Re-run with --apply after reviewing the list above.');
        }

        return self::SUCCESS;
    }
}
