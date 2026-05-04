<script lang="ts">
  import { onMount } from 'svelte';
  import { goto } from '$app/navigation';
  import { auth } from '$lib/stores/auth.svelte.js';
  import { widgetsApi, type FinancialPulse, type RecentJournal } from '$lib/api/widgets.js';
  import { onboardingApi, type OnboardingStatus } from '$lib/api/onboarding.js';
  import { formatRupiah } from '@akunta/ui';

  let pulse = $state<FinancialPulse | null>(null);
  let recent = $state<RecentJournal[]>([]);
  let status = $state<OnboardingStatus | null>(null);
  let loading = $state(true);
  let error = $state<string | null>(null);

  onMount(async () => {
    if (!auth.user) {
      const u = await auth.refresh();
      if (!u) { goto('/login', { replaceState: true }); return; }
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

  function pctDelta(curr: string, prev: string): { value: string; positive: boolean } | null {
    const c = Number(curr);
    const p = Number(prev);
    if (p === 0) return null;
    const diff = ((c - p) / p) * 100;
    return { value: `${diff >= 0 ? '+' : ''}${diff.toFixed(1)}%`, positive: diff >= 0 };
  }

  function statusColor(s: RecentJournal['status']): string {
    return s === 'posted' ? 'bg-paid-light text-paid'
      : s === 'reversed' ? 'bg-warning-light text-warning'
      : 'bg-page-bg text-text-muted';
  }
</script>

<div class="px-6 py-6">
  <header class="mb-5">
    <p class="text-xs font-medium text-text-muted">Beranda</p>
    <h1 class="text-2xl font-bold">Dashboard</h1>
    <p class="text-sm text-text-muted">{pulse?.period_label ?? '—'}</p>
  </header>

  {#if loading}
    <div class="text-text-muted">Memuat dashboard…</div>
  {:else if error}
    <div class="rounded-md border border-danger bg-danger-light p-3 text-sm text-danger">{error}</div>
  {:else if pulse}
    <section class="grid grid-cols-1 gap-4 md:grid-cols-3 mb-6">
      {#each [
        { label: 'Pendapatan', value: pulse.revenue.current, prev: pulse.revenue.previous, color: 'text-paid' },
        { label: 'Beban (HPP + Operasional)', value: pulse.expenses.current, prev: pulse.expenses.previous, color: 'text-warning' },
        { label: 'Laba Bersih', value: pulse.net_income.current, prev: pulse.net_income.previous, color: Number(pulse.net_income.current) >= 0 ? 'text-paid' : 'text-danger' },
      ] as it}
        {@const delta = pctDelta(it.value, it.prev)}
        <article class="rounded-lg border border-border-default bg-card-bg p-4 shadow-xs">
          <p class="text-xs font-bold uppercase tracking-wider text-text-muted">{it.label}</p>
          <strong class="mt-1 block text-2xl font-bold tabnum {it.color}">{formatRupiah(it.value)}</strong>
          {#if delta}
            <p class="mt-1 text-xs {delta.positive ? 'text-paid' : 'text-danger'}">
              {delta.value} vs bulan lalu
            </p>
          {:else}
            <p class="mt-1 text-xs text-text-muted">Tidak ada data periode lalu</p>
          {/if}
        </article>
      {/each}
    </section>

    <section class="grid grid-cols-1 gap-4 md:grid-cols-3 mb-6">
      <article class="rounded-lg border border-border-default bg-card-bg p-4 shadow-xs">
        <p class="text-xs font-bold uppercase tracking-wider text-text-muted">Jurnal Bulan Ini</p>
        <strong class="mt-1 block text-2xl font-bold">{pulse.journals.posted_this_month}</strong>
        <p class="mt-1 text-xs text-text-muted">posted</p>
      </article>
      <article class="rounded-lg border border-border-default bg-card-bg p-4 shadow-xs">
        <p class="text-xs font-bold uppercase tracking-wider text-text-muted">Draft</p>
        <strong class="mt-1 block text-2xl font-bold {pulse.journals.draft_count > 0 ? 'text-warning' : ''}">
          {pulse.journals.draft_count}
        </strong>
        <p class="mt-1 text-xs text-text-muted">menunggu posting</p>
      </article>
      <article class="rounded-lg border border-border-default bg-card-bg p-4 shadow-xs flex flex-col justify-between">
        <p class="text-xs font-bold uppercase tracking-wider text-text-muted">Aksi Cepat</p>
        <a
          href="/journals/new"
          class="mt-2 inline-flex items-center justify-center rounded-md bg-[#0F172A] px-4 py-2 text-sm font-semibold text-white hover:bg-[#1E293B]"
        >
          + Jurnal Baru
        </a>
      </article>
    </section>

    <section class="rounded-lg border border-border-default bg-card-bg shadow-xs">
      <header class="flex items-center justify-between border-b border-border-soft px-4 py-3">
        <strong class="text-sm font-bold uppercase tracking-wider text-text-muted">Jurnal Terakhir</strong>
        <a class="text-xs text-primary hover:underline" href="/journals">Lihat semua →</a>
      </header>
      <table class="w-full text-sm">
        <thead class="bg-page-bg text-xs uppercase tracking-wider text-text-muted">
          <tr>
            <th class="px-4 py-2 text-left">No.</th>
            <th class="px-4 py-2 text-left">Tanggal</th>
            <th class="px-4 py-2 text-left">Keterangan</th>
            <th class="px-4 py-2 text-right">Total</th>
            <th class="px-4 py-2 text-left">Status</th>
          </tr>
        </thead>
        <tbody>
          {#each recent as j (j.id)}
            <tr class="border-t border-border-soft hover:bg-page-bg cursor-pointer" onclick={() => goto(`/journals/${j.id}`)}>
              <td class="px-4 py-2 font-mono">{j.number}</td>
              <td class="px-4 py-2">{j.date}</td>
              <td class="px-4 py-2">{j.memo ?? '—'}</td>
              <td class="px-4 py-2 text-right font-mono tabnum">{formatRupiah(j.total)}</td>
              <td class="px-4 py-2">
                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold {statusColor(j.status)}">
                  {j.status}
                </span>
              </td>
            </tr>
          {:else}
            <tr><td colspan="5" class="px-4 py-8 text-center text-text-muted">Belum ada jurnal.</td></tr>
          {/each}
        </tbody>
      </table>
    </section>
  {/if}
</div>
