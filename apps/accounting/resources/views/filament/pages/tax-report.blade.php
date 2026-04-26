@php
    $fmt = fn ($n) => number_format((float) $n, 0, ',', '.');
@endphp

<x-filament-panels::page>
    <div class="flex items-baseline justify-between border-y border-gray-900/40 py-1 mb-6">
        <span class="ak-eyebrow">§ Laporan Pajak</span>
        <span class="ak-mono text-xs tracking-[0.18em] uppercase text-gray-500">
            @if ($report) {{ $report['period_start'] }} — {{ $report['period_end'] }} @else — @endif
        </span>
    </div>

    <form wire:submit="run" class="ak-paper-soft border ak-rule rounded-sm p-5 mb-6">
        {{ $this->form }}
        <div class="mt-4 flex items-center gap-3 flex-wrap">
            <x-filament::button type="submit" icon="heroicon-m-arrow-path">Jalankan</x-filament::button>
            <x-filament::button
                wire:click="exportEfaktur"
                color="success"
                icon="heroicon-m-arrow-down-tray">
                Export e-Faktur (CSV) — PPN Keluaran
            </x-filament::button>
            @if ($report)
                <span class="ak-mono text-xs text-gray-500">
                    {{ $report['rows']->count() }} transaksi · DPP {{ $fmt($report['totals']['base']) }} · PPN/PPh {{ $fmt($report['totals']['tax']) }}
                </span>
            @endif
        </div>
    </form>

    @if ($report && $report['rows']->count())
        <div class="ak-paper-soft border ak-rule rounded-sm overflow-hidden">
            <div class="overflow-x-auto px-2">
                <table class="w-full text-sm ak-sans">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-gray-500 border-b ak-rule">
                            <th class="px-4 py-2">Tgl</th>
                            <th class="px-4 py-2">No. Jurnal</th>
                            <th class="px-4 py-2">Mitra</th>
                            <th class="px-4 py-2">NPWP</th>
                            <th class="px-4 py-2">Kode</th>
                            <th class="px-4 py-2 text-right">DPP</th>
                            <th class="px-4 py-2 text-right">Tarif</th>
                            <th class="px-4 py-2 text-right">Pajak</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($report['rows'] as $r)
                            <tr class="border-b ak-rule/40">
                                <td class="px-4 py-2 ak-mono text-xs">{{ \Carbon\Carbon::parse($r->date)->format('d/m/Y') }}</td>
                                <td class="px-4 py-2 ak-mono text-xs">{{ $r->number }}</td>
                                <td class="px-4 py-2">{{ $r->partner_name ?? '—' }}</td>
                                <td class="px-4 py-2 ak-mono text-xs">{{ $r->partner_npwp ?? '—' }}</td>
                                <td class="px-4 py-2 ak-mono text-xs">{{ $r->tax_code }}</td>
                                <td class="px-4 py-2 text-right ak-mono">{{ $fmt($r->tax_base ?? 0) }}</td>
                                <td class="px-4 py-2 text-right ak-mono">{{ number_format((float) $r->tax_rate, 2, ',', '.') }}%</td>
                                <td class="px-4 py-2 text-right ak-mono">{{ $fmt($r->tax_amount) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 ak-rule font-semibold">
                            <td colspan="5" class="px-4 py-3 text-right">Total</td>
                            <td class="px-4 py-3 text-right ak-mono">{{ $fmt($report['totals']['base']) }}</td>
                            <td></td>
                            <td class="px-4 py-3 text-right ak-mono">{{ $fmt($report['totals']['tax']) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    @elseif ($report)
        <div class="ak-paper-soft border ak-rule rounded-sm p-8 text-center text-gray-500">
            Tidak ada transaksi pajak pada periode ini.
        </div>
    @endif
</x-filament-panels::page>
