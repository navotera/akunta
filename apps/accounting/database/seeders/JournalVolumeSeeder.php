<?php

declare(strict_types=1);

namespace Database\Seeders;

use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\UserAppAssignment;
use App\Models\Account;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\Period;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds a sizeable, balanced ledger for reporting and performance testing.
 *
 * Run explicitly with: php artisan db:seed --class=JournalVolumeSeeder
 */
class JournalVolumeSeeder extends Seeder
{
    public const JOURNALS_PER_ENTITY = 5000;

    private const FISCAL_JOURNALS = 2500;

    private const BATCH_SIZE = 500;

    public function run(): void
    {
        $entities = Entity::query()->get();
        if ($entities->isEmpty()) {
            $this->command?->warn('  ! JournalVolumeSeeder: no entities found — skipping.');

            return;
        }

        foreach ($entities as $entity) {
            $result = $this->seedEntity($entity);
            $this->command?->info(sprintf(
                '  JournalVolumeSeeder [%s]: created=%d skipped=%d total=%d.',
                $entity->name,
                $result['created'],
                $result['skipped'],
                self::JOURNALS_PER_ENTITY,
            ));
        }
    }

    /**
     * @return array{created: int, skipped: int}
     */
    private function seedEntity(Entity $entity): array
    {
        $periods = Period::query()
            ->where('entity_id', $entity->id)
            ->where('status', Period::STATUS_OPEN)
            ->whereDate('end_date', '>=', Carbon::now()->startOfYear())
            ->whereDate('start_date', '<=', Carbon::now()->endOfYear())
            ->orderBy('start_date')
            ->get();

        if ($periods->isEmpty()) {
            $this->command?->warn("  ! {$entity->name}: no open period in the current year — skipped.");

            return ['created' => 0, 'skipped' => 0];
        }

        $internalAccounts = $this->accountsForMode($entity->id, Journal::MODE_INTERNAL);
        $fiscalAccounts = $this->accountsForMode($entity->id, Journal::MODE_FISCAL);

        // The standard CoA starts as internal-only. Promote only the two
        // accounts used by fiscal demo rows when no fiscal pair exists.
        if ($fiscalAccounts['debit']->isEmpty() || $fiscalAccounts['credit']->isEmpty()) {
            $this->promoteFiscalPair($entity->id);
            $fiscalAccounts = $this->accountsForMode($entity->id, Journal::MODE_FISCAL);
        }

        if ($internalAccounts['debit']->isEmpty() || $internalAccounts['credit']->isEmpty()
            || $fiscalAccounts['debit']->isEmpty() || $fiscalAccounts['credit']->isEmpty()) {
            $this->command?->warn("  ! {$entity->name}: insufficient postable accounts for both modes — skipped.");

            return ['created' => 0, 'skipped' => 0];
        }

        $postedBy = UserAppAssignment::query()
            ->where('entity_id', $entity->id)
            ->value('user_id');
        $existingKeys = Journal::query()
            ->where('source_app', 'journal-volume-seeder')
            ->where('entity_id', $entity->id)
            ->pluck('idempotency_key')
            ->flip();
        $counters = $this->numberCounters($entity->id);
        $journalRows = [];
        $entryRows = [];
        $created = 0;
        $skipped = 0;
        $now = now();

        DB::transaction(function () use (
            $entity,
            $periods,
            $internalAccounts,
            $fiscalAccounts,
            $postedBy,
            $existingKeys,
            &$counters,
            &$journalRows,
            &$entryRows,
            &$created,
            &$skipped,
            $now,
        ): void {
            for ($index = 1; $index <= self::JOURNALS_PER_ENTITY; $index++) {
                $key = "volume-journal:{$entity->id}:{$index}";
                if ($existingKeys->has($key)) {
                    $skipped++;

                    continue;
                }

                $mode = $index <= self::FISCAL_JOURNALS
                    ? Journal::MODE_FISCAL
                    : Journal::MODE_INTERNAL;
                $accounts = $mode === Journal::MODE_FISCAL ? $fiscalAccounts : $internalAccounts;
                $period = $periods->random();
                $date = $this->randomDateInPeriod($period);
                $amount = number_format(random_int(100, 10000000) / 100, 2, '.', '');
                $series = ($mode === Journal::MODE_FISCAL ? 'JF' : 'JI').'-'.$date->format('Ym');
                $counters[$mode][$series] = ($counters[$mode][$series] ?? 0) + 1;
                $number = $series.'-'.str_pad((string) $counters[$mode][$series], 4, '0', STR_PAD_LEFT);
                $journalId = (string) Str::ulid();
                $debitAccount = $accounts['debit']->random();
                $creditAccount = $accounts['credit']->random();

                $journalRows[] = [
                    'id' => $journalId,
                    'entity_id' => $entity->id,
                    'period_id' => $period->id,
                    'type' => Journal::TYPE_GENERAL,
                    'journal_mode' => $mode,
                    'number' => $number,
                    'date' => $date->toDateString(),
                    'reference' => 'VOL-'.str_pad((string) $index, 5, '0', STR_PAD_LEFT),
                    'memo' => $mode === Journal::MODE_FISCAL ? 'Transaksi fiskal demo' : 'Transaksi intern demo',
                    'source_app' => 'journal-volume-seeder',
                    'idempotency_key' => $key,
                    'status' => Journal::STATUS_POSTED,
                    'posted_at' => $now,
                    'posted_by' => $postedBy,
                    'created_by' => $postedBy,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $entryRows[] = [
                    'id' => (string) Str::ulid(),
                    'journal_id' => $journalId,
                    'line_no' => 1,
                    'account_id' => $debitAccount->id,
                    'debit' => $amount,
                    'credit' => '0.00',
                    'memo' => 'Debit '.$debitAccount->name,
                ];
                $entryRows[] = [
                    'id' => (string) Str::ulid(),
                    'journal_id' => $journalId,
                    'line_no' => 2,
                    'account_id' => $creditAccount->id,
                    'debit' => '0.00',
                    'credit' => $amount,
                    'memo' => 'Credit '.$creditAccount->name,
                ];
                $created++;

                if (count($journalRows) >= self::BATCH_SIZE) {
                    $this->flushRows($journalRows, $entryRows);
                }
            }

            $this->flushRows($journalRows, $entryRows);
        });

        return compact('created', 'skipped');
    }

    /** @return array{debit: Collection, credit: Collection} */
    private function accountsForMode(string $entityId, string $mode): array
    {
        $availability = $mode === Journal::MODE_FISCAL
            ? [Account::AVAILABILITY_FISKAL, Account::AVAILABILITY_BOTH]
            : [Account::AVAILABILITY_INTERN, Account::AVAILABILITY_BOTH];

        $accounts = Account::query()
            ->where('entity_id', $entityId)
            ->where('is_active', true)
            ->where('is_postable', true)
            ->whereIn('availability', $availability)
            ->get();

        return [
            'debit' => $accounts->whereIn('type', ['asset', 'expense', 'cogs', 'other']),
            'credit' => $accounts->whereIn('type', ['asset', 'liability', 'equity', 'revenue', 'other']),
        ];
    }

    private function promoteFiscalPair(string $entityId): void
    {
        $debit = Account::query()
            ->where('entity_id', $entityId)
            ->where('is_active', true)
            ->where('is_postable', true)
            ->whereIn('type', ['expense', 'cogs'])
            ->orderBy('code')
            ->first();
        $credit = Account::query()
            ->where('entity_id', $entityId)
            ->where('is_active', true)
            ->where('is_postable', true)
            ->whereIn('type', ['asset', 'liability', 'revenue'])
            ->orderBy('code')
            ->first();

        $debit?->update(['availability' => Account::AVAILABILITY_BOTH]);
        $credit?->update(['availability' => Account::AVAILABILITY_BOTH]);
    }

    /** @return array{internal: array<string, int>, fiscal: array<string, int>} */
    private function numberCounters(string $entityId): array
    {
        $counters = ['internal' => [], 'fiscal' => []];
        Journal::query()
            ->where('entity_id', $entityId)
            ->whereIn('journal_mode', [Journal::MODE_INTERNAL, Journal::MODE_FISCAL])
            ->get(['journal_mode', 'number'])
            ->each(function (Journal $journal) use (&$counters): void {
                if (preg_match('/^(JI|JF)-\d{6}-(\d+)$/', $journal->number, $matches) !== 1) {
                    return;
                }

                $mode = $journal->journal_mode;
                $series = substr($journal->number, 0, 9);
                $counters[$mode][$series] = max($counters[$mode][$series] ?? 0, (int) $matches[2]);
            });

        return $counters;
    }

    private function randomDateInPeriod(Period $period): Carbon
    {
        $start = Carbon::parse($period->start_date)->max(Carbon::now()->startOfYear());
        $end = Carbon::parse($period->end_date)->min(Carbon::now()->endOfYear());

        return $start->copy()->addDays(random_int(0, (int) $start->diffInDays($end)));
    }

    private function flushRows(array &$journalRows, array &$entryRows): void
    {
        if ($journalRows === []) {
            return;
        }

        Journal::query()->insert($journalRows);
        JournalEntry::query()->insert($entryRows);
        $journalRows = [];
        $entryRows = [];
    }
}
