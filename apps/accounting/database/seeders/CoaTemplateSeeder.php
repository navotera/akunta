<?php

namespace Database\Seeders;

use App\Models\Account;
use Illuminate\Database\Seeder;

class CoaTemplateSeeder extends Seeder
{
    /**
     * Indonesian 4-digit COA baseline (spec §8.4).
     * Structure: [code, name, type, normal_balance, is_postable, parent_code].
     * Parent rows are non-postable aggregators.
     */
    public const TEMPLATE = [
        // 1xxx Aktiva
        ['1000', 'Aktiva', 'asset', 'debit', false, null],
        ['1100', 'Aktiva Lancar', 'asset', 'debit', false, '1000'],
        ['1101', 'Kas', 'asset', 'debit', true, '1100'],
        ['1102', 'Bank', 'asset', 'debit', true, '1100'],
        ['1103', 'Piutang Usaha', 'asset', 'debit', true, '1100'],
        ['1104', 'Persediaan', 'asset', 'debit', true, '1100'],
        ['1105', 'PPN Masukan', 'asset', 'debit', true, '1100'],
        ['1200', 'Aktiva Tetap', 'asset', 'debit', false, '1000'],
        ['1201', 'Tanah', 'asset', 'debit', true, '1200'],
        ['1202', 'Bangunan', 'asset', 'debit', true, '1200'],
        ['1203', 'Kendaraan', 'asset', 'debit', true, '1200'],
        ['1204', 'Peralatan', 'asset', 'debit', true, '1200'],
        ['1299', 'Akumulasi Penyusutan', 'asset', 'credit', true, '1200'],

        // 2xxx Kewajiban
        ['2000', 'Kewajiban', 'liability', 'credit', false, null],
        ['2100', 'Kewajiban Lancar', 'liability', 'credit', false, '2000'],
        ['2101', 'Hutang Usaha', 'liability', 'credit', true, '2100'],
        ['2102', 'Hutang PPh', 'liability', 'credit', true, '2100'],
        ['2103', 'PPN Keluaran', 'liability', 'credit', true, '2100'],
        ['2104', 'Hutang Gaji', 'liability', 'credit', true, '2100'],
        ['2200', 'Kewajiban Jangka Panjang', 'liability', 'credit', false, '2000'],
        ['2201', 'Hutang Bank', 'liability', 'credit', true, '2200'],

        // 3xxx Ekuitas
        ['3000', 'Ekuitas', 'equity', 'credit', false, null],
        ['3101', 'Modal Pemilik', 'equity', 'credit', true, '3000'],
        ['3102', 'Laba Ditahan', 'equity', 'credit', true, '3000'],
        ['3103', 'Laba Tahun Berjalan', 'equity', 'credit', true, '3000'],
        ['3104', 'Prive', 'equity', 'debit', true, '3000'],

        // 4xxx Pendapatan
        ['4000', 'Pendapatan', 'revenue', 'credit', false, null],
        ['4101', 'Penjualan', 'revenue', 'credit', true, '4000'],
        ['4102', 'Retur Penjualan', 'revenue', 'debit', true, '4000'],
        ['4103', 'Potongan Penjualan', 'revenue', 'debit', true, '4000'],

        // 5xxx HPP
        ['5000', 'Harga Pokok Penjualan', 'cogs', 'debit', false, null],
        ['5101', 'Pembelian', 'cogs', 'debit', true, '5000'],
        ['5102', 'Retur Pembelian', 'cogs', 'credit', true, '5000'],
        ['5103', 'Biaya Angkut Pembelian', 'cogs', 'debit', true, '5000'],

        // 6xxx Biaya Operasional
        ['6000', 'Biaya Operasional', 'expense', 'debit', false, null],
        ['6101', 'Biaya Gaji', 'expense', 'debit', true, '6000'],
        ['6102', 'Biaya Sewa', 'expense', 'debit', true, '6000'],
        ['6103', 'Biaya Listrik, Air & Telepon', 'expense', 'debit', true, '6000'],
        ['6104', 'Biaya Perlengkapan', 'expense', 'debit', true, '6000'],
        ['6105', 'Biaya Penyusutan', 'expense', 'debit', true, '6000'],
        ['6106', 'Biaya Pemasaran', 'expense', 'debit', true, '6000'],
        ['6107', 'Biaya Administrasi', 'expense', 'debit', true, '6000'],

        // 7xxx Lain-lain
        ['7000', 'Pendapatan & Biaya Lain-lain', 'other', 'credit', false, null],
        ['7101', 'Pendapatan Bunga', 'other', 'credit', true, '7000'],
        ['7102', 'Biaya Bunga', 'other', 'debit', true, '7000'],
        ['7103', 'Laba/Rugi Selisih Kurs', 'other', 'credit', true, '7000'],
    ];

    public function run(?string $entityId = null): void
    {
        $entityId ??= $this->command?->option('entity') ?? null;

        if ($entityId === null) {
            $this->command?->warn('CoaTemplateSeeder requires --entity=<ulid>; skipping.');

            return;
        }

        $idByCode = [];

        foreach (self::TEMPLATE as [$code, $name, $type, $normal, $postable, $parent]) {
            $account = Account::create([
                'entity_id' => $entityId,
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'normal_balance' => $normal,
                'is_postable' => $postable,
                'is_active' => true,
                'parent_account_id' => $parent ? ($idByCode[$parent] ?? null) : null,
            ]);

            $idByCode[$code] = $account->id;
        }

        $this->command?->info(sprintf('Seeded %d accounts for entity %s.', count(self::TEMPLATE), $entityId));
    }
}
