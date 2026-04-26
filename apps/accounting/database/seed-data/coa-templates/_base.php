<?php

/**
 * Generic UMKM Indonesia CoA backbone — used by every industry template plus
 * standalone for "Generic" companies. Indonesian PSAK-compatible 4-digit codes.
 *
 * Convention:
 *   1xxx = Aktiva (asset)
 *   2xxx = Kewajiban (liability)
 *   3xxx = Ekuitas (equity)
 *   4xxx = Pendapatan (revenue)
 *   5xxx = HPP (cogs)
 *   6xxx = Beban (expense)
 *   7-9xxx = Pendapatan/Beban Lain
 */
return [
    // ===== ASSET =====
    ['1000', 'Aktiva',                   'asset', 'debit',  null,   false],
    ['1100', 'Aktiva Lancar',            'asset', 'debit',  '1000', false],
    ['1101', 'Kas',                      'asset', 'debit',  '1100', true],
    ['1102', 'Bank',                     'asset', 'debit',  '1100', true],
    ['1110', 'Petty Cash',               'asset', 'debit',  '1100', true],
    ['1201', 'Piutang Usaha',            'asset', 'debit',  '1100', true],
    ['1202', 'Piutang Karyawan',         'asset', 'debit',  '1100', true],
    ['1301', 'Persediaan Barang',        'asset', 'debit',  '1100', true],
    ['1401', 'Biaya Dibayar di Muka',    'asset', 'debit',  '1100', true],

    ['1500', 'Aktiva Tetap',             'asset', 'debit',  '1000', false],
    ['1501', 'Peralatan',                'asset', 'debit',  '1500', true],
    ['1502', 'Kendaraan',                'asset', 'debit',  '1500', true],
    ['1503', 'Bangunan',                 'asset', 'debit',  '1500', true],
    ['1591', 'Akumulasi Penyusutan Peralatan', 'asset', 'credit', '1500', true],
    ['1592', 'Akumulasi Penyusutan Kendaraan', 'asset', 'credit', '1500', true],
    ['1593', 'Akumulasi Penyusutan Bangunan',  'asset', 'credit', '1500', true],

    // ===== LIABILITY =====
    ['2000', 'Kewajiban',                'liability', 'credit', null,   false],
    ['2100', 'Kewajiban Lancar',         'liability', 'credit', '2000', false],
    ['2101', 'Hutang Usaha',             'liability', 'credit', '2100', true],
    ['2102', 'Hutang PPN Keluaran',      'liability', 'credit', '2100', true],
    ['2103', 'PPN Masukan',              'liability', 'credit', '2100', true],
    ['2104', 'Hutang PPh 21',            'liability', 'credit', '2100', true],
    ['2105', 'Hutang PPh 23',            'liability', 'credit', '2100', true],
    ['2106', 'Hutang Gaji',              'liability', 'credit', '2100', true],

    ['2200', 'Kewajiban Jangka Panjang', 'liability', 'credit', '2000', false],
    ['2201', 'Pinjaman Bank',            'liability', 'credit', '2200', true],

    // ===== EQUITY =====
    ['3000', 'Ekuitas',                  'equity', 'credit', null,    false],
    ['3101', 'Modal Disetor',            'equity', 'credit', '3000',  true],
    ['3201', 'Laba Ditahan',             'equity', 'credit', '3000',  true],
    ['3301', 'Prive',                    'equity', 'debit',  '3000',  true],

    // ===== REVENUE =====
    ['4000', 'Pendapatan',               'revenue', 'credit', null,    false],
    ['4101', 'Penjualan',                'revenue', 'credit', '4000',  true],
    ['4102', 'Diskon Penjualan',         'revenue', 'debit',  '4000',  true],
    ['4103', 'Retur Penjualan',          'revenue', 'debit',  '4000',  true],
    ['4901', 'Pendapatan Lain',          'revenue', 'credit', '4000',  true],

    // ===== COGS =====
    ['5000', 'Harga Pokok Penjualan',    'cogs', 'debit', null,    false],
    ['5101', 'HPP Barang',               'cogs', 'debit', '5000',  true],

    // ===== EXPENSE =====
    ['6000', 'Beban',                    'expense', 'debit', null,    false],
    ['6101', 'Beban Gaji',               'expense', 'debit', '6000',  true],
    ['6102', 'Beban Tunjangan',          'expense', 'debit', '6000',  true],
    ['6201', 'Beban Sewa',               'expense', 'debit', '6000',  true],
    ['6202', 'Beban Listrik',            'expense', 'debit', '6000',  true],
    ['6203', 'Beban Air',                'expense', 'debit', '6000',  true],
    ['6204', 'Beban Internet/Telpon',    'expense', 'debit', '6000',  true],
    ['6301', 'Beban Penyusutan',         'expense', 'debit', '6000',  true],
    ['6401', 'Beban Transportasi',       'expense', 'debit', '6000',  true],
    ['6501', 'Beban ATK',                'expense', 'debit', '6000',  true],
    ['6601', 'Beban Pemasaran',          'expense', 'debit', '6000',  true],
    ['6701', 'Beban Bank/Adm',           'expense', 'debit', '6000',  true],
    ['6901', 'Beban Lain-lain',          'expense', 'debit', '6000',  true],
];
