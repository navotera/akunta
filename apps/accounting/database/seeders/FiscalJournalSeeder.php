<?php

declare(strict_types=1);

namespace Database\Seeders;

use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\UserAppAssignment;
use App\Models\Account;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\Period;
use App\Services\JournalNumberGenerator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class FiscalJournalSeeder extends Seeder
{
    private const SCENARIOS = [
        ['key' => 'fiscal-gaji', 'mode' => Journal::MODE_FISCAL, 'memo' => 'Beban gaji fiskal bulan berjalan', 'amount' => '1000000.00'],
        ['key' => 'fiscal-sewa', 'mode' => Journal::MODE_FISCAL, 'memo' => 'Beban sewa fiskal bulan berjalan', 'amount' => '750000.00'],
        ['key' => 'fiscal-listrik', 'mode' => Journal::MODE_FISCAL, 'memo' => 'Beban listrik fiskal bulan berjalan', 'amount' => '500000.00'],
        ['key' => 'internal-gaji', 'mode' => Journal::MODE_INTERNAL, 'memo' => 'Beban gaji internal bulan berjalan', 'amount' => '950000.00'],
        ['key' => 'internal-sewa', 'mode' => Journal::MODE_INTERNAL, 'memo' => 'Beban sewa internal bulan berjalan', 'amount' => '700000.00'],
        ['key' => 'internal-listrik', 'mode' => Journal::MODE_INTERNAL, 'memo' => 'Beban listrik internal bulan berjalan', 'amount' => '450000.00'],
    ];

    public function run(JournalNumberGenerator $numberGenerator): void
    {
        $entities = Entity::query()->get();
        if ($entities->isEmpty()) {
            $this->command?->warn('  ! FiscalJournalSeeder: no entities found — skipping.');

            return;
        }

        $created = 0;
        $skipped = 0;

        foreach ($entities as $entity) {
            $result = $this->seedEntity($entity, $numberGenerator);
            $created += $result['created'];
            $skipped += $result['skipped'];
        }

        $this->command?->info("  FiscalJournalSeeder: {$created} journals created, {$skipped} already present.");
    }

    /**
     * @return array{created: int, skipped: int}
     */
    private function seedEntity(Entity $entity, JournalNumberGenerator $numberGenerator): array
    {
        $fiscalExpenses = Account::query()
            ->where('entity_id', $entity->id)
            ->where('type', 'expense')
            ->where('is_active', true)
            ->where('is_postable', true)
            ->orderBy('code')
            ->get();

        if ($fiscalExpenses->isEmpty()) {
            $this->command?->warn("  ! {$entity->name}: no postable expense accounts — skipped.");

            return ['created' => 0, 'skipped' => 0];
        }

        $fiscalExpenses->each->update(['availability' => 'both']);

        $date = Carbon::today()->toDateString();
        $period = Period::query()
            ->where('entity_id', $entity->id)
            ->where('status', Period::STATUS_OPEN)
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->first();

        if ($period === null) {
            $this->command?->warn("  ! {$entity->name}: no open period for {$date} — skipped.");

            return ['created' => 0, 'skipped' => 0];
        }

        $paymentAccount = Account::query()
            ->where('entity_id', $entity->id)
            ->where('type', 'asset')
            ->where('is_active', true)
            ->where('is_postable', true)
            ->whereIn('code', ['1101', '1102', '1110'])
            ->orderBy('code')
            ->first();

        if ($paymentAccount === null) {
            $this->command?->warn("  ! {$entity->name}: no cash/bank account — skipped.");

            return ['created' => 0, 'skipped' => 0];
        }

        $postedBy = UserAppAssignment::query()
            ->where('entity_id', $entity->id)
            ->value('user_id');
        $created = 0;
        $skipped = 0;

        foreach (self::SCENARIOS as $index => $scenario) {
            $expense = $fiscalExpenses->get($index % $fiscalExpenses->count());
            if ($expense === null) {
                break;
            }

            $idempotencyKey = "demo-journal:{$entity->id}:{$scenario['key']}:{$date}";
            if (Journal::query()->where('idempotency_key', $idempotencyKey)->exists()) {
                $skipped++;

                continue;
            }

            DB::transaction(function () use ($entity, $period, $expense, $paymentAccount, $scenario, $date, $idempotencyKey, $postedBy, $numberGenerator): void {
                $journal = Journal::create([
                    'entity_id' => $entity->id,
                    'period_id' => $period->id,
                    'type' => Journal::TYPE_GENERAL,
                    'journal_mode' => $scenario['mode'],
                    'number' => $numberGenerator->next($entity->id, $date, $scenario['mode']),
                    'date' => $date,
                    'memo' => $scenario['memo'],
                    'reference' => 'DEMO-JOURNAL-'.$scenario['key'],
                    'source_app' => 'accounting-seeder',
                    'idempotency_key' => $idempotencyKey,
                    'status' => Journal::STATUS_POSTED,
                    'posted_at' => now(),
                    'posted_by' => $postedBy,
                    'created_by' => $postedBy,
                ]);

                JournalEntry::create([
                    'journal_id' => $journal->id,
                    'line_no' => 1,
                    'account_id' => $expense->id,
                    'debit' => $scenario['amount'],
                    'credit' => '0.00',
                    'memo' => $scenario['memo'],
                ]);
                JournalEntry::create([
                    'journal_id' => $journal->id,
                    'line_no' => 2,
                    'account_id' => $paymentAccount->id,
                    'debit' => '0.00',
                    'credit' => $scenario['amount'],
                    'memo' => 'Pembayaran '.$expense->name,
                ]);
            });

            $created++;
        }

        return compact('created', 'skipped');
    }
}
