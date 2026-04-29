<x-filament-panels::page>
    <div class="flex items-baseline justify-between border-y border-gray-900/40 py-1 mb-6 print:hidden">
        <span class="ak-eyebrow">§ Laporan — Buku Besar</span>
        <span class="ak-mono text-xs tracking-[0.18em] uppercase text-gray-500">
            @if ($report) {{ $report['account']->code }} · {{ $report['period_start'] }} → {{ $report['period_end'] }} @else — @endif
        </span>
    </div>

    <form wire:submit="run" class="ak-paper-soft border ak-rule rounded-sm p-5 mb-6 print:hidden">
        {{ $this->form }}
        <div class="mt-4 flex items-center gap-3">
            <x-filament::button type="submit" icon="heroicon-m-arrow-path">
                Jalankan Laporan
            </x-filament::button>
            @if ($report)
                <span class="ak-mono text-xs text-gray-500">
                    {{ $report['lines']->count() }} baris · saldo akhir
                    {{ number_format((float) $report['ending'], 2, ',', '.') }}
                </span>
            @endif
        </div>
    </form>

    @if ($report)
        @php $a = $report['account']; @endphp
        <div class="ak-paper-soft border ak-rule rounded-sm overflow-hidden">
            <div class="flex items-baseline justify-between px-6 pt-6 pb-4 border-b ak-rule">
                <div>
                    <div class="ak-eyebrow mb-1">General Ledger</div>
                    <h3 class="ak-display text-3xl tracking-tight">
                        Buku <em class="ak-italic ak-copper">Besar</em>
                    </h3>
                    <div class="mt-2 ak-mono text-sm text-gray-700 dark:text-gray-300">
                        <span class="font-medium">{{ $a->code }}</span> · {{ $a->name }}
                        <span class="ml-2 text-[0.65rem] uppercase tracking-[0.18em] text-gray-500">{{ $a->type }} · normal {{ $a->normal_balance }}</span>
                    </div>
                </div>
                <div class="text-right ak-mono text-xs uppercase tracking-[0.18em] text-gray-500">
                    <div>Periode</div>
                    <div class="text-gray-900 dark:text-gray-100 text-sm mt-0.5">
                        {{ \Illuminate\Support\Carbon::parse($report['period_start'])->format('d M Y') }} —
                        {{ \Illuminate\Support\Carbon::parse($report['period_end'])->format('d M Y') }}
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-2 md:grid-cols-4 gap-0 border-b ak-rule">
                <div class="px-6 py-4 border-r ak-rule">
                    <div class="ak-mono text-[0.65rem] uppercase tracking-[0.18em] text-gray-500">Saldo Awal</div>
                    <div class="ak-mono tabular-nums text-base mt-1">{{ number_format((float) $report['opening'], 2, ',', '.') }}</div>
                </div>
                <div class="px-6 py-4 border-r ak-rule">
                    <div class="ak-mono text-[0.65rem] uppercase tracking-[0.18em] text-gray-500">Total Debit</div>
                    <div class="ak-mono tabular-nums text-base mt-1">{{ number_format((float) $report['total_debit'], 2, ',', '.') }}</div>
                </div>
                <div class="px-6 py-4 border-r ak-rule">
                    <div class="ak-mono text-[0.65rem] uppercase tracking-[0.18em] text-gray-500">Total Kredit</div>
                    <div class="ak-mono tabular-nums text-base mt-1">{{ number_format((float) $report['total_credit'], 2, ',', '.') }}</div>
                </div>
                <div class="px-6 py-4 ak-paper-soft">
                    <div class="ak-mono text-[0.65rem] uppercase tracking-[0.18em] text-gray-500">Saldo Akhir</div>
                    <div class="ak-mono tabular-nums text-base mt-1 font-semibold">{{ number_format((float) $report['ending'], 2, ',', '.') }}</div>
                </div>
            </div>

            <div class="overflow-x-auto px-2">
                <table class="w-full text-sm ak-sans">
                    <thead>
                        <tr class="ak-double-rule">
                            <th class="text-left px-4 py-3 ak-display font-medium italic text-gray-600" style="font-size:0.8rem; letter-spacing:0.12em;">Tanggal</th>
                            <th class="text-left px-4 py-3 ak-display font-medium italic text-gray-600" style="font-size:0.8rem; letter-spacing:0.12em;">No. Jurnal</th>
                            <th class="text-left px-4 py-3 ak-display font-medium italic text-gray-600" style="font-size:0.8rem; letter-spacing:0.12em;">Referensi</th>
                            <th class="text-left px-4 py-3 ak-display font-medium italic text-gray-600" style="font-size:0.8rem; letter-spacing:0.12em;">Keterangan</th>
                            <th class="text-right px-4 py-3 ak-display font-medium italic text-gray-600" style="font-size:0.8rem; letter-spacing:0.12em;">Debit</th>
                            <th class="text-right px-4 py-3 ak-display font-medium italic text-gray-600" style="font-size:0.8rem; letter-spacing:0.12em;">Kredit</th>
                            <th class="text-right px-4 py-3 ak-display font-medium italic text-gray-600" style="font-size:0.8rem; letter-spacing:0.12em;">Saldo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="border-b border-dashed ak-rule bg-[rgb(247,245,238)]/40">
                            <td class="px-4 py-2.5 ak-mono text-gray-500" colspan="6">Saldo Awal</td>
                            <td class="px-4 py-2.5 text-right ak-mono tabular-nums font-medium">
                                {{ number_format((float) $report['opening'], 2, ',', '.') }}
                            </td>
                        </tr>
                        @forelse ($report['lines'] as $l)
                            <tr class="border-b border-dashed ak-rule hover:bg-[rgb(184,101,74)]/[0.05] transition-colors">
                                <td class="px-4 py-2.5 ak-mono text-gray-700 dark:text-gray-300 whitespace-nowrap">
                                    {{ \Illuminate\Support\Carbon::parse($l->date)->format('d M Y') }}
                                </td>
                                <td class="px-4 py-2.5 ak-mono text-gray-900 dark:text-gray-100 whitespace-nowrap">
                                    <a href="{{ \App\Filament\Resources\JournalResource::getUrl('edit', parameters: ['record' => $l->journal_id], tenant: \Filament\Facades\Filament::getTenant()) }}"
                                       class="hover:ak-copper underline-offset-2 hover:underline">
                                        {{ $l->number ?: '—' }}
                                    </a>
                                </td>
                                <td class="px-4 py-2.5 text-gray-600 dark:text-gray-400">{{ $l->reference ?: '—' }}</td>
                                <td class="px-4 py-2.5 text-gray-700 dark:text-gray-300">
                                    {{ $l->line_memo ?: $l->journal_memo ?: '—' }}
                                </td>
                                <td class="px-4 py-2.5 text-right ak-mono tabular-nums">
                                    @if ((float) $l->debit > 0)
                                        {{ number_format((float) $l->debit, 2, ',', '.') }}
                                    @else
                                        <span class="text-gray-300">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-right ak-mono tabular-nums">
                                    @if ((float) $l->credit > 0)
                                        {{ number_format((float) $l->credit, 2, ',', '.') }}
                                    @else
                                        <span class="text-gray-300">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2.5 text-right ak-mono tabular-nums font-medium">
                                    {{ number_format((float) $l->balance, 2, ',', '.') }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-4 py-10 text-center text-gray-400 ak-italic" style="font-family: 'Fraunces', serif; font-style: italic;">
                                    Tidak ada transaksi pada periode ini.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                    <tfoot>
                        <tr class="ak-double-rule">
                            <td class="px-4 py-4 ak-display italic text-gray-700" colspan="4" style="font-variation-settings:'opsz' 48;">Total Periode</td>
                            <td class="px-4 py-4 text-right ak-mono tabular-nums font-semibold">
                                {{ number_format((float) $report['total_debit'], 2, ',', '.') }}
                            </td>
                            <td class="px-4 py-4 text-right ak-mono tabular-nums font-semibold">
                                {{ number_format((float) $report['total_credit'], 2, ',', '.') }}
                            </td>
                            <td class="px-4 py-4 text-right ak-mono tabular-nums font-bold">
                                {{ number_format((float) $report['ending'], 2, ',', '.') }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="px-6 py-4 border-t ak-rule flex items-center justify-between text-xs ak-mono uppercase tracking-[0.18em] text-gray-500">
                <span>{{ $report['lines']->count() }} baris · generated {{ now()->format('Y-m-d H:i') }}</span>
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
