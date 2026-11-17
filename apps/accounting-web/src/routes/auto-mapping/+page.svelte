<script lang="ts">
  import { createListReturn } from '$lib/utils/list-return.svelte.js';

  import { goto } from '$app/navigation';
  import {
    autoMappingApi,
    type AutoMappingRaw,
    type AutoMappingEntityLink,
    type AutoMappingAppOption,
  } from '$lib/api/auto-mapping.js';
  import { tenant } from '$lib/stores/tenant.svelte.js';
  import { can } from '$lib/stores/permissions.svelte.js';
  import { formatDateTime } from '$lib/utils/date.js';

  let rows = $state<AutoMappingRaw[]>([]);
  let activeTab = $state<'mapped' | 'raw'>('mapped');
  let search = $state('');
  let sourceFilter = $state('');
  let statusFilter = $state('');
  let dateFilter = $state('');
  let loading = $state(true);
  let error = $state<string | null>(null);
  let entityLinks = $state<AutoMappingEntityLink[]>([]);
  let appOptions = $state<AutoMappingAppOption[]>([]);
  let linkAppCode = $state('');
  let sourceEntityId = $state('');
  let linkBusy = $state(false);
  let linkError = $state<string | null>(null);
  let loadRequest = 0;

  $effect(() => {
    const entityId = tenant.id;
    const requestId = ++loadRequest;
    rows = [];
    entityLinks = [];
    appOptions = [];
    linkAppCode = '';
    sourceEntityId = '';
    clearFilters();
    listReturn.restore();
    loading = true;
    error = null;
    linkError = null;
    if (!entityId) {
      loading = false;
      return;
    }
    void loadForEntity(entityId, requestId);
  });

  async function loadForEntity(entityId: string, requestId: number) {
    try {
      const [rawResponse, linkResponse] = await Promise.all([
        autoMappingApi.list(entityId),
        autoMappingApi.entityLinks(entityId),
      ]);
      if (requestId !== loadRequest) return;
      rows = rawResponse.data;
      entityLinks = linkResponse.data;
      appOptions = linkResponse.apps;
      linkAppCode = appOptions[0]?.code ?? '';
    } catch (e) {
      if (requestId !== loadRequest) return;
      error = e instanceof Error ? e.message : 'Gagal memuat Auto Mapping.';
    } finally {
      if (requestId === loadRequest) loading = false;
    }
  }

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
    const entityId = tenant.id;
    if (!entityId) return;
    loading = true;
    error = null;
    try {
      const result = await autoMappingApi.list(entityId);
      if (tenant.id === entityId) rows = result.data;
    } catch (e) {
      if (tenant.id === entityId)
        error = e instanceof Error ? e.message : 'Gagal menyinkronkan Auto Mapping.';
    } finally {
      if (tenant.id === entityId) loading = false;
    }
  }

  async function addEntityLink(event: SubmitEvent) {
    event.preventDefault();
    if (!can('automapping.entity_link.manage') || !linkAppCode || !sourceEntityId.trim()) return;
    const entityId = tenant.id;
    if (!entityId) return;
    linkBusy = true;
    linkError = null;
    try {
      await autoMappingApi.createEntityLink(linkAppCode, sourceEntityId.trim(), entityId);
      const links = (await autoMappingApi.entityLinks(entityId)).data;
      if (tenant.id === entityId) {
        entityLinks = links;
        sourceEntityId = '';
      }
    } catch (e) {
      if (tenant.id === entityId)
        linkError = e instanceof Error ? e.message : 'Gagal menyimpan pemetaan entitas.';
    } finally {
      linkBusy = false;
    }
  }

  async function removeEntityLink(link: AutoMappingEntityLink) {
    if (!can('automapping.entity_link.manage')) return;
    if (!confirm(`Hapus pemetaan ${link.app.code} / ${link.source_entity_id}?`)) return;
    const entityId = tenant.id;
    if (!entityId) return;
    linkBusy = true;
    linkError = null;
    try {
      await autoMappingApi.deleteEntityLink(link.id, entityId);
      if (tenant.id === entityId) entityLinks = entityLinks.filter((item) => item.id !== link.id);
    } catch (e) {
      if (tenant.id === entityId)
        linkError = e instanceof Error ? e.message : 'Gagal menghapus pemetaan entitas.';
    } finally {
      linkBusy = false;
    }
  }
  const listReturn = createListReturn(
    'automapping',
    () => ({ activeTab, search, sourceFilter, statusFilter, dateFilter }),
    (saved) => {
      ({ activeTab, search, sourceFilter, statusFilter, dateFilter } = saved);
    },
    () => !loading,
  );
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
          onclick={() => goto('/integrasi')}
        >
          <span aria-hidden="true">⚙</span> Integrasi
        </button>
        <button
          type="button"
          class="inline-flex h-10 items-center gap-2 rounded-md bg-[#1b84ff] px-4 text-[13px] font-semibold text-white shadow-sm hover:bg-[#056ee9] disabled:opacity-50"
          onclick={syncRows}
          disabled={loading}
        >
          <span aria-hidden="true">↻</span> Sinkronisasi sekarang
        </button>
      </div>
    </div>

    <section class="mt-7 rounded-lg border border-[#dbdfe9] bg-white p-5 shadow-sm">
      <h2 class="text-[15px] font-semibold text-[#071437]">Pemetaan Entitas Sumber</h2>
      <p class="mt-1 text-[13px] text-[#78829d]">
        Hubungkan ID entitas dari aplikasi pengirim ke {tenant.name ?? 'entitas Akunta ini'}.
        Aplikasi cukup mengirim <code>source_entity_id</code>; ID entitas Akunta tidak perlu
        disimpan di aplikasi pengirim.
      </p>
      {#if entityLinks.length > 0}
        <div class="mt-4 overflow-x-auto">
          <table class="w-full min-w-[560px] text-left text-[13px]">
            <thead class="border-b border-[#e5e7eb] text-[#78829d]"
              ><tr>
                <th class="py-2">Aplikasi</th><th class="py-2">ID Entitas Sumber</th><th
                  class="py-2">Entitas Akunta</th
                ><th class="py-2 text-right">Aksi</th>
              </tr></thead
            >
            <tbody>
              {#each entityLinks as link (link.id)}
                <tr class="border-b border-[#f1f1f4]">
                  <td class="py-2">{link.app.name} ({link.app.code})</td>
                  <td class="py-2 font-mono text-[12px]">{link.source_entity_id}</td>
                  <td class="py-2">{tenant.name}</td>
                  <td class="py-2 text-right">
                    {#if can('automapping.entity_link.manage')}
                      <button
                        type="button"
                        class="text-[#d61f52] hover:underline"
                        disabled={linkBusy}
                        onclick={() => removeEntityLink(link)}>Hapus</button
                      >
                    {/if}
                  </td>
                </tr>
              {/each}
            </tbody>
          </table>
        </div>
      {/if}
      {#if can('automapping.entity_link.manage')}
        <form class="mt-4 flex flex-wrap items-end gap-3" onsubmit={addEntityLink}>
          <label class="flex min-w-[180px] flex-col gap-1 text-[12px] font-medium text-[#4b5675]">
            Aplikasi sumber
            <select
              class="h-10 rounded-md border border-[#dbdfe9] bg-white px-3"
              bind:value={linkAppCode}
              required
            >
              {#each appOptions as app (app.id)}<option value={app.code}
                  >{app.name} ({app.code})</option
                >{/each}
            </select>
          </label>
          <label
            class="flex min-w-[260px] flex-1 flex-col gap-1 text-[12px] font-medium text-[#4b5675]"
          >
            ID entitas di aplikasi sumber
            <input
              class="h-10 rounded-md border border-[#dbdfe9] px-3 font-mono"
              bind:value={sourceEntityId}
              maxlength="160"
              placeholder="UUID dari Asseto, POSO, atau aplikasi lain"
              required
            />
          </label>
          <button
            type="submit"
            class="h-10 rounded-md bg-[#1b84ff] px-4 text-[13px] font-semibold text-white disabled:opacity-50"
            disabled={linkBusy || !linkAppCode}>Simpan Pemetaan</button
          >
        </form>
      {/if}
      {#if linkError}<p class="mt-3 text-[13px] text-[#d61f52]">{linkError}</p>{/if}
    </section>

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
                  data-return-row={`automapping:${row.id}`}
                  onclick={() => {
                    listReturn.remember(row.id);
                    void goto(`/auto-mapping/${row.id}`);
                  }}
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
