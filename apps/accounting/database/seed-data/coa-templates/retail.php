<?php

/**
 * Retail / Toko (UMKM): tambah akun spesifik retail di atas _base.
 *
 *  - Persediaan multi-kategori
 *  - Kartu kredit / EDC sebagai metode penerimaan
 *  - Diskon pelanggan + komisi marketplace
 */
$base = require __DIR__.'/_base.php';

return array_merge($base, [
    ['1302', 'Persediaan Bahan Baku',         'asset',   'debit',  '1100', true],
    ['1303', 'Persediaan Barang Konsinyasi',  'asset',   'debit',  '1100', true],
    ['1110', 'Kas EDC',                        'asset',   'debit',  '1100', true],
    ['1111', 'Kas E-Wallet',                   'asset',   'debit',  '1100', true],

    ['4104', 'Pendapatan Marketplace',        'revenue', 'credit', '4000', true],
    ['4105', 'Pendapatan Konsinyasi',         'revenue', 'credit', '4000', true],

    ['5102', 'HPP Marketplace',               'cogs',    'debit',  '5000', true],

    ['6602', 'Komisi Marketplace',            'expense', 'debit',  '6000', true],
    ['6603', 'Biaya Pengiriman',              'expense', 'debit',  '6000', true],
    ['6604', 'Diskon & Promosi',              'expense', 'debit',  '6000', true],
]);
