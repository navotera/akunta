<x-filament-panels::page>
    @php
        $healthy   = $cron['healthy'] ?? false;
        $age       = $cron['age_seconds'] ?? null;
        $last      = $cron['last'] ?? null;
        $threshold = $cron['threshold_seconds'] ?? 180;
        $tabs = [
            'status' => ['label' => 'Status', 'icon' => 'heroicon-m-signal'],
            'log'    => ['label' => 'Activity Log', 'icon' => 'heroicon-m-list-bullet'],
        ];
    @endphp

    {{-- ===== Tabs ===== --}}
    <div class="border-b border-gray-200 dark:border-white/10 mb-6">
        <nav class="-mb-px flex gap-6" aria-label="Tabs">
            @foreach ($tabs as $key => $meta)
                @php $isActive = $activeTab === $key; @endphp
                <button
                    type="button"
                    wire:click="setActiveTab('{{ $key }}')"
                    class="inline-flex items-center gap-2 border-b-2 px-1 py-3 text-sm font-medium transition
                        {{ $isActive
                            ? 'border-primary-500 text-primary-600 dark:text-primary-400'
                            : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200' }}"
                >
                    <x-filament::icon :icon="$meta['icon']" class="w-4 h-4"/>
                    {{ $meta['label'] }}
                </button>
            @endforeach
        </nav>
    </div>

    {{-- ===== Tab: Status ===== --}}
    @if ($activeTab === 'status')
        <div class="space-y-6">
            {{-- Cron status banner --}}
            <div class="rounded-xl border p-5
                {{ $healthy
                    ? 'border-success-300 bg-success-50 dark:border-success-700/40 dark:bg-success-950/40'
                    : 'border-warning-300 bg-warning-50 dark:border-warning-700/40 dark:bg-warning-950/40' }}">
                <div class="flex items-start gap-4">
                    <div class="shrink-0 mt-0.5">
                        @if ($healthy)
                            <x-filament::icon icon="heroicon-o-check-circle" class="w-6 h-6 text-success-600 dark:text-success-400"/>
                        @else
                            <x-filament::icon icon="heroicon-o-exclamation-triangle" class="w-6 h-6 text-warning-600 dark:text-warning-400"/>
                        @endif
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="font-semibold text-gray-900 dark:text-gray-100">
                            @if ($healthy)
                                Scheduler Aktif — Cron Berjalan
                            @else
                                Scheduler Tidak Terdeteksi — Cron Belum Disetel
                            @endif
                        </div>
                        <div class="mt-1 text-sm text-gray-700 dark:text-gray-300">
                            @if ($healthy)
                                Heartbeat terakhir: <span class="ak-mono">{{ $last }}</span>
                                @if ($age !== null) ({{ $age }} detik lalu) @endif
                            @else
                                @if ($last === null)
                                    Belum pernah ada heartbeat. Cron OS harus dikonfigurasi agar
                                    <code>php artisan schedule:run</code> dipanggil setiap menit.
                                @else
                                    Heartbeat terakhir <span class="ak-mono">{{ $last }}</span>
                                    ({{ $age }} detik lalu) — melebihi ambang {{ $threshold }} detik.
                                @endif
                            @endif
                        </div>

                        <div class="mt-4 flex flex-wrap items-center gap-2">
                            <x-filament::button wire:click="refreshStatus" size="sm" color="gray" icon="heroicon-m-arrow-path">
                                Refresh
                            </x-filament::button>
                            <x-filament::button wire:click="testManualHeartbeat" size="sm" color="primary" icon="heroicon-m-bolt">
                                Tes Sekarang
                            </x-filament::button>
                            <x-filament::button wire:click="toggleInstructions" size="sm" color="gray" icon="heroicon-m-question-mark-circle">
                                @if ($showInstructions) Tutup Petunjuk @else Detail / Petunjuk Setup @endif
                            </x-filament::button>
                            @if ($last !== null)
                                <x-filament::button wire:click="clearHeartbeat" size="sm" color="danger" outlined icon="heroicon-m-trash">
                                    Reset Heartbeat
                                </x-filament::button>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            {{-- Retention card --}}
            <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-6">
                <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100 mb-1">
                    Retensi Activity Log
                </h2>
                <p class="text-sm text-gray-600 dark:text-gray-300 mb-4">
                    Log eksekusi cron dipertahankan selama jumlah hari berikut, lalu dihapus
                    otomatis oleh tugas <span class="ak-mono">accounting:prune-cron-logs</span>
                    yang berjalan setiap jam. Rentang valid: {{ \App\Models\CronSetting::RETENTION_MIN }}–{{ \App\Models\CronSetting::RETENTION_MAX }} hari.
                </p>

                <form wire:submit.prevent="saveRetention" class="flex items-end gap-3 flex-wrap">
                    <div>
                        <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">
                            Hari
                        </label>
                        <input
                            type="number"
                            wire:model="retentionDays"
                            min="{{ \App\Models\CronSetting::RETENTION_MIN }}"
                            max="{{ \App\Models\CronSetting::RETENTION_MAX }}"
                            step="1"
                            class="block w-32 rounded-lg border-gray-300 dark:border-white/10 bg-white dark:bg-white/5 text-sm focus:border-primary-500 focus:ring-primary-500"
                        />
                    </div>
                    <x-filament::button type="submit" size="sm" color="primary" icon="heroicon-m-check">
                        Simpan Retensi
                    </x-filament::button>
                </form>
            </div>

            {{-- Instructions panel --}}
            @if ($showInstructions)
                <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-6">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-2">
                        Cara Mengaktifkan Cron untuk Akunta
                    </h2>
                    <ol class="list-decimal list-inside space-y-3 text-sm text-gray-700 dark:text-gray-300">
                        <li>Buka crontab editor:
                            <pre class="mt-2 p-3 rounded-md bg-gray-50 dark:bg-gray-800 ak-mono text-xs overflow-x-auto"><code>crontab -e</code></pre>
                        </li>
                        <li>Tambahkan baris berikut:
                            <pre class="mt-2 p-3 rounded-md bg-gray-50 dark:bg-gray-800 ak-mono text-xs overflow-x-auto"><code>{{ $this->getCronCommandSnippet() }}</code></pre>
                        </li>
                        <li>Verifikasi: <code>crontab -l</code></li>
                        <li>Tunggu 60–90 detik lalu klik <strong>Refresh</strong>.</li>
                    </ol>
                </div>
            @endif
        </div>
    @endif

    {{-- ===== Tab: Activity Log ===== --}}
    @if ($activeTab === 'log')
        <div>
            {{ $this->table }}
        </div>
    @endif
</x-filament-panels::page>
