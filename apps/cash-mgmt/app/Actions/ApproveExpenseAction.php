<?php

namespace App\Actions;

use Akunta\Core\Actions\BaseAction;
use Akunta\Core\Hooks;
use Akunta\Rbac\Models\User;
use App\Exceptions\CashMgmtException;
use App\Models\Expense;

class ApproveExpenseAction extends BaseAction
{
    public function execute(Expense $expense, ?User $user = null): Expense
    {
        $this->authorize('expense.approve', $expense);

        if (! $expense->isDraft()) {
            throw CashMgmtException::notDraft($expense->status);
        }

        if (bccomp((string) $expense->amount, '0', 2) <= 0) {
            throw CashMgmtException::zeroAmount();
        }

        $this->fireBefore(Hooks::EXPENSE_BEFORE_APPROVE, $expense, $user);

        $this->runInTransaction(function () use ($expense, $user) {
            $expense->forceFill([
                'status' => Expense::STATUS_APPROVED,
                'approved_at' => now(),
                'approved_by' => $user?->id,
            ])->save();

            $this->audit(
                action: 'expense.approve',
                resourceType: Expense::class,
                resourceId: $expense->id,
                entityId: $expense->entity_id,
                metadata: [
                    'category_code' => $expense->category_code,
                    'amount' => (string) $expense->amount,
                    'fund_id' => $expense->fund_id,
                ],
                actorUserId: $user?->id,
            );
        });

        $expense->refresh();

        $this->fireAfter(Hooks::EXPENSE_AFTER_APPROVE, $expense, $user);

        return $expense;
    }
}
