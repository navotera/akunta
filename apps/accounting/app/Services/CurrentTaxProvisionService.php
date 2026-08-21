<?php

declare(strict_types=1);

namespace App\Services;

use Akunta\Rbac\Models\Entity;
use App\Models\Account;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\Period;
use App\Models\TaxProvision;
use App\Services\Reporting\FiscalReconciliationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CurrentTaxProvisionService
{
    public function __construct(
        private readonly FiscalReconciliationService $reconciliation,
        private readonly JournalNumberGenerator $numberGenerator,
    ) {}

    /** @return array<string, mixed> */
    public function calculate(
        Entity $entity,
        string $periodStart,
        string $periodEnd,
        string $taxRate,
        string $lossCompensation = '0',
        string $taxCredits = '0',
    ): array {
        $report = $this->reconciliation->compute($entity->id, $periodStart, $periodEnd);
        $fiscalNetIncome = (string) $report['final_net_income'];
        $incomeAfterLoss = bcsub($fiscalNetIncome, $lossCompensation, 2);
        $taxableIncome = bccomp($incomeAfterLoss, '0', 2) > 0 ? $incomeAfterLoss : '0.00';
        $grossCurrentTax = $this->percentage($taxableIncome, $taxRate);
        $taxCreditsApplied = bccomp($taxCredits, $grossCurrentTax, 2) > 0
            ? $grossCurrentTax
            : $taxCredits;
        $currentTaxPayable = bcsub($grossCurrentTax, $taxCreditsApplied, 2);

        return [
            'entity_id' => $entity->id,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'fiscal_net_income' => $fiscalNetIncome,
            'loss_compensation' => $this->money($lossCompensation),
            'taxable_income' => $this->money($taxableIncome),
            'tax_rate' => number_format((float) $taxRate, 4, '.', ''),
            'gross_current_tax' => $this->money($grossCurrentTax),
            'tax_credits' => $this->money($taxCredits),
            'tax_credits_applied' => $this->money($taxCreditsApplied),
            'current_tax_payable' => $this->money($currentTaxPayable),
            'unused_tax_credits' => $this->money(bcsub($taxCredits, $taxCreditsApplied, 2)),
            'approved_adjustments' => [
                'positive' => (string) $report['positive_adjustments'],
                'negative' => (string) $report['negative_adjustments'],
            ],
            'deferred_tax_status' => 'not_calculated',
            'deferred_tax_note' => 'Pajak tangguhan memerlukan dasar pajak aset/liabilitas dan tidak dihitung otomatis dari tanda koreksi.',
        ];
    }

    /** @param array<string, mixed> $input */
    public function createOrUpdate(Entity $entity, array $input, ?string $userId): TaxProvision
    {
        $calculation = $this->calculate(
            $entity,
            $input['period_start'],
            $input['period_end'],
            (string) $input['tax_rate'],
            (string) ($input['loss_compensation'] ?? 0),
            (string) ($input['tax_credits'] ?? 0),
        );

        $expense = $this->account($entity, $input['expense_account_id'], 'expense', 'expense_account_id');
        $payable = $this->account($entity, $input['payable_account_id'], 'liability', 'payable_account_id');
        $prepaid = null;
        if (bccomp((string) $calculation['tax_credits_applied'], '0', 2) > 0) {
            if (empty($input['prepaid_tax_account_id'])) {
                throw ValidationException::withMessages([
                    'prepaid_tax_account_id' => 'Akun pajak dibayar di muka wajib dipilih jika terdapat kredit pajak.',
                ]);
            }
            $prepaid = $this->account($entity, $input['prepaid_tax_account_id'], 'asset', 'prepaid_tax_account_id');
        }

        $period = Period::query()
            ->where('entity_id', $entity->id)
            ->where('status', Period::STATUS_OPEN)
            ->whereDate('start_date', '<=', $input['recognition_date'])
            ->whereDate('end_date', '>=', $input['recognition_date'])
            ->first();
        if (! $period) {
            throw ValidationException::withMessages([
                'recognition_date' => "Tidak ada periode terbuka untuk tanggal {$input['recognition_date']}.",
            ]);
        }

        return DB::transaction(function () use (
            $entity,
            $input,
            $userId,
            $calculation,
            $expense,
            $payable,
            $prepaid,
            $period,
        ): TaxProvision {
            $provision = TaxProvision::query()
                ->where('entity_id', $entity->id)
                ->whereDate('period_start', $input['period_start'])
                ->whereDate('period_end', $input['period_end'])
                ->lockForUpdate()
                ->first();

            $journal = $provision?->journal;
            if ($journal && ! in_array($journal->status, [Journal::STATUS_DRAFT, Journal::STATUS_REJECTED, Journal::STATUS_REVERSED], true)) {
                throw ValidationException::withMessages([
                    'journal' => 'Jurnal pajak sudah diajukan atau diposting. Reverse jurnal tersebut sebelum membuat perhitungan pengganti.',
                ]);
            }

            $attributes = [
                ...$calculation,
                'recognition_date' => $input['recognition_date'],
                'expense_account_id' => $expense->id,
                'payable_account_id' => $payable->id,
                'prepaid_tax_account_id' => $prepaid?->id,
                'calculation_hash' => hash('sha256', json_encode($calculation, JSON_THROW_ON_ERROR)),
                'calculation_snapshot' => $calculation,
                'updated_by' => $userId,
            ];
            unset($attributes['unused_tax_credits'], $attributes['approved_adjustments'], $attributes['deferred_tax_status'], $attributes['deferred_tax_note']);

            if ($provision) {
                $provision->fill($attributes)->save();
            } else {
                $provision = TaxProvision::create([
                    ...$attributes,
                    'entity_id' => $entity->id,
                    'created_by' => $userId,
                ]);
            }

            if ($journal?->status === Journal::STATUS_REVERSED) {
                $journal = null;
            }

            if (! $journal) {
                $journalNumber = $this->numberGenerator->next(
                    $entity->id,
                    $input['recognition_date'],
                    Journal::MODE_INTERNAL,
                    Journal::TYPE_ADJUSTMENT,
                );
                $journal = Journal::create([
                    'entity_id' => $entity->id,
                    'period_id' => $period->id,
                    'type' => Journal::TYPE_ADJUSTMENT,
                    'journal_mode' => Journal::MODE_INTERNAL,
                    'number' => $journalNumber,
                    'transaction_code' => $this->numberGenerator->nextTransactionCode($entity->id, $input['recognition_date']),
                    'date' => $input['recognition_date'],
                    'memo' => 'Provisi Pajak Penghasilan Kini '.$input['period_start'].' s.d. '.$input['period_end'],
                    'reference' => 'TAX-PROVISION-'.$input['period_end'],
                    'source_app' => 'tax_provision',
                    'source_id' => $provision->id,
                    'idempotency_key' => 'tax-provision:'.$provision->id.':'.$journalNumber,
                    'status' => Journal::STATUS_DRAFT,
                    'created_by' => $userId,
                ]);
            } else {
                $journal->fill([
                    'period_id' => $period->id,
                    'date' => $input['recognition_date'],
                    'memo' => 'Provisi Pajak Penghasilan Kini '.$input['period_start'].' s.d. '.$input['period_end'],
                    'reference' => 'TAX-PROVISION-'.$input['period_end'],
                    'status' => Journal::STATUS_DRAFT,
                ])->save();
                $journal->entries()->delete();
            }

            $this->writeEntries($journal, $calculation, $expense, $payable, $prepaid);
            $provision->forceFill(['journal_id' => $journal->id])->save();

            return $provision->fresh([
                'journal.entries.account',
                'expenseAccount:id,code,name',
                'payableAccount:id,code,name',
                'prepaidTaxAccount:id,code,name',
            ]);
        });
    }

    private function account(Entity $entity, string $id, string $type, string $field): Account
    {
        $account = Account::query()
            ->where('entity_id', $entity->id)
            ->where('id', $id)
            ->where('type', $type)
            ->where('is_active', true)
            ->where('is_postable', true)
            ->whereIn('availability', [Account::AVAILABILITY_INTERN, Account::AVAILABILITY_BOTH])
            ->first();

        if (! $account) {
            throw ValidationException::withMessages([
                $field => 'Akun harus aktif, postable, dan tersedia pada buku Intern.',
            ]);
        }

        return $account;
    }

    /** @param array<string, mixed> $calculation */
    private function writeEntries(
        Journal $journal,
        array $calculation,
        Account $expense,
        Account $payable,
        ?Account $prepaid,
    ): void {
        $grossTax = (string) $calculation['gross_current_tax'];
        if (bccomp($grossTax, '0', 2) <= 0) {
            throw ValidationException::withMessages([
                'taxable_income' => 'Tidak ada pajak kini yang perlu dijurnal untuk perhitungan ini.',
            ]);
        }

        $line = 1;
        JournalEntry::create([
            'journal_id' => $journal->id,
            'line_no' => $line++,
            'account_id' => $expense->id,
            'debit' => $grossTax,
            'credit' => 0,
            'memo' => 'Beban Pajak Penghasilan Kini',
            'metadata' => ['tax_provision_id' => $journal->source_id],
        ]);

        $creditsApplied = (string) $calculation['tax_credits_applied'];
        if (bccomp($creditsApplied, '0', 2) > 0 && $prepaid) {
            JournalEntry::create([
                'journal_id' => $journal->id,
                'line_no' => $line++,
                'account_id' => $prepaid->id,
                'debit' => 0,
                'credit' => $creditsApplied,
                'memo' => 'Pemakaian kredit pajak dibayar di muka',
                'metadata' => ['tax_provision_id' => $journal->source_id],
            ]);
        }

        $payableAmount = (string) $calculation['current_tax_payable'];
        if (bccomp($payableAmount, '0', 2) > 0) {
            JournalEntry::create([
                'journal_id' => $journal->id,
                'line_no' => $line,
                'account_id' => $payable->id,
                'debit' => 0,
                'credit' => $payableAmount,
                'memo' => 'Utang Pajak Penghasilan Kini',
                'metadata' => ['tax_provision_id' => $journal->source_id],
            ]);
        }
    }

    private function percentage(string $amount, string $rate): string
    {
        $raw = bcdiv(bcmul($amount, $rate, 6), '100', 4);

        return bcadd($raw, '0.005', 2);
    }

    private function money(string $value): string
    {
        return bcadd($value, '0', 2);
    }
}
