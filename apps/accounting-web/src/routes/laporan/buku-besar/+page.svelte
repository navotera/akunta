<script lang="ts">
  import { onMount } from 'svelte';
  import { goto } from '$app/navigation';
  import { page } from '$app/stores';
  import { auth } from '$lib/stores/auth.svelte.js';
  import { reportingApi, type GeneralLedgerData } from '$lib/api/reporting.js';
  import { accountApi, type AccountOption } from '$lib/api/account.js';
  import { sourceRefApi, type SourceRefRegistryItem } from '$lib/api/source-ref.js';
  import { formatRupiah } from '@akunta/ui';
  import { formatDate } from '$lib/utils/date.js';
  import ReportShell from '$lib/components/reporting/ReportShell.svelte';
  import BookToggle, { type BookToggleValue } from '$lib/components/reporting/BookToggle.svelte';
  import AccountCombobox from '$lib/components/ui/AccountCombobox.svelte';
  import DateInput from '$lib/components/ui/DateInput.svelte';

  function firstOfMonth(): string {
    const d = new Date();
    return new Date(d.getFullYear(), d.getMonth(), 1).toISOString().slice(0, 10);
  }

  let accounts = $state<AccountOption[]>([]);
  let accountId = $state('');
  let periodStart = $state(firstOfMonth());
  let periodEnd = $state(new Date().toISOString().slice(0, 10));
  let report = $state<GeneralLedgerData | null>(null);
  let loading = $state(false);
  let error = $state<string | null>(null);
  let journalMode = $state<BookToggleValue>('internal');
  let isInspector = $derived(
    auth.user?.roles.some((role) => role.toLowerCase() === 'inspector') ?? false,
  );

  // Source-ref filter — `source_app:ref_type` selector + ref autocomplete
  let sourcePair = $state(''); // e.g. "poso:customer"
  let sourceRefId = $state('');
  let sourceRefQuery = $state('');
  let sourceRefSuggestions = $state<SourceRefRegistryItem[]>([]);
  let sourceRefMenuOpen = $state(false);

  // Distinct source pairs auto-derived from registry on first load.
  let knownPairs = $state<{ value: string; label: string }[]>([]);

  function parsePair(): { source_app: string; ref_type: string } | null {
    if (!sourcePair) return null;
    const [app, type] = sourcePair.split(':');
    if (!app || !type) return null;
    return { source_app: app, ref_type: type };
  }

  async function reloadSuggestions(): Promise<void> {
    const p = parsePair();
    if (!p) {
      sourceRefSuggestions = [];
      return;
    }
    sourceRefSuggestions = await sourceRefApi.list({
      source_app: p.source_app,
      ref_type: p.ref_type,
      q: sourceRefQuery || undefined,
    });
  }

  function clearSourceFilter(): void {
    sourceRefId = '';
    sourceRefQuery = '';
    sourceRefSuggestions = [];
    sourceRefMenuOpen = false;
  }

  function selectedSuggestionLabel(): string {
    const hit = sourceRefSuggestions.find((s) => s.ref_id === sourceRefId);
    return hit?.label ?? hit?.code ?? sourceRefId;
  }

  async function load() {
    if (!accountId) return;
    loading = true;
    error = null;
    try {
      const filters: Record<string, string> = {};
      const pair = parsePair();
      if (pair && sourceRefId) {
        filters.source_app = pair.source_app;
        filters.source_ref_type = pair.ref_type;
        filters.source_ref_id = sourceRefId;
      }
      const res = await reportingApi.generalLedger(
        accountId,
        periodStart,
        periodEnd,
        filters,
        journalMode,
      );
      report = res.data;
    } catch (e) {
      error = (e as Error).message;
    } finally {
      loading = false;
    }
  }

  async function loadAccounts(mode: BookToggleValue): Promise<AccountOption[]> {
    if (mode !== 'both') return accountApi.list('', undefined, true, mode);

    const [internal, fiscal] = await Promise.all([
      accountApi.list('', undefined, true, 'internal'),
      accountApi.list('', undefined, true, 'fiscal'),
    ]);
    return [
      ...new Map([...internal, ...fiscal].map((account) => [account.id, account])).values(),
    ].sort((a, b) => a.code.localeCompare(b.code));
  }

  function comparisonLines(internal: GeneralLedgerData, fiscal: GeneralLedgerData) {
    return [
      ...internal.lines.map((line) => ({
        key: `internal-${line.line_id}`,
        book: 'internal' as const,
        line,
      })),
      ...fiscal.lines.map((line) => ({
        key: `fiscal-${line.line_id}`,
        book: 'fiscal' as const,
        line,
      })),
    ].sort(
      (a, b) =>
        a.line.date.localeCompare(b.line.date) || a.line.number.localeCompare(b.line.number),
    );
  }

  async function deriveKnownPairs(): Promise<void> {
    // One unfiltered list — small (max 200 rows). Distinct on the fly.
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
    accounts = await loadAccounts(journalMode);
    const queryAccount = $page.url.searchParams.get('account_id');
    accountId = queryAccount ?? accounts[0]?.id ?? '';
    await deriveKnownPairs();

    // Deep-link from Buku Pembantu: pre-apply source filter when present
    const qApp = $page.url.searchParams.get('source_app');
    const qType = $page.url.searchParams.get('source_ref_type');
    const qRef = $page.url.searchParams.get('source_ref_id');
    if (qApp && qType) {
      sourcePair = `${qApp}:${qType}`;
      if (qRef) {
        sourceRefId = qRef;
        await reloadSuggestions();
      }
    }

    if (accountId) await load();
  });

  function fmtSourceCell(line: GeneralLedgerData['lines'][number]): string {
    const meta = line.metadata?.source;
    if (meta?.ref_label) return meta.ref_label;
    if (meta?.ref_code) return meta.ref_code;
    if (line.source_ref_id) return `${line.source_ref_type}:${line.source_ref_id}`;
    return '—';
  }
</script>

<ReportShell
  title="Buku Besar"
  breadcrumb="Laporan / Buku Besar"
  subtitle={report ? `${report.account.code} — ${report.account.name}` : null}
>
  {#snippet actions()}
    <BookToggle
      value={journalMode}
      includeBoth
      disabled={isInspector || loading}
      onChange={async (value) => {
        journalMode = value;
        const previousAccount = accountId;
        accounts = await loadAccounts(journalMode);
        accountId = accounts.some((account) => account.id === previousAccount)
          ? previousAccount
          : (accounts[0]?.id ?? '');
        if (accountId) await load();
        else report = null;
      }}
    />
  {/snippet}
  {#snippet toolbar()}
    <label class="text-sm">
      <span class="block font-medium mb-1">Akun</span>
      <div class="w-72">
        <AccountCombobox
          {accounts}
          value={accountId}
          onSelect={(id) => (accountId = id)}
          testId="report-account"
        />
      </div>
    </label>
    <label class="text-sm">
      <span class="block font-medium mb-1">Mulai</span>
      <DateInput value={periodStart} onChange={(iso) => (periodStart = iso)} />
    </label>
    <label class="text-sm">
      <span class="block font-medium mb-1">Sampai</span>
      <DateInput value={periodEnd} onChange={(iso) => (periodEnd = iso)} />
    </label>

    {#if knownPairs.length}
      <label class="text-sm">
        <span class="block font-medium mb-1">Sumber</span>
        <select
          class="rounded-md border border-border-default px-3 py-2 text-sm"
          bind:value={sourcePair}
          onchange={() => {
            clearSourceFilter();
            reloadSuggestions();
          }}
        >
          <option value="">Semua</option>
          {#each knownPairs as p (p.value)}
            <option value={p.value}>{p.label}</option>
          {/each}
        </select>
      </label>
    {/if}

    {#if sourcePair}
      <label class="text-sm relative">
        <span class="block font-medium mb-1">Ref</span>
        <input
          type="text"
          class="w-56 rounded-md border border-border-default px-3 py-2 text-sm"
          placeholder="Cari (kode/nama)…"
          value={sourceRefId ? selectedSuggestionLabel() : sourceRefQuery}
          oninput={(e) => {
            sourceRefId = '';
            sourceRefQuery = (e.currentTarget as HTMLInputElement).value;
            sourceRefMenuOpen = true;
            reloadSuggestions();
          }}
          onfocus={() => {
            sourceRefMenuOpen = true;
            reloadSuggestions();
          }}
          onblur={() => setTimeout(() => (sourceRefMenuOpen = false), 150)}
        />
        {#if sourceRefMenuOpen && sourceRefSuggestions.length}
          <ul
            class="absolute z-20 mt-1 max-h-60 w-72 overflow-y-auto rounded-md border border-border-default bg-card-bg shadow-md"
          >
            {#each sourceRefSuggestions as s (s.ref_id)}
              <li>
                <button
                  type="button"
                  class="flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-page-bg"
                  onclick={() => {
                    sourceRefId = s.ref_id;
                    sourceRefQuery = '';
                    sourceRefMenuOpen = false;
                  }}
                >
                  <span>
                    <span class="font-medium">{s.label ?? '(no label)'}</span>
                    {#if s.code}
                      <span class="ml-2 text-xs text-text-muted">{s.code}</span>
                    {/if}
                  </span>
                  <span class="text-xs text-text-muted">{s.entry_count}×</span>
                </button>
              </li>
            {/each}
          </ul>
        {/if}
        {#if sourceRefId}
          <button
            type="button"
            class="absolute right-2 top-9 text-text-muted hover:text-danger"
            onclick={clearSourceFilter}
            aria-label="Hapus filter sumber">×</button
          >
        {/if}
      </label>
    {/if}

    <button
      type="button"
      class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-active disabled:opacity-50"
      onclick={load}
      disabled={loading || !accountId}
      data-testid="report-run"
    >
      {loading ? 'Memuat…' : 'Tampilkan'}
    </button>
  {/snippet}

  {#if error}
    <div class="p-4 text-sm text-danger">{error}</div>
  {:else if !report}
    <div class="p-6 text-center text-text-muted">Pilih akun dan rentang tanggal.</div>
  {:else if journalMode === 'both' && report.fiscal}
    {@const fiscal = report.fiscal}
    <table class="w-full text-sm">
      <thead class="bg-page-bg text-xs uppercase tracking-wider text-text-muted">
        <tr>
          <th class="px-4 py-3 text-left" rowspan="2">Tanggal</th>
          <th class="px-4 py-3 text-left" rowspan="2">No. Jurnal</th>
          <th class="px-4 py-3 text-left" rowspan="2">Keterangan</th>
          <th class="px-4 py-3 text-left" rowspan="2">Sumber</th>
          <th class="px-4 py-2 text-center text-[#16a34a]" colspan="2">Debit</th>
          <th class="px-4 py-2 text-center text-[#f87171]" colspan="2">Kredit</th>
          <th class="px-4 py-2 text-center" colspan="2">Saldo</th>
        </tr>
        <tr>
          {#each ['Debit', 'Kredit', 'Saldo'] as group (group)}
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
        <tr class="bg-page-bg/60 italic">
          <td class="px-4 py-2" colspan="8">Saldo Awal ({report.period_start})</td>
          <td class="px-4 py-2 text-right font-mono tabnum">{formatRupiah(report.opening)}</td>
          <td class="px-4 py-2 text-right font-mono tabnum">{formatRupiah(fiscal.opening)}</td>
        </tr>
        {#each comparisonLines(report, fiscal) as item (item.key)}
          <tr
            class="cursor-pointer border-t border-border-soft hover:bg-page-bg"
            onclick={() => goto(`/journals/${item.line.journal_id}`)}
          >
            <td class="px-4 py-2">{formatDate(item.line.date)}</td>
            <td class="px-4 py-2 font-mono">{item.line.number}</td>
            <td class="px-4 py-2">{item.line.line_memo ?? item.line.journal_memo ?? '—'}</td>
            <td class="px-4 py-2 text-text-muted">{fmtSourceCell(item.line)}</td>
            <td class="px-4 py-2 text-right font-mono tabnum"
              >{formatRupiah(item.book === 'internal' ? item.line.debit : '0')}</td
            >
            <td class="px-4 py-2 text-right font-mono tabnum"
              >{formatRupiah(item.book === 'fiscal' ? item.line.debit : '0')}</td
            >
            <td class="px-4 py-2 text-right font-mono tabnum"
              >{formatRupiah(item.book === 'internal' ? item.line.credit : '0')}</td
            >
            <td class="px-4 py-2 text-right font-mono tabnum"
              >{formatRupiah(item.book === 'fiscal' ? item.line.credit : '0')}</td
            >
            <td class="px-4 py-2 text-right font-mono tabnum"
              >{formatRupiah(item.book === 'internal' ? item.line.balance : '0')}</td
            >
            <td class="px-4 py-2 text-right font-mono tabnum"
              >{formatRupiah(item.book === 'fiscal' ? item.line.balance : '0')}</td
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
          <td class="px-4 py-2 text-right font-mono tabnum">{formatRupiah(report.total_debit)}</td>
          <td class="px-4 py-2 text-right font-mono tabnum">{formatRupiah(fiscal.total_debit)}</td>
          <td class="px-4 py-2 text-right font-mono tabnum">{formatRupiah(report.total_credit)}</td>
          <td class="px-4 py-2 text-right font-mono tabnum">{formatRupiah(fiscal.total_credit)}</td>
          <td class="px-4 py-2 text-right font-mono tabnum">{formatRupiah(report.ending)}</td>
          <td class="px-4 py-2 text-right font-mono tabnum">{formatRupiah(fiscal.ending)}</td>
        </tr>
      </tfoot>
    </table>
  {:else}
    <table class="w-full text-sm">
      <thead class="bg-page-bg text-xs uppercase tracking-wider text-text-muted">
        <tr>
          <th class="px-4 py-3 text-left">Tanggal</th>
          <th class="px-4 py-3 text-left">No. Jurnal</th>
          <th class="px-4 py-3 text-left">Keterangan</th>
          <th class="px-4 py-3 text-left">Sumber</th>
          <th class="px-4 py-3 text-right text-[#16a34a]">Debit</th>
          <th class="px-4 py-3 text-right text-[#f87171]">Kredit</th>
          <th class="px-4 py-3 text-right">Saldo</th>
        </tr>
      </thead>
      <tbody>
        <tr class="bg-page-bg/60 italic">
          <td class="px-4 py-2" colspan="6">Saldo Awal ({report.period_start})</td>
          <td class="px-4 py-2 text-right font-mono tabnum">{formatRupiah(report.opening)}</td>
        </tr>
        {#each report.lines as l (l.line_id)}
          <tr
            class="border-t border-border-soft hover:bg-page-bg cursor-pointer"
            onclick={() => goto(`/journals/${l.journal_id}`)}
          >
            <td class="px-4 py-2">{formatDate(l.date)}</td>
            <td class="px-4 py-2 font-mono">{l.number}</td>
            <td class="px-4 py-2">{l.line_memo ?? l.journal_memo ?? '—'}</td>
            <td class="px-4 py-2 text-text-muted">{fmtSourceCell(l)}</td>
            <td class="px-4 py-2 text-right font-mono tabnum"
              >{Number(l.debit) > 0 ? formatRupiah(l.debit) : '—'}</td
            >
            <td class="px-4 py-2 text-right font-mono tabnum"
              >{Number(l.credit) > 0 ? formatRupiah(l.credit) : '—'}</td
            >
            <td class="px-4 py-2 text-right font-mono tabnum">{formatRupiah(l.balance)}</td>
          </tr>
        {:else}
          <tr
            ><td colspan="7" class="px-4 py-10 text-center text-text-muted"
              >Tidak ada transaksi pada rentang ini.</td
            ></tr
          >
        {/each}
      </tbody>
      {#if report.lines.length > 0}
        <tfoot class="bg-page-bg font-semibold">
          <tr class="border-t-2 border-border-default">
            <td class="px-4 py-2" colspan="4">Total</td>
            <td class="px-4 py-2 text-right font-mono tabnum">{formatRupiah(report.total_debit)}</td
            >
            <td class="px-4 py-2 text-right font-mono tabnum"
              >{formatRupiah(report.total_credit)}</td
            >
            <td class="px-4 py-2 text-right font-mono tabnum text-base"
              >{formatRupiah(report.ending)}</td
            >
          </tr>
        </tfoot>
      {/if}
    </table>
  {/if}
</ReportShell>
