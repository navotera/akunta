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

        <div class="mt-6 max-w-2xl">
            <table class="w-full text-sm border-collapse">
                <thead><tr><th class="text-left p-2 border" colspan="3">Pendapatan</th></tr></thead>
                <tbody>
                    @foreach ($report['revenue']['lines'] as $row)
                        <tr>
                            <td class="p-2 border font-mono">{{ $row->code }}</td>
                            <td class="p-2 border">{{ $row->name }}</td>
                            <td class="p-2 border text-right font-mono">{{ $fmt($row->balance) }}</td>
                        </tr>
                    @endforeach
                    <tr class="font-semibold">
                        <td class="p-2 border" colspan="2">Total Pendapatan</td>
                        <td class="p-2 border text-right font-mono">{{ $fmt($report['revenue']['total']) }}</td>
                    </tr>
                </tbody>

                <thead><tr><th class="text-left p-2 border" colspan="3">HPP</th></tr></thead>
                <tbody>
                    @foreach ($report['cogs']['lines'] as $row)
                        <tr>
                            <td class="p-2 border font-mono">{{ $row->code }}</td>
                            <td class="p-2 border">{{ $row->name }}</td>
                            <td class="p-2 border text-right font-mono">{{ $fmt($row->balance) }}</td>
                        </tr>
                    @endforeach
                    <tr class="font-semibold">
                        <td class="p-2 border" colspan="2">Total HPP</td>
                        <td class="p-2 border text-right font-mono">{{ $fmt($report['cogs']['total']) }}</td>
                    </tr>
                </tbody>

                <tbody class="bg-gray-100 dark:bg-gray-900 font-semibold">
                    <tr>
                        <td class="p-2 border" colspan="2">Laba Kotor</td>
                        <td class="p-2 border text-right font-mono">{{ $fmt($report['gross_profit']) }}</td>
                    </tr>
                </tbody>

                <thead><tr><th class="text-left p-2 border" colspan="3">Biaya Operasional</th></tr></thead>
                <tbody>
                    @foreach ($report['expenses']['lines'] as $row)
                        <tr>
                            <td class="p-2 border font-mono">{{ $row->code }}</td>
                            <td class="p-2 border">{{ $row->name }}</td>
                            <td class="p-2 border text-right font-mono">{{ $fmt($row->balance) }}</td>
                        </tr>
                    @endforeach
                    <tr class="font-semibold">
                        <td class="p-2 border" colspan="2">Total Biaya Operasional</td>
                        <td class="p-2 border text-right font-mono">{{ $fmt($report['expenses']['total']) }}</td>
                    </tr>
                </tbody>

                <tfoot class="bg-emerald-50 dark:bg-emerald-950 font-bold">
                    <tr>
                        <td class="p-2 border" colspan="2">Laba (Rugi) Bersih</td>
                        <td class="p-2 border text-right font-mono">{{ $fmt($report['net_income']) }}</td>
                    </tr>
                </tfoot>
            </table>
            <p class="text-sm mt-2 text-gray-600">Periode {{ $report['period_start'] }} s/d {{ $report['period_end'] }}.</p>
        </div>
    @endif
</x-filament-panels::page>
