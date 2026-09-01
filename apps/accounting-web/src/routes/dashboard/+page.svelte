<script lang="ts">
  import { onMount } from 'svelte';
  import { goto } from '$app/navigation';
  import { auth } from '$lib/stores/auth.svelte.js';
  import { tenant } from '$lib/stores/tenant.svelte.js';
  import { period } from '$lib/stores/period.svelte.js';
  import { widgetsApi, type FinancialPulse, type RecentJournal } from '$lib/api/widgets.js';
  import { reportingApi, type FiscalReconciliationData } from '$lib/api/reporting.js';
  import { installationOnboardingApi } from '$lib/api/installation-onboarding.js';
  import { formatRupiah } from '@akunta/ui';
  import { formatDate } from '$lib/utils/date.js';
  import Spark from '$lib/components/dashboard/Spark.svelte';
  import Donut from '$lib/components/dashboard/Donut.svelte';
  import CashflowChart from '$lib/components/dashboard/CashflowChart.svelte';

  let pulse = $state<FinancialPulse | null>(null);
  let recent = $state<RecentJournal[]>([]);
  let loading = $state(true);
  let error = $state<string | null>(null);
  let missingActivePeriod = $state(false);
  let dashboardMode = $state<'internal' | 'fiscal'>('internal');
  let fiscalReport = $state<FiscalReconciliationData | null>(null);
  let fiscalLoading = $state(false);
  let fiscalError = $state<string | null>(null);
  let taxRate = $state(22);

  const colors = ['#1B84FF', '#7239EA', '#17C653', '#F6C000', '#F8285A', '#7AA2FF'];
  let hasFiscalBook = $derived(
    tenant.available.find((item) => item.id === tenant.id)?.bookkeeping_mode ===
      'independent_books',
  );
  let estimatedTaxableIncome = $derived(Math.max(0, Number(fiscalReport?.final_net_income ?? 0)));
  let estimatedIncomeTax = $derived((estimatedTaxableIncome * taxRate) / 100);
  let trendIncome = $derived(pulse?.trend.map((item) => Number(item.income)) ?? []);
  let trendExpense = $derived(pulse?.trend.map((item) => Number(item.expense)) ?? []);
  let trendLabels = $derived(pulse?.trend.map((item) => item.label) ?? []);
  let revenueTotal = $derived(
    pulse?.revenue_composition.reduce((total, item) => total + Number(item.amount), 0) ?? 0,
  );
  let donutSlices = $derived(
    (pulse?.revenue_composition ?? []).map((item, index) => ({
      value: Number(item.amount),
      color: colors[index % colors.length],
    })),
  );

  onMount(async () => {
    if (!auth.user) {
      const user = await auth.refresh();
      if (!user) {
        if (!auth.error) goto('/login', { replaceState: true });
        else {
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
      const onboarding = await installationOnboardingApi.status();
      if (!onboarding.completed) {
        if (auth.user?.is_sso_admin) {
          goto('/onboarding', { replaceState: true });
        } else {
          error = 'Setup Akunta belum diselesaikan. Minta admin Ecopa menyelesaikan onboarding.';
        }
        return;
      }
      if (!period.activeId) await period.refresh();
      if (!period.activeId) {
        missingActivePeriod = true;
        throw new Error('Periode aktif belum diatur. Pilih periode aktif untuk membuka dashboard.');
      }
      if (!tenant.id) {
        throw new Error('Pilih entitas untuk membuka dashboard.');
      }
      [pulse, recent] = await Promise.all([
        widgetsApi.financialPulse(period.activeId, tenant.id),
        widgetsApi.recentJournals(8, period.activeId, tenant.id, 'internal'),
      ]);
    } catch (exception) {
      error = exception instanceof Error ? exception.message : String(exception);
    } finally {
      loading = false;
    }
  });

  async function switchDashboardMode(mode: 'internal' | 'fiscal') {
    dashboardMode = mode;
    if (mode !== 'fiscal' || fiscalReport || fiscalLoading || !pulse || !tenant.id) return;

    fiscalLoading = true;
    fiscalError = null;
    try {
      const response = await reportingApi.fiscalReconciliation(
        pulse.period.start_date,
        pulse.period.end_date,
        tenant.id,
      );
      fiscalReport = response.data;
      const storedRate = Number(localStorage.getItem(`akunta.tax-rate.${tenant.id}`));
      if (Number.isFinite(storedRate) && storedRate >= 0 && storedRate <= 100) taxRate = storedRate;
    } catch (exception) {
      fiscalError = exception instanceof Error ? exception.message : String(exception);
    } finally {
      fiscalLoading = false;
    }
  }

  function updateTaxRate(event: Event) {
    const next = Number((event.currentTarget as HTMLInputElement).value);
    taxRate = Number.isFinite(next) ? Math.min(100, Math.max(0, next)) : 0;
    localStorage.setItem(`akunta.tax-rate.${tenant.id}`, String(taxRate));
  }

  function compact(value: number | string): string {
    const number = typeof value === 'string' ? Number(value) : value;
    const absolute = Math.abs(number);
    if (absolute >= 1e9) return (number / 1e9).toFixed(1) + ' M';
    if (absolute >= 1e6) return (number / 1e6).toFixed(1) + ' jt';
    if (absolute >= 1e3) return (number / 1e3).toFixed(0) + ' rb';
    return Math.round(number).toLocaleString('id-ID');
  }

  function pctDelta(
    current: string,
    previous: string,
  ): { value: string; positive: boolean } | null {
    const currentValue = Number(current);
    const previousValue = Number(previous);
    if (previousValue === 0) return null;
    const difference = ((currentValue - previousValue) / Math.abs(previousValue)) * 100;
    return {
      value: `${difference >= 0 ? '+' : ''}${difference.toFixed(1)}%`,
      positive: difference >= 0,
    };
  }

  function statusLabel(status: RecentJournal['status']): string {
    return {
      draft: 'Diajukan',
      submitted: 'Di review',
      posted: 'Tersimpan',
      rejected: 'Perlu Revisi',
      reversed: 'Dibalik',
    }[status];
  }
</script>

<div class="px-6 py-5">
  {#if loading}
    <div class="text-text-muted">Memuat dashboard…</div>
  {:else if error}
    <div
      class="rounded-md border border-danger bg-danger-light p-3 text-sm text-danger"
      role="alert"
    >
      <p>{error}</p>
      {#if missingActivePeriod}
        <a
          href="/periode"
          class="mt-2 inline-block font-semibold text-danger underline underline-offset-2 hover:text-danger/80"
        >
          Atur periode aktif
        </a>
      {/if}
    </div>
  {:else if pulse}
    <header class="mb-5 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h1 class="text-2xl font-bold">Dashboard</h1>
        <p class="text-sm text-text-muted">
          {tenant.name} · {pulse.period.name} · {formatDate(pulse.period.start_date)}–{formatDate(
            pulse.period.end_date,
          )}
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
          {#each [{ label: 'Laba Buku Fiskal', value: Number(fiscalReport.book_net_income) }, { label: 'Koreksi Positif', value: Number(fiscalReport.positive_adjustments) }, { label: 'Koreksi Negatif', value: Number(fiscalReport.negative_adjustments) }, { label: 'Estimasi Penghasilan Kena Pajak', value: estimatedTaxableIncome }] as item (item.label)}
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
                    class="w-20 rounded-md border border-border-default bg-card-bg px-2 py-1.5 text-right text-sm"
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
              Simulasi periode aktif. Kredit pajak, kompensasi rugi, fasilitas tarif, dan pajak
              final belum diperhitungkan.
            </p>
            <a
              href="/fiskal/koreksi"
              class="mt-4 inline-flex rounded-md bg-warning px-3 py-2 text-xs font-semibold text-white hover:opacity-90"
              >Buka Koreksi &amp; Provisi Pajak</a
            >
          </article>

          <article
            class="overflow-hidden rounded-lg border border-border-default bg-card-bg shadow-xs"
          >
            <header class="border-b border-border-soft px-4 py-3">
              <h2 class="text-sm font-bold">Dampak Koreksi Fiskal</h2>
              <p class="text-xs text-text-muted">{pulse.period.name}</p>
            </header>
            <div class="overflow-x-auto">
              <table class="w-full text-sm">
                <thead class="bg-page-bg text-xs uppercase tracking-wider text-text-muted">
                  <tr
                    ><th class="px-4 py-2 text-left">Akun</th><th class="px-4 py-2 text-right"
                      >Buku</th
                    ><th class="px-4 py-2 text-right">Koreksi +</th><th class="px-4 py-2 text-right"
                      >Koreksi −</th
                    ><th class="px-4 py-2 text-right">Final</th></tr
                  >
                </thead>
                <tbody>
                  {#each fiscalReport.rows
                    .filter((row) => Number(row.positive_adjustment) || Number(row.negative_adjustment))
                    .slice(0, 8) as row (row.account_id)}
                    <tr class="border-t border-border-soft">
                      <td class="px-4 py-3"
                        ><div class="font-mono text-xs">{row.code}</div>
                        <div class="text-xs text-text-muted">{row.name}</div></td
                      >
                      <td class="px-4 py-3 text-right text-xs">{formatRupiah(row.book_amount)}</td>
                      <td class="px-4 py-3 text-right text-xs text-warning"
                        >{formatRupiah(row.positive_adjustment)}</td
                      >
                      <td class="px-4 py-3 text-right text-xs text-paid"
                        >{formatRupiah(row.negative_adjustment)}</td
                      >
                      <td class="px-4 py-3 text-right text-xs font-semibold"
                        >{formatRupiah(row.final_amount)}</td
                      >
                    </tr>
                  {:else}
                    <tr
                      ><td colspan="5" class="px-4 py-10 text-center text-text-muted"
                        >Belum ada koreksi Fiskal approved pada periode aktif.</td
                      ></tr
                    >
                  {/each}
                </tbody>
              </table>
            </div>
          </article>
        </section>
      {/if}
    {:else}
      <section class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        {#each [{ label: 'Saldo Kas & Bank', current: pulse.cash_balance.current, previous: pulse.cash_balance.previous, hint: `${pulse.cash_balance.account_count} akun` }, { label: 'Pendapatan', current: pulse.revenue.current, previous: pulse.revenue.previous, hint: `vs ${pulse.previous_period?.name ?? 'periode sebelumnya'}` }, { label: 'Beban', current: pulse.expenses.current, previous: pulse.expenses.previous, hint: `vs ${pulse.previous_period?.name ?? 'periode sebelumnya'}` }, { label: 'Laba Bersih', current: pulse.net_income.current, previous: pulse.net_income.previous, hint: pulse.period_label }] as kpi (kpi.label)}
          {@const delta = pctDelta(kpi.current, kpi.previous)}
          <article class="rounded-lg border border-border-default bg-card-bg p-4 shadow-xs">
            <div class="mb-2 flex items-start justify-between">
              <span class="text-[0.7rem] font-medium text-text-muted">{kpi.label}</span>
              <span
                class="text-[0.7rem] font-medium {delta?.positive === false
                  ? 'text-danger'
                  : 'text-paid'}">{delta?.value ?? '—'}</span
              >
            </div>
            <div class="flex items-end justify-between gap-2">
              <div>
                <div class="text-[1.4rem] font-bold tabnum leading-none">
                  <span class="mr-1 text-xs font-medium text-text-muted">Rp</span>{compact(
                    kpi.current,
                  )}
                </div>
                <div class="mt-1 text-[0.65rem] text-text-muted">{kpi.hint}</div>
              </div>
              <Spark data={trendIncome.length ? trendIncome : [0]} />
            </div>
          </article>
        {/each}
      </section>

      <section class="mb-4 grid grid-cols-1 gap-3 lg:grid-cols-[1.65fr_1fr]">
        <article class="rounded-lg border border-border-default bg-card-bg p-4 shadow-xs">
          <div class="mb-3 flex items-baseline justify-between">
            <div>
              <h2 class="text-sm font-bold">Tren Pendapatan &amp; Beban</h2>
              <p class="text-[0.7rem] text-text-muted">
                Jurnal Intern posted · {pulse.period.name}
              </p>
            </div>
          </div>
          <CashflowChart income={trendIncome} expense={trendExpense} labels={trendLabels} />
        </article>

        <article class="rounded-lg border border-border-default bg-card-bg p-4 shadow-xs">
          <h2 class="mb-3 text-sm font-bold">Komposisi Pendapatan</h2>
          {#if pulse.revenue_composition.length}
            <div class="flex items-center gap-4">
              <div class="relative shrink-0">
                <Donut data={donutSlices} size={140} />
                <div class="absolute inset-0 grid place-items-center text-center">
                  <div>
                    <div class="text-[0.65rem] text-text-muted">Total</div>
                    <div class="text-sm font-semibold">Rp {compact(revenueTotal)}</div>
                  </div>
                </div>
              </div>
              <div class="min-w-0 flex-1 space-y-2">
                {#each pulse.revenue_composition as item, index (item.account_id)}
                  <div class="flex items-center gap-2">
                    <span
                      class="h-2 w-2 shrink-0 rounded-sm"
                      style={`background:${colors[index % colors.length]}`}
                    ></span>
                    <div class="min-w-0 flex-1">
                      <div class="truncate text-xs font-medium">{item.label}</div>
                      <div class="text-[0.65rem] text-text-muted">{item.code}</div>
                    </div>
                    <div class="text-right text-xs font-semibold">Rp {compact(item.amount)}</div>
                  </div>
                {/each}
              </div>
            </div>
          {:else}
            <div class="py-12 text-center text-sm text-text-muted">
              Belum ada pendapatan posted pada periode aktif.
            </div>
          {/if}
        </article>
      </section>

      <section class="mb-4 grid grid-cols-1 gap-3 lg:grid-cols-[1.4fr_1fr]">
        <article
          class="overflow-hidden rounded-lg border border-border-default bg-card-bg shadow-xs"
        >
          <header class="border-b border-border-soft px-4 py-3">
            <h2 class="text-sm font-bold">Saldo Aset &amp; Liabilitas Terbesar</h2>
            <p class="text-xs text-text-muted">
              Saldo buku Intern sampai akhir {pulse.period.name}
            </p>
          </header>
          <table class="w-full text-sm">
            <thead class="bg-page-bg text-xs uppercase tracking-wider text-text-muted"
              ><tr
                ><th class="px-4 py-2 text-left">Akun</th><th class="px-4 py-2 text-left"
                  >Kelompok</th
                ><th class="px-4 py-2 text-right">Saldo</th></tr
              ></thead
            >
            <tbody>
              {#each pulse.balance_accounts as account (account.account_id)}
                <tr class="border-t border-border-soft"
                  ><td class="px-4 py-2.5"
                    ><span class="mr-2 font-mono text-xs text-text-muted">{account.code}</span
                    >{account.label}</td
                  ><td class="px-4 py-2.5 text-xs capitalize text-text-muted"
                    >{account.type === 'asset' ? 'Aset' : 'Liabilitas'}</td
                  ><td class="px-4 py-2.5 text-right font-mono text-xs"
                    >{formatRupiah(account.amount)}</td
                  ></tr
                >
              {:else}
                <tr
                  ><td colspan="3" class="px-4 py-10 text-center text-text-muted"
                    >Belum ada saldo posted.</td
                  ></tr
                >
              {/each}
            </tbody>
          </table>
        </article>

        <article class="rounded-lg border border-border-default bg-card-bg p-4 shadow-xs">
          <div class="mb-3 flex items-center justify-between">
            <h2 class="text-sm font-bold">Perlu Review</h2>
            <span class="ak-pill bg-warning-light text-warning"
              >{pulse.journals.submitted_count}</span
            >
          </div>
          <div class="space-y-2">
            {#each pulse.pending_journals as journal (journal.id)}
              <button
                class="flex w-full items-center gap-3 rounded-md border border-border-default p-3 text-left hover:bg-page-bg"
                onclick={() => goto(`/journals/${journal.id}`)}
              >
                <div class="min-w-0 flex-1">
                  <div class="truncate text-xs font-semibold">{journal.number}</div>
                  <div class="truncate text-[0.65rem] text-text-muted">
                    {journal.memo ?? 'Tanpa keterangan'} · {formatDate(journal.date)}
                  </div>
                </div>
                <span class="text-xs font-semibold">{formatRupiah(journal.total)}</span>
              </button>
            {:else}
              <div class="py-10 text-center text-sm text-text-muted">
                Tidak ada jurnal menunggu review.
              </div>
            {/each}
          </div>
        </article>
      </section>

      <section class="rounded-lg border border-border-default bg-card-bg shadow-xs">
        <header class="flex items-center justify-between border-b border-border-soft px-4 py-3">
          <div>
            <strong class="text-sm font-bold">Jurnal Periode Aktif</strong>
            <p class="text-xs text-text-muted">
              {pulse.journals.posted_count} posted · {pulse.journals.draft_count} diajukan · {pulse
                .journals.rejected_count} perlu revisi
            </p>
          </div>
          <a class="text-xs text-primary hover:underline" href="/journals">Lihat semua →</a>
        </header>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-page-bg text-[0.65rem] uppercase tracking-wider text-text-muted"
              ><tr
                ><th class="px-4 py-2 text-left">No.</th><th class="px-4 py-2 text-left">Tanggal</th
                ><th class="px-4 py-2 text-left">Keterangan</th><th class="px-4 py-2 text-right"
                  >Total</th
                ><th class="px-4 py-2 text-left">Status</th></tr
              ></thead
            >
            <tbody>
              {#each recent as journal (journal.id)}
                <tr
                  class="cursor-pointer border-t border-border-soft hover:bg-page-bg"
                  onclick={() => goto(`/journals/${journal.id}`)}
                  ><td class="px-4 py-2 font-mono text-xs">{journal.number}</td><td
                    class="px-4 py-2 text-xs">{formatDate(journal.date)}</td
                  ><td class="px-4 py-2 text-xs">{journal.memo ?? '—'}</td><td
                    class="px-4 py-2 text-right font-mono text-xs">{formatRupiah(journal.total)}</td
                  ><td class="px-4 py-2"
                    ><span class="ak-pill bg-page-bg text-text-muted"
                      >{statusLabel(journal.status)}</span
                    ></td
                  ></tr
                >
              {:else}
                <tr
                  ><td colspan="5" class="px-4 py-10 text-center text-text-muted"
                    >Belum ada jurnal pada periode aktif.</td
                  ></tr
                >
              {/each}
            </tbody>
          </table>
        </div>
      </section>
    {/if}
  {/if}
</div>
