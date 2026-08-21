<script lang="ts">
  import { onMount } from 'svelte';
  import { goto } from '$app/navigation';
  import { auth } from '$lib/stores/auth.svelte.js';
  import { reportingApi, type TrialBalanceData } from '$lib/api/reporting.js';
  import { ApiError } from '$lib/api/client.js';
  import { formatRupiah } from '@akunta/ui';
  import ReportShell from '$lib/components/reporting/ReportShell.svelte';
  import DateInput from '$lib/components/ui/DateInput.svelte';
  import { formatDate } from '$lib/utils/date.js';
  import BookToggle, { type BookToggleValue } from '$lib/components/reporting/BookToggle.svelte';

  let asOf = $state(new Date().toISOString().slice(0, 10));
  let report = $state<TrialBalanceData | null>(null);
  let loading = $state(false);
  let error = $state<string | null>(null);
  let journalMode = $state<BookToggleValue>('internal');
  let isInspector = $derived(
    auth.user?.roles.some((role) => role.toLowerCase() === 'inspector') ?? false,
  );

  async function load() {
    loading = true;
    error = null;
    try {
      const res = await reportingApi.trialBalance(asOf, journalMode);
      report = res.data;
    } catch (e) {
      error = e instanceof ApiError ? `Server ${e.status}` : (e as Error).message;
    } finally {
      loading = false;
    }
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
    await load();
  });
</script>

<ReportShell
  title="Neraca Saldo"
  breadcrumb="Laporan / Neraca Saldo"
  subtitle={report ? `Per ${formatDate(report.as_of)}` : null}
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
      <span class="block font-medium mb-1">Per Tanggal</span>
      <DateInput value={asOf} onChange={(iso) => (asOf = iso)} testId="report-as-of" />
    </label>
    <button
      type="button"
      class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-active disabled:opacity-50"
      onclick={load}
      disabled={loading}
      data-testid="report-run"
    >
      {loading ? 'Memuat…' : 'Tampilkan'}
    </button>
  {/snippet}

  {#if error}
    <div class="p-4 text-sm text-danger">{error}</div>
  {:else if !report}
    <div class="p-6 text-center text-text-muted">Memuat…</div>
  {:else if journalMode === 'both' && report.comparison}
    {@const internalRows = new Map(report.comparison.internal.rows.map((row) => [row.id, row]))}
    {@const fiscalRows = new Map(report.comparison.fiscal.rows.map((row) => [row.id, row]))}
    {@const rowIds = [...new Set([...internalRows.keys(), ...fiscalRows.keys()])]}
    <table class="w-full text-sm">
      <thead class="bg-page-bg text-xs uppercase tracking-wider text-text-muted">
        <tr>
          <th class="px-4 py-3 text-left" rowspan="2">Kode</th>
          <th class="px-4 py-3 text-left" rowspan="2">Nama Akun</th>
          <th class="w-[16%] px-4 py-2 text-center align-middle text-[#16a34a]" colspan="2">Debit</th>
          <th class="w-[16%] px-4 py-2 text-center align-middle text-[#f87171]" colspan="2">Kredit</th>
          <th class="w-[16%] px-4 py-2 text-center align-middle" colspan="2">Saldo</th>
        </tr>
        <tr>
          <th class="bg-gradient-to-r from-[#f0fdf4] to-[#dcfce7] px-4 py-2 text-center text-[#166534]">Intern</th>
          <th class="bg-gradient-to-r from-[#fffbeb] to-[#fef9c3] px-4 py-2 text-center text-[#854d0e]">Fiskal</th>
          <th class="bg-gradient-to-r from-[#f0fdf4] to-[#dcfce7] px-4 py-2 text-center text-[#166534]">Intern</th>
          <th class="bg-gradient-to-r from-[#fffbeb] to-[#fef9c3] px-4 py-2 text-center text-[#854d0e]">Fiskal</th>
          <th class="bg-gradient-to-r from-[#f0fdf4] to-[#dcfce7] px-4 py-2 text-center text-[#166534]">Intern</th>
          <th class="bg-gradient-to-r from-[#fffbeb] to-[#fef9c3] px-4 py-2 text-center text-[#854d0e]">Fiskal</th>
        </tr>
      </thead>
      <tbody>
        {#each rowIds as id (id)}
          {@const internal = internalRows.get(id)}
          {@const fiscal = fiscalRows.get(id)}
          {@const row = internal ?? fiscal}
          <tr class="border-t border-border-soft hover:bg-page-bg cursor-pointer" onclick={() => goto(`/laporan/buku-besar?account_id=${id}`)}>
            <td class="px-4 py-2 font-mono">{row?.code}</td>
            <td class="px-4 py-2">{row?.name}</td>
            <td class="px-4 py-2 text-right font-mono tabnum">{formatRupiah(internal?.total_debit ?? '0')}</td>
            <td class="px-4 py-2 text-right font-mono tabnum">{formatRupiah(fiscal?.total_debit ?? '0')}</td>
            <td class="px-4 py-2 text-right font-mono tabnum">{formatRupiah(internal?.total_credit ?? '0')}</td>
            <td class="px-4 py-2 text-right font-mono tabnum">{formatRupiah(fiscal?.total_credit ?? '0')}</td>
            <td class="px-4 py-2 text-right font-mono tabnum">{formatRupiah(internal?.balance ?? '0')}</td>
            <td class="px-4 py-2 text-right font-mono tabnum">{formatRupiah(fiscal?.balance ?? '0')}</td>
          </tr>
        {/each}
      </tbody>
    </table>
  {:else}
    <table class="w-full text-sm">
      <thead class="bg-page-bg text-xs uppercase tracking-wider text-text-muted">
        <tr>
          <th class="px-4 py-3 text-left">Kode</th>
          <th class="px-4 py-3 text-left">Nama Akun</th>
          <th class="px-4 py-3 text-right text-[#16a34a]">Debit</th>
          <th class="px-4 py-3 text-right text-[#f87171]">Kredit</th>
          <th class="px-4 py-3 text-right">Saldo</th>
        </tr>
      </thead>
      <tbody>
        {#each report.rows as r (r.id)}
          <tr
            class="border-t border-border-soft hover:bg-page-bg cursor-pointer"
            onclick={() => goto(`/laporan/buku-besar?account_id=${r.id}`)}
          >
            <td class="px-4 py-2 font-mono">{r.code}</td>
            <td class="px-4 py-2">{r.name}</td>
            <td class="px-4 py-2 text-right font-mono tabnum">{formatRupiah(r.total_debit)}</td>
            <td class="px-4 py-2 text-right font-mono tabnum">{formatRupiah(r.total_credit)}</td>
            <td class="px-4 py-2 text-right font-mono tabnum font-semibold"
              >{formatRupiah(r.balance)}</td
            >
          </tr>
        {:else}
          <tr
            ><td colspan="5" class="px-4 py-10 text-center text-text-muted"
              >Tidak ada saldo pada tanggal ini.</td
            ></tr
          >
        {/each}
      </tbody>
      {#if report.rows.length > 0}
        <tfoot class="bg-page-bg font-semibold">
          <tr class="border-t-2 border-border-default">
            <td class="px-4 py-3" colspan="2">Total</td>
            <td class="px-4 py-3 text-right font-mono tabnum">{formatRupiah(report.total_debit)}</td
            >
            <td class="px-4 py-3 text-right font-mono tabnum"
              >{formatRupiah(report.total_credit)}</td
            >
            <td class="px-4 py-3 text-right font-mono tabnum text-paid">
              {report.total_debit === report.total_credit ? '✓ Balance' : '⚠ Tidak balance'}
            </td>
          </tr>
        </tfoot>
      {/if}
    </table>
  {/if}
</ReportShell>
