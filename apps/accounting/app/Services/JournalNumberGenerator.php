<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Journal;
use Illuminate\Support\Carbon;

class JournalNumberGenerator
{
    public function next(string $entityId, string $date, string $mode): string
    {
        $prefix = $mode === Journal::MODE_FISCAL ? 'JF' : 'JI';
        $series = $prefix.'-'.Carbon::parse($date)->format('Ym');
        $last = Journal::query()
            ->where('entity_id', $entityId)
            ->where('journal_mode', $mode)
            ->where('number', 'like', $series.'-%')
            ->orderByDesc('number')
            ->value('number');

        $next = 1;
        if ($last && preg_match('/-(\d+)$/', $last, $matches)) {
            $next = (int) $matches[1] + 1;
        }

        return $series.'-'.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
