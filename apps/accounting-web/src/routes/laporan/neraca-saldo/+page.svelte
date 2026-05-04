<script lang="ts">
  import { onMount } from 'svelte';
  import { goto } from '$app/navigation';
  import { auth } from '$lib/stores/auth.svelte.js';
  import { reportingApi, type TrialBalanceData } from '$lib/api/reporting.js';
  import { ApiError } from '$lib/api/client.js';
  import { formatRupiah } from '@akunta/ui';
  import ReportShell from '$lib/components/reporting/ReportShell.svelte';

  let asOf = $state(new Date().toISOString().slice(0, 10));
  let report = $state<TrialBalanceData | null>(null);
  let loading = $state(false);
  let error = $state<string | null>(null);

  async function load() {
    loading = true;
    error = null;
    try {
      const res = await reportingApi.trialBalance(asOf);
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
</script>

<ReportShell title="Neraca Saldo" breadcrumb="Laporan / Neraca Saldo" subtitle={report ? `Per ${report.as_of}` : null}>
  {#snippet toolbar()}
    <label class="text-sm">
      <span class="block font-medium mb-1">Per Tanggal</span>
      <input type="date" class="rounded-md border border-border-default px-2 py-1.5" bind:value={asOf} data-testid="report-as-of" />
    </label>
    <button
      type="button"
      class="rounded-md bg-[#0F172A] px-4 py-2 text-sm font-semibold text-white hover:bg-[#1E293B] disabled:opacity-50"
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
    <table class="w-full text-sm">
      <thead class="bg-page-bg text-xs uppercase tracking-wider text-text-muted">
        <tr>
          <th class="px-4 py-3 text-left">Kode</th>
          <th class="px-4 py-3 text-left">Nama Akun</th>
          <th class="px-4 py-3 text-right">Debit</th>
          <th class="px-4 py-3 text-right">Kredit</th>
          <th class="px-4 py-3 text-right">Saldo</th>
        </tr>
      </thead>
      <tbody>
        {#each report.rows as r (r.id)}
          <tr class="border-t border-border-soft hover:bg-page-bg cursor-pointer" onclick={() => goto(`/laporan/buku-besar?account_id=${r.id}`)}>
            <td class="px-4 py-2 font-mono">{r.code}</td>
            <td class="px-4 py-2">{r.name}</td>
            <td class="px-4 py-2 text-right font-mono tabnum">{formatRupiah(r.total_debit)}</td>
            <td class="px-4 py-2 text-right font-mono tabnum">{formatRupiah(r.total_credit)}</td>
            <td class="px-4 py-2 text-right font-mono tabnum font-semibold">{formatRupiah(r.balance)}</td>
          </tr>
        {:else}
          <tr><td colspan="5" class="px-4 py-10 text-center text-text-muted">Tidak ada saldo pada tanggal ini.</td></tr>
        {/each}
      </tbody>
      {#if report.rows.length > 0}
        <tfoot class="bg-page-bg font-semibold">
          <tr class="border-t-2 border-border-default">
            <td class="px-4 py-3" colspan="2">Total</td>
            <td class="px-4 py-3 text-right font-mono tabnum">{formatRupiah(report.total_debit)}</td>
            <td class="px-4 py-3 text-right font-mono tabnum">{formatRupiah(report.total_credit)}</td>
            <td class="px-4 py-3 text-right font-mono tabnum text-paid">
              {report.total_debit === report.total_credit ? '✓ Balance' : '⚠ Tidak balance'}
            </td>
          </tr>
        </tfoot>
      {/if}
    </table>
  {/if}
</ReportShell>
