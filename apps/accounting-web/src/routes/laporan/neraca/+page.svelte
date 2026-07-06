<script lang="ts">
  import { onMount } from 'svelte';
  import { goto } from '$app/navigation';
  import { auth } from '$lib/stores/auth.svelte.js';
  import { reportingApi, type BalanceSheetData, type BalanceSheetSection } from '$lib/api/reporting.js';
  import { ApiError } from '$lib/api/client.js';
  import { formatRupiah } from '@akunta/ui';
  import ReportShell from '$lib/components/reporting/ReportShell.svelte';
  import DateInput from '$lib/components/ui/DateInput.svelte';
  import { sourceFromName } from '$lib/components/reporting/sourceFromName.js';

  let asOf = $state(new Date().toISOString().slice(0, 10));
  let report = $state<BalanceSheetData | null>(null);
  let loading = $state(false);
  let error = $state<string | null>(null);

  async function load() {
    loading = true;
    error = null;
    try {
      const res = await reportingApi.balanceSheet(asOf);
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
    await load();
  });

  function sectionRows(sec: BalanceSheetSection) {
    return sec.lines;
  }
</script>

<ReportShell
  title="Neraca"
  breadcrumb="Laporan / Neraca"
  subtitle={report ? `Per ${report.as_of}` : null}
>
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
  {:else}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 p-4">
      <section class="rounded-md border border-border-soft">
        <header class="bg-page-bg px-3 py-2 text-xs font-bold uppercase tracking-wider text-text-muted">Aset</header>
        <table class="w-full text-sm">
          <tbody>
            {#each sectionRows(report.assets) as r (r.id)}
              <tr class="border-t border-border-soft">
                <td class="px-3 py-2 font-mono w-20">{r.code}</td>
                <td class="px-3 py-2">
                  <span>{r.name}</span>
                  <span class="ak-source-pill ml-2">{sourceFromName(r.name)}</span>
                </td>
                <td class="px-3 py-2 text-right font-mono tabnum">{formatRupiah(r.balance)}</td>
              </tr>
            {/each}
            <tr class="border-t-2 border-border-default bg-page-bg">
              <td class="px-3 py-2 font-semibold" colspan="2">Total Aset</td>
              <td class="px-3 py-2 text-right font-mono tabnum font-bold">{formatRupiah(report.assets.total)}</td>
            </tr>
          </tbody>
        </table>
      </section>

      <section class="rounded-md border border-border-soft">
        <header class="bg-page-bg px-3 py-2 text-xs font-bold uppercase tracking-wider text-text-muted">Liabilitas + Ekuitas</header>
        <table class="w-full text-sm">
          <tbody>
            <tr class="bg-page-bg/60"><td colspan="3" class="px-3 py-1.5 text-xs font-semibold text-text-muted">Liabilitas</td></tr>
            {#each sectionRows(report.liabilities) as r (r.id)}
              <tr class="border-t border-border-soft">
                <td class="px-3 py-2 font-mono w-20">{r.code}</td>
                <td class="px-3 py-2">
                  <span>{r.name}</span>
                  <span class="ak-source-pill ml-2">{sourceFromName(r.name)}</span>
                </td>
                <td class="px-3 py-2 text-right font-mono tabnum">{formatRupiah(r.balance)}</td>
              </tr>
            {/each}
            <tr class="border-t border-border-default">
              <td class="px-3 py-1.5 font-semibold" colspan="2">Total Liabilitas</td>
              <td class="px-3 py-1.5 text-right font-mono tabnum font-semibold">{formatRupiah(report.liabilities.total)}</td>
            </tr>

            <tr class="bg-page-bg/60"><td colspan="3" class="px-3 py-1.5 text-xs font-semibold text-text-muted">Ekuitas</td></tr>
            {#each sectionRows(report.equity) as r (r.id)}
              <tr class="border-t border-border-soft">
                <td class="px-3 py-2 font-mono w-20">{r.code}</td>
                <td class="px-3 py-2">
                  <span>{r.name}</span>
                  <span class="ak-source-pill ml-2">{sourceFromName(r.name)}</span>
                </td>
                <td class="px-3 py-2 text-right font-mono tabnum">{formatRupiah(r.balance)}</td>
              </tr>
            {/each}
            {#if report.equity.net_income_ytd}
              <tr class="border-t border-border-soft italic text-text-muted">
                <td class="px-3 py-2 font-mono w-20">—</td>
                <td class="px-3 py-2">Laba Bersih YTD (auto)</td>
                <td class="px-3 py-2 text-right font-mono tabnum">{formatRupiah(report.equity.net_income_ytd)}</td>
              </tr>
            {/if}
            <tr class="border-t border-border-default">
              <td class="px-3 py-1.5 font-semibold" colspan="2">Total Ekuitas</td>
              <td class="px-3 py-1.5 text-right font-mono tabnum font-semibold">{formatRupiah(report.equity.total)}</td>
            </tr>

            <tr class="border-t-2 border-border-default bg-page-bg">
              <td class="px-3 py-2 font-bold" colspan="2">Total Liabilitas + Ekuitas</td>
              <td class="px-3 py-2 text-right font-mono tabnum font-bold">
                {formatRupiah((Number(report.liabilities.total) + Number(report.equity.total)).toFixed(2))}
              </td>
            </tr>
          </tbody>
        </table>
      </section>

      <div class="md:col-span-2 text-right text-xs">
        {#if report.balanced}
          <span class="text-paid font-semibold" data-testid="bs-balanced">✓ Aset = Liabilitas + Ekuitas</span>
        {:else}
          <span class="text-danger font-semibold" data-testid="bs-unbalanced">⚠ Tidak balance — periksa jurnal</span>
        {/if}
      </div>
    </div>
  {/if}
</ReportShell>
