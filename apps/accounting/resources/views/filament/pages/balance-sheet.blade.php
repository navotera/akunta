<x-filament-panels::page>
    <form wire:submit="run">
        {{ $this->form }}
        <div class="mt-4">
            <x-filament::button type="submit">Refresh</x-filament::button>
        </div>
    </form>

    @if ($report)
        @php
            $fmt = fn ($v) => number_format((float) $v, 2, ',', '.');
        @endphp

        <div class="grid md:grid-cols-2 gap-6 mt-6">
            <section>
                <h2 class="text-lg font-semibold mb-2">Aktiva</h2>
                <table class="w-full text-sm border-collapse">
                    <tbody>
                        @foreach ($report['assets']['lines'] as $row)
                            <tr>
                                <td class="p-2 border font-mono">{{ $row->code }}</td>
                                <td class="p-2 border">{{ $row->name }}</td>
                                <td class="p-2 border text-right font-mono">{{ $fmt($row->balance) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="font-semibold bg-gray-100 dark:bg-gray-900">
                        <tr><td class="p-2 border" colspan="2">Total Aktiva</td><td class="p-2 border text-right font-mono">{{ $fmt($report['assets']['total']) }}</td></tr>
                    </tfoot>
                </table>
            </section>

            <section>
                <h2 class="text-lg font-semibold mb-2">Kewajiban & Ekuitas</h2>
                <table class="w-full text-sm border-collapse">
                    <thead><tr><th class="text-left p-2 border" colspan="3">Kewajiban</th></tr></thead>
                    <tbody>
                        @foreach ($report['liabilities']['lines'] as $row)
                            <tr>
                                <td class="p-2 border font-mono">{{ $row->code }}</td>
                                <td class="p-2 border">{{ $row->name }}</td>
                                <td class="p-2 border text-right font-mono">{{ $fmt($row->balance) }}</td>
                            </tr>
                        @endforeach
                        <tr class="font-semibold">
                            <td class="p-2 border" colspan="2">Subtotal Kewajiban</td>
                            <td class="p-2 border text-right font-mono">{{ $fmt($report['liabilities']['total']) }}</td>
                        </tr>
                    </tbody>
                    <thead><tr><th class="text-left p-2 border" colspan="3">Ekuitas</th></tr></thead>
                    <tbody>
                        @foreach ($report['equity']['lines'] as $row)
                            <tr>
                                <td class="p-2 border font-mono">{{ $row->code }}</td>
                                <td class="p-2 border">{{ $row->name }}</td>
                                <td class="p-2 border text-right font-mono">{{ $fmt($row->balance) }}</td>
                            </tr>
                        @endforeach
                        <tr>
                            <td class="p-2 border" colspan="2"><em>Laba (Rugi) Tahun Berjalan</em></td>
                            <td class="p-2 border text-right font-mono italic">{{ $fmt($report['equity']['net_income_ytd']) }}</td>
                        </tr>
                        <tr class="font-semibold">
                            <td class="p-2 border" colspan="2">Subtotal Ekuitas</td>
                            <td class="p-2 border text-right font-mono">{{ $fmt($report['equity']['total']) }}</td>
                        </tr>
                    </tbody>
                    <tfoot class="font-semibold bg-gray-100 dark:bg-gray-900">
                        <tr>
                            <td class="p-2 border" colspan="2">Total Kewajiban + Ekuitas</td>
                            <td class="p-2 border text-right font-mono">{{ $fmt(bcadd($report['liabilities']['total'], $report['equity']['total'], 2)) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </section>
        </div>

        <p class="text-sm mt-4 {{ $report['balanced'] ? 'text-emerald-600' : 'text-red-600 font-semibold' }}">
            {{ $report['balanced'] ? 'Neraca seimbang ✓' : '⚠ Neraca TIDAK seimbang. Investigate.' }}
            Per tanggal {{ $report['as_of'] }}.
        </p>
    @endif
</x-filament-panels::page>
