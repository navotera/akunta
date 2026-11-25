<?php

declare(strict_types=1);

namespace App\Services;

use Akunta\Audit\Models\AuditLog;
use App\Models\Journal;
use App\Models\JournalEntry;

class JournalRevision
{
    private const METADATA = ['transaction_code', 'journal_mode', 'input_group_id', 'date', 'type', 'memo', 'reference', 'period_id'];

    public function fingerprint(Journal $journal): string
    {
        return hash('sha256', json_encode([
            $this->contents($journal),
            $journal->attachments()->where('entity_id', $journal->entity_id)->reorder()->orderBy('id')->pluck('id')->all(),
        ], JSON_THROW_ON_ERROR));
    }

    public function hasChanges(Journal $journal): bool
    {
        $audit = AuditLog::query()->where('entity_id', $journal->entity_id)
            ->where('resource_type', Journal::class)->where('resource_id', $journal->id);
        $rejection = (clone $audit)->where('action', 'journal.reject')->latest()->orderByDesc('id')->first();
        $fingerprint = $rejection?->metadata['revision_fingerprint'] ?? null;
        if (is_string($fingerprint)) {
            return $fingerprint !== $this->fingerprint($journal);
        }

        // Older rejections only recorded the transition. Recover the prior journal contents
        // from the first edit's previous snapshot, and use attachment audit as evidence.
        if ($rejection) {
            $audit->where(fn ($query) => $query->where('created_at', '>', $rejection->created_at)
                ->orWhere(fn ($query) => $query->where('created_at', $rejection->created_at)->where('id', '>', $rejection->id)));
        } else {
            $audit->where('created_at', '>=', $journal->reviewed_at ?? $journal->created_at);
        }
        if ((clone $audit)->where('action', 'journal.attachment_changed')->exists()) {
            return true;
        }

        $edit = $audit->where('action', 'journal.updated')->oldest()->orderBy('id')->first();
        $previous = $edit?->metadata['previous_snapshot'] ?? null;

        return is_array($previous) && $this->normalize($previous) !== $this->contents($journal);
    }

    private function contents(Journal $journal): array
    {
        $snapshot = $journal->only(self::METADATA);
        $snapshot['date'] = $journal->date?->toDateString();
        $entries = $journal->entries()->get();
        foreach (['debit', 'credit'] as $side) {
            $snapshot['entries_'.$side] = $entries->filter(fn (JournalEntry $entry) => (float) $entry->{$side} > 0)
                ->map(fn (JournalEntry $entry) => ['account_id' => $entry->account_id, 'amount' => $entry->{$side}, 'memo' => $entry->memo])
                ->all();
        }

        return $this->normalize($snapshot);
    }

    private function normalize(array $snapshot): array
    {
        $contents = [];
        foreach (self::METADATA as $field) {
            $contents[$field] = $snapshot[$field] ?? null;
        }
        foreach (['entries_debit', 'entries_credit'] as $side) {
            $rows = array_map(fn (array $row) => [
                $row['account_id'], (string) $row['amount'], $row['memo'] ?? null,
            ], $snapshot[$side] ?? []);
            sort($rows);
            $contents[$side] = $rows;
        }

        return $contents;
    }
}
