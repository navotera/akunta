<script lang="ts">
  import { onMount } from 'svelte';
  import { goto } from '$app/navigation';
  import { auth } from '$lib/stores/auth.svelte.js';
  import { reportingApi, type IncomeStatementData, type IncomeStatementSection } from '$lib/api/reporting.js';
  import { ApiError } from '$lib/api/client.js';
  import { formatRupiah } from '@akunta/ui';
  import ReportShell from '$lib/components/reporting/ReportShell.svelte';
  import DateInput from '$lib/components/ui/DateInput.svelte';
  import { sourceFromName } from '$lib/components/reporting/sourceFromName.js';

  function firstOfMonth(): string {
    const d = new Date();
    return new Date(d.getFullYear(), d.getMonth(), 1).toISOString().slice(0, 10);
  }

  let periodStart = $state(firstOfMonth());
  let periodEnd = $state(new Date().toISOString().slice(0, 10));
  let report = $state<IncomeStatementData | null>(null);
  let loading = $state(false);
  let error = $state<string | null>(null);

  async function load() {
    loading = true;
    error = null;
    try {
      const res = await reportingApi.incomeStatement(periodStart, periodEnd);
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

  function renderSection(label: string, section: IncomeStatementSection) {
    return { label, lines: section.lines, total: section.total };
  }
</script>

<ReportShell
  title="Laba Rugi"
  breadcrumb="Laporan / Laba Rugi"
  subtitle={report ? `${report.period_start} s/d ${report.period_end}` : null}
>
  {#snippet toolbar()}
    <label class="text-sm">
      <span class="block font-medium mb-1">Mulai</span>
      <DateInput value={periodStart} onChange={(iso) => (periodStart = iso)} testId="report-period-start" />
    </label>
    <label class="text-sm">
      <span class="block font-medium mb-1">Sampai</span>
      <DateInput value={periodEnd} onChange={(iso) => (periodEnd = iso)} testId="report-period-end" />
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
    {@const sections = [
      renderSection('Pendapatan', report.revenue),
      renderSection('Harga Pokok Penjualan', report.cogs),
      renderSection('Beban Operasional', report.expenses),
    ]}
    {@const revenueTotal = Number(report.revenue.total)}
    {@const grossMargin = revenueTotal > 0
      ? (Number(report.gross_profit) / revenueTotal) * 100
      : 0}
    {@const netMargin = revenueTotal > 0
      ? (Number(report.net_income) / revenueTotal) * 100
      : 0}

    <div class="ak-pl-kpis">
      <div class="ak-pl-kpi">
        <p class="ak-pl-kpi__label">Pendapatan</p>
        <p class="ak-pl-kpi__value">{formatRupiah(report.revenue.total)}</p>
      </div>
      <div class="ak-pl-kpi">
        <p class="ak-pl-kpi__label">Beban Pokok</p>
        <p class="ak-pl-kpi__value">{formatRupiah(report.cogs.total)}</p>
      </div>
      <div class="ak-pl-kpi">
        <p class="ak-pl-kpi__label">Laba Kotor</p>
        <p class="ak-pl-kpi__value">{formatRupiah(report.gross_profit)}</p>
        <p class="ak-pl-kpi__sub">margin {grossMargin.toFixed(1)}%</p>
      </div>
      <div class="ak-pl-kpi">
        <p class="ak-pl-kpi__label">Laba Bersih</p>
        <p class="ak-pl-kpi__value {Number(report.net_income) >= 0 ? 'text-paid' : 'text-danger'}" data-testid="net-income">
          {formatRupiah(report.net_income)}
        </p>
        <p class="ak-pl-kpi__sub">margin {netMargin.toFixed(1)}%</p>
      </div>
    </div>

    <table class="w-full text-sm">
      <thead class="bg-page-bg text-xs uppercase tracking-wider text-text-muted">
        <tr>
          <th class="px-4 py-2 text-left w-24">Kode</th>
          <th class="px-4 py-2 text-left">Akun</th>
          <th class="px-4 py-2 text-right w-40">Nilai</th>
          <th class="px-4 py-2 text-right w-32">Sumber</th>
        </tr>
      </thead>
      <tbody>
        {#each sections as sec}
          <tr class="bg-page-bg">
            <td colspan="4" class="px-4 py-2 text-xs font-bold uppercase tracking-wider text-text-muted">{sec.label}</td>
          </tr>
          {#each sec.lines as r (r.id)}
            <tr class="border-t border-border-soft">
              <td class="px-4 py-2 font-mono">{r.code}</td>
              <td class="px-4 py-2">{r.name}</td>
              <td class="px-4 py-2 text-right font-mono tabnum">{formatRupiah(r.balance)}</td>
              <td class="px-4 py-2 text-right">
                <span class="ak-source-pill">{sourceFromName(r.name)}</span>
              </td>
            </tr>
          {/each}
          <tr class="border-t border-border-default">
            <td class="px-4 py-2"></td>
            <td class="px-4 py-2 font-semibold">Total {sec.label}</td>
            <td class="px-4 py-2 text-right font-mono tabnum font-semibold">{formatRupiah(sec.total)}</td>
            <td class="px-4 py-2"></td>
          </tr>
        {/each}
        <tr class="border-t-2 border-border-default bg-page-bg">
          <td class="px-4 py-2"></td>
          <td class="px-4 py-2 font-semibold">Laba Kotor</td>
          <td class="px-4 py-2 text-right font-mono tabnum font-semibold">{formatRupiah(report.gross_profit)}</td>
          <td class="px-4 py-2"></td>
        </tr>
        <tr class="border-t-2 border-border-default bg-page-bg">
          <td class="px-4 py-2"></td>
          <td class="px-4 py-2 text-base font-bold">Laba Bersih (YTD)</td>
          <td class="px-4 py-2 text-right font-mono tabnum text-base font-bold {Number(report.net_income) >= 0 ? 'text-paid' : 'text-danger'}">
            {formatRupiah(report.net_income)}
          </td>
          <td class="px-4 py-2"></td>
        </tr>
      </tbody>
    </table>
  {/if}
</ReportShell>
