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

    public ?string $preset = null;

    /**
     * Preset → form defaults map. Each preset seeds: title, subheading,
     * journal type, memo prefix, and number prefix used for auto-generation.
     */
    private const PRESETS = [
        'sales' => [
            'title' => 'Jurnal Penjualan',
            'subheading' => 'Catat transaksi penjualan. Debit Kas/Piutang, Kredit Pendapatan + PPN Keluaran.',
            'type' => Journal::TYPE_GENERAL,
            'memo' => 'Penjualan — ',
            'prefix' => 'JS',
        ],
        'purchase' => [
            'title' => 'Jurnal Pembelian',
            'subheading' => 'Catat transaksi pembelian. Debit Persediaan/Beban + PPN Masukan, Kredit Kas/Hutang.',
            'type' => Journal::TYPE_GENERAL,
            'memo' => 'Pembelian — ',
            'prefix' => 'JP',
        ],
        Journal::TYPE_GENERAL => [
            'title' => 'Jurnal Umum',
            'subheading' => 'Catat transaksi double-entry umum. Setiap baris akan terkunci saat di-post.',
            'type' => Journal::TYPE_GENERAL,
            'memo' => null,
            'prefix' => 'JV',
        ],
        Journal::TYPE_ADJUSTMENT => [
            'title' => 'Jurnal Penyesuaian',
            'subheading' => 'Catat penyesuaian akhir periode (depresiasi, akrual, prepaid).',
            'type' => Journal::TYPE_ADJUSTMENT,
            'memo' => 'Penyesuaian — ',
            'prefix' => 'JA',
        ],
    ];

    public function mount(): void
    {
        $this->preset = (string) (request()->query('preset') ?? '');
        parent::mount();
    }

    public function getMaxContentWidth(): MaxWidth
    {
        return MaxWidth::ScreenTwoExtraLarge;
    }

    public function getTitle(): string
    {
        return $this->presetConfig()['title'] ?? 'Jurnal Baru';
    }

    public function getSubheading(): ?string
    {
        return $this->presetConfig()['subheading'] ?? 'Catat transaksi double-entry. Setiap baris akan terkunci saat di-post.';
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $cfg = $this->presetConfig();
        if ($cfg === null) {
            return $data;
        }

        if (empty($data['type'])) {
            $data['type'] = $cfg['type'];
        }
        if (empty($data['memo']) && $cfg['memo'] !== null) {
            $data['memo'] = $cfg['memo'];
        }

        return $data;
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
        $prefix = ($this->presetConfig()['prefix'] ?? 'JV').'-'.$date->format('Ym');

        $lastSeq = Journal::query()
            ->where('entity_id', $data['entity_id'] ?? null)
            ->where('number', 'like', $prefix.'-%')
            ->orderByDesc('number')
            ->value('number');

        $next = 1;
        if ($lastSeq && preg_match('/-(\d+)$/', $lastSeq, $m)) {
            $next = (int) $m[1] + 1;
        }

        return $prefix.'-'.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    private function presetConfig(): ?array
    {
        return self::PRESETS[$this->preset] ?? null;
    }
}
