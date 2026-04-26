<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Account;
use App\Models\Journal;
use App\Models\JournalTemplate;
use App\Models\JournalTemplateLine;
use Illuminate\Support\Facades\DB;

/**
 * Seed 5 contoh template jurnal yang sering dipakai UMKM.
 *
 * Setiap template:
 *   - Idempoten per (entity_id, code) — skip kalau sudah ada.
 *   - Skip kalau salah satu account.code belum ada di entity (CoA template
 *     belum diterapkan dulu).
 *
 * Dipakai dari Filament header action di ListJournalTemplates supaya user baru
 * bisa lihat contoh nyata bagaimana template + jurnal berulang bekerja.
 */
class SeedSampleJournalTemplatesAction
{
    /** @return array<int, array{code: string, name: string, description: string, lines: list<array{account_code: string, side: string, amount: int|float, memo?: string}>}> */
    public static function definitions(): array
    {
        return [
            [
                'code'        => 'SAMPLE-RENT',
                'name'        => 'Sewa Kantor Bulanan',
                'description' => 'Pencatatan beban sewa kantor yang dibayar tunai setiap bulan. Cocok untuk recurring journal frequency=monthly.',
                'lines' => [
                    ['account_code' => '6201', 'side' => 'debit',  'amount' => 5_000_000, 'memo' => 'Sewa kantor bulan ini'],
                    ['account_code' => '1101', 'side' => 'credit', 'amount' => 5_000_000, 'memo' => 'Bayar tunai'],
                ],
            ],
            [
                'code'        => 'SAMPLE-SALES-PPN',
                'name'        => 'Penjualan Tunai + PPN 11%',
                'description' => 'Penjualan langsung diterima cash, dengan PPN keluaran 11%. Kas naik sebesar DPP + PPN, Pendapatan dicatat tanpa PPN, sisanya jadi Hutang PPN.',
                'lines' => [
                    ['account_code' => '1101', 'side' => 'debit',  'amount' => 1_110_000, 'memo' => 'Penerimaan kas (DPP + PPN)'],
                    ['account_code' => '4101', 'side' => 'credit', 'amount' => 1_000_000, 'memo' => 'Penjualan (DPP)'],
                    ['account_code' => '2102', 'side' => 'credit', 'amount' => 110_000,   'memo' => 'PPN Keluaran 11%'],
                ],
            ],
            [
                'code'        => 'SAMPLE-PURCHASE-PPN',
                'name'        => 'Pembelian Barang + PPN 11%',
                'description' => 'Pembelian persediaan secara kredit dari supplier ber-NPWP. PPN Masukan dicatat sebagai aktiva (bisa dikreditkan), Hutang Usaha bertambah sebesar total faktur.',
                'lines' => [
                    ['account_code' => '1301', 'side' => 'debit',  'amount' => 2_000_000, 'memo' => 'Persediaan barang'],
                    ['account_code' => '2103', 'side' => 'debit',  'amount' => 220_000,   'memo' => 'PPN Masukan 11%'],
                    ['account_code' => '2101', 'side' => 'credit', 'amount' => 2_220_000, 'memo' => 'Hutang ke supplier'],
                ],
            ],
            [
                'code'        => 'SAMPLE-DEPRECIATION',
                'name'        => 'Penyusutan Peralatan Bulanan',
                'description' => 'Beban penyusutan garis lurus per bulan untuk peralatan kantor. Cocok untuk recurring monthly + auto_post=true (tidak perlu review tiap bulan).',
                'lines' => [
                    ['account_code' => '6301', 'side' => 'debit',  'amount' => 500_000, 'memo' => 'Beban penyusutan peralatan'],
                    ['account_code' => '1591', 'side' => 'credit', 'amount' => 500_000, 'memo' => 'Akumulasi penyusutan'],
                ],
            ],
            [
                'code'        => 'SAMPLE-PAYROLL',
                'name'        => 'Pembayaran Gaji + Potongan PPh 21',
                'description' => 'Gaji bruto dibebankan, PPh 21 dipotong (jadi hutang ke kas negara), sisanya dibayar via kas/bank ke karyawan.',
                'lines' => [
                    ['account_code' => '6101', 'side' => 'debit',  'amount' => 10_000_000, 'memo' => 'Beban gaji bruto'],
                    ['account_code' => '2104', 'side' => 'credit', 'amount' => 500_000,    'memo' => 'Potongan PPh 21 (5%)'],
                    ['account_code' => '1102', 'side' => 'credit', 'amount' => 9_500_000,  'memo' => 'Transfer bank ke karyawan'],
                ],
            ],
        ];
    }

    /**
     * @return array{created: int, skipped_existing: int, skipped_missing_account: array<string, list<string>>, total: int}
     */
    public function execute(string $entityId, ?string $createdBy = null): array
    {
        $defs = self::definitions();

        $existingCodes = JournalTemplate::query()
            ->where('entity_id', $entityId)
            ->pluck('code')
            ->all();

        $allLineCodes = collect($defs)
            ->flatMap(fn ($d) => array_column($d['lines'], 'account_code'))
            ->unique()
            ->values()
            ->all();

        $accounts = Account::query()
            ->where('entity_id', $entityId)
            ->whereIn('code', $allLineCodes)
            ->get()
            ->keyBy('code');

        $created = 0;
        $skippedExisting = 0;
        $skippedMissing = [];

        DB::transaction(function () use ($defs, $entityId, $existingCodes, $accounts, $createdBy, &$created, &$skippedExisting, &$skippedMissing) {
            foreach ($defs as $def) {
                if (in_array($def['code'], $existingCodes, true)) {
                    $skippedExisting++;

                    continue;
                }

                $missing = [];
                foreach ($def['lines'] as $line) {
                    if (! $accounts->has($line['account_code'])) {
                        $missing[] = $line['account_code'];
                    }
                }
                if ($missing !== []) {
                    $skippedMissing[$def['code']] = array_values(array_unique($missing));

                    continue;
                }

                $tmpl = JournalTemplate::create([
                    'entity_id'    => $entityId,
                    'code'         => $def['code'],
                    'name'         => $def['name'],
                    'description'  => $def['description'],
                    'journal_type' => Journal::TYPE_GENERAL,
                    'default_memo' => $def['name'],
                    'is_active'    => true,
                    'created_by'   => $createdBy,
                ]);

                foreach ($def['lines'] as $i => $line) {
                    JournalTemplateLine::create([
                        'template_id' => $tmpl->id,
                        'line_no'     => $i + 1,
                        'account_id'  => $accounts[$line['account_code']]->id,
                        'side'        => $line['side'],
                        'amount'      => $line['amount'],
                        'memo'        => $line['memo'] ?? null,
                    ]);
                }
                $created++;
            }
        });

        return [
            'created'                 => $created,
            'skipped_existing'        => $skippedExisting,
            'skipped_missing_account' => $skippedMissing,
            'total'                   => count($defs),
        ];
    }
}
