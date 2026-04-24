<?php

namespace App\Actions;

use Akunta\Core\Actions\BaseAction;
use Akunta\Core\Hooks;
use Akunta\Rbac\Models\User;
use App\Exceptions\PayrollException;
use App\Models\PayrollRun;

class ApprovePayrollAction extends BaseAction
{
    public function execute(PayrollRun $run, ?User $user = null): PayrollRun
    {
        $this->authorize('payroll.approve', $run);

        if (! $run->isDraft()) {
            throw PayrollException::notDraft($run->status);
        }

        if (bccomp((string) $run->total_wages, '0', 2) <= 0) {
            throw PayrollException::zeroTotal();
        }

        $this->fireBefore(Hooks::PAYROLL_BEFORE_APPROVE, $run, $user);

        $this->runInTransaction(function () use ($run, $user) {
            $run->forceFill([
                'status' => PayrollRun::STATUS_APPROVED,
                'approved_at' => now(),
                'approved_by' => $user?->id,
            ])->save();

            $this->audit(
                action: 'payroll.approve',
                resourceType: PayrollRun::class,
                resourceId: $run->id,
                entityId: $run->entity_id,
                metadata: [
                    'period_label' => $run->period_label,
                    'total_wages' => (string) $run->total_wages,
                ],
                actorUserId: $user?->id,
            );
        });

        $run->refresh();

        $this->fireAfter(Hooks::PAYROLL_AFTER_APPROVE, $run, $user);

        return $run;
    }
}
