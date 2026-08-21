<?php

declare(strict_types=1);

namespace App\Services;

use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\UserAppAssignment;
use App\Models\Account;
use App\Models\AutoMappingRawData;
use App\Models\FakeDataRecord;
use App\Models\FiscalAdjustment;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\JournalTemplate;
use App\Models\JournalTemplateLine;
use App\Models\Period;
use App\Models\RecurringJournal;
use App\Models\SourceRefRegistry;
use App\Models\User;
use App\Services\Onboarding\CoaTemplateRegistry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class FakeDataService
{
    public const GROUPS = [
        'periods' => ['label' => 'Periode Akuntansi', 'description' => 'Periode bulanan tahun berjalan untuk simulasi pembukuan.'],
        'accounts' => ['label' => 'COA Teknologi & IT', 'description' => 'COA untuk custom software, SaaS, hosting, hardware/software IT, konsultasi, dan managed services.'],
        'journal_templates' => ['label' => 'Template Jurnal IT', 'description' => 'Template SaaS, hosting, cloud, payroll, dan penyusutan untuk latihan.'],
        'recurring_journals' => ['label' => 'Jurnal Berulang', 'description' => 'Jadwal berulang untuk SaaS, cloud, dan payroll pada periode pilihan.', 'requires_period' => true],
        'journals' => ['label' => 'Jurnal & Laporan Demo', 'description' => 'Jurnal Intern dan Fiskal yang mengisi dashboard, neraca saldo, neraca, buku besar, dan buku pembantu.', 'requires_period' => true],
        'users' => ['label' => 'User & Roles Demo', 'description' => 'Akun operator, supervisor, dan Inspector untuk menguji alur review jurnal serta inspeksi read-only.'],
        'auto_mapping' => ['label' => 'Auto Mapping Raw Data', 'description' => '30 contoh JSON transaksi dari payroll, kas kecil, POS, bank, pajak, dan sumber eksternal lain.'],
    ];

    public function groups(Entity $entity): array
    {
        return collect(self::GROUPS)->map(function (array $definition, string $key) use ($entity): array {
            return [
                'key' => $key,
                ...$definition,
                'requires_period' => (bool) ($definition['requires_period'] ?? false),
                'count' => FakeDataRecord::where('entity_id', $entity->id)->where('group_key', $key)->count(),
            ];
        })->values()->all();
    }

    public function fakeUsers(Entity $entity): array
    {
        return FakeDataRecord::query()->where('entity_id', $entity->id)->where('group_key', 'users')->where('model_type', User::class)->get()->map(function (FakeDataRecord $marker): ?array {
            $user = User::find($marker->model_id);
            if (! $user) {
                return null;
            }

            return ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'roles' => UserAppAssignment::where('user_id', $user->id)->whereNull('revoked_at')->with('role')->get()->pluck('role.name')->filter()->values()->all()];
        })->filter()->values()->all();
    }

    public function import(Entity $entity, string $group, ?Period $period = null): int
    {
        abort_unless(isset(self::GROUPS[$group]), 404, 'Kelompok fake data tidak ditemukan.');

        if (($this->groupRequiresPeriod($group)) && $period === null) {
            throw new \InvalidArgumentException('Pilih periode untuk mengimpor data keuangan fake.');
        }

        return DB::transaction(fn (): int => match ($group) {
            'periods' => $this->importPeriods($entity),
            'accounts' => $this->importAccounts($entity),
            'journal_templates' => $this->importJournalTemplates($entity),
            'recurring_journals' => $this->importRecurringJournals($entity, $period),
            'journals' => $this->importJournals($entity, $period),
            'users' => $this->importUsers($entity),
            'auto_mapping' => $this->importAutoMapping($entity),
        });
    }

    public function groupRequiresPeriod(string $group): bool
    {
        return (bool) (self::GROUPS[$group]['requires_period'] ?? false);
    }

    public function delete(Entity $entity, string $group): int
    {
        abort_unless(isset(self::GROUPS[$group]), 404, 'Kelompok fake data tidak ditemukan.');

        return DB::transaction(function () use ($entity, $group): int {
            $priority = [
                FiscalAdjustment::class => 10,
                UserAppAssignment::class => 10,
                RecurringJournal::class => 10,
                Journal::class => 20,
                SourceRefRegistry::class => 30,
                JournalTemplate::class => 40,
                Account::class => 50,
                Period::class => 60,
                User::class => 70,
            ];
            $records = FakeDataRecord::where('entity_id', $entity->id)
                ->where('group_key', $group)
                ->get()
                ->sortBy(fn (FakeDataRecord $record) => $priority[$record->model_type] ?? 25);
            $deleted = 0;
            foreach ($records as $marker) {
                $modelType = $marker->model_type;
                $model = $modelType::query()->find($marker->model_id);
                if (! $model) {
                    $marker->delete();

                    continue;
                }

                $modelEntityId = $model->getAttribute('entity_id');
                if ($modelEntityId !== null && (string) $modelEntityId !== (string) $entity->id) {
                    // A corrupt/cross-tenant marker must never authorize deletion.
                    $marker->delete();

                    continue;
                }
                if ($model instanceof Account && $model->isSystemAccount()) {
                    // A required account may have originated from the fake COA,
                    // but it is now part of the entity's permanent baseline.
                    $marker->delete();

                    continue;
                }
                if ($model instanceof Period && Journal::where('period_id', $model->id)->exists()) {
                    continue;
                }
                if ($model instanceof Account && (
                    JournalEntry::where('account_id', $model->id)->exists()
                    || JournalTemplateLine::where('account_id', $model->id)->exists()
                    || FiscalAdjustment::where('account_id', $model->id)->exists()
                )) {
                    continue;
                }
                if ($model instanceof JournalTemplate && RecurringJournal::where('template_id', $model->id)->exists()) {
                    continue;
                }
                if ($model instanceof SourceRefRegistry && JournalEntry::query()
                    ->where('source_app', $model->source_app)
                    ->where('source_ref_type', $model->ref_type)
                    ->where('source_ref_id', $model->ref_id)
                    ->exists()) {
                    continue;
                }
                if ($model instanceof User && $this->userHasNonFakeDependencies($model, $entity, $marker)) {
                    continue;
                }

                $model->delete();
                $marker->delete();
                $deleted++;
            }

            return $deleted;
        });
    }

    private function importPeriods(Entity $entity): int
    {
        // Never create overlapping demo periods around periods configured by
        // the user. The period importer is only a bootstrap for an empty entity.
        if (Period::where('entity_id', $entity->id)->exists()) {
            $this->synchronizeFakePeriodStatuses($entity);

            return 0;
        }

        $created = 0;
        $currentYear = now()->year;
        for ($year = $currentYear - 4; $year <= $currentYear; $year++) {
            $start = Carbon::create($year, 1, 1)->startOfYear();
            $end = $start->copy()->endOfYear();
            $period = Period::firstOrCreate(
                ['entity_id' => $entity->id, 'start_date' => $start->toDateString()],
                ['name' => 'Demo '.$start->format('Y'), 'end_date' => $end->toDateString(), 'status' => Period::STATUS_OPEN],
            );
            if ($period->wasRecentlyCreated) {
                $this->mark($entity, 'periods', $period);
                $created++;
            }
        }

        $this->synchronizeFakePeriodStatuses($entity);

        return $created;
    }

    private function importAccounts(Entity $entity): int
    {
        $rows = app(CoaTemplateRegistry::class)->load('teknologi');
        $independentBooks = data_get($entity->workspace_settings, 'bookkeeping_mode', 'independent_books') === 'independent_books';
        $created = 0;
        $existingAccounts = Account::where('entity_id', $entity->id)->get();
        $markedFakeAccountIds = FakeDataRecord::query()
            ->where('entity_id', $entity->id)
            ->where('group_key', 'accounts')
            ->where('model_type', Account::class)
            ->pluck('model_id')
            ->flip();
        $idsByCode = [];
        $managedCodes = [];

        foreach ($rows as $row) {
            [$code, $name, $type, $normalBalance, , $isPostable] = $row;
            $availability = $row[6] ?? Account::AVAILABILITY_BOTH;
            $description = $row[7] ?? null;
            if (! $independentBooks) {
                if ($availability === Account::AVAILABILITY_FISKAL) {
                    continue;
                }
                $availability = Account::AVAILABILITY_INTERN;
            }

            // A canonical-code match may be upgraded only when provenance
            // proves this importer owns the account in the same entity.
            // This repairs stale native demo COA classifications without
            // mutating a manual account that happens to use the same code.
            $account = $existingAccounts->first(fn (Account $candidate): bool => strcasecmp($candidate->code, $code) === 0
                && $markedFakeAccountIds->has((string) $candidate->id));
            if ($account) {
                $account->forceFill([
                    'name' => $name,
                    'description' => $description,
                    'type' => $type,
                    'normal_balance' => $normalBalance,
                    'is_postable' => $isPostable,
                    'is_active' => true,
                    'availability' => $availability,
                ])->save();
                $managedCodes[] = $code;
            } else {
                $account = $this->findEquivalentAccount($existingAccounts, $row, $availability);
            }
            if (! $account) {
                $resolvedCode = $this->availableTechnologyCode($existingAccounts, $code);
                $account = Account::create([
                    'entity_id' => $entity->id,
                    'code' => $resolvedCode,
                    'name' => $name,
                    'description' => $description,
                    'type' => $type,
                    'normal_balance' => $normalBalance,
                    'is_postable' => $isPostable,
                    'is_active' => true,
                    'availability' => $availability,
                ]);
                $existingAccounts->push($account);
            }

            $idsByCode[$code] = $account->id;
            if ($account->wasRecentlyCreated) {
                $this->mark($entity, 'accounts', $account);
                $managedCodes[] = $code;
                $created++;
            } elseif ($description !== null && FakeDataRecord::query()
                ->where('entity_id', $entity->id)
                ->where('group_key', 'accounts')
                ->where('model_type', Account::class)
                ->where('model_id', $account->id)
                ->exists()) {
                // Backfill only accounts proven to originate from this fake
                // importer. A manual account sharing the code is untouched.
                $account->update(['description' => $description]);
            }
        }

        foreach ($rows as $row) {
            [$code, , , , $parentCode] = $row;
            if (! in_array($code, $managedCodes, true) || $parentCode === null) {
                continue;
            }
            Account::whereKey($idsByCode[$code])->update([
                'parent_account_id' => $idsByCode[$parentCode] ?? null,
            ]);
        }

        app(RequiredAccountService::class)->ensure($entity);

        return $created;
    }

    private function importJournalTemplates(Entity $entity): int
    {
        $definitions = [
            ['FAKE-IT-SAAS', 'Tagihan SaaS Bulanan', Journal::MODE_INTERNAL, [['1201', 'debit', 33_300_000], ['4102', 'credit', 30_000_000], ['2110', 'credit', 3_300_000]]],
            ['FAKE-IT-HOSTING', 'Pendapatan Hosting Bulanan', Journal::MODE_INTERNAL, [['1102', 'debit', 16_650_000], ['4103', 'credit', 15_000_000], ['2110', 'credit', 1_650_000]]],
            ['FAKE-IT-CLOUD', 'Biaya Cloud Pelanggan', Journal::MODE_INTERNAL, [['5102', 'debit', 12_000_000], ['2101', 'credit', 12_000_000]]],
            ['FAKE-IT-PAYROLL', 'Payroll Tim IT', Journal::MODE_INTERNAL, [['6101', 'debit', 45_000_000], ['1102', 'credit', 45_000_000]]],
            ['FAKE-IT-DEPRECIATION', 'Penyusutan Komputer Komersial', Journal::MODE_INTERNAL, [['6501', 'debit', 2_000_000], ['1591', 'credit', 2_000_000]]],
            ['FAKE-IT-DEPRECIATION-FISCAL', 'Penyusutan Komputer Fiskal', Journal::MODE_FISCAL, [['6502', 'debit', 2_500_000], ['1592', 'credit', 2_500_000]]],
        ];
        if (data_get($entity->workspace_settings, 'bookkeeping_mode', 'independent_books') !== 'independent_books') {
            $definitions = array_values(array_filter(
                $definitions,
                fn (array $definition): bool => $definition[2] !== Journal::MODE_FISCAL,
            ));
        }
        $codes = collect($definitions)->flatMap(fn (array $definition) => collect($definition[3])->pluck(0))->unique();
        $accounts = $this->technologyAccountsForCodes($entity, $codes);
        $created = 0;

        foreach ($definitions as [$code, $name, $mode, $lines]) {
            if (JournalTemplate::where('entity_id', $entity->id)->where('code', $code)->exists()) {
                continue;
            }
            $missing = collect($lines)->pluck(0)->reject(fn (string $accountCode) => $accounts->has($accountCode));
            if ($missing->isNotEmpty()) {
                throw new \RuntimeException('Import COA Teknologi & IT terlebih dahulu. Akun tidak ditemukan: '.$missing->implode(', '));
            }

            $template = JournalTemplate::create([
                'entity_id' => $entity->id,
                'code' => $code,
                'name' => $name,
                'description' => 'Template fake untuk simulasi perusahaan teknologi dan layanan IT.',
                'journal_type' => Journal::TYPE_GENERAL,
                'journal_mode' => $mode,
                'default_memo' => $name,
                'default_reference' => 'FAKE-DEMO',
                'is_active' => true,
            ]);
            foreach ($lines as $index => [$accountCode, $side, $amount]) {
                JournalTemplateLine::create([
                    'template_id' => $template->id,
                    'line_no' => $index + 1,
                    'account_id' => $accounts[$accountCode]->id,
                    'side' => $side,
                    'amount' => $amount,
                    'memo' => $name,
                ]);
            }
            $this->mark($entity, 'journal_templates', $template);
            $created++;
        }

        $this->synchronizeFakePeriodStatuses($entity);

        return $created;
    }

    private function synchronizeFakePeriodStatuses(Entity $entity): void
    {
        $periodIds = FakeDataRecord::query()
            ->where('entity_id', $entity->id)
            ->where('group_key', 'periods')
            ->where('model_type', Period::class)
            ->pluck('model_id');

        if ($periodIds->isEmpty()) {
            return;
        }

        $currentStart = now()->startOfYear()->toDateString();
        Period::query()
            ->where('entity_id', $entity->id)
            ->whereIn('id', $periodIds)
            ->get()
            ->each(function (Period $period) use ($currentStart): void {
                $isCurrent = $period->start_date->toDateString() === $currentStart;
                $period->forceFill([
                    'status' => $isCurrent ? Period::STATUS_OPEN : Period::STATUS_CLOSED,
                    'closed_at' => $isCurrent ? null : ($period->closed_at ?? now()),
                    'closed_by' => $isCurrent ? null : $period->closed_by,
                ])->save();
            });
    }

    private function importRecurringJournals(Entity $entity, ?Period $period): int
    {
        if ($period === null) {
            throw new \InvalidArgumentException('Pilih periode untuk jurnal berulang fake.');
        }

        $templates = JournalTemplate::where('entity_id', $entity->id)
            ->whereIn('code', ['FAKE-IT-SAAS', 'FAKE-IT-CLOUD', 'FAKE-IT-PAYROLL'])
            ->get()
            ->keyBy('code');
        if ($templates->count() < 3) {
            throw new \RuntimeException('Import Template Jurnal IT terlebih dahulu.');
        }

        $definitions = [
            ['FAKE SaaS Bulanan', 'FAKE-IT-SAAS', 1],
            ['FAKE Tagihan Cloud Bulanan', 'FAKE-IT-CLOUD', 10],
            ['FAKE Payroll Tim IT', 'FAKE-IT-PAYROLL', 25],
        ];
        $created = 0;
        foreach ($definitions as [$name, $templateCode, $day]) {
            if (RecurringJournal::where('entity_id', $entity->id)->where('name', $name)->exists()) {
                continue;
            }
            $nextRun = Carbon::parse($period->start_date)->day(min($day, Carbon::parse($period->start_date)->daysInMonth));
            if ($nextRun->lt(Carbon::parse($period->start_date))) {
                $nextRun = Carbon::parse($period->start_date);
            }
            $recurring = RecurringJournal::create([
                'entity_id' => $entity->id,
                'template_id' => $templates[$templateCode]->id,
                'name' => $name,
                'frequency' => RecurringJournal::FREQUENCY_MONTHLY,
                'day' => $day,
                'start_date' => $period->start_date,
                'end_date' => $period->end_date,
                'next_run_at' => $nextRun->toDateString(),
                'status' => RecurringJournal::STATUS_ACTIVE,
                'auto_post' => false,
            ]);
            $this->mark($entity, 'recurring_journals', $recurring);
            $created++;
        }

        return $created;
    }

    private function importJournals(Entity $entity, ?Period $period): int
    {
        if ($period === null) {
            throw new \InvalidArgumentException('Pilih periode untuk jurnal dan laporan fake.');
        }

        $requiredCodes = [
            '1102', '1201', '1301', '1404', '1501', '2101', '2110', '3101', '4101', '4102', '4103',
            '4104', '4107', '4108', '4109', '4110', '4111', '4191', '5101', '5102', '5104', '5107', '5108',
            '6101', '6202', '6204', '6205', '6301', '6603',
        ];
        $accounts = $this->technologyAccountsForCodes($entity, $requiredCodes);
        $missing = collect($requiredCodes)->reject(fn (string $code) => $accounts->has($code));
        if ($missing->isNotEmpty()) {
            throw new \RuntimeException('Import COA Teknologi & IT terlebih dahulu. Akun tidak ditemukan: '.$missing->implode(', '));
        }

        $anchor = now()->betweenIncluded(Carbon::parse($period->start_date), Carbon::parse($period->end_date))
            ? now()
            : Carbon::parse($period->end_date);
        $date = fn (int $daysAgo): string => $this->clampDateToPeriod($period, $anchor->copy()->subDays($daysAgo));

        $common = [
            ['opening-capital', 'Setoran modal awal perusahaan', $period->start_date->toDateString(), [['1102', 250_000_000, 0], ['3101', 0, 250_000_000]], null],
            ['hardware-purchase', 'Pembelian persediaan hardware server', $date(42), [['1301', 40_000_000, 0], ['2101', 0, 40_000_000]], ['procurement', 'vendor', 'VEN-IT-001', 'NUSATECH', 'PT Nusa Teknologi']],
            ['project-invoice', 'Invoice proyek custom software ERP', $date(35), [['1201', 88_800_000, 0], ['4101', 0, 80_000_000], ['2110', 0, 8_800_000]], ['sales', 'customer', 'CUS-IT-001', 'MAJU-DIGITAL', 'PT Maju Digital']],
            ['project-collection', 'Penerimaan termin proyek custom software', $date(28), [['1102', 50_000_000, 0], ['1201', 0, 50_000_000]], ['sales', 'customer', 'CUS-IT-001', 'MAJU-DIGITAL', 'PT Maju Digital']],
            ['saas-income', 'Langganan SaaS paket Business', $date(20), [['1102', 33_300_000, 0], ['4102', 0, 30_000_000], ['2110', 0, 3_300_000]], ['subscriptions', 'customer', 'CUS-IT-002', 'SINERGI-CLOUD', 'CV Sinergi Cloud']],
            ['hosting-income', 'Pendapatan hosting dan domain', $date(0), [['1102', 16_650_000, 0], ['4103', 0, 15_000_000], ['2110', 0, 1_650_000]], ['subscriptions', 'customer', 'CUS-IT-003', 'TOKO-NUSANTARA', 'Toko Nusantara']],
            ['managed-service-income', 'Layanan managed network bulanan', $date(18), [['1201', 19_980_000, 0], ['4107', 0, 18_000_000], ['2110', 0, 1_980_000]], ['managed-services', 'customer', 'CUS-IT-005', 'PRIMA-NETWORK', 'PT Prima Network']],
            ['implementation-income', 'Implementasi dan integrasi API pelanggan', $date(16), [['1201', 22_200_000, 0], ['4108', 0, 20_000_000], ['2110', 0, 2_200_000]], ['projects', 'customer', 'CUS-IT-006', 'INTEGRASI-MART', 'PT Integrasi Mart']],
            ['support-income', 'Kontrak support dan maintenance aplikasi', $date(12), [['1102', 13_320_000, 0], ['4109', 0, 12_000_000], ['2110', 0, 1_320_000]], ['support', 'customer', 'CUS-IT-007', 'ANDAL-SUPPORT', 'CV Andal Sistem']],
            ['security-audit-income', 'Jasa keamanan siber dan audit TI', $date(10), [['1201', 27_750_000, 0], ['4110', 0, 25_000_000], ['2110', 0, 2_750_000]], ['security', 'customer', 'CUS-IT-008', 'AMAN-DIGITAL', 'PT Aman Digital']],
            ['training-income', 'Pelatihan administrator aplikasi pelanggan', $date(8), [['1102', 8_880_000, 0], ['4111', 0, 8_000_000], ['2110', 0, 880_000]], ['training', 'customer', 'CUS-IT-009', 'AKADEMI-TEKNO', 'Akademi Tekno']],
            ['hardware-sale', 'Penjualan perangkat jaringan', $date(14), [['1201', 27_750_000, 0], ['4104', 0, 25_000_000], ['2110', 0, 2_750_000]], ['sales', 'customer', 'CUS-IT-004', 'KARYA-MANDIRI', 'PT Karya Mandiri']],
            ['hardware-cogs', 'HPP perangkat jaringan terjual', $date(14), [['5104', 15_000_000, 0], ['1301', 0, 15_000_000]], null],
            ['developer-cost', 'Biaya langsung developer proyek', $date(11), [['5101', 35_000_000, 0], ['1102', 0, 35_000_000]], ['projects', 'project', 'PRJ-ERP-001', 'ERP-001', 'Implementasi ERP PT Maju Digital']],
            ['cloud-cost', 'Cloud dan server langsung pelanggan', $date(9), [['5102', 12_000_000, 0], ['2101', 0, 12_000_000]], ['procurement', 'vendor', 'VEN-CLOUD-001', 'CLOUD-ID', 'PT Cloud Infrastruktur Indonesia']],
            ['payroll', 'Payroll tim engineering dan support', $date(7), [['6101', 45_000_000, 0], ['1102', 0, 45_000_000]], ['payroll', 'department', 'DEPT-ENG', 'ENGINEERING', 'Engineering & Support']],
            ['software-tools', 'Lisensi tools development internal', $date(5), [['6202', 8_000_000, 0], ['1102', 0, 8_000_000]], ['procurement', 'vendor', 'VEN-SOFT-001', 'DEVTOOLS', 'DevTools Global']],
            ['vendor-advance', 'Uang muka pengadaan perangkat lab', $date(4), [['1404', 15_000_000, 0], ['1102', 0, 15_000_000]], ['procurement', 'vendor', 'VEN-LAB-001', 'LAB-SYSTEMS', 'PT Lab Systems Indonesia']],
            ['third-party-license', 'Lisensi monitoring untuk managed service pelanggan', $date(4), [['5107', 4_000_000, 0], ['2101', 0, 4_000_000]], ['procurement', 'vendor', 'VEN-MON-001', 'MONITORING-CLOUD', 'Monitoring Cloud']],
            ['customer-support-cost', 'Biaya engineer support langsung pelanggan', $date(3), [['5108', 7_000_000, 0], ['1102', 0, 7_000_000]], ['support', 'project', 'SUP-001', 'SUPPORT-001', 'Support PT Prima Network']],
            ['product-research', 'Riset proof-of-concept produk baru', $date(3), [['6204', 9_000_000, 0], ['1102', 0, 9_000_000]], ['research', 'project', 'RND-001', 'POC-AI', 'Proof-of-concept AI']],
            ['internal-compliance', 'Asesmen keamanan dan compliance internal', $date(2), [['6205', 6_000_000, 0], ['2101', 0, 6_000_000]], ['procurement', 'vendor', 'VEN-SEC-002', 'SECURE-AUDIT', 'PT Secure Audit Indonesia']],
            ['digital-marketing', 'Kampanye pemasaran digital SaaS', $date(0), [['6301', 6_000_000, 0], ['1102', 0, 6_000_000]], null],
        ];

        $created = 0;
        $independentBooks = data_get($entity->workspace_settings, 'bookkeeping_mode', 'independent_books') === 'independent_books';
        $modes = $independentBooks
            ? [Journal::MODE_INTERNAL, Journal::MODE_FISCAL]
            : [Journal::MODE_INTERNAL];
        foreach ($modes as $mode) {
            foreach ($common as [$key, $memo, $journalDate, $entries, $source]) {
                $created += $this->createFakeJournal($entity, $period, $accounts, $mode, $key, $memo, $journalDate, $entries, Journal::STATUS_POSTED, $mode === Journal::MODE_INTERNAL ? $source : null);
            }
        }

        $internalOnly = [
            ['representation', 'Jamuan dan representasi relasi bisnis', $date(2), [['6603', 5_000_000, 0], ['1102', 0, 5_000_000]]],
            ['management-accrual', 'Akrual progres proyek untuk evaluasi manajemen', $date(1), [['1201', 12_000_000, 0], ['4191', 0, 12_000_000]]],
        ];
        foreach ($internalOnly as [$key, $memo, $journalDate, $entries]) {
            $created += $this->createFakeJournal($entity, $period, $accounts, Journal::MODE_INTERNAL, $key, $memo, $journalDate, $entries);
        }

        if ($independentBooks) {
            $created += $this->createFakeJournal($entity, $period, $accounts, Journal::MODE_FISCAL, 'representation', 'Beban representasi sebelum koreksi fiskal', $date(2), [['6603', 5_000_000, 0], ['1102', 0, 5_000_000]]);
        }

        foreach ([
            ['draft-server', 'Rencana pembelian server tambahan', Journal::STATUS_DRAFT, [['1501', 18_000_000, 0], ['2101', 0, 18_000_000]]],
            ['review-consultant', 'Tagihan konsultan keamanan menunggu review', Journal::STATUS_SUBMITTED, [['6202', 7_500_000, 0], ['2101', 0, 7_500_000]]],
            ['revision-claim', 'Klaim implementasi perlu bukti tambahan', Journal::STATUS_REJECTED, [['6603', 1_250_000, 0], ['1102', 0, 1_250_000]]],
        ] as [$key, $memo, $status, $entries]) {
            $created += $this->createFakeJournal($entity, $period, $accounts, Journal::MODE_INTERNAL, $key, $memo, $date(0), $entries, $status);
        }

        $fiscalRepresentation = $independentBooks ? Journal::where('entity_id', $entity->id)
            ->where('idempotency_key', $this->journalKey($period, Journal::MODE_FISCAL, 'representation'))
            ->first() : null;
        if ($fiscalRepresentation && ! FiscalAdjustment::where('entity_id', $entity->id)->where('journal_id', $fiscalRepresentation->id)->exists()) {
            $adjustment = FiscalAdjustment::create([
                'entity_id' => $entity->id,
                'journal_id' => $fiscalRepresentation->id,
                'account_id' => $accounts['6603']->id,
                'date' => $fiscalRepresentation->date,
                'direction' => FiscalAdjustment::DIRECTION_POSITIVE,
                'amount' => 2_000_000,
                'reason' => 'Sebagian beban representasi tidak didukung daftar nominatif.',
                'legal_basis' => 'Simulasi koreksi fiskal positif untuk kebutuhan demo.',
                'status' => FiscalAdjustment::STATUS_APPROVED,
                'approved_at' => now(),
            ]);
            $this->mark($entity, 'journals', $adjustment);
            $created++;
        }

        return $created;
    }

    /**
     * @param  Collection<string, Account>  $accounts
     * @param  list<array{0: string, 1: int|float, 2: int|float}>  $entries
     * @param  array{0: string, 1: string, 2: string, 3: string, 4: string}|null  $source
     */
    private function createFakeJournal(
        Entity $entity,
        Period $period,
        $accounts,
        string $mode,
        string $key,
        string $memo,
        string $date,
        array $entries,
        string $status = Journal::STATUS_POSTED,
        ?array $source = null,
    ): int {
        $idempotencyKey = $this->journalKey($period, $mode, $key, $entity);
        if (Journal::where('idempotency_key', $idempotencyKey)->exists()) {
            return 0;
        }

        foreach ($entries as [$accountCode]) {
            /** @var Account|null $account */
            $account = $accounts->get($accountCode);
            if (! $account) {
                throw new \RuntimeException("Akun {$accountCode} tidak tersedia untuk jurnal fake.");
            }
            if (! $account->isAvailableFor($mode)) {
                throw new \RuntimeException("Akun {$accountCode} tidak aktif untuk mode {$mode}. Periksa klasifikasi COA.");
            }
        }

        $token = strtoupper(substr(hash('sha256', $idempotencyKey), 0, 8));
        $journal = Journal::create([
            'entity_id' => $entity->id,
            'period_id' => $period->id,
            'type' => $key === 'opening-capital' ? Journal::TYPE_OPENING : Journal::TYPE_GENERAL,
            'journal_mode' => $mode,
            'number' => 'DEMO-'.($mode === Journal::MODE_FISCAL ? 'FIS' : 'INT').'-'.$token,
            'transaction_code' => 'DEMO-TRX-'.$token,
            'date' => $date,
            'memo' => $memo,
            'reference' => 'FAKE-DEMO',
            'source_app' => 'fake-data',
            'idempotency_key' => $idempotencyKey,
            'status' => $status,
            'posted_at' => $status === Journal::STATUS_POSTED ? now() : null,
            'review_note' => $status === Journal::STATUS_REJECTED ? 'Lampirkan bukti transaksi yang memadai.' : null,
            'reviewed_at' => in_array($status, [Journal::STATUS_SUBMITTED, Journal::STATUS_REJECTED], true) ? now() : null,
        ]);

        foreach ($entries as $index => [$accountCode, $debit, $credit]) {
            JournalEntry::create([
                'journal_id' => $journal->id,
                'line_no' => $index + 1,
                'account_id' => $accounts[$accountCode]->id,
                'debit' => $debit,
                'credit' => $credit,
                'memo' => $memo,
                'source_app' => $source[0] ?? null,
                'source_ref_type' => $source[1] ?? null,
                'source_ref_id' => $source[2] ?? null,
                'metadata' => $source ? ['ref_code' => $source[3], 'ref_label' => $source[4], 'fake_data' => true] : ['fake_data' => true],
            ]);
        }

        if ($source !== null) {
            [$sourceApp, $refType, $refId, $refCode, $refLabel] = $source;
            $registry = SourceRefRegistry::firstOrCreate(
                ['entity_id' => $entity->id, 'source_app' => $sourceApp, 'ref_type' => $refType, 'ref_id' => $refId],
                [
                    'last_code' => $refCode,
                    'last_label' => $refLabel,
                    'last_attrs' => ['fake_data' => true],
                    'first_seen_at' => now(),
                    'last_seen_at' => now(),
                    'entry_count' => count($entries),
                ],
            );
            if ($registry->wasRecentlyCreated) {
                $this->mark($entity, 'journals', $registry);
            }
        }

        $this->mark($entity, 'journals', $journal);

        return 1;
    }

    private function journalKey(Period $period, string $mode, string $key, ?Entity $entity = null): string
    {
        $entityId = $entity?->id ?? $period->entity_id;

        return 'fake-it-'.substr((string) $entityId, -8).'-'.substr((string) $period->id, -8).'-'.$mode.'-'.$key;
    }

    private function clampDateToPeriod(Period $period, Carbon $date): string
    {
        $start = Carbon::parse($period->start_date);
        $end = Carbon::parse($period->end_date);

        return $date->max($start)->min($end)->toDateString();
    }

    private function importUsers(Entity $entity): int
    {
        $app = RbacApp::where('code', 'accounting')->first();
        if (! $app) {
            throw new \RuntimeException('Aplikasi accounting belum terdaftar.');
        }
        $created = 0;
        foreach ([['operator', 'Demo Operator', 'operator'], ['supervisor', 'Demo Supervisor', 'supervisor'], ['inspector', 'Demo Inspector', 'inspector']] as [$key, $name, $roleCode]) {
            $legacyEmail = "{$key}.fake@akunta.local";
            $legacyUser = User::where('email', $legacyEmail)->first();
            $legacyIsMarkedHere = $legacyUser && FakeDataRecord::where('entity_id', $entity->id)
                ->where('group_key', 'users')
                ->where('model_type', User::class)
                ->where('model_id', $legacyUser->id)
                ->exists();
            $email = $legacyIsMarkedHere
                ? $legacyEmail
                : "{$key}.fake+".strtolower(substr((string) $entity->id, -8)).'@akunta.local';
            $user = User::where('email', $email)->first();
            $alreadyMarked = $user && FakeDataRecord::where('entity_id', $entity->id)->where('group_key', 'users')->where('model_type', User::class)->where('model_id', $user->id)->exists();
            if ($user && ! $alreadyMarked) {
                continue;
            }
            if (! $user) {
                $user = User::create(['email' => $email, 'name' => $name, 'password_hash' => Hash::make('fake-password')]);
            }
            $role = Role::firstOrCreate(
                ['code' => $roleCode, 'tenant_id' => null],
                [
                    'name' => match ($roleCode) {
                        'operator' => 'Operator',
                        'supervisor' => 'Supervisor',
                        'inspector' => 'Inspector',
                        default => ucfirst($roleCode),
                    },
                    'description' => $roleCode === 'inspector'
                        ? 'Read-only inspection access'
                        : 'Role demo aplikasi accounting',
                    'is_preset' => true,
                ],
            );
            $assignment = UserAppAssignment::firstOrCreate(['user_id' => $user->id, 'app_id' => $app->id, 'entity_id' => $entity->id, 'role_id' => $role->id], ['assigned_at' => now()]);
            $this->mark($entity, 'users', $user);
            // Never claim an existing assignment as fake data. Only assignments
            // created by this import may be removed by Clear Fake Data.
            if ($assignment->wasRecentlyCreated) {
                $this->mark($entity, 'users', $assignment);
            }
            if (! $alreadyMarked) {
                $created++;
            }
        }

        return $created;
    }

    private function importAutoMapping(Entity $entity): int
    {
        $engine = app(AutoMappingEngine::class);
        $examples = [
            ['payroll.salary', ['employee_id' => 'EMP-001', 'employee_name' => 'Siti Aminah', 'pay_date' => '2026-08-01', 'net_salary' => 7500000]],
            ['payroll.salary', ['employee_id' => 'EMP-002', 'employee_name' => 'Budi Santoso', 'pay_date' => '2026-08-01', 'net_salary' => 6800000]],
            ['payroll.bonus', ['employee_id' => 'EMP-003', 'period' => '2026-Q2', 'bonus_amount' => 2500000, 'approved_by' => 'HR-ADMIN']],
            ['payroll.thr', ['employee_id' => 'EMP-004', 'payment_date' => '2026-03-20', 'thr_amount' => 8000000, 'tax' => 250000]],
            ['petty_cash.expense', ['expense_date' => '2026-08-02', 'category' => 'transport', 'amount' => 185000, 'description' => 'Transport meeting client', 'receipt_no' => 'PC-001']],
            ['petty_cash.expense', ['expense_date' => '2026-08-03', 'category' => 'office_supplies', 'amount' => 325000, 'description' => 'Kertas dan tinta printer', 'requested_by' => 'Admin']],
            ['petty_cash.replenishment', ['request_date' => '2026-08-04', 'fund_code' => 'PC-JKT', 'replenishment_amount' => 3000000, 'approved' => true]],
            ['pos.sale', ['order_id' => 'POS-1001', 'sold_at' => '2026-08-01T10:15:00+07:00', 'subtotal' => 450000, 'discount' => 25000, 'total' => 425000, 'payment_method' => 'cash']],
            ['pos.sale', ['order_id' => 'POS-1002', 'sold_at' => '2026-08-01T13:40:00+07:00', 'items' => 4, 'total' => 780000, 'payment_method' => 'qris']],
            ['pos.refund', ['refund_id' => 'REF-001', 'order_id' => 'POS-0990', 'refund_date' => '2026-08-02', 'refund_amount' => 150000, 'reason' => 'Barang rusak']],
            ['ecommerce.order', ['order_no' => 'ORD-2001', 'order_date' => '2026-08-03', 'customer' => ['code' => 'CUS-01', 'name' => 'PT Maju Jaya'], 'grand_total' => 1850000, 'shipping_fee' => 25000]],
            ['ecommerce.order', ['order_no' => 'ORD-2002', 'created_at' => '2026-08-03T15:00:00+07:00', 'customer_id' => 'CUS-02', 'items_total' => 990000, 'tax_total' => 108900, 'grand_total' => 1098900]],
            ['ecommerce.refund', ['refund_no' => 'ERF-001', 'original_order' => 'ORD-1998', 'processed_at' => '2026-08-04', 'amount' => 275000, 'status' => 'approved']],
            ['bank.receipt', ['bank_ref' => 'BNK-IN-001', 'value_date' => '2026-08-01', 'payer' => 'PT Pelanggan', 'amount' => 5000000, 'narration' => 'Pelunasan invoice INV-001']],
            ['bank.transfer', ['transaction_id' => 'TRF-001', 'transaction_date' => '2026-08-02', 'from_account' => '1102', 'to_account' => '1103', 'amount' => 1250000]],
            ['bank.fee', ['transaction_id' => 'FEE-001', 'posted_date' => '2026-08-03', 'fee_type' => 'monthly_admin', 'fee_amount' => 15000, 'bank_code' => 'BANK-X']],
            ['bank.interest', ['statement_date' => '2026-08-04', 'account_no' => '****1102', 'interest_income' => 42500, 'tax_withheld' => 8500]],
            ['purchase.invoice', ['invoice_no' => 'PINV-001', 'invoice_date' => '2026-08-01', 'vendor_code' => 'VEN-01', 'net_amount' => 3200000, 'vat' => 352000, 'total' => 3552000]],
            ['purchase.invoice', ['document' => ['number' => 'PINV-002', 'date' => '2026-08-02'], 'supplier' => ['id' => 'VEN-02'], 'amount' => 1750000, 'payment_term' => 30]],
            ['vendor.payment', ['payment_no' => 'VPAY-001', 'payment_date' => '2026-08-05', 'vendor_id' => 'VEN-01', 'paid_amount' => 3552000, 'method' => 'bank_transfer']],
            ['inventory.purchase', ['grn_no' => 'GRN-001', 'received_date' => '2026-08-02', 'warehouse' => 'JKT-01', 'inventory_value' => 4200000, 'supplier' => 'VEN-03']],
            ['inventory.sale', ['delivery_no' => 'DO-001', 'delivery_date' => '2026-08-03', 'sku_count' => 6, 'cost_of_goods' => 850000, 'warehouse' => 'JKT-01']],
            ['inventory.adjustment', ['adjustment_no' => 'ADJ-001', 'adjustment_date' => '2026-08-04', 'reason' => 'Stock opname', 'adjustment_value' => -125000]],
            ['fixed_asset.purchase', ['asset_no' => 'FA-001', 'purchase_date' => '2026-08-01', 'asset_name' => 'Laptop operasional', 'cost' => 12500000, 'useful_life_months' => 36]],
            ['fixed_asset.depreciation', ['period' => '2026-08', 'asset_no' => 'FA-001', 'depreciation_expense' => 347222, 'accumulated_depreciation' => 694444]],
            ['tax.ppn', ['tax_period' => '2026-07', 'filing_date' => '2026-08-10', 'output_vat' => 4500000, 'input_vat' => 3200000, 'payable' => 1300000]],
            ['tax.withholding', ['withholding_no' => 'WHT-001', 'withholding_date' => '2026-08-05', 'tax_type' => 'PPh23', 'base_amount' => 2000000, 'tax_amount' => 40000]],
            ['expense.claim', ['claim_no' => 'CLM-001', 'claimant' => 'EMP-005', 'claim_date' => '2026-08-03', 'total_claim' => 675000, 'purpose' => 'Perjalanan dinas']],
            ['subscription.invoice', ['subscription_id' => 'SUB-001', 'billing_date' => '2026-08-01', 'service' => 'Cloud hosting', 'period_months' => 1, 'amount' => 1250000]],
            ['loan.disbursement', ['loan_no' => 'LOAN-001', 'disbursement_date' => '2026-08-06', 'lender' => 'Bank X', 'principal' => 50000000, 'interest_rate' => 10.5]],
        ];
        $created = 0;
        foreach ($examples as $index => [$sourceType, $payload]) {
            $sourceUrl = 'https://'.str_replace('.', '-', $sourceType).'.example.com/events';
            $payload = ['source' => $sourceUrl] + $payload;
            $key = 'fake-auto-mapping-'.$entity->id.'-'.$index;
            $raw = $engine->ingest($entity, $sourceType, $payload, $key, null);
            $raw->forceFill(['status' => AutoMappingRawData::STATUS_UNMAPPED, 'processed_at' => now()])->save();
            $this->mark($entity, 'auto_mapping', $raw);
            if ($raw->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * Resolve canonical Technology & IT codes to an existing equivalent
     * account. This lets the demo use a compatible manual COA without adding
     * a second account whose spelling or punctuation is only slightly
     * different.
     *
     * @param  iterable<string>  $codes
     * @return Collection<string, Account>
     */
    private function technologyAccountsForCodes(Entity $entity, iterable $codes): Collection
    {
        $rows = collect(app(CoaTemplateRegistry::class)->load('teknologi'))->keyBy(0);
        $accounts = Account::where('entity_id', $entity->id)->where('is_active', true)->get();
        $independentBooks = data_get($entity->workspace_settings, 'bookkeeping_mode', 'independent_books') === 'independent_books';

        return collect($codes)->mapWithKeys(function (string $code) use ($rows, $accounts, $independentBooks): array {
            $row = $rows->get($code);
            if (! is_array($row)) {
                return [];
            }

            $availability = $independentBooks
                ? ($row[6] ?? Account::AVAILABILITY_BOTH)
                : Account::AVAILABILITY_INTERN;
            $account = $this->findEquivalentAccount($accounts, $row, $availability);

            return $account ? [$code => $account] : [];
        });
    }

    /**
     * Duplicate matching intentionally has a high bar. Similarity alone is
     * insufficient: accounting nature and book availability must also agree.
     *
     * @param  Collection<int, Account>  $accounts
     * @param  array{0: string, 1: string, 2: string, 3: string, 4: ?string, 5: bool, 6?: string, 7?: string}  $row
     */
    private function findEquivalentAccount(Collection $accounts, array $row, string $availability): ?Account
    {
        [$code, $name, $type, $normalBalance, , $isPostable] = $row;

        $compatible = $accounts->filter(fn (Account $account): bool => $account->is_active
            && $account->type === $type
            && $account->normal_balance === $normalBalance
            && (bool) $account->is_postable === $isPostable
            && $this->availabilityIsCompatible($account->availability, $availability));

        $normalizedName = $this->normalizeAccountName($name);
        $exactCode = $compatible->first(fn (Account $account): bool => strcasecmp($account->code, $code) === 0
            && $this->accountNamesAreEquivalent($account->name, $normalizedName));
        if ($exactCode) {
            return $exactCode;
        }

        $normalizedCode = $this->normalizeAccountCode($code);
        $codeMatch = $compatible->first(fn (Account $account): bool => $this->normalizeAccountCode($account->code) === $normalizedCode
            && $this->accountNamesAreEquivalent($account->name, $normalizedName));
        if ($codeMatch) {
            return $codeMatch;
        }

        return $compatible->first(function (Account $account) use ($normalizedName): bool {
            return $this->accountNamesAreEquivalent($account->name, $normalizedName);
        });
    }

    private function accountNamesAreEquivalent(string $candidateName, string $normalizedExpected): bool
    {
        $candidate = $this->normalizeAccountName($candidateName);
        if ($candidate === $normalizedExpected) {
            return true;
        }
        if (min(strlen($candidate), strlen($normalizedExpected)) < 12) {
            return false;
        }

        similar_text($candidate, $normalizedExpected, $similarity);

        return $similarity >= 92.0;
    }

    /** @param Collection<int, Account> $accounts */
    private function availableTechnologyCode(Collection $accounts, string $preferredCode): string
    {
        if (! $accounts->contains(fn (Account $account): bool => strcasecmp($account->code, $preferredCode) === 0)) {
            return $preferredCode;
        }

        for ($suffix = 1; $suffix <= 99; $suffix++) {
            $candidate = $preferredCode.'.'.str_pad((string) $suffix, 2, '0', STR_PAD_LEFT);
            if (! $accounts->contains(fn (Account $account): bool => strcasecmp($account->code, $candidate) === 0)) {
                return $candidate;
            }
        }

        throw new \RuntimeException("Tidak dapat menentukan kode alternatif untuk akun teknologi {$preferredCode}.");
    }

    private function availabilityIsCompatible(string $actual, string $required): bool
    {
        return $actual === Account::AVAILABILITY_BOTH
            || $actual === $required;
    }

    private function normalizeAccountCode(string $code): string
    {
        return strtoupper((string) preg_replace('/[^a-z0-9]/i', '', Str::ascii($code)));
    }

    private function normalizeAccountName(string $name): string
    {
        $name = Str::lower(Str::ascii($name));
        $name = str_replace(['&', 'hutang', 'aktiva', 'kewajiban'], [' dan ', 'utang', 'aset', 'liabilitas'], $name);
        $name = preg_replace('/\b(utama|perseroan|perusahaan)\b/', ' ', $name) ?? $name;

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $name));
    }

    private function userHasNonFakeDependencies(User $user, Entity $entity, FakeDataRecord $currentMarker): bool
    {
        if (UserAppAssignment::where('user_id', $user->id)->exists()) {
            return true;
        }

        return FakeDataRecord::query()
            ->where('model_type', User::class)
            ->where('model_id', $user->id)
            ->where('id', '!=', $currentMarker->id)
            ->where('entity_id', '!=', $entity->id)
            ->exists();
    }

    private function mark(Entity $entity, string $group, object $model): void
    {
        FakeDataRecord::firstOrCreate(['entity_id' => $entity->id, 'group_key' => $group, 'model_type' => $model::class, 'model_id' => $model->getKey()]);
    }
}
