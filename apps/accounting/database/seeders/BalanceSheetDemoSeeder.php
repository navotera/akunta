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
use Illuminate\Support\Facades\DB;

/**
 * Seeds a balanced, representative balance sheet for local demos.
 *
 * Each scenario is created for Internal and Fiscal modes, so the comparative
 * Balance Sheet has meaningful rows in both columns.
 */
class BalanceSheetDemoSeeder extends Seeder
{
    private const SCENARIOS = [
        ['key' => 'initial-capital', 'memo' => 'Setoran modal awal', 'debit' => 'cash', 'credit' => 'capital', 'amount' => '250000000.00'],
        ['key' => 'bank-loan', 'memo' => 'Penerimaan pinjaman bank', 'debit' => 'bank', 'credit' => 'loan', 'amount' => '150000000.00'],
        ['key' => 'inventory-cash', 'memo' => 'Pembelian persediaan tunai', 'debit' => 'inventory', 'credit' => 'cash', 'amount' => '40000000.00'],
        ['key' => 'credit-sale', 'memo' => 'Penjualan kredit', 'debit' => 'receivable', 'credit' => 'revenue', 'amount' => '60000000.00'],
        ['key' => 'inventory-payable', 'memo' => 'Pembelian persediaan kredit', 'debit' => 'inventory', 'credit' => 'payable', 'amount' => '30000000.00'],
        ['key' => 'equipment-purchase', 'memo' => 'Pembelian peralatan usaha', 'debit' => 'equipment', 'credit' => 'bank', 'amount' => '25000000.00'],
        ['key' => 'prepayment-tax', 'memo' => 'Pembayaran biaya dibayar di muka', 'debit' => 'prepayment', 'credit' => 'tax_payable', 'amount' => '5500000.00'],
    ];

    private const ACCOUNT_CODES = [
        'cash' => ['1101'],
        'bank' => ['1102'],
        'receivable' => ['1103', '1201'],
        'inventory' => ['1104', '1301'],
        'prepayment' => ['1105', '1401'],
        'equipment' => ['1204', '1501'],
        'payable' => ['2101'],
        'tax_payable' => ['2103', '2102'],
        'loan' => ['2201'],
        'capital' => ['3101'],
        'revenue' => ['4101'],
    ];

    public function run(JournalNumberGenerator $numberGenerator): void
    {
        Entity::query()->each(function (Entity $entity) use ($numberGenerator): void {
            $result = $this->seedEntity($entity, $numberGenerator);
            $this->command?->info("  BalanceSheetDemoSeeder [{$entity->name}]: created={$result['created']} skipped={$result['skipped']}.");
        });
    }

    /** @return array{created: int, skipped: int} */
    private function seedEntity(Entity $entity, JournalNumberGenerator $numberGenerator): array
    {
        $date = today()->toDateString();
        $period = Period::query()
            ->where('entity_id', $entity->id)
            ->where('status', Period::STATUS_OPEN)
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->first();
        if (! $period) {
            $this->command?->warn("  ! {$entity->name}: no open period for {$date} — skipped.");

            return ['created' => 0, 'skipped' => 0];
        }

        $accountsByCode = Account::query()
            ->where('entity_id', $entity->id)
            ->whereIn('code', collect(self::ACCOUNT_CODES)->flatten())
            ->where('is_active', true)
            ->where('is_postable', true)
            ->get()
            ->keyBy('code');
        $accounts = collect(self::ACCOUNT_CODES)
            ->map(fn (array $codes) => collect($codes)->map(fn (string $code) => $accountsByCode->get($code))->first(fn ($account) => $account !== null));
        $missing = $accounts->filter(fn ($account) => $account === null)->keys();
        if ($missing->isNotEmpty()) {
            $this->command?->warn("  ! {$entity->name}: missing postable accounts for {$missing->implode(', ')} — skipped.");

            return ['created' => 0, 'skipped' => 0];
        }

        $accounts->each->update(['availability' => Account::AVAILABILITY_BOTH]);
        $postedBy = UserAppAssignment::query()->where('entity_id', $entity->id)->value('user_id');
        $created = 0;
        $skipped = 0;

        foreach ([Journal::MODE_INTERNAL, Journal::MODE_FISCAL] as $mode) {
            foreach (self::SCENARIOS as $scenario) {
                $idempotencyKey = "balance-sheet-demo:{$entity->id}:{$mode}:{$scenario['key']}:{$date}";
                if (Journal::query()->where('idempotency_key', $idempotencyKey)->exists()) {
                    $skipped++;

                    continue;
                }

                DB::transaction(function () use ($entity, $period, $date, $mode, $scenario, $accounts, $idempotencyKey, $postedBy, $numberGenerator): void {
                    $journal = Journal::create([
                        'entity_id' => $entity->id,
                        'period_id' => $period->id,
                        'type' => Journal::TYPE_GENERAL,
                        'journal_mode' => $mode,
                        'number' => $numberGenerator->next($entity->id, $date, $mode),
                        'date' => $date,
                        'reference' => 'BS-DEMO-'.strtoupper($scenario['key']),
                        'memo' => $scenario['memo'],
                        'source_app' => 'balance-sheet-demo-seeder',
                        'idempotency_key' => $idempotencyKey,
                        'status' => Journal::STATUS_POSTED,
                        'posted_at' => now(),
                        'posted_by' => $postedBy,
                        'created_by' => $postedBy,
                    ]);

                    JournalEntry::create([
                        'journal_id' => $journal->id,
                        'line_no' => 1,
                        'account_id' => $accounts[$scenario['debit']]->id,
                        'debit' => $scenario['amount'],
                        'credit' => '0.00',
                        'memo' => $scenario['memo'],
                    ]);
                    JournalEntry::create([
                        'journal_id' => $journal->id,
                        'line_no' => 2,
                        'account_id' => $accounts[$scenario['credit']]->id,
                        'debit' => '0.00',
                        'credit' => $scenario['amount'],
                        'memo' => $scenario['memo'],
                    ]);
                });

                $created++;
            }
        }

        return compact('created', 'skipped');
    }
}
