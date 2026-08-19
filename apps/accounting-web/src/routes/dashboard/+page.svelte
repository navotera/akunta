<script lang="ts">
  import { onMount } from 'svelte';
  import { goto } from '$app/navigation';
  import { auth } from '$lib/stores/auth.svelte.js';
  import { tenant } from '$lib/stores/tenant.svelte.js';
  import { widgetsApi, type FinancialPulse, type RecentJournal } from '$lib/api/widgets.js';
  import { reportingApi, type FiscalReconciliationData } from '$lib/api/reporting.js';
  import { onboardingApi, type OnboardingStatus } from '$lib/api/onboarding.js';
  import { formatRupiah } from '@akunta/ui';
  import { formatDate } from '$lib/utils/date.js';
  import Spark from '$lib/components/dashboard/Spark.svelte';
  import Donut from '$lib/components/dashboard/Donut.svelte';
  import CashflowChart from '$lib/components/dashboard/CashflowChart.svelte';

  let pulse = $state<FinancialPulse | null>(null);
  let recent = $state<RecentJournal[]>([]);
  let status = $state<OnboardingStatus | null>(null);
  let loading = $state(true);
  let error = $state<string | null>(null);
  let cashflowTab = $state(1); // 0:7H 1:30H 2:90H 3:YTD
  let dashboardMode = $state<'internal' | 'fiscal'>('internal');
  let fiscalReport = $state<FiscalReconciliationData | null>(null);
  let fiscalLoading = $state(false);
  let fiscalError = $state<string | null>(null);
  let taxRate = $state(22);
  let hasFiscalBook = $derived(
    tenant.available.find((item) => item.id === tenant.id)?.bookkeeping_mode ===
      'independent_books',
  );
  let estimatedTaxableIncome = $derived(Math.max(0, Number(fiscalReport?.final_net_income ?? 0)));
  let estimatedIncomeTax = $derived((estimatedTaxableIncome * taxRate) / 100);

  onMount(async () => {
    if (!auth.user) {
      const u = await auth.refresh();
      if (!u) {
        // Only an actual 401 means the SSO session is missing. Keep backend
        // failures on the dashboard so they do not create an auth redirect loop.
        if (!auth.error) {
          goto('/login', { replaceState: true });
        } else {
          error = auth.error;
          loading = false;
        }
        return;
      }
    }
    if (auth.user?.roles.some((role) => role.toLowerCase() === 'inspector')) {
      await goto('/journals', { replaceState: true });
      return;
    }
    try {
      status = await onboardingApi.status();
      if (!status.completed) {
        goto('/onboarding', { replaceState: true });
        return;
      }
      [pulse, recent] = await Promise.all([
        widgetsApi.financialPulse(),
        widgetsApi.recentJournals(8),
      ]);
    } catch (e) {
      error = e instanceof Error ? e.message : String(e);
    } finally {
      loading = false;
    }
  });

  async function switchDashboardMode(mode: 'internal' | 'fiscal') {
    dashboardMode = mode;
    if (mode !== 'fiscal' || fiscalReport || fiscalLoading) return;

    fiscalLoading = true;
    fiscalError = null;
    try {
      const year = new Date().getFullYear();
      const response = await reportingApi.fiscalReconciliation(`${year}-01-01`, `${year}-12-31`);
      fiscalReport = response.data;
      const storedRate = Number(localStorage.getItem(`akunta.tax-rate.${tenant.id}`));
      if (Number.isFinite(storedRate) && storedRate >= 0 && storedRate <= 100) taxRate = storedRate;
    } catch (e) {
      fiscalError = e instanceof Error ? e.message : String(e);
    } finally {
      fiscalLoading = false;
    }
  }

  function updateTaxRate(event: Event) {
    const next = Number((event.currentTarget as HTMLInputElement).value);
    taxRate = Number.isFinite(next) ? Math.min(100, Math.max(0, next)) : 0;
    localStorage.setItem(`akunta.tax-rate.${tenant.id}`, String(taxRate));
  }

  function compact(n: number | string): string {
    const v = typeof n === 'string' ? Number(n) : n;
    const a = Math.abs(v);
    if (a >= 1e9) return (v / 1e9).toFixed(1) + ' M';
    if (a >= 1e6) return (v / 1e6).toFixed(1) + ' jt';
    if (a >= 1e3) return (v / 1e3).toFixed(0) + ' rb';
    return Math.round(v).toLocaleString('id-ID');
  }

  function pctDelta(curr: string, prev: string): { value: string; positive: boolean } | null {
    const c = Number(curr);
    const p = Number(prev);
    if (p === 0) return null;
    const diff = ((c - p) / p) * 100;
    return { value: `${diff >= 0 ? '+' : ''}${diff.toFixed(1)}%`, positive: diff >= 0 };
  }

  // Static demo series for cashflow + donut + tables (no backend yet).
  const cashflowIncome = [120, 140, 165, 150, 178, 195, 210, 198, 220, 245, 260, 285].map(
    (v) => v * 1e6,
  );
  const cashflowExpense = [80, 92, 100, 95, 110, 105, 115, 108, 120, 118, 125, 130].map(
    (v) => v * 1e6,
  );
  const cashflowLabels = Array.from({ length: 12 }, (_, i) => `W${i + 1}`);
  const cashflowNet =
    cashflowIncome.reduce((a, b) => a + b, 0) - cashflowExpense.reduce((a, b) => a + b, 0);

  const donutSlices = [
    {
      value: 58,
      color: '#1B84FF',
      label: 'Penjualan POS',
      amount: 108180000,
      src: 'App Penjualan',
    },
    { value: 22, color: '#7239EA', label: 'Invoice B2B', amount: 41010000, src: 'App Invoice' },
    { value: 12, color: '#7AA2FF', label: 'Layanan / Jasa', amount: 22370000, src: 'Manual' },
    { value: 8, color: '#C4CADA', label: 'Lain-lain', amount: 14860000, src: 'Manual' },
  ];

  const aging = [
    {
      label: 'Piutang Usaha',
      sub: '42 invoice',
      cells: [68420000, 24180000, 8420000, 3120000],
      accent: '#1B84FF',
    },
    {
      label: 'Utang Usaha',
      sub: '18 tagihan',
      cells: [42100000, 18650000, 0, 0],
      accent: '#F6C000',
    },
    { label: 'Pajak Terutang', sub: 'PPN Mei', cells: [12480000, 0, 0, 0], accent: '#0284C7' },
  ];

  const approvals = [
    {
      icon: '🧾',
      title: 'Invoice INV-2056',
      sub: 'Toko Sumber Rejeki · Rp 18.420.000',
      tag: 'App Invoice',
      warn: false,
    },
    {
      icon: '🛒',
      title: 'PO #PO-1183',
      sub: 'CV Anugerah Sentosa · Rp 7.250.000',
      tag: 'App Pembelian',
      warn: false,
    },
    {
      icon: '👥',
      title: 'Penyesuaian Gaji Mei',
      sub: '3 karyawan · Rp 2.850.000',
      tag: 'App Payroll',
      warn: true,
    },
    {
      icon: '🧾',
      title: 'Refund INV-2031',
      sub: 'Bunga Mawar Cake · Rp 480.000',
      tag: 'App Invoice',
      warn: false,
    },
    {
      icon: '📒',
      title: 'Jurnal Penyesuaian',
      sub: 'Reklas biaya sewa Q2',
      tag: 'Manual',
      warn: false,
    },
  ];

  const quickActions = [
    { icon: '🧾', label: 'Buat Invoice', href: '#' },
    { icon: '📒', label: 'Jurnal Manual', href: '/journals/new' },
    { icon: '🏦', label: 'Rekonsiliasi', href: '#' },
    { icon: '📑', label: 'Lapor PPN', href: '#' },
    { icon: '👥', label: 'Run Payroll', href: '#' },
    { icon: '📊', label: 'Laporan P&L', href: '/laporan/laba-rugi' },
    { icon: '🛒', label: 'Tagihan Vendor', href: '#' },
    { icon: '⤓', label: 'Export', href: '#' },
  ];

  const ecoSync = [
    {
      icon: '📈',
      name: 'App Penjualan',
      last: '2 menit lalu',
      status: 'ok' as const,
      count: '142 transaksi',
    },
    {
      icon: '🛒',
      name: 'App Pembelian',
      last: '6 menit lalu',
      status: 'ok' as const,
      count: '38 dokumen',
    },
    {
      icon: '📦',
      name: 'App Inventory',
      last: '14 menit lalu',
      status: 'ok' as const,
      count: '11 mutasi',
    },
    {
      icon: '🧾',
      name: 'App Invoice',
      last: '3 menit lalu',
      status: 'ok' as const,
      count: '27 invoice',
    },
    {
      icon: '👥',
      name: 'App Payroll',
      last: 'kemarin 17:02',
      status: 'warn' as const,
      count: '1 selisih',
    },
    {
      icon: '📑',
      name: 'App e-Faktur',
      last: 'sedang sinkron',
      status: 'syncing' as const,
      count: '—',
    },
  ];

  function statusPill(s: 'ok' | 'warn' | 'syncing' | 'err') {
    if (s === 'ok') return { bg: '#DFFFEA', fg: '#17C653', dot: '#17C653', label: 'OK' };
    if (s === 'warn') return { bg: '#FFF8DD', fg: '#F6C000', dot: '#F6C000', label: 'Periksa' };
    if (s === 'syncing') return { bg: '#E6F4FB', fg: '#0284C7', dot: '#0284C7', label: 'Syncing' };
    return { bg: '#FFEEF3', fg: '#F8285A', dot: '#F8285A', label: 'Error' };
  }
</script>

<div class="px-6 py-5">
  {#if loading}
    <div class="text-text-muted">Memuat dashboard…</div>
  {:else if error}
    <div class="rounded-md border border-danger bg-danger-light p-3 text-sm text-danger">
      {error}
    </div>
  {:else if pulse}
    <header class="mb-5 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold">Dashboard</h1>
        <p class="text-sm text-text-muted">
          {dashboardMode === 'internal'
            ? 'Analisis manajemen berdasarkan buku Intern.'
            : 'Analisis potensi pajak berdasarkan buku dan koreksi Fiskal.'}
        </p>
      </div>
      {#if hasFiscalBook}
        <div class="inline-flex rounded-md border border-border-default bg-card-bg p-1">
          <button
            type="button"
            class="rounded px-4 py-1.5 text-sm font-semibold {dashboardMode === 'internal'
              ? 'bg-primary text-white'
              : 'text-text-muted hover:text-text-default'}"
            onclick={() => void switchDashboardMode('internal')}>Intern</button
          >
          <button
            type="button"
            class="rounded px-4 py-1.5 text-sm font-semibold {dashboardMode === 'fiscal'
              ? 'bg-warning text-white'
              : 'text-text-muted hover:text-text-default'}"
            onclick={() => void switchDashboardMode('fiscal')}>Fiskal</button
          >
        </div>
      {/if}
    </header>

    {#if dashboardMode === 'fiscal'}
      {#if fiscalLoading}
        <div class="text-text-muted">Menganalisis buku Fiskal…</div>
      {:else if fiscalError}
        <div class="rounded-md border border-danger bg-danger-light p-3 text-sm text-danger">
          {fiscalError}
        </div>
      {:else if fiscalReport}
        <section class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
          {#each [{ label: 'Laba Buku Fiskal', value: Number(fiscalReport.book_net_income), tone: 'primary' }, { label: 'Koreksi Positif', value: Number(fiscalReport.positive_adjustments), tone: 'warning' }, { label: 'Koreksi Negatif', value: Number(fiscalReport.negative_adjustments), tone: 'paid' }, { label: 'Estimasi Penghasilan Kena Pajak', value: estimatedTaxableIncome, tone: 'primary' }] as item (item.label)}
            <article class="rounded-lg border border-border-default bg-card-bg p-4 shadow-xs">
              <div class="text-xs font-medium text-text-muted">{item.label}</div>
              <div class="mt-2 text-xl font-bold tabnum">{formatRupiah(item.value)}</div>
            </article>
          {/each}
        </section>

        <section class="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-[1fr_1.45fr]">
          <article class="rounded-lg border border-warning/30 bg-card-bg p-5 shadow-xs">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div>
                <div class="text-xs font-semibold uppercase tracking-wider text-warning">
                  Potensi PPh terutang
                </div>
                <div class="mt-2 text-3xl font-bold tabnum">{formatRupiah(estimatedIncomeTax)}</div>
              </div>
              <label class="text-xs text-text-muted">
                Tarif simulasi
                <span class="mt-1 flex items-center gap-1">
                  <input
                    class="w-20 rounded-md border border-border-default bg-card-bg px-2 py-1.5 text-right text-sm text-text-default"
                    type="number"
                    min="0"
                    max="100"
                    step="0.1"
                    value={taxRate}
                    onchange={updateTaxRate}
                  />
                  <span>%</span>
                </span>
              </label>
            </div>
            <p class="mt-4 text-xs leading-5 text-text-muted">
              Simulasi dihitung dari laba Fiskal setelah koreksi dikalikan tarif di atas. Kredit
              pajak, kompensasi rugi, fasilitas tarif, dan pajak final belum diperhitungkan.
            </p>
            <a
              href="/fiskal/koreksi"
              class="mt-4 inline-flex rounded-md bg-warning px-3 py-2 text-xs font-semibold text-white hover:opacity-90"
              >Buka Koreksi &amp; Pajak Final</a
            >
          </article>

          <article
            class="overflow-hidden rounded-lg border border-border-default bg-card-bg shadow-xs"
          >
            <header class="flex items-center justify-between border-b border-border-soft px-4 py-3">
              <div>
                <h2 class="text-sm font-bold">Akun dengan Dampak Koreksi Terbesar</h2>
                <p class="text-xs text-text-muted">Tahun {new Date().getFullYear()}</p>
              </div>
              <span class="ak-pill bg-warning-light text-warning">
                {fiscalReport.rows.filter(
                  (row) => Number(row.positive_adjustment) || Number(row.negative_adjustment),
                ).length} akun
              </span>
            </header>
            <div class="overflow-x-auto">
              <table class="w-full text-sm">
                <thead class="bg-page-bg text-xs uppercase tracking-wider text-text-muted">
                  <tr>
                    <th class="px-4 py-2 text-left">Akun</th>
                    <th class="px-4 py-2 text-right">Buku</th>
                    <th class="px-4 py-2 text-right">Koreksi +</th>
                    <th class="px-4 py-2 text-right">Koreksi −</th>
                    <th class="px-4 py-2 text-right">Final</th>
                  </tr>
                </thead>
                <tbody>
                  {#each fiscalReport.rows
                    .filter((row) => Number(row.positive_adjustment) || Number(row.negative_adjustment))
                    .sort((a, b) => Number(b.positive_adjustment) + Number(b.negative_adjustment) - Number(a.positive_adjustment) - Number(a.negative_adjustment))
                    .slice(0, 8) as row (row.account_id)}
                    <tr class="border-t border-border-soft">
                      <td class="px-4 py-3">
                        <div class="font-mono text-xs">{row.code}</div>
                        <div class="text-xs text-text-muted">{row.name}</div>
                      </td>
                      <td class="px-4 py-3 text-right font-mono text-xs"
                        >{formatRupiah(row.book_amount)}</td
                      >
                      <td class="px-4 py-3 text-right font-mono text-xs text-warning"
                        >{formatRupiah(row.positive_adjustment)}</td
                      >
                      <td class="px-4 py-3 text-right font-mono text-xs text-paid"
                        >{formatRupiah(row.negative_adjustment)}</td
                      >
                      <td class="px-4 py-3 text-right font-mono text-xs font-semibold"
                        >{formatRupiah(row.final_amount)}</td
                      >
                    </tr>
                  {:else}
                    <tr>
                      <td colspan="5" class="px-4 py-10 text-center text-text-muted">
                        Belum ada koreksi Fiskal yang disetujui tahun ini.
                      </td>
                    </tr>
                  {/each}
                </tbody>
              </table>
            </div>
          </article>
        </section>
      {/if}
    {:else}
      <!-- KPI ROW -->
      <section class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4 mb-4">
        {#each [{ label: 'Saldo Kas & Bank', value: 428750000, delta: '+8,4%', pos: true, data: [40, 42, 44, 41, 46, 48, 52, 54, 57, 60, 63, 68], hint: '6 rekening' }, { label: 'Pendapatan (MTD)', value: Number(pulse.revenue.current), delta: pctDelta(pulse.revenue.current, pulse.revenue.previous)?.value ?? '—', pos: pctDelta(pulse.revenue.current, pulse.revenue.previous)?.positive ?? true, data: [20, 22, 24, 28, 30, 33, 38, 42, 46, 50, 55, 60], hint: 'vs bulan lalu' }, { label: 'Beban (MTD)', value: Number(pulse.expenses.current), delta: pctDelta(pulse.expenses.current, pulse.expenses.previous)?.value ?? '—', pos: pctDelta(pulse.expenses.current, pulse.expenses.previous)?.positive ?? true, data: [35, 38, 40, 42, 44, 42, 40, 38, 36, 35, 33, 32], hint: 'vs bulan lalu' }, { label: 'Laba Bersih (MTD)', value: Number(pulse.net_income.current), delta: pctDelta(pulse.net_income.current, pulse.net_income.previous)?.value ?? '—', pos: Number(pulse.net_income.current) >= 0, data: [10, 12, 14, 18, 20, 24, 28, 32, 36, 40, 44, 48], hint: pulse.period_label }] as kpi (kpi.label)}
          <article class="rounded-lg border border-border-default bg-card-bg p-4 shadow-xs">
            <div class="flex items-start justify-between mb-2">
              <span class="text-[0.7rem] font-medium text-text-muted">{kpi.label}</span>
              <span class="text-[0.7rem] font-medium {kpi.pos ? 'text-paid' : 'text-danger'}">
                {kpi.pos ? '↑' : '↓'}
                {kpi.delta}
              </span>
            </div>
            <div class="flex items-end justify-between gap-2">
              <div>
                <div class="text-[1.4rem] font-bold tabnum leading-none tracking-tight">
                  <span class="text-xs text-text-muted font-medium mr-1">Rp</span>{compact(
                    kpi.value,
                  )}
                </div>
                <div class="text-[0.65rem] text-text-muted mt-1">{kpi.hint}</div>
              </div>
              <Spark data={kpi.data} />
            </div>
          </article>
        {/each}
      </section>

      <!-- CASHFLOW + DONUT -->
      <section class="grid grid-cols-1 gap-3 lg:grid-cols-[1.65fr_1fr] mb-4">
        <div class="rounded-lg border border-border-default bg-card-bg p-4 shadow-xs">
          <div class="flex items-baseline justify-between mb-3">
            <div>
              <h3 class="text-sm font-bold tracking-tight">Arus Kas</h3>
              <div class="text-[0.7rem] text-text-muted mt-0.5">
                {pulse.period_label} · per minggu
              </div>
            </div>
            <div
              class="inline-flex gap-0.5 bg-page-bg p-0.5 rounded-md border border-border-default text-xs"
            >
              {#each ['7H', '30H', '90H', 'YTD'] as t, i (t)}
                <button
                  type="button"
                  class="px-3 py-1 rounded font-medium {cashflowTab === i
                    ? 'bg-card-bg text-text-default shadow-xs'
                    : 'text-text-muted hover:text-text-default'}"
                  onclick={() => (cashflowTab = i)}
                >
                  {t}
                </button>
              {/each}
            </div>
          </div>
          <div class="flex flex-wrap items-center gap-4 mb-2 text-xs">
            <span class="inline-flex items-center gap-1.5">
              <span class="w-2 h-2 rounded-full bg-primary"></span>
              <span>Pemasukan</span>
              <span class="tabnum text-text-muted"
                >· Rp {compact(cashflowIncome.reduce((a, b) => a + b, 0))}</span
              >
            </span>
            <span class="inline-flex items-center gap-1.5">
              <svg width="14" height="6"
                ><line
                  x1="0"
                  y1="3"
                  x2="14"
                  y2="3"
                  stroke="currentColor"
                  class="text-text-muted"
                  stroke-width="1.6"
                  stroke-dasharray="3 3"
                /></svg
              >
              <span>Pengeluaran</span>
              <span class="tabnum text-text-muted"
                >· Rp {compact(cashflowExpense.reduce((a, b) => a + b, 0))}</span
              >
            </span>
            <span class="ml-auto text-text-muted">
              Net <strong class="tabnum text-paid font-semibold">+ Rp {compact(cashflowNet)}</strong
              >
            </span>
          </div>
          <CashflowChart
            income={cashflowIncome}
            expense={cashflowExpense}
            labels={cashflowLabels}
          />
        </div>

        <div class="rounded-lg border border-border-default bg-card-bg p-4 shadow-xs">
          <div class="flex items-baseline justify-between mb-3">
            <h3 class="text-sm font-bold tracking-tight">Komposisi Pendapatan</h3>
            <span class="ak-pill bg-primary-light text-primary">Mei</span>
          </div>
          <div class="flex items-center gap-4">
            <div class="relative shrink-0">
              <Donut data={donutSlices} size={148} />
              <div class="absolute inset-0 grid place-items-center text-center">
                <div>
                  <div class="text-[0.65rem] text-text-muted">Total</div>
                  <div class="tabnum text-[0.95rem] font-semibold">
                    Rp {compact(donutSlices.reduce((s, d) => s + d.amount, 0))}
                  </div>
                </div>
              </div>
            </div>
            <div class="flex-1 flex flex-col gap-2 min-w-0">
              {#each donutSlices as s (s.label)}
                <div class="flex items-center gap-2">
                  <span class="w-2 h-2 rounded-sm shrink-0" style="background: {s.color}"></span>
                  <div class="flex-1 min-w-0">
                    <div class="text-xs font-medium leading-tight truncate">{s.label}</div>
                    <div class="text-[0.65rem] text-text-muted">{s.src}</div>
                  </div>
                  <div class="text-right">
                    <div class="tabnum text-xs font-semibold">Rp {compact(s.amount)}</div>
                    <div class="text-[0.65rem] text-text-muted">{s.value}%</div>
                  </div>
                </div>
              {/each}
            </div>
          </div>
        </div>
      </section>

      <!-- AGING + APPROVALS -->
      <section class="grid grid-cols-1 gap-3 lg:grid-cols-[1.4fr_1fr] mb-4">
        <div class="rounded-lg border border-border-default bg-card-bg shadow-xs overflow-hidden">
          <div class="flex items-center justify-between border-b border-border-default px-4 py-3">
            <div>
              <h3 class="text-sm font-bold tracking-tight">Aging Piutang &amp; Utang</h3>
              <div class="text-[0.7rem] text-text-muted mt-0.5">Klik baris untuk drill-down</div>
            </div>
            <div
              class="inline-flex gap-0.5 bg-page-bg p-0.5 rounded-md border border-border-default text-xs"
            >
              <button class="px-3 py-1 rounded font-medium bg-card-bg text-text-default shadow-xs"
                >Ringkasan</button
              >
              <button class="px-3 py-1 rounded font-medium text-text-muted">Piutang</button>
              <button class="px-3 py-1 rounded font-medium text-text-muted">Utang</button>
            </div>
          </div>
          <table class="w-full text-sm">
            <thead class="bg-page-bg">
              <tr class="text-[0.65rem] uppercase tracking-wider text-text-muted">
                <th class="px-4 py-2 text-left font-medium">Kategori</th>
                <th class="px-4 py-2 text-left font-medium">Belum JT</th>
                <th class="px-4 py-2 text-left font-medium">1–30 hari</th>
                <th class="px-4 py-2 text-left font-medium">31–60 hari</th>
                <th class="px-4 py-2 text-left font-medium">&gt;60 hari</th>
                <th class="px-4 py-2 text-right font-medium">Total</th>
              </tr>
            </thead>
            <tbody>
              {#each aging as r (r.label)}
                {@const total = r.cells.reduce((s, n) => s + n, 0)}
                <tr class="border-t border-border-soft hover:bg-page-bg cursor-pointer">
                  <td class="px-4 py-2.5">
                    <div class="flex items-center gap-2.5">
                      <span class="w-[3px] h-6 rounded-sm shrink-0" style="background: {r.accent}"
                      ></span>
                      <div>
                        <div class="font-semibold text-xs">{r.label}</div>
                        <div class="text-[0.65rem] text-text-muted">{r.sub}</div>
                      </div>
                    </div>
                  </td>
                  {#each r.cells as c, i (i)}
                    <td
                      class="px-4 py-2.5 tabnum text-xs {c === 0
                        ? 'text-text-muted/60'
                        : i >= 2
                          ? 'text-danger'
                          : ''}"
                    >
                      {c === 0 ? '—' : 'Rp ' + compact(c)}
                    </td>
                  {/each}
                  <td class="px-4 py-2.5 text-right tabnum text-xs font-semibold"
                    >Rp {compact(total)}</td
                  >
                </tr>
              {/each}
            </tbody>
          </table>
        </div>

        <div class="rounded-lg border border-border-default bg-card-bg p-4 shadow-xs">
          <div class="flex items-baseline justify-between mb-3">
            <h3 class="text-sm font-bold tracking-tight">Perlu Persetujuan</h3>
            <span class="ak-pill bg-warning-light text-warning">5 baru</span>
          </div>
          <div class="flex flex-col gap-2">
            {#each approvals as a (a.title)}
              <div
                class="flex items-center gap-2.5 p-2 border border-border-default rounded-md bg-card-bg"
              >
                <div
                  class="w-7 h-7 rounded-md grid place-items-center text-sm shrink-0"
                  style="background: {a.warn
                    ? 'var(--m-warning-light, #FFF8DD)'
                    : 'var(--m-primary-light)'}; color: {a.warn ? '#F6C000' : 'var(--m-primary)'};"
                >
                  {a.icon}
                </div>
                <div class="flex-1 min-w-0">
                  <div class="text-xs font-semibold truncate">{a.title}</div>
                  <div class="text-[0.65rem] text-text-muted truncate">
                    {a.sub} · <span>{a.tag}</span>
                  </div>
                </div>
                <button
                  class="w-6 h-6 grid place-items-center rounded text-text-muted hover:text-danger hover:bg-danger-light"
                  title="Tolak">×</button
                >
                <button
                  class="w-6 h-6 grid place-items-center rounded bg-primary text-white text-xs hover:bg-primary-active"
                  title="Setujui">✓</button
                >
              </div>
            {/each}
          </div>
          <button
            class="w-full mt-2.5 py-2 text-xs font-medium text-text-muted hover:text-text-default rounded border border-border-default hover:bg-page-bg"
          >
            Lihat semua (12) ›
          </button>
        </div>
      </section>

      <!-- QUICK ACTIONS + SYNC -->
      <section class="grid grid-cols-1 gap-3 lg:grid-cols-2">
        <div class="rounded-lg border border-border-default bg-card-bg p-4 shadow-xs">
          <div class="flex items-baseline justify-between mb-3">
            <h3 class="text-sm font-bold tracking-tight">Aksi Cepat</h3>
            <span class="text-[0.7rem] text-text-muted">Pintasan ke modul</span>
          </div>
          <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
            {#each quickActions as q (q.label)}
              <a
                href={q.href}
                class="flex flex-col items-start gap-2 p-3 border border-border-default rounded-md bg-card-bg hover:bg-page-bg transition-colors text-left"
              >
                <div
                  class="w-7 h-7 rounded-md grid place-items-center bg-primary-light text-primary text-sm"
                >
                  {q.icon}
                </div>
                <div class="text-xs font-semibold">{q.label}</div>
              </a>
            {/each}
          </div>
        </div>

        <div class="rounded-lg border border-border-default bg-card-bg p-4 shadow-xs">
          <div class="flex items-baseline justify-between mb-3">
            <h3 class="text-sm font-bold tracking-tight">Status Sinkronisasi Ekosistem</h3>
            <button class="text-[0.7rem] font-medium text-text-muted hover:text-primary"
              >↻ Sync sekarang</button
            >
          </div>
          <div class="flex flex-col">
            {#each ecoSync as e (e.name)}
              {@const p = statusPill(e.status)}
              <div
                class="flex items-center gap-2.5 py-2 border-b border-border-soft last:border-b-0"
              >
                <div class="w-7 h-7 rounded-md grid place-items-center bg-page-bg text-sm shrink-0">
                  {e.icon}
                </div>
                <div class="flex-1 min-w-0">
                  <div class="text-xs font-semibold truncate">{e.name}</div>
                  <div class="text-[0.65rem] text-text-muted truncate">{e.count} · {e.last}</div>
                </div>
                <span class="ak-pill" style="background: {p.bg}; color: {p.fg}; gap: 4px;">
                  <span
                    class="w-1.5 h-1.5 rounded-full"
                    style="background: {p.dot}; box-shadow: {e.status === 'syncing'
                      ? '0 0 0 3px ' + p.bg
                      : 'none'}"
                  ></span>
                  {p.label}
                </span>
              </div>
            {/each}
          </div>
        </div>
      </section>

      <!-- Recent journals (kept from v1 for real-data link) -->
      {#if recent.length}
        <section class="mt-3 rounded-lg border border-border-default bg-card-bg shadow-xs">
          <header class="flex items-center justify-between border-b border-border-soft px-4 py-3">
            <strong class="text-[0.7rem] font-bold uppercase tracking-wider text-text-muted"
              >Jurnal Terakhir</strong
            >
            <a class="text-xs text-primary hover:underline" href="/journals">Lihat semua →</a>
          </header>
          <table class="w-full text-sm">
            <thead class="bg-page-bg text-[0.65rem] uppercase tracking-wider text-text-muted">
              <tr>
                <th class="px-4 py-2 text-left font-medium">No.</th>
                <th class="px-4 py-2 text-left font-medium">Tanggal</th>
                <th class="px-4 py-2 text-left font-medium">Keterangan</th>
                <th class="px-4 py-2 text-right font-medium">Total</th>
                <th class="px-4 py-2 text-left font-medium">Status</th>
              </tr>
            </thead>
            <tbody>
              {#each recent as j (j.id)}
                <tr
                  class="border-t border-border-soft hover:bg-page-bg cursor-pointer"
                  onclick={() => goto(`/journals/${j.id}`)}
                >
                  <td class="px-4 py-2 font-mono text-xs">{j.number}</td>
                  <td class="px-4 py-2 text-xs">{formatDate(j.date)}</td>
                  <td class="px-4 py-2 text-xs">{j.memo ?? '—'}</td>
                  <td class="px-4 py-2 text-right font-mono tabnum text-xs"
                    >{formatRupiah(j.total)}</td
                  >
                  <td class="px-4 py-2">
                    <span
                      class="ak-pill {j.status === 'posted'
                        ? 'bg-paid-light text-paid'
                        : j.status === 'reversed'
                          ? 'bg-warning-light text-warning'
                          : 'bg-page-bg text-text-muted'}"
                    >
                      {j.status}
                    </span>
                  </td>
                </tr>
              {/each}
            </tbody>
          </table>
        </section>
      {/if}
    {/if}
  {/if}
</div>
