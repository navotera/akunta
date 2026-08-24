<?php

declare(strict_types=1);

use Akunta\Rbac\Models\Entity;
use App\Models\FakeDataRecord;
use App\Models\FiscalAdjustment;
use App\Models\Journal;
use App\Models\Period;
use App\Models\RecurringJournal;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const DEMO_YEAR = 2026;

    private const DEMO_START = '2026-01-01';

    private const DEMO_END = '2026-12-31';

    public function up(): void
    {
        Entity::query()
            ->where('is_fake_data', true)
            ->each(fn (Entity $entity) => DB::transaction(fn () => $this->consolidate($entity)));
    }

    private function consolidate(Entity $entity): void
    {
        $markers = FakeDataRecord::query()
            ->where('entity_id', $entity->id)
            ->where('group_key', 'periods')
            ->where('model_type', Period::class)
            ->get();
        if ($markers->isEmpty()) {
            return;
        }

        $target = Period::query()
            ->where('entity_id', $entity->id)
            ->whereIn('id', $markers->pluck('model_id'))
            ->whereDate('start_date', self::DEMO_START)
            ->first();
        if (! $target) {
            // An unmarked period at the canonical date may contain manual
            // records. Never claim or overwrite it merely because the entity
            // itself is the demo workspace.
            if (Period::query()->where('entity_id', $entity->id)->whereDate('start_date', self::DEMO_START)->exists()) {
                return;
            }

            $target = Period::create([
                'entity_id' => $entity->id,
                'name' => 'Demo '.self::DEMO_YEAR,
                'start_date' => self::DEMO_START,
                'end_date' => self::DEMO_END,
                'status' => Period::STATUS_OPEN,
            ]);
            FakeDataRecord::create([
                'entity_id' => $entity->id,
                'group_key' => 'periods',
                'model_type' => Period::class,
                'model_id' => $target->id,
            ]);
        }

        foreach ($markers as $marker) {
            if ($marker->model_id === $target->id) {
                continue;
            }
            $source = Period::query()->where('entity_id', $entity->id)->find($marker->model_id);
            if (! $source) {
                $marker->delete();

                continue;
            }

            $journals = Journal::query()
                ->where('entity_id', $entity->id)
                ->where('period_id', $source->id)
                ->get();
            $markedJournalIds = FakeDataRecord::query()
                ->where('entity_id', $entity->id)
                ->where('model_type', Journal::class)
                ->whereIn('model_id', $journals->pluck('id'))
                ->pluck('model_id');
            if ($journals->pluck('id')->diff($markedJournalIds)->isNotEmpty()) {
                continue;
            }

            foreach ($journals as $journal) {
                $date = $this->dateInDemoYear($journal->date);
                $journal->forceFill(['period_id' => $target->id, 'date' => $date])->saveQuietly();
                FiscalAdjustment::query()
                    ->where('entity_id', $entity->id)
                    ->where('journal_id', $journal->id)
                    ->update(['date' => $date]);
            }

            if (! Journal::query()->where('period_id', $source->id)->exists()) {
                $marker->delete();
                $source->delete();
            }
        }

        $target->forceFill([
            'name' => 'Demo '.self::DEMO_YEAR,
            'start_date' => self::DEMO_START,
            'end_date' => self::DEMO_END,
            'status' => Period::STATUS_OPEN,
            'closed_at' => null,
            'closed_by' => null,
        ])->save();

        $recurringIds = FakeDataRecord::query()
            ->where('entity_id', $entity->id)
            ->where('model_type', RecurringJournal::class)
            ->pluck('model_id');
        RecurringJournal::query()
            ->where('entity_id', $entity->id)
            ->whereIn('id', $recurringIds)
            ->get()
            ->each(function (RecurringJournal $recurring): void {
                $day = min(max((int) ($recurring->day ?? 1), 1), 31);
                $nextRun = Carbon::create(self::DEMO_YEAR, 1, 1)->day($day);
                $recurring->forceFill([
                    'start_date' => self::DEMO_START,
                    'end_date' => self::DEMO_END,
                    'next_run_at' => $nextRun->toDateString(),
                ])->save();
            });
    }

    private function dateInDemoYear(Carbon|string $date): string
    {
        $source = Carbon::parse($date);
        $monthStart = Carbon::create(self::DEMO_YEAR, $source->month, 1);

        return $monthStart
            ->day(min($source->day, $monthStart->daysInMonth))
            ->toDateString();
    }

    public function down(): void
    {
        // Irreversible: legacy demo-period boundaries cannot be reconstructed
        // safely after journals have been consolidated into the 2026 period.
    }
};
