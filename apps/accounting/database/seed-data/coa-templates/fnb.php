<?php

/**
 * F&B / Restoran / Cafe / Catering — UMKM kuliner.
 *
 *  - Persediaan bahan baku + minuman
 *  - HPP makanan vs minuman terpisah
 *  - Beban dapur + service charge
 */
$base = require __DIR__.'/_base.php';

return array_merge($base, [
    ['1302', 'Persediaan Bahan Makanan',  'asset',   'debit',  '1100', true],
    ['1303', 'Persediaan Minuman',         'asset',   'debit',  '1100', true],
    ['1304', 'Persediaan Kemasan',         'asset',   'debit',  '1100', true],

    ['4104', 'Pendapatan Makanan',         'revenue', 'credit', '4000', true],
    ['4105', 'Pendapatan Minuman',         'revenue', 'credit', '4000', true],
    ['4106', 'Pendapatan Service Charge',  'revenue', 'credit', '4000', true],

    ['5102', 'HPP Makanan',                'cogs',    'debit',  '5000', true],
    ['5103', 'HPP Minuman',                'cogs',    'debit',  '5000', true],
    ['5104', 'HPP Kemasan',                'cogs',    'debit',  '5000', true],

    ['6121', 'Gaji Koki',                  'expense', 'debit',  '6000', true],
    ['6122', 'Gaji Pelayan',               'expense', 'debit',  '6000', true],
    ['6210', 'Beban Gas LPG',              'expense', 'debit',  '6000', true],
    ['6605', 'Biaya Aplikasi Pesan-Antar', 'expense', 'debit',  '6000', true],
]);
