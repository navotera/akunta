<script lang="ts">
  import { onMount } from 'svelte';
  import { goto } from '$app/navigation';
  import { auth } from '$lib/stores/auth.svelte.js';
  import {
    reportingApi,
    type IncomeStatementData,
    type IncomeStatementSection,
  } from '$lib/api/reporting.js';
  import { ApiError } from '$lib/api/client.js';
  import { formatRupiah } from '@akunta/ui';
  import ReportShell from '$lib/components/reporting/ReportShell.svelte';
  import DateInput from '$lib/components/ui/DateInput.svelte';
  import { sourceFromName } from '$lib/components/reporting/sourceFromName.js';
  import { formatDate } from '$lib/utils/date.js';
  import BookToggle, { type BookToggleValue } from '$lib/components/reporting/BookToggle.svelte';

  function firstOfMonth(): string {
    const d = new Date();
    return new Date(d.getFullYear(), d.getMonth(), 1).toISOString().slice(0, 10);
  }

  let periodStart = $state(firstOfMonth());
  let periodEnd = $state(new Date().toISOString().slice(0, 10));
  let report = $state<IncomeStatementData | null>(null);
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
      const res = await reportingApi.incomeStatement(periodStart, periodEnd, journalMode);
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

  function renderSection(label: string, section: IncomeStatementSection) {
    return { label, lines: section.lines, total: section.total };
  }

  function mergedLines(internal: IncomeStatementSection, fiscal: IncomeStatementSection) {
    const rows = new Map<
      string,
      { id: string; code: string; name: string; internal: string; fiscal: string }
    >();
    for (const row of internal.lines) {
      rows.set(row.id, {
        id: row.id,
        code: row.code,
        name: row.name,
        internal: row.balance,
        fiscal: '0.00',
      });
    }
    for (const row of fiscal.lines) {
      const current = rows.get(row.id);
      if (current) current.fiscal = row.balance;
      else
        rows.set(row.id, {
          id: row.id,
          code: row.code,
          name: row.name,
          internal: '0.00',
          fiscal: row.balance,
        });
    }
    return [...rows.values()].sort((a, b) => a.code.localeCompare(b.code));
  }
</script>

<ReportShell
  title="Laba Rugi"
  breadcrumb="Laporan / Laba Rugi"
  subtitle={report
    ? `${formatDate(report.period_start)} s/d ${formatDate(report.period_end)}`
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
      <span class="block font-medium mb-1">Mulai</span>
      <DateInput
        value={periodStart}
        onChange={(iso) => (periodStart = iso)}
        testId="report-period-start"
      />
    </label>
    <label class="text-sm">
      <span class="block font-medium mb-1">Sampai</span>
      <DateInput
        value={periodEnd}
        onChange={(iso) => (periodEnd = iso)}
        testId="report-period-end"
      />
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
  {:else if journalMode === 'both' && report.fiscal}
    {@const fiscal = report.fiscal}
    {@const comparisonSections = [
      { label: 'Pendapatan', internal: report.revenue, fiscal: fiscal.revenue },
      { label: 'Harga Pokok Penjualan', internal: report.cogs, fiscal: fiscal.cogs },
      { label: 'Beban Operasional', internal: report.expenses, fiscal: fiscal.expenses },
    ]}

    <div class="ak-pl-kpis">
      {#each [{ label: 'Pendapatan', internal: report.revenue.total, fiscal: fiscal.revenue.total }, { label: 'Beban Pokok', internal: report.cogs.total, fiscal: fiscal.cogs.total }, { label: 'Laba Kotor', internal: report.gross_profit, fiscal: fiscal.gross_profit }, { label: 'Laba Bersih', internal: report.net_income, fiscal: fiscal.net_income }] as item (item.label)}
        <div class="ak-pl-kpi">
          <p class="ak-pl-kpi__label">{item.label}</p>
          <div class="mt-3 grid grid-cols-2 gap-3">
            <div>
              <p class="text-xs font-semibold text-[#166534]">Intern</p>
              <p class="mt-1 font-mono font-bold tabnum">{formatRupiah(item.internal)}</p>
            </div>
            <div>
              <p class="text-xs font-semibold text-[#854d0e]">Fiskal</p>
              <p class="mt-1 font-mono font-bold tabnum">{formatRupiah(item.fiscal)}</p>
            </div>
          </div>
        </div>
      {/each}
    </div>

    <table class="w-full text-sm">
      <thead class="bg-page-bg text-xs uppercase tracking-wider text-text-muted">
        <tr>
          <th class="w-24 px-4 py-2 text-left" rowspan="2">Kode</th>
          <th class="px-4 py-2 text-left" rowspan="2">Akun</th>
          <th class="px-4 py-2 text-center" colspan="2">Nilai</th>
          <th class="w-32 px-4 py-2 text-right" rowspan="2">Sumber</th>
        </tr>
        <tr>
          <th
            class="bg-gradient-to-r from-[#f0fdf4] to-[#dcfce7] px-4 py-2 text-center text-[#166534]"
            >Intern</th
          >
          <th
            class="bg-gradient-to-r from-[#fffbeb] to-[#fef9c3] px-4 py-2 text-center text-[#854d0e]"
            >Fiskal</th
          >
        </tr>
      </thead>
      <tbody>
        {#each comparisonSections as section (section.label)}
          <tr class="bg-page-bg">
            <td
              colspan="5"
              class="px-4 py-2 text-xs font-bold uppercase tracking-wider text-text-muted"
              >{section.label}</td
            >
          </tr>
          {#each mergedLines(section.internal, section.fiscal) as row (row.id)}
            <tr class="border-t border-border-soft">
              <td class="px-4 py-2 font-mono">{row.code}</td>
              <td class="px-4 py-2">{row.name}</td>
              <td class="px-4 py-2 text-right font-mono tabnum">{formatRupiah(row.internal)}</td>
              <td class="px-4 py-2 text-right font-mono tabnum">{formatRupiah(row.fiscal)}</td>
              <td class="px-4 py-2 text-right"
                ><span class="ak-source-pill">{sourceFromName(row.name)}</span></td
              >
            </tr>
          {/each}
          <tr class="border-t border-border-default font-semibold">
            <td></td>
            <td class="px-4 py-2">Total {section.label}</td>
            <td class="px-4 py-2 text-right font-mono tabnum"
              >{formatRupiah(section.internal.total)}</td
            >
            <td class="px-4 py-2 text-right font-mono tabnum"
              >{formatRupiah(section.fiscal.total)}</td
            >
            <td></td>
          </tr>
        {/each}
        <tr class="border-t-2 border-border-default bg-page-bg font-semibold">
          <td></td>
          <td class="px-4 py-2">Laba Kotor</td>
          <td class="px-4 py-2 text-right font-mono tabnum">{formatRupiah(report.gross_profit)}</td>
          <td class="px-4 py-2 text-right font-mono tabnum">{formatRupiah(fiscal.gross_profit)}</td>
          <td></td>
        </tr>
        <tr class="border-t-2 border-border-default bg-page-bg text-base font-bold">
          <td></td>
          <td class="px-4 py-2">Laba Bersih (YTD)</td>
          <td class="px-4 py-2 text-right font-mono tabnum">{formatRupiah(report.net_income)}</td>
          <td class="px-4 py-2 text-right font-mono tabnum">{formatRupiah(fiscal.net_income)}</td>
          <td></td>
        </tr>
      </tbody>
    </table>
  {:else}
    {@const sections = [
      renderSection('Pendapatan', report.revenue),
      renderSection('Harga Pokok Penjualan', report.cogs),
      renderSection('Beban Operasional', report.expenses),
    ]}
    {@const revenueTotal = Number(report.revenue.total)}
    {@const grossMargin = revenueTotal > 0 ? (Number(report.gross_profit) / revenueTotal) * 100 : 0}
    {@const netMargin = revenueTotal > 0 ? (Number(report.net_income) / revenueTotal) * 100 : 0}

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
        <p
          class="ak-pl-kpi__value {Number(report.net_income) >= 0 ? 'text-paid' : 'text-danger'}"
          data-testid="net-income"
        >
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
            <td
              colspan="4"
              class="px-4 py-2 text-xs font-bold uppercase tracking-wider text-text-muted"
              >{sec.label}</td
            >
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
            <td class="px-4 py-2 text-right font-mono tabnum font-semibold"
              >{formatRupiah(sec.total)}</td
            >
            <td class="px-4 py-2"></td>
          </tr>
        {/each}
        <tr class="border-t-2 border-border-default bg-page-bg">
          <td class="px-4 py-2"></td>
          <td class="px-4 py-2 font-semibold">Laba Kotor</td>
          <td class="px-4 py-2 text-right font-mono tabnum font-semibold"
            >{formatRupiah(report.gross_profit)}</td
          >
          <td class="px-4 py-2"></td>
        </tr>
        <tr class="border-t-2 border-border-default bg-page-bg">
          <td class="px-4 py-2"></td>
          <td class="px-4 py-2 text-base font-bold">Laba Bersih (YTD)</td>
          <td
            class="px-4 py-2 text-right font-mono tabnum text-base font-bold {Number(
              report.net_income,
            ) >= 0
              ? 'text-paid'
              : 'text-danger'}"
          >
            {formatRupiah(report.net_income)}
          </td>
          <td class="px-4 py-2"></td>
        </tr>
      </tbody>
    </table>
  {/if}
</ReportShell>
