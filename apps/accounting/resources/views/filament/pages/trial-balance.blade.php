<x-filament-panels::page>
    <div class="flex items-baseline justify-between border-y border-gray-900/40 py-1 mb-6">
        <span class="ak-eyebrow">§ Laporan — Neraca Saldo</span>
        <span class="ak-mono text-xs tracking-[0.18em] uppercase text-gray-500">
            @if ($report) Per {{ $report['as_of'] }} @else — @endif
        </span>
    </div>

    <form wire:submit="run" class="ak-paper-soft border ak-rule rounded-sm p-5 mb-6">
        {{ $this->form }}
        <div class="mt-4 flex items-center gap-3">
            <x-filament::button type="submit" icon="heroicon-m-arrow-path">
                Jalankan Laporan
            </x-filament::button>
            @if ($report)
                <span class="ak-mono text-xs text-gray-500">
                    {{ $report['rows']->count() }} akun · {{ number_format((float) $report['total_debit'], 0, ',', '.') }} pos.
                </span>
            @endif
        </div>
    </form>

    @if ($report)
        <div class="ak-paper-soft border ak-rule rounded-sm overflow-hidden">
            <div class="flex items-baseline justify-between px-6 pt-6 pb-4 border-b ak-rule">
                <div>
                    <div class="ak-eyebrow mb-1">Trial Balance</div>
                    <h3 class="ak-display text-3xl tracking-tight">
                        Neraca <em class="ak-italic ak-copper">Saldo</em>
                    </h3>
                </div>
                <div class="text-right ak-mono text-xs uppercase tracking-[0.18em] text-gray-500">
                    <div>As of</div>
                    <div class="text-gray-900 dark:text-gray-100 text-sm mt-0.5">{{ $report['as_of'] }}</div>
                </div>
            </div>

            <div class="overflow-x-auto px-2">
                <table class="w-full text-sm ak-sans">
                    <thead>
                        <tr class="ak-double-rule">
                            <th class="text-left px-4 py-3 ak-display font-medium italic text-gray-600" style="font-size:0.8rem; letter-spacing:0.12em;">Kode</th>
                            <th class="text-left px-4 py-3 ak-display font-medium italic text-gray-600" style="font-size:0.8rem; letter-spacing:0.12em;">Nama Akun</th>
                            <th class="text-left px-4 py-3 ak-display font-medium italic text-gray-600" style="font-size:0.8rem; letter-spacing:0.12em;">Tipe</th>
                            <th class="text-right px-4 py-3 ak-display font-medium italic text-gray-600" style="font-size:0.8rem; letter-spacing:0.12em;">Debit</th>
                            <th class="text-right px-4 py-3 ak-display font-medium italic text-gray-600" style="font-size:0.8rem; letter-spacing:0.12em;">Kredit</th>
                            <th class="text-right px-4 py-3 ak-display font-medium italic text-gray-600" style="font-size:0.8rem; letter-spacing:0.12em;">Saldo</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($report['rows'] as $row)
                            @php $bal = (float) $row->balance; @endphp
                            <tr class="border-b border-dashed ak-rule hover:bg-[rgb(184,101,74)]/[0.05] transition-colors">
                                <td class="px-4 py-2.5 ak-mono text-gray-700 dark:text-gray-300">{{ $row->code }}</td>
                                <td class="px-4 py-2.5 text-gray-900 dark:text-gray-100">{{ $row->name }}</td>
                                <td class="px-4 py-2.5">
                                    <span class="ak-mono text-[0.65rem] uppercase tracking-[0.14em] text-gray-500">{{ $row->type }}</span>
                                </td>
                                <td class="px-4 py-2.5 text-right ak-mono tabular-nums text-gray-900 dark:text-gray-100">
                                    @if ((float) $row->total_debit != 0) {{ number_format((float) $row->total_debit, 2, ',', '.') }} @else <span class="text-gray-300">—</span> @endif
                                </td>
                                <td class="px-4 py-2.5 text-right ak-mono tabular-nums text-gray-900 dark:text-gray-100">
                                    @if ((float) $row->total_credit != 0) {{ number_format((float) $row->total_credit, 2, ',', '.') }} @else <span class="text-gray-300">—</span> @endif
                                </td>
                                <td class="px-4 py-2.5 text-right ak-mono tabular-nums font-medium {{ $bal < 0 ? 'ak-red' : 'text-gray-900 dark:text-gray-100' }}">
                                    {{ number_format($bal, 2, ',', '.') }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="ak-double-rule">
                            <td class="px-4 py-4 ak-display italic text-gray-700" colspan="3" style="font-variation-settings:'opsz' 48;">Total</td>
                            <td class="px-4 py-4 text-right ak-mono tabular-nums font-semibold text-gray-900 dark:text-gray-100">
                                {{ number_format((float) $report['total_debit'], 2, ',', '.') }}
                            </td>
                            <td class="px-4 py-4 text-right ak-mono tabular-nums font-semibold text-gray-900 dark:text-gray-100">
                                {{ number_format((float) $report['total_credit'], 2, ',', '.') }}
                            </td>
                            <td class="px-4 py-4 text-right ak-mono">
                                @php
                                    $diff = (float) $report['total_debit'] - (float) $report['total_credit'];
                                    $balanced = abs($diff) < 0.005;
                                @endphp
                                @if ($balanced)
                                    <span class="inline-flex items-center gap-1.5 text-xs tracking-[0.18em] uppercase ak-ink">
                                        <span class="w-1.5 h-1.5 rounded-full bg-current"></span> Balanced
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1.5 text-xs tracking-[0.18em] uppercase ak-red">
                                        <span class="w-1.5 h-1.5 rounded-full bg-current animate-pulse"></span> Selisih {{ number_format($diff, 2, ',', '.') }}
                                    </span>
                                @endif
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="px-6 py-4 border-t ak-rule flex items-center justify-between text-xs ak-mono uppercase tracking-[0.18em] text-gray-500">
                <span>{{ $report['rows']->count() }} rows · generated {{ now()->format('Y-m-d H:i') }}</span>
                <span class="ak-italic normal-case" style="font-family: 'Fraunces', serif; font-style: italic; letter-spacing: 0;">— fin.</span>
            </div>
        </div>
    @endif
</x-filament-panels::page>
