<x-filament-panels::page>
    <form wire:submit="run">
        {{ $this->form }}
        <div class="mt-4">
            <x-filament::button type="submit">Refresh</x-filament::button>
        </div>
    </form>

    @if ($report)
        <div class="mt-6 overflow-x-auto">
            <table class="w-full text-sm border-collapse">
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th class="text-left p-2 border">Kode</th>
                        <th class="text-left p-2 border">Nama Akun</th>
                        <th class="text-left p-2 border">Tipe</th>
                        <th class="text-right p-2 border">Debit</th>
                        <th class="text-right p-2 border">Kredit</th>
                        <th class="text-right p-2 border">Saldo</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($report['rows'] as $row)
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-800">
                            <td class="p-2 border font-mono">{{ $row->code }}</td>
                            <td class="p-2 border">{{ $row->name }}</td>
                            <td class="p-2 border">{{ $row->type }}</td>
                            <td class="p-2 border text-right font-mono">{{ number_format((float) $row->total_debit, 2, ',', '.') }}</td>
                            <td class="p-2 border text-right font-mono">{{ number_format((float) $row->total_credit, 2, ',', '.') }}</td>
                            <td class="p-2 border text-right font-mono">{{ number_format((float) $row->balance, 2, ',', '.') }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="bg-gray-100 dark:bg-gray-900 font-semibold">
                    <tr>
                        <td class="p-2 border" colspan="3">Total</td>
                        <td class="p-2 border text-right font-mono">{{ number_format((float) $report['total_debit'], 2, ',', '.') }}</td>
                        <td class="p-2 border text-right font-mono">{{ number_format((float) $report['total_credit'], 2, ',', '.') }}</td>
                        <td class="p-2 border"></td>
                    </tr>
                </tfoot>
            </table>
            <p class="text-sm mt-2 text-gray-600">Per tanggal {{ $report['as_of'] }}. Rows: {{ $report['rows']->count() }}.</p>
        </div>
    @endif
</x-filament-panels::page>
