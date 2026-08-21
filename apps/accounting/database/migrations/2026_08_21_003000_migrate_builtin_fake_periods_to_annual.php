<?php

declare(strict_types=1);

use Akunta\Rbac\Models\Entity;
use App\Models\FakeDataRecord;
use App\Models\Journal;
use App\Models\Period;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Entity::query()
            ->where('is_fake_data', true)
            ->each(function (Entity $entity): void {
                DB::transaction(function () use ($entity): void {
                    $markers = FakeDataRecord::query()
                        ->where('entity_id', $entity->id)
                        ->where('group_key', 'periods')
                        ->where('model_type', Period::class)
                        ->get();

                    if ($markers->isEmpty()) {
                        return;
                    }

                    $periods = Period::query()
                        ->where('entity_id', $entity->id)
                        ->whereIn('id', $markers->pluck('model_id'))
                        ->orderBy('start_date')
                        ->get();

                    foreach ($periods->groupBy(fn (Period $period): int => $period->start_date->year) as $year => $yearPeriods) {
                        $start = Carbon::create((int) $year, 1, 1)->startOfYear();
                        $end = $start->copy()->endOfYear();
                        $target = $yearPeriods->first(fn (Period $period): bool => $period->start_date->isSameDay($start));

                        if (! $target) {
                            $target = Period::create([
                                'entity_id' => $entity->id,
                                'name' => 'Demo '.$year,
                                'start_date' => $start,
                                'end_date' => $end,
                                'status' => Period::STATUS_CLOSED,
                            ]);
                            FakeDataRecord::create([
                                'entity_id' => $entity->id,
                                'group_key' => 'periods',
                                'model_type' => Period::class,
                                'model_id' => $target->id,
                            ]);
                        } else {
                            $target->forceFill([
                                'name' => 'Demo '.$year,
                                'start_date' => $start,
                                'end_date' => $end,
                            ])->save();
                        }

                        $oldIds = $yearPeriods
                            ->reject(fn (Period $period): bool => $period->id === $target->id)
                            ->pluck('id');

                        if ($oldIds->isNotEmpty()) {
                            Journal::query()
                                ->where('entity_id', $entity->id)
                                ->whereIn('period_id', $oldIds)
                                ->update(['period_id' => $target->id]);

                            FakeDataRecord::query()
                                ->where('entity_id', $entity->id)
                                ->where('group_key', 'periods')
                                ->where('model_type', Period::class)
                                ->whereIn('model_id', $oldIds)
                                ->delete();

                            Period::query()->whereIn('id', $oldIds)->delete();
                        }

                        $target->forceFill([
                            'status' => (int) $year === now()->year ? Period::STATUS_OPEN : Period::STATUS_CLOSED,
                            'closed_at' => (int) $year === now()->year ? null : ($target->closed_at ?? now()),
                            'closed_by' => (int) $year === now()->year ? null : $target->closed_by,
                        ])->save();
                    }
                });
            });
    }

    public function down(): void
    {
        // The migration is intentionally irreversible: the original monthly
        // periods cannot be reconstructed without risking journal references.
    }
};
