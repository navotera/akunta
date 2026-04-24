<?php

namespace App\Actions;

use Akunta\ApiClient\AutoJournalClient;
use Akunta\ApiClient\Exceptions\ApiException;
use Akunta\ApiClient\Exceptions\DuplicateIdempotencyException;
use Akunta\Core\Actions\BaseAction;
use Akunta\Core\Hooks;
use Akunta\Rbac\Models\User;
use App\Exceptions\CashMgmtException;
use App\Models\Expense;

/**
 * Pay approved Expense — posts auto-journal against Accounting via AutoJournalClient.
 *
 * Debits `expense.category_code` (e.g. 6103 Biaya Listrik), credits the fund's
 * configured cash account (`fund.account_code`, fallback to config('cashmgmt.accounts.cash')).
 * Idempotency key `cashmgmt-expense-<id>-pay` is stable across retries + UI double-clicks
 * + reconcile paths.
 */
class PayExpenseAction extends BaseAction
{
    public function __construct(private readonly AutoJournalClient $client) {}

    public function execute(Expense $expense, ?User $user = null): Expense
    {
        $this->authorize('expense.pay', $expense);

        if (! $expense->isApproved()) {
            throw CashMgmtException::notApproved($expense->status);
        }

        if (bccomp((string) $expense->amount, '0', 2) <= 0) {
            throw CashMgmtException::zeroAmount();
        }

        $expense->loadMissing('fund');
        $fund = $expense->fund;
        if ($fund === null || ! $fund->is_active) {
            throw CashMgmtException::inactiveFund($fund?->name ?? 'unknown');
        }

        $this->fireBefore(Hooks::EXPENSE_BEFORE_PAY, $expense, $user);

        $payload = $this->buildJournalPayload($expense, $fund->account_code);

        try {
            $response = $this->client->postJournal($payload);
            $journalId = $response->journalId;
            $reconciled = false;
        } catch (DuplicateIdempotencyException $e) {
            $journalId = $e->existingJournalId;
            $reconciled = true;
            if ($journalId === null) {
                throw CashMgmtException::accountingApiFailed('duplicate without existing_journal_id');
            }
        } catch (ApiException $e) {
            throw CashMgmtException::accountingApiFailed($e->getMessage());
        }

        $this->runInTransaction(function () use ($expense, $user, $journalId, $reconciled) {
            $expense->forceFill([
                'status' => Expense::STATUS_PAID,
                'journal_id' => $journalId,
                'paid_at' => now(),
                'paid_by' => $user?->id,
            ])->save();

            $this->audit(
                action: 'expense.pay',
                resourceType: Expense::class,
                resourceId: $expense->id,
                entityId: $expense->entity_id,
                metadata: [
                    'category_code' => $expense->category_code,
                    'amount' => (string) $expense->amount,
                    'fund_id' => $expense->fund_id,
                    'journal_id' => $journalId,
                    'reconciled_existing' => $reconciled,
                ],
                actorUserId: $user?->id,
            );
        });

        $expense->refresh();

        $this->fireAfter(Hooks::EXPENSE_AFTER_PAY, $expense, $user);

        return $expense;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildJournalPayload(Expense $expense, string $cashAccountCode): array
    {
        $total = (string) $expense->amount;

        return [
            'entity_id' => $expense->entity_id,
            'date' => $expense->expense_date->format('Y-m-d'),
            'reference' => $expense->reference ?? 'EXPENSE-'.$expense->id,
            'idempotency_key' => $expense->idempotencyKeyForPay(),
            'metadata' => [
                'source_app' => 'cashmgmt',
                'source_id' => $expense->id,
                'memo' => $expense->memo ?? "Pengeluaran {$expense->category_code}",
            ],
            'lines' => [
                [
                    'account_code' => $expense->category_code,
                    'debit' => $total,
                    'credit' => '0',
                    'memo' => 'Biaya',
                ],
                [
                    'account_code' => $cashAccountCode,
                    'debit' => '0',
                    'credit' => $total,
                    'memo' => 'Kas keluar',
                ],
            ],
        ];
    }
}
