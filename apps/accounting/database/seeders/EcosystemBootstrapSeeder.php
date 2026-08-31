<?php

namespace Database\Seeders;

use Akunta\Rbac\Models\Entity;
use App\Actions\ApplyCoaTemplateAction;
use App\Actions\SeedSampleJournalTemplatesAction;
use App\Models\Period;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;

/**
 * First-install bootstrap: ensure every Entity has the basics needed to
 * post journals — generic CoA, an open period for the current month, and
 * sample journal templates.
 *
 * Run explicitly after entities exist. Idempotent and skips entities that
 * already have a populated CoA / period / templates.
 */
class EcosystemBootstrapSeeder extends Seeder
{
    public function run(
        ApplyCoaTemplateAction $coa,
        SeedSampleJournalTemplatesAction $samples,
    ): void {
        // Pull Ecopa entities first if configured — first-install order
        // (Ecopa is the source of truth for the entity catalogue).
        if (config('ecopa.url') && config('ecopa.api_token')) {
            try {
                Artisan::call('ecopa:reconcile');
                $this->command?->line('  EcosystemBootstrap: ecopa:reconcile ran.');
            } catch (\Throwable $e) {
                $this->command?->warn('  ! ecopa:reconcile failed: '.$e->getMessage());
            }
        }

        $entities = Entity::all();
        if ($entities->isEmpty()) {
            $this->command?->warn('  ! No entities to bootstrap — skipping.');

            return;
        }

        foreach ($entities as $entity) {
            if ($entity->is_fake_data) {
                continue;
            }

            $coa->execute($entity->id, 'generic');
            $this->ensureCurrentPeriod($entity->id);
            $samples->execute($entity->id);
        }

        $this->command?->info('  EcosystemBootstrap: '.$entities->count().' entities ensured.');
    }

    protected function ensureCurrentPeriod(string $entityId): Period
    {
        $today = now();
        $start = $today->copy()->startOfMonth()->toDateString();

        $existing = Period::query()
            ->where('entity_id', $entityId)
            ->whereDate('start_date', $start)
            ->first();
        if ($existing) {
            return $existing;
        }

        return Period::create([
            'entity_id' => $entityId,
            'start_date' => $start,
            'end_date' => $today->copy()->endOfMonth()->toDateString(),
            'name' => $today->translatedFormat('F Y'),
            'status' => Period::STATUS_OPEN,
        ]);
    }
}
