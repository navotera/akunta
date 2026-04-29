<x-filament-panels::page>
    <div class="flex items-baseline justify-between border-y border-gray-900/40 py-1 mb-6 print:hidden">
        <span class="ak-eyebrow">§ Laporan — Laba Rugi</span>
        <span class="ak-mono text-xs tracking-[0.18em] uppercase text-gray-500">
            @if ($report) {{ $report['period_start'] }} → {{ $report['period_end'] }} @else — @endif
        </span>
    </div>

    <form wire:submit="run" class="ak-paper-soft border ak-rule rounded-sm p-5 mb-6 print:hidden">
        {{ $this->form }}
        <div class="mt-4 flex items-center gap-3">
            <x-filament::button type="submit" icon="heroicon-m-arrow-path">
                Jalankan Laporan
            </x-filament::button>
            @if ($comparative)
                <span class="ak-mono text-xs text-gray-500">
                    Pembanding: {{ $comparative['prev_period']['start'] }} → {{ $comparative['prev_period']['end'] }}
                </span>
            @endif
        </div>
    </form>

    @if ($report)
        @php
            $fmt = fn ($v) => number_format((float) $v, 2, ',', '.');
            $cmp = $comparative;
            $sections = [
                ['key' => 'revenue', 'label' => 'Pendapatan'],
                ['key' => 'cogs', 'label' => 'Harga Pokok Penjualan'],
                ['key' => 'expenses', 'label' => 'Beban Operasional'],
            ];
        @endphp

        <div class="ak-paper-soft border ak-rule rounded-sm overflow-hidden">
            <div class="flex items-baseline justify-between px-6 pt-6 pb-4 border-b ak-rule">
                <div>
                    <div class="ak-eyebrow mb-1">Income Statement</div>
                    <h3 class="ak-display text-3xl tracking-tight">
                        Laporan <em class="ak-italic ak-copper">Laba Rugi</em>
                    </h3>
                </div>
                <div class="text-right ak-mono text-xs uppercase tracking-[0.18em] text-gray-500">
                    <div>Periode</div>
                    <div class="text-gray-900 dark:text-gray-100 text-sm mt-0.5">
                        {{ \Illuminate\Support\Carbon::parse($report['period_start'])->format('d M Y') }} —
                        {{ \Illuminate\Support\Carbon::parse($report['period_end'])->format('d M Y') }}
                    </div>
                    @if ($cmp)
                        <div class="mt-2 text-[0.65rem]">vs.</div>
                        <div class="text-gray-700 dark:text-gray-300 text-xs">
                            {{ \Illuminate\Support\Carbon::parse($cmp['prev_period']['start'])->format('d M Y') }} —
                            {{ \Illuminate\Support\Carbon::parse($cmp['prev_period']['end'])->format('d M Y') }}
                        </div>
                    @endif
                </div>
            </div>

            <div class="overflow-x-auto px-2">
                <table class="w-full text-sm ak-sans">
                    <thead>
                        <tr class="ak-double-rule">
                            <th class="text-left px-4 py-3 ak-display font-medium italic text-gray-600" style="font-size:0.8rem; letter-spacing:0.12em;">Kode</th>
                            <th class="text-left px-4 py-3 ak-display font-medium italic text-gray-600" style="font-size:0.8rem; letter-spacing:0.12em;">Nama Akun</th>
                            <th class="text-right px-4 py-3 ak-display font-medium italic text-gray-600" style="font-size:0.8rem; letter-spacing:0.12em;">Periode Ini</th>
                            @if ($cmp)
                                <th class="text-right px-4 py-3 ak-display font-medium italic text-gray-600" style="font-size:0.8rem; letter-spacing:0.12em;">Pembanding</th>
                                <th class="text-right px-4 py-3 ak-display font-medium italic text-gray-600" style="font-size:0.8rem; letter-spacing:0.12em;">Δ</th>
                                <th class="text-right px-4 py-3 ak-display font-medium italic text-gray-600" style="font-size:0.8rem; letter-spacing:0.12em;">%</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sections as $sec)
                            @php
                                $section = $report[$sec['key']];
                                $cmpSection = $cmp['sections'][$sec['key']] ?? null;
                                $rows = $cmpSection ? $cmpSection['lines'] : $section['lines'];
                                $colspan = $cmp ? 6 : 3;
                            @endphp
                            <tr class="ak-double-rule">
                                <td colspan="{{ $colspan }}" class="px-4 py-2.5 ak-display italic text-gray-700 font-medium" style="font-size:0.95rem; font-variation-settings:'opsz' 48;">
                                    {{ $sec['label'] }}
                                </td>
                            </tr>
                            @foreach ($rows as $row)
                                <tr class="border-b border-dashed ak-rule hover:bg-[rgb(184,101,74)]/[0.05] transition-colors">
                                    <td class="px-4 py-2.5 ak-mono text-gray-700 dark:text-gray-300">{{ $row->code }}</td>
                                    <td class="px-4 py-2.5 text-gray-900 dark:text-gray-100">{{ $row->name }}</td>
                                    <td class="px-4 py-2.5 text-right ak-mono tabular-nums">
                                        {{ $fmt($cmp ? $row->curr_balance : $row->balance) }}
                                    </td>
                                    @if ($cmp)
                                        <td class="px-4 py-2.5 text-right ak-mono tabular-nums text-gray-600">{{ $fmt($row->prev_balance) }}</td>
                                        <td class="px-4 py-2.5 text-right ak-mono tabular-nums {{ (float) $row->delta < 0 ? 'ak-red' : '' }}">{{ $fmt($row->delta) }}</td>
                                        <td class="px-4 py-2.5 text-right ak-mono tabular-nums text-gray-500">
                                            {{ $row->delta_pct !== null ? number_format((float) $row->delta_pct, 1, ',', '.') . '%' : '—' }}
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                            <tr class="ak-paper-soft">
                                <td colspan="2" class="px-4 py-3 ak-mono text-xs uppercase tracking-[0.14em] text-gray-600">Subtotal {{ $sec['label'] }}</td>
                                <td class="px-4 py-3 text-right ak-mono tabular-nums font-semibold">
                                    {{ $fmt($cmp ? $cmpSection['curr_total'] : $section['total']) }}
                                </td>
                                @if ($cmp)
                                    <td class="px-4 py-3 text-right ak-mono tabular-nums text-gray-600">{{ $fmt($cmpSection['prev_total']) }}</td>
                                    <td class="px-4 py-3 text-right ak-mono tabular-nums">{{ $fmt($cmpSection['total_delta']) }}</td>
                                    <td></td>
                                @endif
                            </tr>
                            @if ($sec['key'] === 'cogs')
                                <tr class="ak-paper-soft border-t border-b ak-rule">
                                    <td colspan="2" class="px-4 py-3 ak-display italic text-gray-700 font-medium">Laba Kotor</td>
                                    <td class="px-4 py-3 text-right ak-mono tabular-nums font-bold">
                                        {{ $fmt($cmp ? $cmp['gross_profit_curr'] : $report['gross_profit']) }}
                                    </td>
                                    @if ($cmp)
                                        <td class="px-4 py-3 text-right ak-mono tabular-nums text-gray-600">{{ $fmt($cmp['gross_profit_prev']) }}</td>
                                        <td class="px-4 py-3 text-right ak-mono tabular-nums {{ (float) $cmp['gross_profit_delta'] < 0 ? 'ak-red' : '' }}">{{ $fmt($cmp['gross_profit_delta']) }}</td>
                                        <td></td>
                                    @endif
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="ak-double-rule">
                            <td colspan="2" class="px-4 py-4 ak-display italic text-gray-900 font-semibold" style="font-variation-settings:'opsz' 48;">Laba (Rugi) Bersih</td>
                            <td class="px-4 py-4 text-right ak-mono tabular-nums font-bold">
                                {{ $fmt($cmp ? $cmp['net_income_curr'] : $report['net_income']) }}
                            </td>
                            @if ($cmp)
                                <td class="px-4 py-4 text-right ak-mono tabular-nums text-gray-600">{{ $fmt($cmp['net_income_prev']) }}</td>
                                <td class="px-4 py-4 text-right ak-mono tabular-nums font-bold {{ (float) $cmp['net_income_delta'] < 0 ? 'ak-red' : '' }}">{{ $fmt($cmp['net_income_delta']) }}</td>
                                <td></td>
                            @endif
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="px-6 py-4 border-t ak-rule flex items-center justify-between text-xs ak-mono uppercase tracking-[0.18em] text-gray-500">
                <span>generated {{ now()->format('Y-m-d H:i') }}</span>
                <span class="ak-italic normal-case" style="font-family: 'Fraunces', serif; font-style: italic; letter-spacing: 0;">— fin.</span>
            </div>
        </div>
    @endif

    @push('styles')
        <style>
            @media print {
                .fi-sidebar, .fi-topbar, .fi-page-header-actions, .print\:hidden { display: none !important; }
                .fi-main { padding: 0 !important; }
                body { background: white !important; }
            }
        </style>
    @endpush
</x-filament-panels::page>
