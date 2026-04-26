<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\TaxCode;
use App\Services\Reporting\TaxReportService;
use Illuminate\Support\Carbon;

/**
 * Export PPN Keluaran transactions as CSV ready for upload to DJP Coretax /
 * legacy e-Faktur Import. Format aligned with Coretax 2024+ "Faktur Pajak"
 * import template:
 *
 *   FK | KD_JENIS_TRANSAKSI | FG_PENGGANTI | NOMOR_FAKTUR | MASA_PAJAK |
 *   TAHUN_PAJAK | TANGGAL_FAKTUR | NPWP | NAMA | ALAMAT_LENGKAP |
 *   JUMLAH_DPP | JUMLAH_PPN | JUMLAH_PPNBM | ID_KETERANGAN_TAMBAHAN |
 *   FG_UANG_MUKA | UANG_MUKA_DPP | UANG_MUKA_PPN | UANG_MUKA_PPNBM | REFERENSI
 *
 * The exact DJP CSV grammar evolves; consider this a "first cut" — the
 * accountant must validate against current Coretax template before submission.
 */
class EfakturCsvExporter
{
    public function __construct(private readonly TaxReportService $report) {}

    /**
     * @return array{filename: string, content: string}
     */
    public function exportOutputVat(string $entityId, string $periodStart, string $periodEnd): array
    {
        $report = $this->report->compute($entityId, $periodStart, $periodEnd, TaxCode::KIND_OUTPUT_VAT);

        $headers = [
            'FK', 'KD_JENIS_TRANSAKSI', 'FG_PENGGANTI', 'NOMOR_FAKTUR',
            'MASA_PAJAK', 'TAHUN_PAJAK', 'TANGGAL_FAKTUR',
            'NPWP', 'NAMA', 'ALAMAT_LENGKAP',
            'JUMLAH_DPP', 'JUMLAH_PPN', 'JUMLAH_PPNBM',
            'ID_KETERANGAN_TAMBAHAN', 'FG_UANG_MUKA',
            'UANG_MUKA_DPP', 'UANG_MUKA_PPN', 'UANG_MUKA_PPNBM',
            'REFERENSI',
        ];

        $lines = [implode(',', $headers)];

        foreach ($report['rows'] as $r) {
            $date  = Carbon::parse($r->date);
            $month = (int) $date->month;
            $year  = (int) $date->year;
            $base  = number_format((float) ($r->tax_base ?? 0), 0, '.', '');
            $tax   = number_format((float) $r->tax_amount, 0, '.', '');

            $row = [
                'FK',                                              // FK = Faktur Keluaran
                '01',                                              // jenis transaksi default 01
                '0',                                               // pengganti = 0
                $this->csvEscape((string) $r->reference ?? $r->number),
                str_pad((string) $month, 2, '0', STR_PAD_LEFT),
                (string) $year,
                $date->format('d/m/Y'),
                $this->csvEscape($this->normalizeNpwp((string) ($r->partner_npwp ?? ''))),
                $this->csvEscape((string) ($r->partner_name ?? '')),
                $this->csvEscape((string) ($r->partner_address ?? '')),
                $base,
                $tax,
                '0',
                '',
                '0',
                '0', '0', '0',
                $this->csvEscape((string) $r->journal_memo ?? ''),
            ];

            $lines[] = implode(',', $row);
        }

        return [
            'filename' => sprintf('efaktur-keluaran-%s-%s.csv', $periodStart, $periodEnd),
            'content'  => implode("\n", $lines)."\n",
        ];
    }

    private function csvEscape(string $v): string
    {
        $v = str_replace(["\r", "\n"], ' ', $v);
        if (str_contains($v, ',') || str_contains($v, '"')) {
            return '"'.str_replace('"', '""', $v).'"';
        }

        return $v;
    }

    private function normalizeNpwp(string $npwp): string
    {
        // Strip everything but digits, then optionally re-format. Keep digits only
        // for max compatibility with Coretax — it accepts both formatted + unformatted.
        return preg_replace('/\D+/', '', $npwp) ?? '';
    }
}
