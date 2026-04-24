<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Models\Period;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;

class PeriodStatusWidget extends Widget
{
    protected static ?int $sort = 2;

    protected static string $view = 'filament.widgets.period-status';

    protected int|string|array $columnSpan = 'full';

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        $entity = Filament::getTenant();
        if ($entity === null) {
            return ['period' => null];
        }

        $today = Carbon::today();

        $period = Period::query()
            ->where('entity_id', $entity->id)
            ->where('status', 'open')
            ->orderBy('start_date')
            ->first();

        if ($period === null) {
            return ['period' => null];
        }

        $endDate = Carbon::parse($period->end_date);
        $daysUntilEnd = $today->diffInDays($endDate, false);

        return [
            'period' => $period,
            'days_until_end' => (int) $daysUntilEnd,
            'overdue' => $daysUntilEnd < 0,
        ];
    }
}
