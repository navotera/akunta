<?php

namespace App\Filament\Resources\JournalResource\Pages;

use App\Filament\Resources\JournalResource;
use App\Models\Journal;
use App\Models\Period;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Support\Carbon;

class CreateJournal extends CreateRecord
{
    protected static string $resource = JournalResource::class;

    public function getMaxContentWidth(): MaxWidth
    {
        return MaxWidth::ScreenTwoExtraLarge;
    }

    public function getTitle(): string
    {
        return 'Jurnal Baru';
    }

    public function getSubheading(): ?string
    {
        return 'Catat transaksi double-entry. Setiap baris akan terkunci saat di-post.';
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Fallback: if period_id missing, resolve from date
        if (empty($data['period_id']) && ! empty($data['date'])) {
            $period = Period::query()
                ->where('status', Period::STATUS_OPEN)
                ->whereDate('start_date', '<=', $data['date'])
                ->whereDate('end_date', '>=', $data['date'])
                ->orderByDesc('start_date')
                ->first();
            if ($period) {
                $data['period_id'] = $period->id;
            }
        }

        // Auto-generate journal number if blank
        if (empty($data['number'])) {
            $data['number'] = $this->generateJournalNumber($data);
        }

        return $data;
    }

    protected function generateJournalNumber(array $data): string
    {
        $date = Carbon::parse($data['date'] ?? now());
        $prefix = 'JV-' . $date->format('Ym');

        $lastSeq = Journal::query()
            ->where('entity_id', $data['entity_id'] ?? null)
            ->where('number', 'like', $prefix . '-%')
            ->orderByDesc('number')
            ->value('number');

        $next = 1;
        if ($lastSeq && preg_match('/-(\d+)$/', $lastSeq, $m)) {
            $next = (int) $m[1] + 1;
        }

        return $prefix . '-' . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
