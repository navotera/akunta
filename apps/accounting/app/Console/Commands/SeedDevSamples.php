<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Akunta\Rbac\Models\Entity;
use App\Actions\ApplyCoaTemplateAction;
use App\Actions\SeedSampleJournalTemplatesAction;
use App\Models\Period;
use Illuminate\Console\Command;

/**
 * Dev helper — populate every Entity in the control DB with:
 *   - generic CoA (idempotent — skips existing accounts)
 *   - one open period covering the current month
 *   - sample journal templates (purchase, payroll, rent, receivable, etc.)
 *
 * Usage:
 *   php artisan akunta:seed-dev-samples
 *   php artisan akunta:seed-dev-samples --entity=01HZ...
 */
class SeedDevSamples extends Command
{
    protected $signature = 'akunta:seed-dev-samples {--entity= : Limit to one entity ULID} {--coa=generic : CoA template key}';

    protected $description = 'Seed CoA + period + sample journal templates for dev/test entities.';

    public function handle(ApplyCoaTemplateAction $coa, SeedSampleJournalTemplatesAction $samples): int
    {
        $query = Entity::query();
        if ($id = $this->option('entity')) {
            $query->where('id', $id);
        }
        $entities = $query->get();

        if ($entities->isEmpty()) {
            $this->warn('No entities found.');

            return self::SUCCESS;
        }

        $coaKey = (string) $this->option('coa');

        foreach ($entities as $entity) {
            $this->line("• Entity {$entity->id} — {$entity->name}");

            $coaResult = $coa->execute($entity->id, $coaKey);
            $this->line("    CoA   : created={$coaResult['created']} skipped={$coaResult['skipped']} (template={$coaKey})");

            $period = $this->ensureCurrentPeriod($entity->id);
            $this->line("    Period: {$period->name} ({$period->start_date->toDateString()} → {$period->end_date->toDateString()})");

            $tmpl = $samples->execute($entity->id);
            $missingNote = empty($tmpl['skipped_missing_account']) ? '' : ' missing_accounts='.json_encode($tmpl['skipped_missing_account']);
            $this->line("    Tmpls : created={$tmpl['created']} skipped_existing={$tmpl['skipped_existing']}{$missingNote}");
        }

        $this->info('Done.');

        return self::SUCCESS;
    }

    protected function ensureCurrentPeriod(string $entityId): Period
    {
        $today = now();
        $start = $today->copy()->startOfMonth()->toDateString();
        $end = $today->copy()->endOfMonth()->toDateString();

        $existing = Period::query()
            ->where('entity_id', $entityId)
            ->whereDate('start_date', $start)
            ->first();
        if ($existing) {
            return $existing;
        }

        return Period::create([
            'entity_id'  => $entityId,
            'start_date' => $start,
            'end_date'   => $end,
            'name'       => $today->translatedFormat('F Y'),
            'status'     => Period::STATUS_OPEN,
        ]);
    }
}
