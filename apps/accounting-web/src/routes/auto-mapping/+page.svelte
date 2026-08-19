<script lang="ts">
  import { onMount } from 'svelte';
  import { goto } from '$app/navigation';
  import { autoMappingApi, type AutoMappingRaw } from '$lib/api/auto-mapping.js';
  import { tenant } from '$lib/stores/tenant.svelte.js';
  import { formatDateTime } from '$lib/utils/date.js';

  let rows = $state<AutoMappingRaw[]>([]);
  let activeTab = $state<'mapped' | 'raw'>('mapped');
  let search = $state('');
  let sourceFilter = $state('');
  let statusFilter = $state('');
  let dateFilter = $state('');
  let loading = $state(true);
  let error = $state<string | null>(null);

  onMount(async () => {
    try {
      rows = (await autoMappingApi.list(tenant.id)).data;
    } catch (e) {
      error = e instanceof Error ? e.message : 'Gagal memuat Auto Mapping.';
    } finally {
      loading = false;
    }
  });

  const sources = $derived([...new Set(rows.map((row) => row.source_type))]);
  const tabRows = $derived(
    rows.filter((row) =>
      activeTab === 'mapped' ? row.status === 'mapped' : row.status !== 'mapped',
    ),
  );
  const visibleRows = $derived(
    tabRows.filter((row) => {
      const query = search.trim().toLowerCase();
      const matchesSearch =
        !query ||
        [row.source_type, row.id, row.journal_id ?? '', row.rule?.name ?? ''].some((value) =>
          value.toLowerCase().includes(query),
        );
      const matchesSource = !sourceFilter || row.source_type === sourceFilter;
      const matchesStatus = !statusFilter || row.status === statusFilter;
      const matchesDate = !dateFilter || row.created_at.slice(0, 10) === dateFilter;
      return matchesSearch && matchesSource && matchesStatus && matchesDate;
    }),
  );

  function sourceLabel(value: string): string {
    return value.replace(/[_-]+/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
  }

  function statusLabel(value: string): string {
    return value === 'mapped'
      ? 'Auto Mapped'
      : value === 'unmapped'
        ? 'Belum Mapping'
        : value === 'pending'
          ? 'Menunggu'
          : 'Gagal';
  }

  function clearFilters() {
    search = '';
    sourceFilter = '';
    statusFilter = '';
    dateFilter = '';
  }

  async function syncRows() {
    loading = true;
    error = null;
    try {
      rows = (await autoMappingApi.list(tenant.id)).data;
    } catch (e) {
      error = e instanceof Error ? e.message : 'Gagal menyinkronkan Auto Mapping.';
    } finally {
      loading = false;
    }
  }
</script>

<div class="min-h-full bg-[#fafbfc] px-5 py-6 text-[#252f4a] lg:px-8">
  <div class="mx-auto max-w-[1500px]">
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <div class="mb-1 flex items-center gap-2 text-xs font-medium text-[#78829d]">
          <span>Jurnal</span><span>›</span><span class="text-[#4b5675]">Auto Mapping</span>
        </div>
        <h1 class="text-[24px] font-bold tracking-[-0.02em] text-[#071437]">Auto Mapping</h1>
        <p class="mt-1 text-[13px] text-[#78829d]">
          Kelola data masuk dari sumber eksternal dan proses pemetaan otomatis ke jurnal.
        </p>
      </div>
      <div class="flex items-center gap-2">
        <button
          type="button"
          class="inline-flex h-10 items-center gap-2 rounded-md border border-[#dbdfe9] bg-white px-4 text-[13px] font-semibold text-[#4b5675] shadow-[0_1px_2px_rgba(15,23,42,.04)] hover:border-[#1b84ff] hover:text-[#1b84ff]"
        >
          <span aria-hidden="true">⚙</span> Integrasi &amp; Source
        </button>
        <button
          type="button"
          class="inline-flex h-10 items-center gap-2 rounded-md bg-[#1b84ff] px-4 text-[13px] font-semibold text-white shadow-sm hover:bg-[#056ee9] disabled:opacity-50"
          onclick={syncRows}
          disabled={loading}
        >
          <span aria-hidden="true">↻</span> Sinkronisasi Sekarang
        </button>
      </div>
    </div>

    <div class="mt-7 border-b border-[#dbdfe9]">
      <div class="flex gap-7">
        <button
          type="button"
          class="relative pb-3 text-[13px] font-semibold {activeTab === 'mapped'
            ? 'text-[#1b84ff]'
            : 'text-[#78829d] hover:text-[#4b5675]'}"
          onclick={() => (activeTab = 'mapped')}
        >
          Auto Mapped <span
            class="ml-1 rounded-full bg-[#eff6ff] px-1.5 py-0.5 text-[11px] text-[#1b84ff]"
            >{rows.filter((row) => row.status === 'mapped').length}</span
          >
          {#if activeTab === 'mapped'}<span
              class="absolute inset-x-0 bottom-[-1px] h-0.5 rounded-full bg-[#1b84ff]"
            ></span>{/if}
        </button>
        <button
          type="button"
          class="relative pb-3 text-[13px] font-semibold {activeTab === 'raw'
            ? 'text-[#1b84ff]'
            : 'text-[#78829d] hover:text-[#4b5675]'}"
          onclick={() => (activeTab = 'raw')}
        >
          Raw Data / Belum Dimapping <span
            class="ml-1 rounded-full bg-[#fff8dd] px-1.5 py-0.5 text-[11px] text-[#a16a00]"
            >{rows.filter((row) => row.status !== 'mapped').length}</span
          >
          {#if activeTab === 'raw'}<span
              class="absolute inset-x-0 bottom-[-1px] h-0.5 rounded-full bg-[#1b84ff]"
            ></span>{/if}
        </button>
      </div>
    </div>

    <section
      class="mt-5 rounded-lg border border-[#dbdfe9] bg-white shadow-[0_1px_2px_rgba(15,23,42,.04)]"
    >
      <div class="flex flex-wrap items-center gap-2 border-b border-[#e5e7eb] p-4">
        <label class="relative min-w-[240px] flex-1 lg:max-w-[380px]">
          <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-[#99a1b7]"
            >⌕</span
          >
          <input
            class="h-10 w-full rounded-md border border-[#dbdfe9] bg-white pl-9 pr-3 text-[13px] outline-none placeholder:text-[#99a1b7] focus:border-[#1b84ff] focus:ring-4 focus:ring-[#1b84ff]/10"
            bind:value={search}
            placeholder="Cari berdasarkan source, referensi, atau jurnal..."
          />
        </label>
        <select
          class="h-10 min-w-[160px] rounded-md border border-[#dbdfe9] bg-white px-3 text-[13px] text-[#4b5675] outline-none focus:border-[#1b84ff]"
          bind:value={sourceFilter}
        >
          <option value="">Semua Source</option>
          {#each sources as source}<option value={source}>{sourceLabel(source)}</option>{/each}
        </select>
        <select
          class="h-10 min-w-[145px] rounded-md border border-[#dbdfe9] bg-white px-3 text-[13px] text-[#4b5675] outline-none focus:border-[#1b84ff]"
          bind:value={statusFilter}
        >
          <option value="">Semua Status</option><option value="mapped">Auto Mapped</option><option
            value="unmapped">Belum Mapping</option
          ><option value="pending">Menunggu</option><option value="failed">Gagal</option>
        </select>
        <input
          type="date"
          class="h-10 rounded-md border border-[#dbdfe9] bg-white px-3 text-[13px] text-[#4b5675] outline-none focus:border-[#1b84ff]"
          bind:value={dateFilter}
          aria-label="Filter tanggal"
        />
        <button
          type="button"
          class="inline-flex h-10 items-center gap-2 rounded-md border border-[#dbdfe9] px-3 text-[13px] font-semibold text-[#4b5675] hover:border-[#1b84ff] hover:text-[#1b84ff]"
          onclick={clearFilters}>⚱ Filter</button
        >
      </div>

      {#if loading}
        <div class="p-12 text-center text-[13px] text-[#78829d]">Memuat data Auto Mapping...</div>
      {:else if error}
        <div class="p-12 text-center text-[13px] text-[#f8285a]">{error}</div>
      {:else if visibleRows.length === 0}
        <div class="p-12 text-center text-[13px] text-[#78829d]">
          Belum ada data pada tab atau filter ini.
        </div>
      {:else}
        <div class="overflow-x-auto">
          <table class="w-full min-w-[980px] text-left">
            <thead
              class="bg-[#fafafb] text-[11px] font-semibold uppercase tracking-[0.04em] text-[#78829d]"
            >
              <tr
                ><th class="w-12 px-4 py-3 text-center">#</th><th class="px-4 py-3">Waktu Masuk</th
                ><th class="px-4 py-3">Source</th><th class="px-4 py-3">Referensi / ID Eksternal</th
                ><th class="px-4 py-3">Status</th><th class="px-4 py-3">Rule / Mapping</th><th
                  class="px-4 py-3">Jurnal</th
                ><th class="px-4 py-3 text-right">Total (Debit = Kredit)</th><th
                  class="w-16 px-4 py-3 text-center">Aksi</th
                ></tr
              >
            </thead>
            <tbody class="text-[13px]">
              {#each visibleRows as row, index (row.id)}
                <tr
                  class="cursor-pointer border-t border-[#e5e7eb] transition-colors hover:bg-[#fafafb]"
                  onclick={() => goto(`/auto-mapping/${row.id}`)}
                >
                  <td class="px-4 py-3.5 text-center text-[#78829d]">{index + 1}</td>
                  <td class="whitespace-nowrap px-4 py-3.5 text-[#4b5675]"
                    >{formatDateTime(row.created_at)}</td
                  >
                  <td class="px-4 py-3.5 font-semibold text-[#252f4a]"
                    >{sourceLabel(row.source_type)}</td
                  >
                  <td class="px-4 py-3.5 font-mono text-[12px] text-[#4b5675]">{row.id}</td>
                  <td class="px-4 py-3.5"
                    ><span
                      class="rounded px-2 py-1 text-[11px] font-semibold {row.status === 'mapped'
                        ? 'bg-[#dfffea] text-[#0d9448]'
                        : row.status === 'failed'
                          ? 'bg-[#ffeef3] text-[#d61f52]'
                          : 'bg-[#fff8dd] text-[#a16a00]'}">{statusLabel(row.status)}</span
                    ></td
                  >
                  <td class="px-4 py-3.5 text-[#4b5675]">{row.rule?.name ?? 'Belum ada rule'}</td>
                  <td class="px-4 py-3.5 font-mono text-[12px] text-[#1b84ff]"
                    >{row.journal_id ?? '—'}</td
                  >
                  <td class="px-4 py-3.5 text-right font-mono tabular-nums text-[#4b5675]">—</td>
                  <td class="px-4 py-3.5 text-center text-[#78829d]">◉</td>
                </tr>
              {/each}
            </tbody>
          </table>
        </div>
        <div
          class="flex flex-wrap items-center justify-between gap-3 border-t border-[#e5e7eb] px-4 py-3 text-[12px] text-[#78829d]"
        >
          <span>Menampilkan 1 – {visibleRows.length} dari {visibleRows.length} data</span>
          <div class="flex items-center gap-1">
            <button type="button" class="h-8 w-8 rounded border border-[#dbdfe9] text-[#99a1b7]"
              >‹</button
            ><button type="button" class="h-8 w-8 rounded bg-[#1b84ff] font-semibold text-white"
              >1</button
            ><button type="button" class="h-8 w-8 rounded border border-[#dbdfe9] text-[#99a1b7]"
              >›</button
            >
          </div>
        </div>
      {/if}
    </section>
  </div>
</div>
