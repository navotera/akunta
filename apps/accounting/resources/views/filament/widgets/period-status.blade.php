<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">Status Periode Akuntansi</x-slot>

        @if ($period === null)
            <div class="text-sm text-gray-500 dark:text-gray-400">
                Tidak ada periode terbuka. Silakan buat periode baru di menu <strong>Periode</strong>.
            </div>
        @else
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <div>
                    <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Periode Aktif</div>
                    <div class="mt-1 text-lg font-semibold">{{ $period->name }}</div>
                    <div class="text-sm text-gray-600 dark:text-gray-400">
                        {{ \Illuminate\Support\Carbon::parse($period->start_date)->translatedFormat('d M Y') }}
                        &mdash;
                        {{ \Illuminate\Support\Carbon::parse($period->end_date)->translatedFormat('d M Y') }}
                    </div>
                </div>

                <div>
                    <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Sisa Hari</div>
                    @if ($overdue)
                        <div class="mt-1 text-lg font-semibold text-red-600 dark:text-red-400">
                            Telat {{ abs($days_until_end) }} hari
                        </div>
                        <div class="text-sm text-red-500">Periode lewat tanggal akhir &mdash; segera tutup.</div>
                    @else
                        <div class="mt-1 text-lg font-semibold {{ $days_until_end <= 7 ? 'text-amber-600 dark:text-amber-400' : '' }}">
                            {{ $days_until_end }} hari
                        </div>
                        <div class="text-sm text-gray-600 dark:text-gray-400">
                            sampai akhir periode
                        </div>
                    @endif
                </div>

                <div>
                    <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Aksi</div>
                    <div class="mt-1 flex flex-wrap gap-2">
                        <x-filament::button
                            tag="a"
                            href="{{ \App\Filament\Resources\PeriodResource::getUrl('edit', ['record' => $period->id]) }}"
                            size="sm"
                            color="gray"
                        >
                            Lihat / Tutup
                        </x-filament::button>
                    </div>
                </div>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
