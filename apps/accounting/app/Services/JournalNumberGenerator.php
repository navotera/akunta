<?php

declare(strict_types=1);

namespace App\Services;

use Akunta\Rbac\Models\Entity;
use App\Models\Journal;
use Illuminate\Support\Carbon;

class JournalNumberGenerator
{
    public function next(
        string $entityId,
        string $date,
        string $mode,
        string $type = Journal::TYPE_GENERAL,
    ): string {
        $entity = Entity::query()->find($entityId);
        $defaultPrefix = match ($type) {
            Journal::TYPE_ADJUSTMENT => 'JP',
            Journal::TYPE_REVERSING => 'JK',
            Journal::TYPE_CLOSING => 'JP',
            default => 'JU',
        };
        $defaultFormat = $defaultPrefix.'/{tahun}/{bulan}/{numbering}';
        $settings = $entity?->workspace_settings;
        $format = data_get($settings, 'journal_number_formats.'.$type)
            ?: data_get($settings, 'journal_number_format')
            ?: $defaultFormat;

        return $this->nextFromFormat($entityId, $date, $format, 'number', $mode);
    }

    public function nextTransactionCode(string $entityId, string $date): string
    {
        $entity = Entity::query()->find($entityId);
        $format = data_get($entity?->workspace_settings, 'transaction_number_format')
            ?: 'TRX/{tahun}/{bulan}/{numbering}';

        return $this->nextFromFormat($entityId, $date, $format, 'transaction_code');
    }

    private function nextFromFormat(
        string $entityId,
        string $date,
        string $format,
        string $column = 'number',
        string $mode = Journal::MODE_INTERNAL,
    ): string {
        $carbon = Carbon::parse($date);
        $likePrefix = $this->formatPrefix($format, $carbon, $mode);
        $lastValues = Journal::query()
            ->where('entity_id', $entityId)
            ->where($column, 'like', $likePrefix.'%')
            ->pluck($column);

        $next = 1;
        foreach ($lastValues as $value) {
            if (preg_match('/(\d+)$/', (string) $value, $matches)) {
                $next = max($next, (int) $matches[1] + 1);
            }
        }

        return $this->render($format, $carbon, $next, $mode);
    }

    private function formatPrefix(string $format, Carbon $date, string $mode): string
    {
        $marker = '__AKUNTA_NUMBER__';
        $prefix = $this->render($format, $date, $marker, $mode);

        return substr($prefix, 0, (int) strrpos($prefix, $marker));
    }

    private function render(
        string $format,
        Carbon $date,
        int|string $number,
        string $mode = Journal::MODE_INTERNAL,
    ): string {
        return strtr($format, [
            '{thn}' => $date->format('Y'),
            '{bln}' => $date->format('m'),
            '{tahun}' => $date->format('y'),
            '{tahun_full}' => $date->format('Y'),
            '{bulan}' => $date->format('n'),
            '{incremented_number}' => (string) $number,
            '{numbering}' => (string) $number,
            '{tipe_jurnal}' => $mode === Journal::MODE_FISCAL ? 'F' : 'I',
            '{journal_type}' => $mode === Journal::MODE_FISCAL ? 'F' : 'I',
        ]);
    }
}
