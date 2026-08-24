<script lang="ts">
  import { onMount } from 'svelte';
  import { goto } from '$app/navigation';
  import { auth } from '$lib/stores/auth.svelte.js';
  import {
    sourceRefApi,
    type SourceRefAggregateRow,
    type SourceRefAggregateMeta,
  } from '$lib/api/source-ref.js';
  import { ApiError } from '$lib/api/client.js';
  import { formatRupiah } from '@akunta/ui';
  import ReportShell from '$lib/components/reporting/ReportShell.svelte';
  import DateInput from '$lib/components/ui/DateInput.svelte';
  import { formatDate } from '$lib/utils/date.js';
  import BookToggle, { type BookToggleValue } from '$lib/components/reporting/BookToggle.svelte';

  function firstOfMonth(): string {
    const d = new Date();
    return new Date(d.getFullYear(), d.getMonth(), 1).toISOString().slice(0, 10);
  }

  let pair = $state(''); // "source_app:ref_type"
  let knownPairs = $state<{ value: string; label: string }[]>([]);
  let periodStart = $state(firstOfMonth());
  let periodEnd = $state(new Date().toISOString().slice(0, 10));
  let rows = $state<SourceRefAggregateRow[]>([]);
  let fiscalRows = $state<SourceRefAggregateRow[]>([]);
  let meta = $state<SourceRefAggregateMeta | null>(null);
  let fiscalMeta = $state<SourceRefAggregateMeta | null>(null);
  let loading = $state(false);
  let error = $state<string | null>(null);
  let journalMode = $state<BookToggleValue>('internal');
  let isInspector = $derived(
    auth.user?.roles.some((role) => role.toLowerCase() === 'inspector') ?? false,
  );

  function parsePair(): { source_app: string; ref_type: string } | null {
    if (!pair) return null;
    const [app, type] = pair.split(':');
    if (!app || !type) return null;
    return { source_app: app, ref_type: type };
  }

  async function deriveKnownPairs(): Promise<void> {
    const all = await sourceRefApi.list();
    const seen = new Set<string>();
    const list: { value: string; label: string }[] = [];
    for (const r of all) {
      const key = `${r.source_app}:${r.ref_type}`;
      if (seen.has(key)) continue;
      seen.add(key);
      list.push({ value: key, label: `${r.source_app} · ${r.ref_type}` });
    }
    knownPairs = list;
    if (!pair && knownPairs[0]) pair = knownPairs[0].value;
  }

  async function load() {
    const p = parsePair();
    if (!p) return;
    loading = true;
    error = null;
    try {
      if (journalMode === 'both') {
        const [internal, fiscal] = await Promise.all([
          sourceRefApi.aggregate(
            p.source_app,
            p.ref_type,
            periodStart,
            periodEnd,
            null,
            'internal',
          ),
          sourceRefApi.aggregate(p.source_app, p.ref_type, periodStart, periodEnd, null, 'fiscal'),
        ]);
        rows = internal.data;
        meta = internal.meta;
        fiscalRows = fiscal.data;
        fiscalMeta = fiscal.meta;
      } else {
        const res = await sourceRefApi.aggregate(
          p.source_app,
          p.ref_type,
          periodStart,
          periodEnd,
          null,
          journalMode,
        );
        rows = res.data;
        meta = res.meta;
        fiscalRows = [];
        fiscalMeta = null;
      }
    } catch (e) {
      error = e instanceof ApiError ? `Server ${e.status}` : (e as Error).message;
    } finally {
      loading = false;
    }
  }

  function netClass(net: string): string {
    return Number(net) < 0 ? 'text-danger' : '';
  }

  function mergedRows() {
    const merged = new Map<
      string,
      {
        ref_id: string;
        code: string | null;
        label: string | null;
        internal: SourceRefAggregateRow | null;
        fiscal: SourceRefAggregateRow | null;
      }
    >();
    for (const row of rows)
      merged.set(row.ref_id, {
        ref_id: row.ref_id,
        code: row.code,
        label: row.label,
        internal: row,
        fiscal: null,
      });
    for (const row of fiscalRows) {
      const current = merged.get(row.ref_id);
      if (current) current.fiscal = row;
      else
        merged.set(row.ref_id, {
          ref_id: row.ref_id,
          code: row.code,
          label: row.label,
          internal: null,
          fiscal: row,
        });
    }
    return [...merged.values()];
  }

  onMount(async () => {
    if (!auth.user) {
      const u = await auth.refresh();
      if (!u) {
        goto('/login', { replaceState: true });
        return;
      }
    }
    if (isInspector) journalMode = 'fiscal';
    await deriveKnownPairs();
    if (pair) await load();
  });
</script>

<ReportShell
  title="Buku Pembantu"
  breadcrumb="Laporan / Buku Pembantu"
  subtitle={meta
    ? `${meta.source_app} · ${meta.ref_type} · ${formatDate(meta.period_start)} → ${formatDate(meta.period_end)}`
    : null}
>
  {#snippet actions()}
    <BookToggle
      value={journalMode}
      includeBoth
      disabled={isInspector || loading}
      onChange={async (value: BookToggleValue) => {
        journalMode = value;
        await load();
      }}
    />
  {/snippet}
  {#snippet toolbar()}
    <label class="text-sm">
      <span class="block font-medium mb-1">Sumber</span>
      <select class="rounded-md border border-border-default px-3 py-2 text-sm" bind:value={pair}>
        {#each knownPairs as p (p.value)}
          <option value={p.value}>{p.label}</option>
        {:else}
          <option value="">Belum ada data</option>
        {/each}
      </select>
    </label>
    <label class="text-sm">
      <span class="block font-medium mb-1">Mulai</span>
      <DateInput value={periodStart} onChange={(iso) => (periodStart = iso)} />
    </label>
    <label class="text-sm">
      <span class="block font-medium mb-1">Sampai</span>
      <DateInput value={periodEnd} onChange={(iso) => (periodEnd = iso)} />
    </label>
    <button
      type="button"
      class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-active disabled:opacity-50"
      onclick={load}
      disabled={loading || !pair}
      data-testid="bp-run"
    >
      {loading ? 'Memuat…' : 'Tampilkan'}
    </button>
  {/snippet}

  {#if error}
    <div class="p-4 text-sm text-danger">{error}</div>
  {:else if !meta}
    <div class="p-6 text-center text-text-muted">
      {knownPairs.length === 0
        ? 'Belum ada jurnal yang membawa source-ref. Aktivitas dari POSO/Payroll akan muncul di sini.'
        : 'Pilih sumber dan rentang tanggal.'}
    </div>
  {:else if journalMode === 'both' && fiscalMeta}
    <table class="w-full text-sm">
      <thead class="bg-page-bg text-xs uppercase tracking-wider text-text-muted">
        <tr>
          <th class="px-4 py-3 text-left" rowspan="2">Kode</th>
          <th class="px-4 py-3 text-left" rowspan="2">Nama</th>
          <th class="px-4 py-2 text-center" colspan="2">Jumlah Entri</th>
          <th class="px-4 py-2 text-center text-[#16a34a]" colspan="2">Total Debit</th>
          <th class="px-4 py-2 text-center text-[#f87171]" colspan="2">Total Kredit</th>
          <th class="px-4 py-2 text-center" colspan="2">Net (D–K)</th>
        </tr>
        <tr>
          {#each ['Entri', 'Debit', 'Kredit', 'Net'] as group (group)}
            <th
              class="bg-gradient-to-r from-[#f0fdf4] to-[#dcfce7] px-4 py-2 text-center text-[#166534]"
              >Intern</th
            >
            <th
              class="bg-gradient-to-r from-[#fffbeb] to-[#fef9c3] px-4 py-2 text-center text-[#854d0e]"
              >Fiskal</th
            >
          {/each}
        </tr>
      </thead>
      <tbody>
        {#each mergedRows() as row (row.ref_id)}
          <tr
            class="cursor-pointer border-t border-border-soft hover:bg-page-bg"
            onclick={() =>
              goto(
                `/laporan/buku-besar?source_app=${meta?.source_app}&source_ref_type=${meta?.ref_type}&source_ref_id=${row.ref_id}`,
              )}
          >
            <td class="px-4 py-2 font-mono">{row.code ?? '—'}</td>
            <td class="px-4 py-2">{row.label ?? row.ref_id}</td>
            <td class="px-4 py-2 text-right tabnum">{row.internal?.entry_count ?? 0}</td>
            <td class="px-4 py-2 text-right tabnum">{row.fiscal?.entry_count ?? 0}</td>
            <td class="px-4 py-2 text-right font-mono tabnum"
              >{formatRupiah(row.internal?.total_debit ?? '0')}</td
            >
            <td class="px-4 py-2 text-right font-mono tabnum"
              >{formatRupiah(row.fiscal?.total_debit ?? '0')}</td
            >
            <td class="px-4 py-2 text-right font-mono tabnum"
              >{formatRupiah(row.internal?.total_credit ?? '0')}</td
            >
            <td class="px-4 py-2 text-right font-mono tabnum"
              >{formatRupiah(row.fiscal?.total_credit ?? '0')}</td
            >
            <td class="px-4 py-2 text-right font-mono tabnum {netClass(row.internal?.net ?? '0')}"
              >{formatRupiah(row.internal?.net ?? '0')}</td
            >
            <td class="px-4 py-2 text-right font-mono tabnum {netClass(row.fiscal?.net ?? '0')}"
              >{formatRupiah(row.fiscal?.net ?? '0')}</td
            >
          </tr>
        {:else}
          <tr
            ><td colspan="10" class="px-4 py-10 text-center text-text-muted"
              >Tidak ada transaksi pada rentang ini.</td
            ></tr
          >
        {/each}
      </tbody>
      <tfoot class="bg-page-bg font-semibold">
        <tr class="border-t-2 border-border-default">
          <td class="px-4 py-2" colspan="4">Total</td>
          <td class="px-4 py-2 text-right font-mono tabnum">{formatRupiah(meta.totals.debit)}</td>
          <td class="px-4 py-2 text-right font-mono tabnum"
            >{formatRupiah(fiscalMeta.totals.debit)}</td
          >
          <td class="px-4 py-2 text-right font-mono tabnum">{formatRupiah(meta.totals.credit)}</td>
          <td class="px-4 py-2 text-right font-mono tabnum"
            >{formatRupiah(fiscalMeta.totals.credit)}</td
          >
          <td colspan="2"></td>
        </tr>
      </tfoot>
    </table>
  {:else}
    <table class="w-full text-sm">
      <thead class="bg-page-bg text-xs uppercase tracking-wider text-text-muted">
        <tr>
          <th class="px-4 py-3 text-left">Kode</th>
          <th class="px-4 py-3 text-left">Nama</th>
          <th class="px-4 py-3 text-right">Jumlah Entri</th>
          <th class="px-4 py-3 text-right text-[#16a34a]">Total Debit</th>
          <th class="px-4 py-3 text-right text-[#f87171]">Total Kredit</th>
          <th class="px-4 py-3 text-right">Net (D–K)</th>
        </tr>
      </thead>
      <tbody>
        {#each rows as r (r.ref_id)}
          <tr
            class="border-t border-border-soft hover:bg-page-bg cursor-pointer"
            onclick={() =>
              goto(
                `/laporan/buku-besar?source_app=${meta?.source_app}&source_ref_type=${meta?.ref_type}&source_ref_id=${r.ref_id}`,
              )}
          >
            <td class="px-4 py-2 font-mono">{r.code ?? '—'}</td>
            <td class="px-4 py-2">{r.label ?? r.ref_id}</td>
            <td class="px-4 py-2 text-right">{r.entry_count}</td>
            <td class="px-4 py-2 text-right font-mono tabnum">{formatRupiah(r.total_debit)}</td>
            <td class="px-4 py-2 text-right font-mono tabnum">{formatRupiah(r.total_credit)}</td>
            <td class="px-4 py-2 text-right font-mono tabnum {netClass(r.net)}"
              >{formatRupiah(r.net)}</td
            >
          </tr>
        {:else}
          <tr
            ><td colspan="6" class="px-4 py-10 text-center text-text-muted"
              >Tidak ada transaksi pada rentang ini.</td
            ></tr
          >
        {/each}
      </tbody>
      {#if rows.length > 0 && meta}
        <tfoot class="bg-page-bg font-semibold">
          <tr class="border-t-2 border-border-default">
            <td class="px-4 py-2" colspan="3">Total</td>
            <td class="px-4 py-2 text-right font-mono tabnum">{formatRupiah(meta.totals.debit)}</td>
            <td class="px-4 py-2 text-right font-mono tabnum">{formatRupiah(meta.totals.credit)}</td
            >
            <td class="px-4 py-2"></td>
          </tr>
        </tfoot>
      {/if}
    </table>
  {/if}
</ReportShell>
