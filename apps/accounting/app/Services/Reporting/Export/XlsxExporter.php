<?php

declare(strict_types=1);

namespace App\Services\Reporting\Export;

use Illuminate\Support\Collection;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Tiny wrapper around openspout to render report payloads to xlsx via streamed response.
 * Each report (TB / IS / BS / GL / comparative) plugs in via a callable that receives the writer.
 */
class XlsxExporter
{
    public function stream(string $filename, callable $writeUsing): StreamedResponse
    {
        return new StreamedResponse(function () use ($writeUsing) {
            $writer = new Writer;
            $writer->openToFile('php://output');
            $writeUsing($writer);
            $writer->close();
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }

    public function exportTrialBalance(array $report, string $entityName): StreamedResponse
    {
        return $this->stream($this->filename('neraca-saldo', $report['as_of']), function (Writer $w) use ($report, $entityName) {
            $this->writeTitle($w, 'Neraca Saldo (Trial Balance)', $entityName, 'Per '.$report['as_of']);
            $this->writeRow($w, ['Kode', 'Nama Akun', 'Tipe', 'Debit', 'Kredit', 'Saldo'], $this->headerStyle());

            foreach ($report['rows'] as $row) {
                $this->writeRow($w, [
                    $row->code,
                    $row->name,
                    $row->type,
                    (float) $row->total_debit,
                    (float) $row->total_credit,
                    (float) $row->balance,
                ]);
            }

            $this->writeRow($w, [
                '', '', 'Total',
                (float) $report['total_debit'],
                (float) $report['total_credit'],
                '',
            ], $this->totalStyle());
        });
    }

    public function exportIncomeStatement(array $report, string $entityName): StreamedResponse
    {
        return $this->stream($this->filename('laba-rugi', $report['period_start'].'_'.$report['period_end']), function (Writer $w) use ($report, $entityName) {
            $this->writeTitle($w, 'Laporan Laba Rugi (Income Statement)', $entityName, $report['period_start'].' s/d '.$report['period_end']);

            $sections = [
                'Pendapatan' => $report['revenue'] ?? null,
                'HPP' => $report['cogs'] ?? null,
                'Beban' => $report['expenses'] ?? null,
            ];

            foreach ($sections as $label => $section) {
                if (! $section) {
                    continue;
                }
                $this->writeRow($w, [$label], $this->sectionStyle());
                $this->writeRow($w, ['Kode', 'Nama Akun', 'Saldo'], $this->headerStyle());
                foreach ($section['lines'] ?? [] as $line) {
                    $this->writeRow($w, [
                        $line->code ?? '',
                        $line->name ?? '',
                        (float) ($line->balance ?? 0),
                    ]);
                }
                $this->writeRow($w, ['', 'Subtotal '.$label, (float) ($section['total'] ?? 0)], $this->totalStyle());
                $this->writeRow($w, ['']);
            }

            if (isset($report['gross_profit'])) {
                $this->writeRow($w, ['', 'Laba Kotor', (float) $report['gross_profit']], $this->totalStyle());
            }
            $this->writeRow($w, ['', 'Laba Bersih', (float) ($report['net_income'] ?? 0)], $this->totalStyle());
        });
    }

    public function exportBalanceSheet(array $report, string $entityName): StreamedResponse
    {
        return $this->stream($this->filename('neraca', $report['as_of']), function (Writer $w) use ($report, $entityName) {
            $this->writeTitle($w, 'Neraca (Balance Sheet)', $entityName, 'Per '.$report['as_of']);

            $sections = [
                'Aset' => $report['assets'] ?? ['lines' => [], 'total' => '0'],
                'Liabilitas' => $report['liabilities'] ?? ['lines' => [], 'total' => '0'],
                'Ekuitas' => $report['equity'] ?? ['lines' => [], 'total' => '0'],
            ];

            foreach ($sections as $label => $section) {
                $this->writeRow($w, [$label], $this->sectionStyle());
                $this->writeRow($w, ['Kode', 'Nama Akun', 'Saldo'], $this->headerStyle());
                foreach ($this->normalizeLines($section['lines']) as $line) {
                    $this->writeRow($w, [
                        $line->code ?? '',
                        $line->name ?? '',
                        (float) ($line->balance ?? 0),
                    ]);
                }
                $this->writeRow($w, ['', 'Subtotal '.$label, (float) $section['total']], $this->totalStyle());
                $this->writeRow($w, ['']);
            }
        });
    }

    public function exportGeneralLedger(array $report, string $entityName): StreamedResponse
    {
        return $this->stream($this->filename('buku-besar-'.$report['account']->code, $report['period_start'].'_'.$report['period_end']), function (Writer $w) use ($report, $entityName) {
            $a = $report['account'];
            $this->writeTitle($w, 'Buku Besar — '.$a->code.' '.$a->name, $entityName, $report['period_start'].' s/d '.$report['period_end']);

            $this->writeRow($w, ['Saldo Awal', (float) $report['opening']], $this->totalStyle());
            $this->writeRow($w, ['']);

            $this->writeRow($w, ['Tanggal', 'No. Jurnal', 'Referensi', 'Keterangan', 'Debit', 'Kredit', 'Saldo'], $this->headerStyle());
            foreach ($report['lines'] as $l) {
                $this->writeRow($w, [
                    (string) $l->date,
                    (string) ($l->number ?? ''),
                    (string) ($l->reference ?? ''),
                    trim((string) ($l->line_memo ?: $l->journal_memo)),
                    (float) $l->debit,
                    (float) $l->credit,
                    (float) $l->balance,
                ]);
            }

            $this->writeRow($w, ['']);
            $this->writeRow($w, ['', '', '', 'Total Periode', (float) $report['total_debit'], (float) $report['total_credit'], ''], $this->totalStyle());
            $this->writeRow($w, ['', '', '', 'Saldo Akhir', '', '', (float) $report['ending']], $this->totalStyle());
        });
    }

    private function normalizeLines(mixed $lines): Collection
    {
        if ($lines instanceof Collection) {
            return $lines;
        }
        if (is_array($lines) && isset($lines['lines'])) {
            return collect($lines['lines']);
        }

        return collect($lines);
    }

    private function writeTitle(Writer $w, string $title, string $entity, string $period): void
    {
        $this->writeRow($w, [$entity], (new Style)->setFontBold()->setFontSize(14));
        $this->writeRow($w, [$title], (new Style)->setFontBold()->setFontSize(12));
        $this->writeRow($w, [$period]);
        $this->writeRow($w, ['Generated: '.now()->toDateTimeString()]);
        $this->writeRow($w, ['']);
    }

    private function writeRow(Writer $w, array $cells, ?Style $style = null): void
    {
        $w->addRow($style ? Row::fromValues($cells, $style) : Row::fromValues($cells));
    }

    private function headerStyle(): Style
    {
        $border = new Border(new BorderPart(Border::BOTTOM, Color::BLACK, Border::WIDTH_THIN));

        return (new Style)
            ->setFontBold()
            ->setBorder($border)
            ->setBackgroundColor('EFEDE5');
    }

    private function totalStyle(): Style
    {
        return (new Style)->setFontBold()->setBackgroundColor('F7F5EE');
    }

    private function sectionStyle(): Style
    {
        return (new Style)->setFontBold()->setFontSize(11)->setFontColor('0D3B2E');
    }

    private function filename(string $base, string $tag): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_\-\.]/', '-', $base.'-'.$tag);

        return $safe.'.xlsx';
    }
}
