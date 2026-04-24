<?php

namespace App\Actions;

use Akunta\ApiClient\AutoJournalClient;
use Akunta\ApiClient\Exceptions\ApiException;
use Akunta\ApiClient\Exceptions\DuplicateIdempotencyException;
use Akunta\ApiClient\Responses\JournalResponse;
use Akunta\Core\Actions\BaseAction;
use Akunta\Core\Hooks;
use Akunta\Rbac\Models\User;
use App\Exceptions\PayrollException;
use App\Models\PayrollRun;
use Illuminate\Support\Facades\DB;

/**
 * Pay payroll run — posts auto-journal against Accounting (second-tier hub) via AutoJournalClient.
 * Sequence §5.3.
 *
 * Account codes are taken from config('payroll.accounts.{wage_expense,cash}') so tenants can
 * override without editing this class. Defaults match the Indonesian 4-digit COA seeder in
 * apps/accounting (6110 Biaya Gaji ↔ 1101 Kas).
 */
class PayPayrollAction extends BaseAction
{
    public function __construct(private readonly AutoJournalClient $client) {}

    public function execute(PayrollRun $run, ?User $user = null): PayrollRun
    {
        $this->authorize('payroll.pay', $run);

        if (! $run->isApproved()) {
            throw PayrollException::notApproved($run->status);
        }

        if (bccomp((string) $run->total_wages, '0', 2) <= 0) {
            throw PayrollException::zeroTotal();
        }

        $this->fireBefore(Hooks::PAYROLL_BEFORE_PAY, $run, $user);

        $payload = $this->buildJournalPayload($run);

        try {
            $response = $this->client->postJournal($payload);
            $journalId = $response->journalId;
            $reconciled = false;
        } catch (DuplicateIdempotencyException $e) {
            $journalId = $e->existingJournalId;
            $reconciled = true;
            if ($journalId === null) {
                throw PayrollException::accountingApiFailed('duplicate without existing_journal_id');
            }
        } catch (ApiException $e) {
            throw PayrollException::accountingApiFailed($e->getMessage());
        }

        $this->runInTransaction(function () use ($run, $user, $journalId, $reconciled) {
            $run->forceFill([
                'status' => PayrollRun::STATUS_PAID,
                'journal_id' => $journalId,
                'paid_at' => now(),
                'paid_by' => $user?->id,
            ])->save();

            $this->audit(
                action: 'payroll.pay',
                resourceType: PayrollRun::class,
                resourceId: $run->id,
                entityId: $run->entity_id,
                metadata: [
                    'period_label' => $run->period_label,
                    'total_wages' => (string) $run->total_wages,
                    'journal_id' => $journalId,
                    'reconciled_existing' => $reconciled,
                ],
                actorUserId: $user?->id,
            );
        });

        $run->refresh();

        $this->fireAfter(Hooks::PAYROLL_AFTER_PAY, $run, $user);

        return $run;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildJournalPayload(PayrollRun $run): array
    {
        $wageAccount = (string) config('payroll.accounts.wage_expense', '6110');
        $cashAccount = (string) config('payroll.accounts.cash', '1101');
        $total = (string) $run->total_wages;

        return [
            'entity_id' => $run->entity_id,
            'date' => $run->run_date->format('Y-m-d'),
            'reference' => 'PAYROLL-'.$run->period_label,
            'idempotency_key' => $run->idempotencyKeyForPay(),
            'metadata' => [
                'source_app' => 'payroll',
                'source_id' => $run->id,
                'memo' => "Pembayaran gaji {$run->period_label}",
            ],
            'lines' => [
                [
                    'account_code' => $wageAccount,
                    'debit' => $total,
                    'credit' => '0',
                    'memo' => 'Biaya Gaji',
                ],
                [
                    'account_code' => $cashAccount,
                    'debit' => '0',
                    'credit' => $total,
                    'memo' => 'Kas keluar',
                ],
            ],
        ];
    }
}
