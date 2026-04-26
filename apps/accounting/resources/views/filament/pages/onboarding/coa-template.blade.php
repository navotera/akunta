@php
    $existingCount = $this->getExistingCount();
    $stats         = $this->getStats();
    $accounts      = $this->getStubAccounts();
    $debitTree     = $this->previewMode === 'tree' ? $this->buildSideTree($accounts, 'debit')  : [];
    $creditTree    = $this->previewMode === 'tree' ? $this->buildSideTree($accounts, 'credit') : [];
    $grouped       = $this->previewMode === 'flat' ? $this->groupByType($accounts) : [];

    $sideClass = fn (string $nb) => $nb === 'debit'
        ? 'text-success-600 dark:text-success-400'
        : 'text-warning-600 dark:text-warning-400';
@endphp

<x-filament-panels::page>
    <div class="flex items-baseline justify-between border-y border-gray-900/40 dark:border-white/10 py-1 mb-6">
        <span class="ak-eyebrow">§ Onboarding — Template CoA</span>
        <span class="ak-mono text-xs tracking-[0.18em] uppercase text-gray-500">
            {{ $existingCount }} akun saat ini di entitas
        </span>
    </div>

    {{-- ===== Existing entity warning ===== --}}
    @if ($existingCount > 0)
        <div class="rounded-xl border border-warning-300 bg-warning-50 dark:border-warning-700/40 dark:bg-warning-950/40 p-4 mb-6">
            <div class="flex items-start gap-3">
                <x-filament::icon icon="heroicon-o-information-circle" class="w-5 h-5 mt-0.5 text-warning-600 dark:text-warning-400 shrink-0"/>
                <div class="text-sm text-gray-700 dark:text-gray-200">
                    Entitas sudah memiliki <strong>{{ $existingCount }} akun</strong>.
                    Template ditambahkan secara <strong>idempoten</strong> — kode yang sudah ada dilewati, tidak ditimpa.
                </div>
            </div>
        </div>
    @endif

    {{-- ===== Tips panel ===== --}}
    <div class="ak-paper-soft border ak-rule rounded-sm p-5 mb-6">
        <div class="flex items-start justify-between gap-3 mb-2">
            <div class="flex items-center gap-2">
                <x-filament::icon icon="heroicon-o-light-bulb" class="w-5 h-5 text-info-500"/>
                <h3 class="font-semibold text-gray-900 dark:text-gray-100">Cara Membaca Template Ini</h3>
            </div>
            <x-filament::button wire:click="toggleTips" size="sm" color="gray">
                @if ($showTips) Sembunyikan @else Tampilkan @endif
            </x-filament::button>
        </div>

        @if ($showTips)
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm text-gray-700 dark:text-gray-300 mt-3">
                <div>
                    <div class="ak-eyebrow text-success-600 dark:text-success-400 mb-1">Kolom Debit (kiri)</div>
                    <p>Akun ber-saldo normal <strong>debit</strong>: <em>Aktiva</em> (1xxx), <em>Beban</em> (5xxx-6xxx). Bertambah saat di-debit.</p>
                </div>
                <div>
                    <div class="ak-eyebrow text-warning-600 dark:text-warning-400 mb-1">Kolom Kredit (kanan)</div>
                    <p>Akun ber-saldo normal <strong>kredit</strong>: <em>Kewajiban</em> (2xxx), <em>Ekuitas</em> (3xxx), <em>Pendapatan</em> (4xxx). Bertambah saat di-kredit.</p>
                </div>
                <div>
                    <div class="ak-eyebrow mb-1">Akun Grup vs Postable</div>
                    <p>Baris <span class="font-semibold">tebal</span> bertanda <span class="text-[10px] uppercase tracking-wider text-gray-400">grup</span> = akun header (tidak bisa di-jurnal). Indentasi menunjukkan parent-child.</p>
                </div>
                <div>
                    <div class="ak-eyebrow mb-1">Konvensi Kode</div>
                    <p>4-digit, PSAK-compatible. <code class="ak-mono text-xs">1xxx</code>=Aktiva, <code class="ak-mono text-xs">2xxx</code>=Kewajiban, <code class="ak-mono text-xs">3xxx</code>=Ekuitas, <code class="ak-mono text-xs">4xxx</code>=Pendapatan, <code class="ak-mono text-xs">5xxx</code>=HPP, <code class="ak-mono text-xs">6xxx</code>=Beban.</p>
                </div>
                <div class="md:col-span-2">
                    <div class="ak-eyebrow mb-1">Tindak Lanjut Setelah Apply</div>
                    <p>Setelah diterapkan, kamu bisa: <strong>(1)</strong> tambah/edit akun spesifik di <em>Bagan Akun</em>, <strong>(2)</strong> non-aktifkan akun yang tidak terpakai (jangan dihapus jika sudah ada jurnal), <strong>(3)</strong> apply template lain — kode yang bertabrakan otomatis dilewati.</p>
                </div>
            </div>
        @endif
    </div>

    {{-- ===== Template selector + actions ===== --}}
    <div class="ak-paper-soft border ak-rule rounded-sm p-5 mb-6">
        {{ $this->form }}
        <div class="mt-4 flex flex-wrap items-center gap-2">
            <x-filament::button
                wire:click="apply"
                wire:confirm="Terapkan template? Akun yang sudah ada akan dilewati."
                color="primary"
                icon="heroicon-m-sparkles">
                Terapkan ke Entitas
            </x-filament::button>
            <span class="ak-mono text-xs text-gray-500 ml-auto">
                Pilih industri di atas — pratinjau muncul otomatis di bawah.
            </span>
        </div>
    </div>

    {{-- ===== Stats strip ===== --}}
    @if ($stats['total'] > 0)
        <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 mb-4">
            <div class="ak-paper-soft border ak-rule rounded-sm p-3">
                <div class="ak-eyebrow text-gray-500">Total Akun</div>
                <div class="text-xl font-semibold ak-mono mt-1">{{ $stats['total'] }}</div>
            </div>
            <div class="ak-paper-soft border ak-rule rounded-sm p-3">
                <div class="ak-eyebrow text-gray-500">Postable</div>
                <div class="text-xl font-semibold ak-mono mt-1">{{ $stats['postable'] }}</div>
            </div>
            <div class="ak-paper-soft border ak-rule rounded-sm p-3">
                <div class="ak-eyebrow text-gray-500">Grup</div>
                <div class="text-xl font-semibold ak-mono mt-1">{{ $stats['groups'] }}</div>
            </div>
            <div class="ak-paper-soft border ak-rule rounded-sm p-3">
                <div class="ak-eyebrow text-success-600 dark:text-success-400">Debit</div>
                <div class="text-xl font-semibold ak-mono mt-1">{{ $stats['debit'] }}</div>
            </div>
            <div class="ak-paper-soft border ak-rule rounded-sm p-3">
                <div class="ak-eyebrow text-warning-600 dark:text-warning-400">Credit</div>
                <div class="text-xl font-semibold ak-mono mt-1">{{ $stats['credit'] }}</div>
            </div>
        </div>
    @endif

    {{-- ===== View mode toggle ===== --}}
    <div class="flex flex-wrap items-center gap-2 mb-4">
        <div class="inline-flex rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden text-sm">
            <button type="button" wire:click="setPreviewMode('tree')"
                class="px-4 py-2 transition
                    {{ $previewMode === 'tree'
                        ? 'bg-primary-500 text-white'
                        : 'bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800' }}">
                Pohon (Debit / Kredit)
            </button>
            <button type="button" wire:click="setPreviewMode('flat')"
                class="px-4 py-2 border-l border-gray-200 dark:border-gray-700 transition
                    {{ $previewMode === 'flat'
                        ? 'bg-primary-500 text-white'
                        : 'bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800' }}">
                Daftar per Tipe
            </button>
        </div>

        <span class="ak-mono text-xs text-gray-500 ml-auto">Pratinjau — {{ $template_key }}</span>
    </div>

    {{-- ===== TREE VIEW ===== --}}
    @if ($previewMode === 'tree')
        @if (empty($debitTree) && empty($creditTree))
            <div class="ak-paper-soft border ak-rule rounded-sm p-8 text-sm text-gray-500 text-center">
                Pilih template di atas untuk pratinjau.
            </div>
        @else
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                {{-- Debit column --}}
                <div class="ak-paper-soft border ak-rule rounded-sm p-5 ak-tree-debit relative overflow-hidden">
                    <div class="flex items-baseline justify-between mb-3 pb-2 border-b border-gray-200 dark:border-gray-700">
                        <span class="ak-eyebrow text-success-600 dark:text-success-400">Debit (Saldo Normal Debit)</span>
                        <span class="ak-mono text-xs text-gray-500">{{ collect($debitTree)->count() }} root · {{ $stats['debit'] }} akun</span>
                    </div>
                    @if (empty($debitTree))
                        <div class="text-sm text-gray-500 text-center py-6">— tidak ada akun debit —</div>
                    @else
                        <ul class="ak-tree text-sm relative z-10">
                            @foreach ($debitTree as $node)
                                @include('filament.pages.onboarding._template-tree-node', ['node' => $node])
                            @endforeach
                        </ul>
                    @endif
                </div>

                {{-- Credit column --}}
                <div class="ak-paper-soft border ak-rule rounded-sm p-5 ak-tree-credit relative overflow-hidden">
                    <div class="flex items-baseline justify-between mb-3 pb-2 border-b border-gray-200 dark:border-gray-700">
                        <span class="ak-eyebrow text-warning-600 dark:text-warning-400">Credit (Saldo Normal Kredit)</span>
                        <span class="ak-mono text-xs text-gray-500">{{ collect($creditTree)->count() }} root · {{ $stats['credit'] }} akun</span>
                    </div>
                    @if (empty($creditTree))
                        <div class="text-sm text-gray-500 text-center py-6">— tidak ada akun kredit —</div>
                    @else
                        <ul class="ak-tree text-sm relative z-10">
                            @foreach ($creditTree as $node)
                                @include('filament.pages.onboarding._template-tree-node', ['node' => $node])
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        @endif
    @endif

    {{-- ===== FLAT VIEW (per tipe) ===== --}}
    @if ($previewMode === 'flat')
        <div class="ak-paper-soft border ak-rule rounded-sm p-6">
            @forelse ($grouped as $type => $rows)
                <section class="mb-7 last:mb-0 break-inside-avoid">
                    <h3 class="text-sm uppercase tracking-[0.18em] text-gray-500 border-b border-gray-300 dark:border-gray-700 pb-1 mb-3">
                        {{ $this->getTypeLabel($type) }}
                        <span class="ak-mono normal-case tracking-normal text-xs text-gray-400">({{ count($rows) }})</span>
                    </h3>

                    <table class="w-full text-sm ak-sans">
                        <tbody>
                            @foreach ($rows->sortBy('code') as $a)
                                @php $depth = max(0, ((int) (strlen($a->code) - 4)) / 2); @endphp
                                <tr class="border-b border-gray-100 dark:border-gray-800 last:border-0">
                                    <td class="py-1 ak-mono text-xs text-gray-600 dark:text-gray-400 w-20 align-top">{{ $a->code }}</td>
                                    <td class="py-1 align-top" style="padding-left: {{ $depth * 1.25 }}rem;">
                                        <span class="{{ $a->is_postable ? '' : 'font-semibold' }}">{{ $a->name }}</span>
                                        @if (! $a->is_postable)
                                            <span class="ml-2 text-[10px] uppercase tracking-wider text-gray-400">grup</span>
                                        @endif
                                    </td>
                                    <td class="py-1 text-xs {{ $sideClass($a->normal_balance) }} align-top w-20">
                                        {{ ucfirst($a->normal_balance) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </section>
            @empty
                <div class="text-sm text-gray-500 text-center py-8">Pilih template untuk pratinjau.</div>
            @endforelse
        </div>
    @endif

    @push('styles')
        <style>
            .ak-tree, .ak-tree ul { list-style: none; padding-left: 0; margin: 0; }
            .ak-tree ul { padding-left: 1.5rem; border-left: 1px dashed rgb(120 120 120 / 0.25); margin-left: 0.5rem; }

            .ak-tree-debit::before, .ak-tree-credit::before {
                content: ''; position: absolute; top: 0; left: 0; right: 0;
                height: 3px; pointer-events: none; z-index: 1;
            }
            .ak-tree-debit::before  { background: linear-gradient(to left, #17C653, rgba(23, 198, 83, 0)); }
            .ak-tree-credit::before { background: linear-gradient(to left, #F8285A, rgba(248, 40, 90, 0)); }

            .ak-tree-debit::after, .ak-tree-credit::after {
                content: ''; position: absolute; top: -2rem; right: -2rem;
                width: 18rem; height: 18rem; pointer-events: none; z-index: 0;
                filter: blur(0.5px);
            }
            .ak-tree-debit::after  { background: radial-gradient(circle at top right, rgba(23, 198, 83, 0.45), rgba(23, 198, 83, 0.10) 50%, rgba(23, 198, 83, 0) 75%); }
            .ak-tree-credit::after { background: radial-gradient(circle at top right, rgba(248, 40, 90, 0.40), rgba(248, 40, 90, 0.10) 50%, rgba(248, 40, 90, 0) 75%); }
            .dark .ak-tree-debit::after  { background: radial-gradient(circle at top right, rgba(23, 198, 83, 0.30), rgba(23, 198, 83, 0.06) 50%, rgba(23, 198, 83, 0) 75%); }
            .dark .ak-tree-credit::after { background: radial-gradient(circle at top right, rgba(248, 40, 90, 0.26), rgba(248, 40, 90, 0.06) 50%, rgba(248, 40, 90, 0) 75%); }

            .ak-tree li { padding: 0.15rem 0; }
            .ak-tree-row {
                display: grid; grid-template-columns: 1.25rem 5rem 1fr; gap: 0.75rem;
                align-items: center; padding: 0.3rem 0.5rem; border-radius: 0.375rem;
            }
            .ak-tree-row:hover { background: rgb(0 0 0 / 0.03); }
            .dark .ak-tree-row:hover { background: rgb(255 255 255 / 0.05); }
            .ak-tree-toggle {
                display: inline-flex; width: 1rem; height: 1rem;
                align-items: center; justify-content: center;
                color: rgb(120 130 157); cursor: pointer; user-select: none;
                font-size: 0.7rem;
            }
            .ak-tree-toggle.is-leaf { visibility: hidden; }
            .ak-tree-toggle::before { content: '▾'; transition: transform 0.15s; }
            .ak-tree-toggle.is-collapsed::before { transform: rotate(-90deg); }
        </style>
    @endpush

    @push('scripts')
        <script>
            document.addEventListener('click', (e) => {
                const t = e.target.closest('.ak-tree-toggle');
                if (! t) return;
                const li = t.closest('li');
                if (! li) return;
                const sub = li.querySelector(':scope > ul');
                if (! sub) return;
                t.classList.toggle('is-collapsed');
                sub.style.display = t.classList.contains('is-collapsed') ? 'none' : '';
            });
        </script>
    @endpush
</x-filament-panels::page>
