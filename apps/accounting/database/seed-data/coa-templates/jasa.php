<?php

/**
 * Jasa / Professional services — konsultan, agency, IT, akuntan.
 *
 *  - No persediaan (skip 1301-1303)
 *  - HPP minimal (jasa pendukung subkontrak)
 *  - Pendapatan project-based, retainer
 */
$base = require __DIR__.'/_base.php';

return array_merge($base, [
    ['1402', 'WIP / Project Belum Ditagih', 'asset',   'debit',  '1100', true],

    ['4104', 'Pendapatan Jasa',             'revenue', 'credit', '4000', true],
    ['4105', 'Pendapatan Retainer',         'revenue', 'credit', '4000', true],
    ['4106', 'Pendapatan Komisi',           'revenue', 'credit', '4000', true],

    ['5102', 'Biaya Subkontrak',            'cogs',    'debit',  '5000', true],
    ['5103', 'Biaya Lisensi/Software',      'cogs',    'debit',  '5000', true],

    ['6121', 'Honor Profesional',           'expense', 'debit',  '6000', true],
    ['6606', 'Biaya Software & Tools',      'expense', 'debit',  '6000', true],
    ['6607', 'Biaya Pelatihan/Sertifikasi', 'expense', 'debit',  '6000', true],
]);
