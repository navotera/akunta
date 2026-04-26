<?php

declare(strict_types=1);

namespace Database\Seeders;

use Akunta\Rbac\Models\App as RbacApp;
use Akunta\Rbac\Models\Entity;
use Akunta\Rbac\Models\Role;
use Akunta\Rbac\Models\Tenant;
use Akunta\Rbac\Models\User;
use Akunta\Rbac\Models\UserAppAssignment;
use App\Actions\ApplyCoaTemplateAction;
use App\Actions\SeedSampleJournalTemplatesAction;
use App\Models\Account;
use App\Models\Branch;
use App\Models\CostCenter;
use App\Models\Journal;
use App\Models\JournalEntry;
use App\Models\Partner;
use App\Models\Period;
use App\Models\Project;
use App\Models\RecurringJournal;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Demo Mode — provisions a fully-populated "PT. Demo" entity so a fresh install
 * has realistic data to explore: CoA, branches, cost centers, projects, partners,
 * 6 months of posted journals across the common UMKM scenarios, sample journal
 * templates, and a recurring journal.
 *
 * Trigger via env: SEED_DEMO_DATA=true (DatabaseSeeder reads this).
 *
 * Idempotent end-to-end — re-running is a no-op for existing rows. Safe to run
 * after a partial seed.
 *
 * NOT for production. Skip in CI by leaving SEED_DEMO_DATA unset.
 */
class DemoDataSeeder extends Seeder
{
    private const ENTITY_NAME = 'PT. Demo';

    public function run(): void
    {
        DB::transaction(function (): void {
            $tenant = $this->resolveTenant();
            $entity = $this->resolveEntity($tenant);
            $this->ensureSuperAdminAssigned($entity);

            app(ApplyCoaTemplateAction::class)->execute($entity->id, 'generic');

            $periods = $this->seedPeriods($entity);
            $branches = $this->seedBranches($entity);
            $costCenters = $this->seedCostCenters($entity);
            $partners = $this->seedPartners($entity);
            $this->seedProjects($entity, $partners);

            $accounts = Account::query()
                ->where('entity_id', $entity->id)
                ->get()
                ->keyBy('code');

            $this->seedJournals($entity, $periods, $branches, $costCenters, $partners, $accounts);

            app(SeedSampleJournalTemplatesAction::class)->execute($entity->id);
            $this->seedRecurringJournals($entity);

            $this->command?->info("DemoDataSeeder selesai untuk entitas '".self::ENTITY_NAME."'.");
        });
    }

    private function resolveTenant(): Tenant
    {
        return Tenant::firstOrCreate(
            ['slug' => env('SUPER_ADMIN_TENANT_SLUG', 'akunta-dev')],
            ['name' => env('SUPER_ADMIN_TENANT_NAME', 'Akunta Dev Tenant')],
        );
    }

    private function resolveEntity(Tenant $tenant): Entity
    {
        return Entity::firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => self::ENTITY_NAME],
            ['relation_type' => 'independent'],
        );
    }

    private function ensureSuperAdminAssigned(Entity $entity): void
    {
        $email = env('SUPER_ADMIN_EMAIL', 'superadmin@akunta.local');
        $user = User::where('email', $email)->first();
        if ($user === null) {
            return;
        }

        $app = RbacApp::firstOrCreate(
            ['code' => 'accounting'],
            ['name' => 'Accounting', 'version' => '0.1', 'enabled' => true],
        );

        $role = Role::whereNull('tenant_id')->where('code', 'super_admin')->first();
        if ($role === null) {
            return;
        }

        UserAppAssignment::firstOrCreate(
            [
                'user_id'   => $user->id,
                'app_id'    => $app->id,
                'entity_id' => $entity->id,
                'role_id'   => $role->id,
            ],
            ['assigned_at' => now()],
        );
    }

    /** @return array{current: Period, prev: Period} */
    private function seedPeriods(Entity $entity): array
    {
        $year = (int) Carbon::now()->year;

        $current = Period::firstOrCreate(
            ['entity_id' => $entity->id, 'name' => (string) $year],
            [
                'start_date' => Carbon::create($year, 1, 1)->toDateString(),
                'end_date'   => Carbon::create($year, 12, 31)->toDateString(),
                'status'     => Period::STATUS_OPEN,
            ],
        );

        $prev = Period::firstOrCreate(
            ['entity_id' => $entity->id, 'name' => (string) ($year - 1)],
            [
                'start_date' => Carbon::create($year - 1, 1, 1)->toDateString(),
                'end_date'   => Carbon::create($year - 1, 12, 31)->toDateString(),
                'status'     => Period::STATUS_CLOSED,
            ],
        );

        return ['current' => $current, 'prev' => $prev];
    }

    /** @return array<string, Branch> keyed by code */
    private function seedBranches(Entity $entity): array
    {
        $defs = [
            ['BR-JKT', 'Cabang Jakarta',  'Jakarta'],
            ['BR-SBY', 'Cabang Surabaya', 'Surabaya'],
            ['BR-BDG', 'Cabang Bandung',  'Bandung'],
        ];

        $out = [];
        foreach ($defs as [$code, $name, $city]) {
            $out[$code] = Branch::firstOrCreate(
                ['entity_id' => $entity->id, 'code' => $code],
                ['name' => $name, 'city' => $city, 'is_active' => true],
            );
        }

        return $out;
    }

    /** @return array<string, CostCenter> keyed by code */
    private function seedCostCenters(Entity $entity): array
    {
        $defs = [
            ['CC-HQ',    'Head Office'],
            ['CC-SALES', 'Sales & Marketing'],
            ['CC-OPS',   'Operations'],
            ['CC-FIN',   'Finance & Accounting'],
        ];

        $out = [];
        foreach ($defs as [$code, $name]) {
            $out[$code] = CostCenter::firstOrCreate(
                ['entity_id' => $entity->id, 'code' => $code],
                ['name' => $name, 'is_active' => true],
            );
        }

        return $out;
    }

    /** @return array{customers: array<string, Partner>, vendors: array<string, Partner>, employees: array<string, Partner>} */
    private function seedPartners(Entity $entity): array
    {
        $customers = [
            ['CUST-001', 'PT. Mitra Sejahtera',     '01.234.567.8-901.000', 'finance@mitra.co.id'],
            ['CUST-002', 'CV. Bintang Terang',      '02.345.678.9-012.000', 'admin@bintang.id'],
            ['CUST-003', 'Toko Maju Jaya',          null,                   'majujaya@gmail.com'],
            ['CUST-004', 'PT. Sumber Rejeki',       '03.456.789.0-123.000', 'po@sumberrejeki.com'],
            ['CUST-005', 'Cafe Senyum',             null,                   'cafesenyum@gmail.com'],
        ];

        $vendors = [
            ['VEND-001', 'PT. Supplier Utama',  '04.567.890.1-234.000', 'sales@supplierutama.co.id'],
            ['VEND-002', 'CV. Bahan Baku Mas',  '05.678.901.2-345.000', 'order@bahanbaku.id'],
            ['VEND-003', 'PT. Logistik Cepat',  '06.789.012.3-456.000', 'cs@logistikcepat.id'],
        ];

        $employees = [
            ['EMP-001', 'Budi Santoso',  null, 'budi@ptdemo.co.id'],
            ['EMP-002', 'Siti Rahmawati', null, 'siti@ptdemo.co.id'],
            ['EMP-003', 'Andi Pratama',   null, 'andi@ptdemo.co.id'],
        ];

        $make = function (Entity $entity, string $type, array $rows): array {
            $out = [];
            foreach ($rows as [$code, $name, $npwp, $email]) {
                $out[$code] = Partner::firstOrCreate(
                    ['entity_id' => $entity->id, 'code' => $code],
                    [
                        'type'      => $type,
                        'name'      => $name,
                        'npwp'      => $npwp,
                        'email'     => $email,
                        'country'   => 'ID',
                        'is_active' => true,
                    ],
                );
            }

            return $out;
        };

        return [
            'customers' => $make($entity, Partner::TYPE_CUSTOMER, $customers),
            'vendors'   => $make($entity, Partner::TYPE_VENDOR,   $vendors),
            'employees' => $make($entity, Partner::TYPE_EMPLOYEE, $employees),
        ];
    }

    /** @param array{customers: array<string, Partner>, ...} $partners */
    private function seedProjects(Entity $entity, array $partners): void
    {
        $defs = [
            ['PRJ-001', 'Renovasi Kantor Pusat', 'CUST-001', '-3 months', null,         Project::STATUS_ACTIVE],
            ['PRJ-002', 'Implementasi ERP',     'CUST-004', '-6 months', '+2 months',  Project::STATUS_ACTIVE],
            ['PRJ-003', 'Event Launch Q4',      'CUST-002', '-2 months', '-1 week',    Project::STATUS_CLOSED],
        ];

        foreach ($defs as [$code, $name, $custCode, $start, $end, $status]) {
            Project::firstOrCreate(
                ['entity_id' => $entity->id, 'code' => $code],
                [
                    'name'       => $name,
                    'partner_id' => $partners['customers'][$custCode]?->id,
                    'start_date' => Carbon::parse($start)->toDateString(),
                    'end_date'   => $end ? Carbon::parse($end)->toDateString() : null,
                    'status'     => $status,
                    'is_active'  => $status !== Project::STATUS_CLOSED,
                ],
            );
        }
    }

    /**
     * @param array{current: Period, prev: Period} $periods
     * @param array<string, Branch> $branches
     * @param array<string, CostCenter> $costCenters
     * @param array{customers: array<string, Partner>, vendors: array<string, Partner>, employees: array<string, Partner>} $partners
     * @param \Illuminate\Support\Collection<string, Account> $accounts
     */
    private function seedJournals(
        Entity $entity,
        array $periods,
        array $branches,
        array $costCenters,
        array $partners,
        \Illuminate\Support\Collection $accounts,
    ): void {
        // Skip jika sudah ada jurnal untuk entitas (idempotency).
        if (Journal::where('entity_id', $entity->id)->exists()) {
            return;
        }

        $period = $periods['current'];
        $now = Carbon::now();
        $sample = [];

        // Opening balance — set di awal tahun
        $sample[] = [
            'date' => Carbon::create($now->year, 1, 1),
            'memo' => 'Saldo awal tahun',
            'type' => Journal::TYPE_OPENING,
            'lines' => [
                ['1101', 'debit',  50_000_000, 'Saldo kas awal'],
                ['1102', 'debit',  200_000_000, 'Saldo bank awal'],
                ['1301', 'debit',  75_000_000, 'Saldo persediaan awal'],
                ['1501', 'debit',  150_000_000, 'Peralatan kantor'],
                ['3001', 'credit', 475_000_000, 'Modal disetor pemilik'],
            ],
        ];

        // 3 bulan terakhir — pola berulang penjualan, pembelian, gaji, sewa, depresiasi
        for ($monthsAgo = 3; $monthsAgo >= 0; $monthsAgo--) {
            $monthDate = $now->copy()->subMonthsNoOverflow($monthsAgo)->startOfMonth();

            // Penjualan tunai + PPN (2x per bulan)
            foreach ([5, 18] as $day) {
                $amount = random_int(8_000_000, 25_000_000);
                $ppn    = (int) round($amount * 0.11);
                $sample[] = [
                    'date' => $monthDate->copy()->day($day),
                    'memo' => 'Penjualan tunai',
                    'partner' => 'CUST-00'.random_int(1, 5),
                    'branch' => 'BR-JKT',
                    'cost_center' => 'CC-SALES',
                    'lines' => [
                        ['1101', 'debit',  $amount + $ppn, 'Terima cash + PPN'],
                        ['4101', 'credit', $amount,        'Pendapatan jualan'],
                        ['2102', 'credit', $ppn,           'PPN Keluaran 11%'],
                    ],
                ];
            }

            // Penjualan kredit
            $kredit = random_int(15_000_000, 40_000_000);
            $sample[] = [
                'date' => $monthDate->copy()->day(10),
                'memo' => 'Penjualan kredit',
                'partner' => 'CUST-001',
                'branch' => 'BR-SBY',
                'cost_center' => 'CC-SALES',
                'lines' => [
                    ['1201', 'debit',  $kredit + (int) round($kredit * 0.11), 'Piutang usaha'],
                    ['4101', 'credit', $kredit,                                'Pendapatan'],
                    ['2102', 'credit', (int) round($kredit * 0.11),            'PPN Keluaran'],
                ],
            ];

            // Pelunasan piutang (selisih sebagian)
            $bayar = (int) round($kredit * 0.7);
            $sample[] = [
                'date' => $monthDate->copy()->day(25),
                'memo' => 'Pelunasan piutang sebagian',
                'partner' => 'CUST-001',
                'lines' => [
                    ['1102', 'debit',  $bayar, 'Transfer masuk'],
                    ['1201', 'credit', $bayar, 'Kurangi piutang'],
                ],
            ];

            // Pembelian persediaan kredit + PPN Masukan
            $beli = random_int(5_000_000, 15_000_000);
            $ppnIn = (int) round($beli * 0.11);
            $sample[] = [
                'date' => $monthDate->copy()->day(8),
                'memo' => 'Pembelian persediaan kredit',
                'partner' => 'VEND-001',
                'cost_center' => 'CC-OPS',
                'lines' => [
                    ['1301', 'debit',  $beli,         'Persediaan barang'],
                    ['2103', 'debit',  $ppnIn,        'PPN Masukan 11%'],
                    ['2101', 'credit', $beli + $ppnIn, 'Hutang ke supplier'],
                ],
            ];

            // Bayar hutang
            $bayarHutang = $beli;
            $sample[] = [
                'date' => $monthDate->copy()->day(28),
                'memo' => 'Pelunasan hutang supplier',
                'partner' => 'VEND-001',
                'lines' => [
                    ['2101', 'debit',  $bayarHutang, 'Kurangi hutang'],
                    ['1102', 'credit', $bayarHutang, 'Transfer keluar'],
                ],
            ];

            // Gaji + PPh 21
            $sample[] = [
                'date' => $monthDate->copy()->day(28),
                'memo' => 'Pembayaran gaji bulanan',
                'cost_center' => 'CC-HQ',
                'lines' => [
                    ['6101', 'debit',  10_000_000, 'Beban gaji bruto'],
                    ['2104', 'credit', 500_000,    'PPh 21 dipotong'],
                    ['1102', 'credit', 9_500_000,  'Transfer ke karyawan'],
                ],
            ];

            // Sewa kantor
            $sample[] = [
                'date' => $monthDate->copy()->day(1),
                'memo' => 'Sewa kantor bulanan',
                'cost_center' => 'CC-HQ',
                'lines' => [
                    ['6201', 'debit',  5_000_000, 'Beban sewa'],
                    ['1101', 'credit', 5_000_000, 'Bayar tunai'],
                ],
            ];

            // Penyusutan
            $sample[] = [
                'date' => $monthDate->copy()->endOfMonth(),
                'memo' => 'Penyusutan peralatan bulanan',
                'type' => Journal::TYPE_ADJUSTMENT,
                'cost_center' => 'CC-FIN',
                'lines' => [
                    ['6301', 'debit',  500_000, 'Beban penyusutan'],
                    ['1591', 'credit', 500_000, 'Akumulasi penyusutan'],
                ],
            ];
        }

        $userId = User::where('email', env('SUPER_ADMIN_EMAIL', 'superadmin@akunta.local'))->value('id');

        foreach ($sample as $idx => $j) {
            $missing = false;
            foreach ($j['lines'] as $line) {
                if (! $accounts->has($line[0])) {
                    $missing = true;
                    break;
                }
            }
            if ($missing) {
                continue;
            }

            $date = $j['date'];
            $number = $this->nextJournalNumber($entity->id, $date, $idx);

            $journal = Journal::create([
                'entity_id' => $entity->id,
                'period_id' => $period->id,
                'type'      => $j['type'] ?? Journal::TYPE_GENERAL,
                'number'    => $number,
                'date'      => $date->toDateString(),
                'memo'      => $j['memo'],
                'status'    => Journal::STATUS_POSTED,
                'posted_at' => $date->copy()->addHour(),
                'posted_by' => $userId,
                'created_by' => $userId,
            ]);

            $branchId = isset($j['branch']) ? ($branches[$j['branch']]?->id) : null;
            $ccId     = isset($j['cost_center']) ? ($costCenters[$j['cost_center']]?->id) : null;
            $partnerId = null;
            if (isset($j['partner'])) {
                $allPartners = array_merge($partners['customers'], $partners['vendors'], $partners['employees']);
                $partnerId = $allPartners[$j['partner']]?->id ?? null;
            }

            foreach ($j['lines'] as $i => [$code, $side, $amount, $memo]) {
                JournalEntry::create([
                    'journal_id'    => $journal->id,
                    'line_no'       => $i + 1,
                    'account_id'    => $accounts[$code]->id,
                    'partner_id'    => $partnerId,
                    'branch_id'     => $branchId,
                    'cost_center_id'=> $ccId,
                    'debit'         => $side === 'debit' ? $amount : 0,
                    'credit'        => $side === 'credit' ? $amount : 0,
                    'memo'          => $memo,
                ]);
            }
        }
    }

    private function nextJournalNumber(string $entityId, Carbon $date, int $offset): string
    {
        $prefix = 'JV-'.$date->format('Ym');
        $last = Journal::query()
            ->where('entity_id', $entityId)
            ->where('number', 'like', $prefix.'-%')
            ->orderByDesc('number')
            ->value('number');

        $next = 1;
        if ($last && preg_match('/-(\d+)$/', $last, $m)) {
            $next = (int) $m[1] + 1;
        }

        return $prefix.'-'.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    private function seedRecurringJournals(Entity $entity): void
    {
        $template = \App\Models\JournalTemplate::query()
            ->where('entity_id', $entity->id)
            ->where('code', 'SAMPLE-RENT')
            ->first();

        if ($template === null) {
            return;
        }

        RecurringJournal::firstOrCreate(
            ['entity_id' => $entity->id, 'name' => 'Sewa Kantor Bulanan (auto)'],
            [
                'template_id' => $template->id,
                'frequency'   => RecurringJournal::FREQUENCY_MONTHLY,
                'day'         => 1,
                'start_date'  => Carbon::now()->startOfMonth()->toDateString(),
                'next_run_at' => Carbon::now()->addMonthNoOverflow()->startOfMonth()->toDateString(),
                'auto_post'   => true,
                'status'      => RecurringJournal::STATUS_ACTIVE,
            ],
        );
    }
}
