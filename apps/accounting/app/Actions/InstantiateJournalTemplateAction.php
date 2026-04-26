<?php

declare(strict_types=1);

namespace App\Actions;

use App\Exceptions\JournalException;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\JournalTemplate;
use App\Models\Period;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Materialize a JournalTemplate into a draft (or auto-posted) Journal.
 *
 * Caller may pass `$overrides` keyed by template line_no:
 *   [1 => ['amount' => '500000.00'], 2 => ['amount' => '500000.00']]
 *
 * Lines whose template `amount = 0` and have no override → throws.
 * Total debit and total credit must match (else throws).
 */
class InstantiateJournalTemplateAction
{
    /**
     * @param  array<int, array{amount?: string|float|int, memo?: string}>  $overrides
     */
    public function execute(
        JournalTemplate $template,
        string $date,
        array $overrides = [],
        ?string $reference = null,
        ?string $memo = null,
        ?string $sourceApp = null,
        ?string $sourceId = null,
        ?string $idempotencyKey = null,
        ?string $createdBy = null,
    ): Journal {
        if (! $template->is_active) {
            throw JournalException::notPosted('inactive_template');
        }

        $template->loadMissing('lines');

        if ($template->lines->count() < 2) {
            throw JournalException::notPosted('template_needs_at_least_2_lines');
        }

        $period = Period::query()
            ->where('entity_id', $template->entity_id)
            ->where('status', Period::STATUS_OPEN)
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->first();
        if ($period === null) {
            throw JournalException::notPosted('no_open_period_for_date');
        }

        $resolved = [];
        $totalDebit  = '0.00';
        $totalCredit = '0.00';

        foreach ($template->lines as $tl) {
            $amount = (string) $tl->amount;
            if (isset($overrides[$tl->line_no]['amount'])) {
                $amount = (string) $overrides[$tl->line_no]['amount'];
            }
            if (bccomp($amount, '0', 2) <= 0) {
                throw JournalException::notPosted("template_line_{$tl->line_no}_requires_amount");
            }

            $debit  = $tl->side === 'debit'  ? $amount : '0.00';
            $credit = $tl->side === 'credit' ? $amount : '0.00';

            $totalDebit  = bcadd($totalDebit, $debit, 2);
            $totalCredit = bcadd($totalCredit, $credit, 2);

            $resolved[] = [
                'line_no'        => $tl->line_no,
                'account_id'     => $tl->account_id,
                'partner_id'     => $tl->partner_id,
                'cost_center_id' => $tl->cost_center_id,
                'project_id'     => $tl->project_id,
                'branch_id'      => $tl->branch_id,
                'debit'          => $debit,
                'credit'         => $credit,
                'memo'           => $overrides[$tl->line_no]['memo'] ?? $tl->memo,
            ];
        }

        if (bccomp($totalDebit, $totalCredit, 2) !== 0) {
            throw JournalException::notPosted('template_unbalanced');
        }

        return DB::transaction(function () use ($template, $period, $date, $resolved, $reference, $memo, $sourceApp, $sourceId, $idempotencyKey, $createdBy) {
            $journal = Journal::create([
                'entity_id'       => $template->entity_id,
                'period_id'       => $period->id,
                'type'            => $template->journal_type,
                'number'          => 'TJ-'.strtoupper(substr((string) Str::ulid(), -10)),
                'date'            => $date,
                'reference'       => $reference ?? $template->default_reference,
                'memo'            => $memo ?? $template->default_memo,
                'source_app'      => $sourceApp ?? 'accounting',
                'source_id'       => $sourceId,
                'idempotency_key' => $idempotencyKey,
                'template_id'     => $template->id,
                'status'          => Journal::STATUS_DRAFT,
                'created_by'      => $createdBy,
            ]);

            foreach ($resolved as $r) {
                JournalEntry::create([
                    'journal_id'     => $journal->id,
                    'line_no'        => $r['line_no'],
                    'account_id'     => $r['account_id'],
                    'partner_id'     => $r['partner_id'],
                    'cost_center_id' => $r['cost_center_id'],
                    'project_id'     => $r['project_id'],
                    'branch_id'      => $r['branch_id'],
                    'debit'          => $r['debit'],
                    'credit'         => $r['credit'],
                    'memo'           => $r['memo'],
                ]);
            }

            return $journal;
        });
    }
}
