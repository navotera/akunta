@php
    $fmt = fn (string $n) => number_format((float) $n, 0, ',', '.');
    $isAR = ($report['type'] ?? null) === 'customer';
    $sideLabel = $isAR ? 'Piutang' : 'Hutang';
    $eyebrow   = $isAR ? '§ Buku Pembantu Piutang' : '§ Buku Pembantu Hutang';
@endphp

<x-filament-panels::page>
    <div class="flex items-baseline justify-between border-y border-gray-900/40 py-1 mb-6">
        <span class="ak-eyebrow">{{ $eyebrow }}</span>
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
                    {{ $report['rows']->count() }} mitra · saldo total {{ $fmt($report['total_balance']) }}
                </span>
            @endif
        </div>
    </form>

    @if ($report)
        {{-- ======== Summary table ======== --}}
        <div class="ak-paper-soft border ak-rule rounded-sm overflow-hidden mb-6">
            <div class="flex items-baseline justify-between px-6 pt-6 pb-4 border-b ak-rule">
                <div>
                    <div class="ak-eyebrow mb-1">Saldo Per Mitra</div>
                    <h3 class="ak-display text-2xl tracking-tight">
                        Buku Pembantu <em class="ak-italic ak-copper">{{ $sideLabel }}</em>
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
                        <tr class="text-left text-xs uppercase tracking-wide text-gray-500 border-b ak-rule">
                            <th class="px-4 py-2">Kode</th>
                            <th class="px-4 py-2">Nama Mitra</th>
                            <th class="px-4 py-2 text-right">Total Debit</th>
                            <th class="px-4 py-2 text-right">Total Kredit</th>
                            <th class="px-4 py-2 text-right">Saldo</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($report['rows'] as $row)
                            <tr class="border-b ak-rule/40 hover:bg-gray-50 dark:hover:bg-white/5">
                                <td class="px-4 py-2 ak-mono text-xs">{{ $row->code ?? '—' }}</td>
                                <td class="px-4 py-2">{{ $row->name }}</td>
                                <td class="px-4 py-2 text-right ak-mono">{{ $fmt($row->total_debit) }}</td>
                                <td class="px-4 py-2 text-right ak-mono">{{ $fmt($row->total_credit) }}</td>
                                <td class="px-4 py-2 text-right ak-mono font-semibold">{{ $fmt($row->balance) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-4 py-6 text-center text-gray-500">Tidak ada saldo terbuka.</td></tr>
                        @endforelse
                    </tbody>
                    @if ($report['rows']->count())
                        <tfoot>
                            <tr class="border-t-2 ak-rule font-semibold">
                                <td colspan="4" class="px-4 py-3 text-right">Total {{ $sideLabel }}</td>
                                <td class="px-4 py-3 text-right ak-mono">{{ $fmt($report['total_balance']) }}</td>
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </div>

        {{-- ======== Aging table ======== --}}
        @if ($aging)
            <div class="ak-paper-soft border ak-rule rounded-sm overflow-hidden">
                <div class="flex items-baseline justify-between px-6 pt-6 pb-4 border-b ak-rule">
                    <div>
                        <div class="ak-eyebrow mb-1">Aging — FIFO</div>
                        <h3 class="ak-display text-2xl tracking-tight">
                            Umur <em class="ak-italic ak-copper">{{ $sideLabel }}</em>
                        </h3>
                    </div>
                    <div class="text-right ak-mono text-xs uppercase tracking-[0.18em] text-gray-500">
                        <div>Buckets (hari)</div>
                        <div class="text-gray-900 dark:text-gray-100 text-sm mt-0.5">0 / 30 / 60 / 90 / 90+</div>
                    </div>
                </div>

                <div class="overflow-x-auto px-2">
                    <table class="w-full text-sm ak-sans">
                        <thead>
                            <tr class="text-left text-xs uppercase tracking-wide text-gray-500 border-b ak-rule">
                                <th class="px-4 py-2">Kode</th>
                                <th class="px-4 py-2">Nama Mitra</th>
                                @foreach ($aging['buckets'] as $b)
                                    <th class="px-4 py-2 text-right">{{ $b }}</th>
                                @endforeach
                                <th class="px-4 py-2 text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($aging['rows'] as $row)
                                <tr class="border-b ak-rule/40 hover:bg-gray-50 dark:hover:bg-white/5">
                                    <td class="px-4 py-2 ak-mono text-xs">{{ $row->partner_code ?? '—' }}</td>
                                    <td class="px-4 py-2">{{ $row->partner_name }}</td>
                                    @foreach ($aging['buckets'] as $b)
                                        <td class="px-4 py-2 text-right ak-mono">{{ $fmt($row->buckets[$b]) }}</td>
                                    @endforeach
                                    <td class="px-4 py-2 text-right ak-mono font-semibold">{{ $fmt($row->total) }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="{{ count($aging['buckets']) + 3 }}" class="px-4 py-6 text-center text-gray-500">Tidak ada saldo terbuka untuk aging.</td></tr>
                            @endforelse
                        </tbody>
                        @if ($aging['rows']->count())
                            <tfoot>
                                <tr class="border-t-2 ak-rule font-semibold">
                                    <td colspan="2" class="px-4 py-3 text-right">Total</td>
                                    @foreach ($aging['buckets'] as $b)
                                        <td class="px-4 py-3 text-right ak-mono">{{ $fmt($aging['totals'][$b]) }}</td>
                                    @endforeach
                                    <td class="px-4 py-3 text-right ak-mono">{{ $fmt($aging['totals']['total']) }}</td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
            </div>
        @endif
    @endif
</x-filament-panels::page>
