<?php

/**
 * Konstruksi / Kontraktor — proyek-based, retensi, jaminan pelaksanaan.
 */
$base = require __DIR__.'/_base.php';

return array_merge($base, [
    ['1203', 'Piutang Retensi',               'asset',   'debit',  '1100', true],
    ['1402', 'Biaya Proyek Belum Ditagih',    'asset',   'debit',  '1100', true],
    ['1403', 'Jaminan Pelaksanaan',           'asset',   'debit',  '1100', true],

    ['1504', 'Alat Berat',                    'asset',   'debit',  '1500', true],
    ['1594', 'Akumulasi Penyusutan Alat Berat', 'asset', 'credit', '1500', true],

    ['2107', 'Hutang Retensi',                'liability', 'credit', '2100', true],
    ['2108', 'Uang Muka Pelanggan',           'liability', 'credit', '2100', true],

    ['4108', 'Pendapatan Konstruksi',         'revenue', 'credit', '4000', true],
    ['4109', 'Pendapatan Termin',             'revenue', 'credit', '4000', true],

    ['5102', 'Biaya Material Proyek',         'cogs',    'debit',  '5000', true],
    ['5103', 'Biaya Tenaga Kerja Proyek',     'cogs',    'debit',  '5000', true],
    ['5104', 'Biaya Subkontraktor',           'cogs',    'debit',  '5000', true],
    ['5105', 'Biaya Sewa Alat',               'cogs',    'debit',  '5000', true],

    ['6213', 'Beban Bahan Bakar Alat Berat',  'expense', 'debit',  '6000', true],
    ['6608', 'Biaya Tender',                  'expense', 'debit',  '6000', true],
]);
