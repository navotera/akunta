# Step 12c-i — Jurnal Khusus: Sales + Purchase

**Locked:** 2026-04-30
**Scope:** Add Sales Journal + Purchase Journal as Filament Resources sharing single `Journal` model. Foundation untuk AR/AP sub-ledger.
**References:** spec §8.6, architecture §4.4, decisions log entry 2026-04-30.

---

## Pre-flight

Confirm before mulai:
- Branch: cabang dari `main` setelah Reporting Phase 1 + Step 12b-α-ii-min commits.
- Test baseline: full suite green (accounting 94/282 + 32 tests other apps = 126/365 assertions).
- Tenant DB schema currently has `journals` w/ existing TYPE constants. Migration baru menambah kolom + extend enum.
- Tidak ada in-flight migration untuk `journals` table dari step lain.

---

## Acceptance criteria

1. User dapat input Jurnal Penjualan via Filament wizard:
   - Pilih partner (customer), tanggal, reference, lines bisnis (qty × harga × akun pendapatan + tax_code)
   - System auto-generate `JournalEntry[]`: debit AR (Partner.default_ar_account_id atau global setting) atau Kas, credit Pendapatan + PPN Keluaran lines
   - Number auto: `JS-2026-04-NNNN` (counter per entity+type+year-month)
   - Posted journal balanced + immutable
2. Jurnal Pembelian symmetric: vendor partner, debit Inventory/Beban + PPN Masukan, credit AP atau Kas
3. Partner mandatory: form validation gagal kalau partner kosong di Sales/Purchase
4. Period lock honored: tidak bisa post ke periode closed
5. Idempotency: re-submit form dgn `idempotency_key` sama → return existing journal, no duplicate
6. Reporting existing tetap kerja: Trial Balance/Neraca/Laba Rugi include lines dari sales/purchase
7. RBAC: ability `viewAnySales`, `createSales`, `viewAnyPurchase`, `createPurchase` granular
8. Pest tests minimum 12 baru green:
   - `SalesJournalCreationTest` (4): wizard valid posts, partner required, period lock, idempotency
   - `PurchaseJournalCreationTest` (4): symmetric
   - `JournalNumberGeneratorTest` (3): per-type counter, year-month rollover, atomic concurrency
   - `PartnerJournalRelationsTest` (1): scoped relation correctness

---

## Migration order

1. `2026_04_30_100000_add_jurnal_khusus_columns_to_journals.php`
   ```php
   Schema::table('journals', function (Blueprint $t) {
       $t->ulid('partner_id')->nullable()->after('reversed_by_journal_id');
       $t->foreign('partner_id')->references('id')->on('partners')->nullOnDelete();
       $t->decimal('business_total', 20, 2)->nullable()->after('partner_id');
       $t->index(['entity_id', 'type', 'date']);
       $t->index('partner_id');
   });
   // type enum constraint extension — Postgres CHECK constraint:
   DB::statement("ALTER TABLE journals DROP CONSTRAINT IF EXISTS journals_type_check");
   DB::statement("ALTER TABLE journals ADD CONSTRAINT journals_type_check CHECK (type IN (
       'general','adjustment','closing','reversing','opening',
       'sales','purchase','cash_receipt','cash_disbursement'
   ))");
   ```
2. `2026_04_30_100100_add_applies_to_type_to_journal_templates.php`
   ```php
   Schema::table('journal_templates', function (Blueprint $t) {
       $t->string('applies_to_type')->nullable()->after('source_app');
       $t->index('applies_to_type');
   });
   ```

Both tagged `--database=tenant_*` — applied per-tenant via existing migration runner.

---

## Files baru

```
apps/accounting/
├── app/
│   ├── Actions/Journal/Special/
│   │   ├── PostSalesJournalAction.php
│   │   ├── PostPurchaseJournalAction.php
│   │   └── Dto/
│   │       ├── SalesJournalDto.php
│   │       ├── SalesJournalLineDto.php
│   │       ├── PurchaseJournalDto.php
│   │       └── PurchaseJournalLineDto.php
│   ├── Filament/Resources/
│   │   ├── SalesJournalResource.php
│   │   ├── SalesJournalResource/Pages/{ListSalesJournals,CreateSalesJournal}.php
│   │   ├── PurchaseJournalResource.php
│   │   └── PurchaseJournalResource/Pages/{ListPurchaseJournals,CreatePurchaseJournal}.php
│   ├── Policies/
│   │   ├── SalesJournalPolicy.php
│   │   └── PurchaseJournalPolicy.php
│   └── Services/
│       └── JournalNumberGenerator.php
├── database/migrations/
│   ├── 2026_04_30_100000_add_jurnal_khusus_columns_to_journals.php
│   └── 2026_04_30_100100_add_applies_to_type_to_journal_templates.php
└── tests/Feature/Journal/Special/
    ├── SalesJournalCreationTest.php
    ├── PurchaseJournalCreationTest.php
    └── JournalNumberGeneratorTest.php
```

## Files diubah

```
apps/accounting/app/Models/
├── Journal.php           — TYPE_SALES/PURCHASE/CASH_RECEIPT/CASH_DISBURSEMENT constants + partner() relation + scope methods
├── Partner.php           — salesJournals(), purchaseJournals(), cashReceiptJournals(), cashDisbursementJournals()
└── JournalTemplate.php   — applies_to_type fillable + cast

apps/accounting/database/factories/
└── JournalFactory.php    — state methods sales(), purchase()

apps/accounting/tests/Feature/Journal/Special/
└── (existing PartnerJournalRelationsTest extended atau baru)
```

---

## Wizard form spec (Filament Stepper)

### SalesJournalResource Create page

**Step 1 — Header**
- `entity_id` (auto from session context, hidden)
- `partner_id` (Select required — only customers, type='customer' or type='both')
- `date` (DatePicker required, default today, `afterOrEqual` period start, `beforeOrEqual` period end)
- `reference` (TextInput nullable — no faktur eksternal)
- `memo` (Textarea nullable)
- `is_cash` (Toggle — true = Kas debit, false = AR debit; default false)
- `cash_account_id` (Select, only when `is_cash=true`, filter type='asset' + bank/kas group)

**Step 2 — Lines bisnis (Repeater)**
- `account_id` (Select required — type='revenue', is_postable=true)
- `description` (TextInput nullable)
- `qty` (TextInput numeric default 1)
- `unit_price` (TextInput numeric required, bcmath string)
- `subtotal` (computed `qty × unit_price`, read-only)
- `tax_code_id` (Select nullable — type='vat_out' atau 'wht')
- `cost_center_id` (Select nullable)
- `project_id` (Select nullable)

Footer total: sum subtotals + sum tax (computed read-only).

**Step 3 — Review jurnal**
- Generate preview via `PostSalesJournalAction::preview($dto)` — returns `Journal` (unsaved) w/ entries
- Display table read-only: Account | Debit | Credit
- Display: Total Debit, Total Credit, Balanced ✓/✗
- Submit → call `PostSalesJournalAction::execute($dto)` → redirect to list

### PurchaseJournalResource Create page

Symmetric. `partner_id` filter type='vendor' or 'both'. Step 2 line `account_id` filter type='expense' OR 'asset' (untuk inventory/aset yg dibeli). `tax_code_id` filter 'vat_in' atau 'wht'. `is_cash` true = Kas credit, false = AP credit.

---

## PostSalesJournalAction skeleton

```php
final class PostSalesJournalAction
{
    public function __construct(
        private readonly JournalNumberGenerator $numberGen,
        private readonly TaxLineResolver $taxResolver,
    ) {}

    public function execute(SalesJournalDto $dto): Journal
    {
        return DB::transaction(function () use ($dto) {
            $this->guardPeriodOpen($dto->entityId, $dto->date);
            if ($dto->idempotencyKey && $existing = $this->findByIdempotencyKey($dto)) {
                return $existing;
            }

            $journal = Journal::create([
                'entity_id' => $dto->entityId,
                'period_id' => Period::resolveFor($dto->entityId, $dto->date)->id,
                'type' => Journal::TYPE_SALES,
                'number' => $this->numberGen->next($dto->entityId, Journal::TYPE_SALES, $dto->date),
                'date' => $dto->date,
                'reference' => $dto->reference,
                'memo' => $dto->memo,
                'partner_id' => $dto->partnerId,
                'business_total' => $this->sumLines($dto->lines),
                'idempotency_key' => $dto->idempotencyKey,
                'created_by' => auth()->id(),
            ]);

            $entries = $this->generateEntries($dto, $journal);
            $journal->entries()->createMany($entries);
            $this->guardBalanced($journal);

            $journal->update([
                'status' => Journal::STATUS_POSTED,
                'posted_at' => now(),
                'posted_by' => auth()->id(),
            ]);

            event(new JournalPosted($journal));
            return $journal->fresh('entries');
        });
    }

    public function preview(SalesJournalDto $dto): Journal { /* same as execute, no save, return unsaved Journal */ }

    private function generateEntries(SalesJournalDto $dto, Journal $j): array
    {
        $entries = [];
        $lineNo = 1;
        $totalDebit = '0';

        // Revenue + tax lines (credit side)
        foreach ($dto->lines as $line) {
            $entries[] = [
                'line_no' => $lineNo++,
                'account_id' => $line->accountId,
                'debit' => '0',
                'credit' => $line->subtotal,
                'memo' => $line->description,
                'metadata' => ['cost_center_id' => $line->costCenterId, 'project_id' => $line->projectId],
            ];
            $totalDebit = bcadd($totalDebit, $line->subtotal, 2);

            if ($line->taxCodeId) {
                $taxLine = $this->taxResolver->resolve($line->taxCodeId, $line->subtotal, isOutput: true);
                $entries[] = [
                    'line_no' => $lineNo++,
                    'account_id' => $taxLine->accountId,
                    'debit' => '0',
                    'credit' => $taxLine->amount,
                    'memo' => "Tax {$taxLine->code}",
                    'metadata' => ['tax_code_id' => $line->taxCodeId],
                ];
                $totalDebit = bcadd($totalDebit, $taxLine->amount, 2);
            }
        }

        // AR or Cash (debit side, single line)
        $debitAccount = $dto->isCash ? $dto->cashAccountId : $this->resolveArAccount($dto->partnerId, $dto->entityId);
        $entries[] = [
            'line_no' => $lineNo++,
            'account_id' => $debitAccount,
            'debit' => $totalDebit,
            'credit' => '0',
            'memo' => null,
            'metadata' => [],
        ];

        return $entries;
    }
}
```

Symmetric structure untuk `PostPurchaseJournalAction` (debit/credit roles swapped, AP instead of AR, vat_in instead of vat_out).

---

## JournalNumberGenerator

```php
final class JournalNumberGenerator
{
    private const PREFIX_MAP = [
        Journal::TYPE_SALES => 'JS',
        Journal::TYPE_PURCHASE => 'JP',
        Journal::TYPE_CASH_RECEIPT => 'JKM',
        Journal::TYPE_CASH_DISBURSEMENT => 'JKK',
        Journal::TYPE_GENERAL => 'JU',
        Journal::TYPE_ADJUSTMENT => 'JA',
        Journal::TYPE_CLOSING => 'JC',
    ];

    public function next(string $entityId, string $type, Carbon $date): string
    {
        $prefix = self::PREFIX_MAP[$type] ?? 'J';
        $yearMonth = $date->format('Y-m');

        return DB::transaction(function () use ($entityId, $type, $date, $prefix, $yearMonth) {
            // Atomic: SELECT ... FOR UPDATE pattern via unique counter row
            $counter = JournalNumberCounter::lockForUpdate()->firstOrCreate(
                ['entity_id' => $entityId, 'type' => $type, 'year_month' => $yearMonth],
                ['next_seq' => 1]
            );
            $seq = $counter->next_seq;
            $counter->increment('next_seq');

            return sprintf('%s-%s-%04d', $prefix, $yearMonth, $seq);
        });
    }
}
```

Need new migration `journal_number_counters` table:
```sql
journal_number_counters
├── id (PK, ulid)
├── entity_id (FK)
├── type (string)
├── year_month (string, e.g. '2026-04')
├── next_seq (int)
└── UQ (entity_id, type, year_month)
```

→ add as 3rd migration: `2026_04_30_100200_create_journal_number_counters_table.php`.

---

## Test plan (Pest)

```
tests/Feature/Journal/Special/SalesJournalCreationTest.php
  it('creates posted balanced sales journal with AR debit and revenue+tax credits')
  it('rejects sales journal without partner')
  it('rejects sales journal posted to closed period')
  it('returns existing journal when idempotency_key duplicate')

tests/Feature/Journal/Special/PurchaseJournalCreationTest.php
  it('creates posted balanced purchase journal with expense+tax debits and AP credit')
  it('rejects purchase journal without partner')
  it('honors cash purchase variant — credits Kas instead of AP')
  it('auto-injects PPh withholding line when tax_code is wht type')

tests/Feature/Journal/Special/JournalNumberGeneratorTest.php
  it('generates JS prefix for sales, JP for purchase, JKM for cash_receipt, JKK for cash_disbursement')
  it('rolls over counter at month boundary')
  it('handles concurrent generation atomically via row lock')
```

Target: 11 baru tests minimum, ~30+ assertions. Suite total target naik dari 94/282 → ~105/315.

---

## Quality gates (per laravel:quality-checks)

- `composer pint` — pass
- `composer phpstan` (level 7) — pass
- `composer test` — accounting suite hijau, no regression di existing 94 tests
- Manual smoke: open Filament panel, create 1 sales + 1 purchase via wizard, verify number sequence + Trial Balance reflects new entries

---

## Rollback strategy

Per migration reversible (`down()`):
- Drop `partner_id`, `business_total` columns
- Restore old `journals_type_check` constraint (5 values)
- Drop `applies_to_type` column
- Drop `journal_number_counters` table

Resource removal: hapus 4 file Resource + 2 Policy + 2 Action + 4 DTO. Service `JournalNumberGenerator` standalone, aman tetap.

---

## Open questions (decide before code)

1. **AR/AP account resolution per partner:** Apakah `partners.default_ar_account_id` / `default_ap_account_id` sudah ada column? Atau pakai global setting (entity-level)?
   - **Default rekomendasi:** Cek model Partner. Kalau belum ada, tambah 2 column nullable + fallback ke entity setting `default_ar_account_id` / `default_ap_account_id`.
2. **Inventory di Purchase:** v1 minimal — line item `account_id` bisa point ke akun aset (1xxx) atau beban (5xxx/6xxx). No tracking qty di stock master (deferred ke Inventory v2).
3. **Reverse mechanism:** Reuse existing `reverseJournal()` flow? Atau add explicit "Reverse" action di Resource? **Default:** reuse existing.

---

## Out of scope (eksplisit ditangguhkan)

- Multi-currency Sales/Purchase
- Inventory qty tracking + COGS auto-journal
- Approval workflow (multi-level review)
- Recurring sales/purchase (defer 12c-iv)
- Cash Receipt/Disbursement (defer 12c-ii)
- Buku Pembantu reports (defer 12c-iii)
- Register PPN reports (defer 12c-iii)

---

## Ready to execute?

Pre-conditions di atas resolved → execute step 12c-i:

1. Migration (3 file) + run `php artisan migrate --database=tenant_<ulid>` di tenant test
2. Model updates (Journal, Partner, JournalTemplate)
3. JournalNumberGenerator service + counter table
4. Action + DTO classes (Sales + Purchase)
5. Filament Resources + Pages + Policies (Sales + Purchase)
6. Pest tests (11 baru)
7. Quality gates
8. Manual smoke test
9. Update `CLAUDE.md` status line + memory

Estimated touch: ~25 file baru, ~5 file diubah.
