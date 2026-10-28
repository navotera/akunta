<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Account;
use App\Models\Journal;
use App\Models\Period;
use Illuminate\Support\Facades\DB;

class AccountDeletionUsage
{
    public function describe(Account $account, bool $fiscalOnly = false): array
    {
        $journals = Journal::withTrashed()
            ->where('entity_id', $account->entity_id)
            ->whereHas('entries', fn ($entries) => $entries->where('account_id', $account->id));
        if ($fiscalOnly) {
            $journals->where('journal_mode', Journal::MODE_FISCAL);
        }

        $otherBlockers = [];
        if (! $fiscalOnly) {
            $references = [
                'sub-akun' => $account->children()->where('entity_id', $account->entity_id)->count(),
                'template jurnal' => DB::table('journal_template_lines')->where('account_id', $account->id)->distinct()->count('template_id'),
                'koreksi Fiskal' => DB::table('fiscal_adjustments')->where('entity_id', $account->entity_id)->where('account_id', $account->id)->count(),
                'provisi pajak' => DB::table('tax_provisions')->where('entity_id', $account->entity_id)->where(fn ($query) => $query
                    ->where('expense_account_id', $account->id)->orWhere('payable_account_id', $account->id))->count(),
            ];
            foreach ($references as $label => $count) {
                if ($count > 0) {
                    $otherBlockers[] = ['label' => $label, 'count' => $count];
                }
            }
        }

        $journalCount = (clone $journals)->count();
        $activeCount = (clone $journals)->whereHas('period', fn ($period) => $period
            ->where('entity_id', $account->entity_id)->where('status', Period::STATUS_OPEN))->count();
        $inactiveCount = (clone $journals)->whereHas('period', fn ($period) => $period
            ->where('entity_id', $account->entity_id)->whereIn('status', [Period::STATUS_INACTIVE, Period::STATUS_CLOSING, Period::STATUS_CLOSED]))->count();

        return [
            'account' => ['id' => $account->id, 'code' => $account->code, 'name' => $account->name],
            'journal_count' => $journalCount,
            'active_period_count' => $activeCount,
            'inactive_period_count' => $inactiveCount,
            'unresolved_period_count' => $journalCount - $activeCount - $inactiveCount,
            'can_soft_delete' => ! $fiscalOnly && ! $account->isSystemAccount()
                && $journalCount > 0 && $journalCount === $inactiveCount && ! $account->children()->exists(),
            'trashed_count' => (clone $journals)->whereNotNull('deleted_at')->count(),
            'fiscal_only' => $fiscalOnly,
            'other_blockers' => $otherBlockers,
            'is_system' => $account->isSystemAccount(),
        ];
    }
}
