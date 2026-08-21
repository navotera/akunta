<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Spa\Widgets;

use App\Http\Controllers\Api\Spa\Concerns\AuthorizesBookAccess;
use App\Http\Controllers\Api\Spa\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\Period;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class FinancialPulseController extends Controller
{
    use AuthorizesBookAccess;
    use ResolvesTenant;

    public function show(Request $request): JsonResponse
    {
        $entity = $this->resolveEntity($request);
        abort_unless($entity->is_active, 422, 'Entitas yang dipilih sedang nonaktif.');
        $data = $request->validate(['period_id' => ['nullable', 'string']]);
        $period = $this->resolvePeriod($entity->id, $data['period_id'] ?? null);
        $previousPeriod = Period::query()
            ->where('entity_id', $entity->id)
            ->whereDate('end_date', '<', $period->start_date)
            ->latest('end_date')
            ->first();
        $journalMode = $this->isInspector($request) ? Journal::MODE_FISCAL : Journal::MODE_INTERNAL;
        $current = $this->periodTotals($entity->id, $period->id, $journalMode);
        $previous = $previousPeriod
            ? $this->periodTotals($entity->id, $previousPeriod->id, $journalMode)
            : $this->emptyTotals();
        $cashAccountIds = $this->cashAccountIds($entity->id);

        return response()->json([
            'data' => [
                'entity_id' => $entity->id,
                'period' => $this->periodPayload($period),
                'previous_period' => $previousPeriod ? $this->periodPayload($previousPeriod) : null,
                'period_label' => $period->name,
                'revenue' => ['current' => $current['revenue'], 'previous' => $previous['revenue']],
                'expenses' => ['current' => $current['total_expenses'], 'previous' => $previous['total_expenses']],
                'net_income' => ['current' => $current['net_income'], 'previous' => $previous['net_income']],
                'cash_balance' => [
                    'current' => $this->accountBalance($entity->id, $cashAccountIds, $period->end_date->toDateString(), $journalMode),
                    'previous' => $previousPeriod
                        ? $this->accountBalance($entity->id, $cashAccountIds, $previousPeriod->end_date->toDateString(), $journalMode)
                        : '0.00',
                    'account_count' => count($cashAccountIds),
                ],
                'journals' => $this->journalCounts($entity->id, $period->id, $journalMode),
                'trend' => $this->trend($entity->id, $period, $journalMode),
                'revenue_composition' => $this->revenueComposition($entity->id, $period->id, $journalMode),
                'balance_accounts' => $this->balanceAccounts($entity->id, $period->end_date->toDateString(), $journalMode),
                'pending_journals' => $this->pendingJournals($entity->id, $period->id, $journalMode),
            ],
        ]);
    }

    private function resolvePeriod(string $entityId, ?string $periodId): Period
    {
        $query = Period::query()->where('entity_id', $entityId);
        if ($periodId) {
            $period = (clone $query)->whereKey($periodId)->first();
            if (! $period) {
                throw ValidationException::withMessages([
                    'period_id' => 'Periode aktif bukan milik entitas yang dipilih.',
                ]);
            }

            return $period;
        }

        $period = (clone $query)
            ->whereDate('start_date', '<=', now()->toDateString())
            ->whereDate('end_date', '>=', now()->toDateString())
            ->latest('start_date')
            ->first() ?? (clone $query)->latest('start_date')->first();
        if (! $period) {
            throw ValidationException::withMessages([
                'period_id' => 'Periode aktif tidak ditemukan pada entitas yang dipilih.',
            ]);
        }

        return $period;
    }

    /** @return array{id: string, name: string, start_date: string, end_date: string, status: string} */
    private function periodPayload(Period $period): array
    {
        return [
            'id' => $period->id,
            'name' => $period->name,
            'start_date' => $period->start_date->toDateString(),
            'end_date' => $period->end_date->toDateString(),
            'status' => $period->status,
        ];
    }

    /** @return array{revenue: string, cogs: string, expenses: string, total_expenses: string, net_income: string} */
    private function emptyTotals(): array
    {
        return ['revenue' => '0.00', 'cogs' => '0.00', 'expenses' => '0.00', 'total_expenses' => '0.00', 'net_income' => '0.00'];
    }

    /** @return array{revenue: string, cogs: string, expenses: string, total_expenses: string, net_income: string} */
    private function periodTotals(string $entityId, string $periodId, string $journalMode): array
    {
        $rows = $this->postedEntries($entityId, $journalMode)
            ->where('journals.period_id', $periodId)
            ->whereIn('accounts.type', ['revenue', 'cogs', 'expense'])
            ->select(['accounts.type', 'accounts.normal_balance'])
            ->selectRaw('SUM(journal_entries.debit) as debit')
            ->selectRaw('SUM(journal_entries.credit) as credit')
            ->groupBy('accounts.type', 'accounts.normal_balance')
            ->get();
        $totals = $this->emptyTotals();
        foreach ($rows as $row) {
            $amount = $row->normal_balance === 'debit'
                ? bcsub((string) $row->debit, (string) $row->credit, 2)
                : bcsub((string) $row->credit, (string) $row->debit, 2);
            $bucket = $row->type === 'revenue' ? 'revenue' : ($row->type === 'cogs' ? 'cogs' : 'expenses');
            $totals[$bucket] = bcadd($totals[$bucket], $amount, 2);
        }
        $totals['total_expenses'] = bcadd($totals['cogs'], $totals['expenses'], 2);
        $totals['net_income'] = bcsub($totals['revenue'], $totals['total_expenses'], 2);

        return $totals;
    }

    /** @return array{draft_count: int, submitted_count: int, rejected_count: int, posted_count: int} */
    private function journalCounts(string $entityId, string $periodId, string $journalMode): array
    {
        $row = Journal::query()
            ->where('entity_id', $entityId)
            ->where('period_id', $periodId)
            ->where('journal_mode', $journalMode)
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as draft_count', [Journal::STATUS_DRAFT])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as submitted_count', [Journal::STATUS_SUBMITTED])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as rejected_count', [Journal::STATUS_REJECTED])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as posted_count', [Journal::STATUS_POSTED])
            ->first();

        return [
            'draft_count' => (int) ($row->draft_count ?? 0),
            'submitted_count' => (int) ($row->submitted_count ?? 0),
            'rejected_count' => (int) ($row->rejected_count ?? 0),
            'posted_count' => (int) ($row->posted_count ?? 0),
        ];
    }

    /** @return Builder<JournalEntry> */
    private function postedEntries(string $entityId, string $journalMode): Builder
    {
        return JournalEntry::query()
            ->join('journals', 'journals.id', '=', 'journal_entries.journal_id')
            ->join('accounts', 'accounts.id', '=', 'journal_entries.account_id')
            ->where('journals.entity_id', $entityId)
            ->where('accounts.entity_id', $entityId)
            ->where('journals.status', Journal::STATUS_POSTED)
            ->where('journals.journal_mode', $journalMode);
    }

    /** @return list<string> */
    private function cashAccountIds(string $entityId): array
    {
        $accounts = Account::query()
            ->where('entity_id', $entityId)
            ->where('type', 'asset')
            ->get(['id', 'parent_account_id', 'name', 'is_postable']);
        $cashParents = $accounts
            ->filter(fn (Account $account): bool => str_contains(mb_strtolower($account->name), 'kas dan setara kas'))
            ->pluck('id')
            ->all();

        return $accounts
            ->filter(function (Account $account) use ($cashParents): bool {
                if (! $account->is_postable) {
                    return false;
                }
                $name = mb_strtolower($account->name);

                return in_array($account->parent_account_id, $cashParents, true)
                    || str_contains($name, 'kas')
                    || str_contains($name, 'bank')
                    || str_contains($name, 'payment gateway');
            })
            ->pluck('id')->values()->all();
    }

    /** @param list<string> $accountIds */
    private function accountBalance(string $entityId, array $accountIds, string $asOf, string $journalMode): string
    {
        if ($accountIds === []) {
            return '0.00';
        }
        $row = $this->postedEntries($entityId, $journalMode)
            ->whereIn('accounts.id', $accountIds)
            ->whereDate('journals.date', '<=', $asOf)
            ->selectRaw('COALESCE(SUM(journal_entries.debit - journal_entries.credit), 0) as balance')
            ->first();

        return number_format((float) ($row->balance ?? 0), 2, '.', '');
    }

    /** @return list<array{label: string, income: string, expense: string}> */
    private function trend(string $entityId, Period $period, string $journalMode): array
    {
        $start = $period->start_date->copy()->startOfDay();
        $end = $period->end_date->copy()->startOfDay();
        $days = max(1, $start->diffInDays($end) + 1);
        $bucketCount = min(12, $days);
        $bucketDays = (int) ceil($days / $bucketCount);
        $buckets = [];
        for ($index = 0; $index < $bucketCount; $index++) {
            $bucketStart = $start->copy()->addDays($index * $bucketDays);
            if ($bucketStart->gt($end)) {
                break;
            }
            $buckets[] = [
                'label' => $bucketStart->translatedFormat($days > 62 ? 'M' : 'd M'),
                'income' => '0.00',
                'expense' => '0.00',
            ];
        }

        $rows = $this->postedEntries($entityId, $journalMode)
            ->where('journals.period_id', $period->id)
            ->whereIn('accounts.type', ['revenue', 'cogs', 'expense'])
            ->get(['journals.date', 'accounts.type', 'accounts.normal_balance', 'journal_entries.debit', 'journal_entries.credit']);
        foreach ($rows as $row) {
            $date = Carbon::parse($row->date)->startOfDay();
            $bucketIndex = min(count($buckets) - 1, (int) floor($start->diffInDays($date) / $bucketDays));
            if ($bucketIndex < 0 || ! isset($buckets[$bucketIndex])) {
                continue;
            }
            $amount = $row->normal_balance === 'debit'
                ? bcsub((string) $row->debit, (string) $row->credit, 2)
                : bcsub((string) $row->credit, (string) $row->debit, 2);
            $key = $row->type === 'revenue' ? 'income' : 'expense';
            $buckets[$bucketIndex][$key] = bcadd($buckets[$bucketIndex][$key], $amount, 2);
        }

        return $buckets;
    }

    /** @return list<array{account_id: string, code: string, label: string, amount: string}> */
    private function revenueComposition(string $entityId, string $periodId, string $journalMode): array
    {
        return $this->postedEntries($entityId, $journalMode)
            ->where('journals.period_id', $periodId)
            ->where('accounts.type', 'revenue')
            ->select(['accounts.id as account_id', 'accounts.code', 'accounts.name as label'])
            ->selectRaw('SUM(journal_entries.credit - journal_entries.debit) as amount')
            ->groupBy('accounts.id', 'accounts.code', 'accounts.name')
            ->orderByDesc('amount')->limit(6)->get()
            ->map(fn ($row): array => [
                'account_id' => $row->account_id,
                'code' => $row->code,
                'label' => $row->label,
                'amount' => number_format((float) $row->amount, 2, '.', ''),
            ])->all();
    }

    /** @return list<array{account_id: string, code: string, label: string, type: string, amount: string}> */
    private function balanceAccounts(string $entityId, string $asOf, string $journalMode): array
    {
        return $this->postedEntries($entityId, $journalMode)
            ->whereDate('journals.date', '<=', $asOf)
            ->whereIn('accounts.type', ['asset', 'liability'])
            ->select(['accounts.id as account_id', 'accounts.code', 'accounts.name as label', 'accounts.type', 'accounts.normal_balance'])
            ->selectRaw('SUM(journal_entries.debit) as debit')
            ->selectRaw('SUM(journal_entries.credit) as credit')
            ->groupBy('accounts.id', 'accounts.code', 'accounts.name', 'accounts.type', 'accounts.normal_balance')
            ->get()
            ->map(function ($row): array {
                $amount = $row->normal_balance === 'debit'
                    ? bcsub((string) $row->debit, (string) $row->credit, 2)
                    : bcsub((string) $row->credit, (string) $row->debit, 2);

                return ['account_id' => $row->account_id, 'code' => $row->code, 'label' => $row->label, 'type' => $row->type, 'amount' => $amount];
            })
            ->filter(fn (array $row): bool => bccomp($row['amount'], '0.00', 2) !== 0)
            ->sortByDesc(fn (array $row): float => abs((float) $row['amount']))
            ->take(8)->values()->all();
    }

    /** @return list<array{id: string, number: string, date: string, memo: ?string, total: string}> */
    private function pendingJournals(string $entityId, string $periodId, string $journalMode): array
    {
        return Journal::query()
            ->where('entity_id', $entityId)
            ->where('period_id', $periodId)
            ->where('journal_mode', $journalMode)
            ->where('status', Journal::STATUS_SUBMITTED)
            ->withSum('entries as total_debit', 'debit')
            ->latest('date')->limit(5)
            ->get(['id', 'number', 'date', 'memo'])
            ->map(fn (Journal $journal): array => [
                'id' => $journal->id,
                'number' => $journal->number,
                'date' => $journal->date->toDateString(),
                'memo' => $journal->memo,
                'total' => (string) ($journal->total_debit ?? 0),
            ])->all();
    }
}
