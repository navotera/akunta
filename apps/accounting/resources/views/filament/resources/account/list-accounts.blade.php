@php
    $sideClass = fn (string $nb) => $nb === 'debit'
        ? 'text-success-600 dark:text-success-400'
        : 'text-warning-600 dark:text-warning-400';

    $needsTreeData = in_array($viewMode, ['tree', 'report'], true);
    $accounts      = $needsTreeData ? $this->getAccounts() : collect();
    $debitTree     = $viewMode === 'tree'   ? $this->buildSideTree($accounts, 'debit')  : [];
    $creditTree    = $viewMode === 'tree'   ? $this->buildSideTree($accounts, 'credit') : [];
    $grouped       = $viewMode === 'report' ? $this->groupByType($accounts) : [];
@endphp

<x-filament-panels::page>
    {{-- ===== Tab strip ===== --}}
    <div class="flex flex-wrap items-center gap-2 mb-4">
        <div class="inline-flex rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden text-sm">
            <button type="button" wire:click="setTab('list')"
                class="px-4 py-2 transition
                    {{ $viewMode === 'list'
                        ? 'bg-primary-500 text-white'
                        : 'bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800' }}">
                Daftar
            </button>
            <button type="button" wire:click="setTab('tree')"
                class="px-4 py-2 border-l border-gray-200 dark:border-gray-700 transition
                    {{ $viewMode === 'tree'
                        ? 'bg-primary-500 text-white'
                        : 'bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800' }}">
                Pohon
            </button>
            <button type="button" wire:click="setTab('report')"
                class="px-4 py-2 border-l border-gray-200 dark:border-gray-700 transition
                    {{ $viewMode === 'report'
                        ? 'bg-primary-500 text-white'
                        : 'bg-white dark:bg-gray-900 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800' }}">
                Laporan
            </button>
        </div>

        @if ($needsTreeData)
            <input type="text"
                wire:model.live.debounce.300ms="treeSearch"
                placeholder="Cari kode / nama..."
                class="flex-1 min-w-[14rem] max-w-xs px-3 py-2 text-sm rounded-md border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 focus:outline-none focus:ring-2 focus:ring-primary-500"/>

            <span class="ak-mono text-xs text-gray-500">{{ $accounts->count() }} akun</span>
        @endif

        @if ($viewMode === 'report')
            <button type="button" onclick="window.print()"
                class="ml-auto px-3 py-2 text-sm rounded-md bg-gray-100 dark:bg-gray-800 text-gray-700 dark:text-gray-200 hover:bg-gray-200 dark:hover:bg-gray-700 inline-flex items-center gap-2">
                <x-filament::icon icon="heroicon-m-printer" class="w-4 h-4"/>
                Cetak
            </button>
        @endif
    </div>

    {{-- ===== TAB: DAFTAR — pakai Filament table standar ===== --}}
    @if ($viewMode === 'list')
        {{ $this->table }}
    @endif

    {{-- ===== TAB: POHON — split debit/credit ===== --}}
    @if ($viewMode === 'tree')
        @if (empty($debitTree) && empty($creditTree))
            <div class="ak-paper-soft border ak-rule rounded-sm p-8 text-sm text-gray-500 text-center">
                Tidak ada akun. Terapkan template CoA dulu via Pengaturan → Template CoA.
            </div>
        @else
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                {{-- Debit column with bottom green gradient --}}
                <div class="ak-paper-soft border ak-rule rounded-sm p-5 ak-tree-debit relative overflow-hidden">
                    <div class="flex items-baseline justify-between mb-3 pb-2 border-b border-gray-200 dark:border-gray-700">
                        <span class="ak-eyebrow text-success-600 dark:text-success-400">Debit (Saldo Normal Debit)</span>
                        <span class="ak-mono text-xs text-gray-500">{{ collect($debitTree)->count() }} root</span>
                    </div>
                    @if (empty($debitTree))
                        <div class="text-sm text-gray-500 text-center py-6">— tidak ada akun debit —</div>
                    @else
                        <ul class="ak-tree text-sm relative z-10">
                            @foreach ($debitTree as $node)
                                @include('filament.resources.account._tree-node', ['node' => $node, 'depth' => 0])
                            @endforeach
                        </ul>
                    @endif
                </div>

                {{-- Credit column --}}
                <div class="ak-paper-soft border ak-rule rounded-sm p-5 ak-tree-credit relative overflow-hidden">
                    <div class="flex items-baseline justify-between mb-3 pb-2 border-b border-gray-200 dark:border-gray-700">
                        <span class="ak-eyebrow text-warning-600 dark:text-warning-400">Credit (Saldo Normal Kredit)</span>
                        <span class="ak-mono text-xs text-gray-500">{{ collect($creditTree)->count() }} root</span>
                    </div>
                    @if (empty($creditTree))
                        <div class="text-sm text-gray-500 text-center py-6">— tidak ada akun kredit —</div>
                    @else
                        <ul class="ak-tree text-sm relative z-10">
                            @foreach ($creditTree as $node)
                                @include('filament.resources.account._tree-node', ['node' => $node, 'depth' => 0])
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        @endif
    @endif

    {{-- ===== TAB: LAPORAN ===== --}}
    @if ($viewMode === 'report')
        <div class="ak-paper-soft border ak-rule rounded-sm p-8 ak-print-target">
            <div class="text-center mb-6 print:mb-4">
                <div class="ak-eyebrow">Bagan Akun</div>
                <h2 class="text-2xl font-semibold tracking-tight">Chart of Accounts</h2>
                <div class="text-xs text-gray-500 ak-mono mt-1">
                    Per {{ now()->isoFormat('D MMMM YYYY') }} · {{ $accounts->count() }} akun
                </div>
            </div>

            @forelse ($grouped as $type => $rows)
                <section class="mb-7 break-inside-avoid">
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
                <div class="text-sm text-gray-500 text-center py-8">Tidak ada akun.</div>
            @endforelse
        </div>
    @endif

    @push('styles')
        <style>
            .ak-tree, .ak-tree ul { list-style: none; padding-left: 0; margin: 0; }
            .ak-tree ul { padding-left: 1.5rem; border-left: 1px dashed rgb(120 120 120 / 0.25); margin-left: 0.5rem; }

            /* ===== Side-accent stack: top-strip + corner radial ===== */
            /* (a) thin colored top edge — fade dari kanan ke kiri */
            .ak-tree-debit::before,
            .ak-tree-credit::before {
                content: '';
                position: absolute;
                top: 0; left: 0; right: 0;
                height: 3px;
                pointer-events: none;
                z-index: 1;
            }
            .ak-tree-debit::before {
                background: linear-gradient(to left, #17C653, rgba(23, 198, 83, 0));
            }
            .ak-tree-credit::before {
                background: linear-gradient(to left, #F8285A, rgba(248, 40, 90, 0));
            }

            /* (b) corner radial — diperbesar jadi 18rem, intensitas naik */
            .ak-tree-debit::after,
            .ak-tree-credit::after {
                content: '';
                position: absolute;
                top: -2rem; right: -2rem;
                width: 18rem; height: 18rem;
                pointer-events: none;
                z-index: 0;
                filter: blur(0.5px);
            }
            .ak-tree-debit::after {
                background: radial-gradient(circle at top right, rgba(23, 198, 83, 0.45), rgba(23, 198, 83, 0.10) 50%, rgba(23, 198, 83, 0) 75%);
            }
            .ak-tree-credit::after {
                background: radial-gradient(circle at top right, rgba(248, 40, 90, 0.40), rgba(248, 40, 90, 0.10) 50%, rgba(248, 40, 90, 0) 75%);
            }
            .dark .ak-tree-debit::after {
                background: radial-gradient(circle at top right, rgba(23, 198, 83, 0.30), rgba(23, 198, 83, 0.06) 50%, rgba(23, 198, 83, 0) 75%);
            }
            .dark .ak-tree-credit::after {
                background: radial-gradient(circle at top right, rgba(248, 40, 90, 0.26), rgba(248, 40, 90, 0.06) 50%, rgba(248, 40, 90, 0) 75%);
            }

            /* (optional layer 3) huruf watermark — uncomment kalau mau lebih ekspresif
            .ak-tree-debit { background-image: ...; }
            */
            .ak-tree li { padding: 0.15rem 0; }
            .ak-tree-row {
                display: grid;
                grid-template-columns: 1.25rem 5rem 1fr;
                gap: 0.75rem;
                align-items: center;
                padding: 0.3rem 0.5rem;
                border-radius: 0.375rem;
            }
            .ak-tree-row:hover { background: rgb(0 0 0 / 0.03); }
            .dark .ak-tree-row:hover { background: rgb(255 255 255 / 0.05); }
            .ak-tree-toggle {
                display: inline-flex;
                width: 1rem;
                height: 1rem;
                align-items: center;
                justify-content: center;
                color: rgb(120 130 157);
                cursor: pointer;
                user-select: none;
                font-size: 0.7rem;
            }
            .ak-tree-toggle.is-leaf { visibility: hidden; }
            .ak-tree-toggle::before { content: '▾'; transition: transform 0.15s; }
            .ak-tree-toggle.is-collapsed::before { transform: rotate(-90deg); }

            @media print {
                .fi-sidebar, .fi-topbar, .fi-page-header, .fi-page > *:not(.ak-print-target) { display: none !important; }
                .ak-print-target { box-shadow: none !important; border: 0 !important; padding: 0 !important; }
                body { background: white !important; }
            }
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
