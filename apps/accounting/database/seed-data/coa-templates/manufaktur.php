<?php

/**
 * Manufaktur — produksi barang. UMKM kelas menengah dengan WIP + finished goods.
 */
$base = require __DIR__.'/_base.php';

return array_merge($base, [
    ['1302', 'Persediaan Bahan Baku',         'asset',   'debit',  '1100', true],
    ['1303', 'Persediaan Barang Setengah Jadi', 'asset', 'debit',  '1100', true],
    ['1304', 'Persediaan Barang Jadi',        'asset',   'debit',  '1100', true],
    ['1305', 'Persediaan Bahan Penolong',     'asset',   'debit',  '1100', true],

    ['1504', 'Mesin & Peralatan Produksi',    'asset',   'debit',  '1500', true],
    ['1594', 'Akumulasi Penyusutan Mesin',    'asset',   'credit', '1500', true],

    ['4107', 'Pendapatan Penjualan Pabrik',   'revenue', 'credit', '4000', true],

    ['5102', 'Biaya Bahan Baku Terpakai',     'cogs',    'debit',  '5000', true],
    ['5103', 'Biaya Tenaga Kerja Langsung',   'cogs',    'debit',  '5000', true],
    ['5104', 'Biaya Overhead Pabrik',         'cogs',    'debit',  '5000', true],

    ['6123', 'Gaji Tenaga Produksi',          'expense', 'debit',  '6000', true],
    ['6211', 'Beban Pemeliharaan Mesin',      'expense', 'debit',  '6000', true],
    ['6212', 'Beban Bahan Bakar Pabrik',      'expense', 'debit',  '6000', true],
]);
