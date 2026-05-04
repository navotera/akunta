<script lang="ts">
  import { onMount } from 'svelte';
  import { goto } from '$app/navigation';
  import { page } from '$app/stores';
  import { auth } from '$lib/stores/auth.svelte.js';
  import { reportingApi, type GeneralLedgerData } from '$lib/api/reporting.js';
  import { accountApi, type AccountOption } from '$lib/api/account.js';
  import { ApiError } from '$lib/api/client.js';
  import { formatRupiah } from '@akunta/ui';
  import ReportShell from '$lib/components/reporting/ReportShell.svelte';

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

  async function load() {
    if (!accountId) return;
    loading = true;
    error = null;
    try {
      const res = await reportingApi.generalLedger(accountId, periodStart, periodEnd);
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
    accounts = await accountApi.list();
    const queryAccount = $page.url.searchParams.get('account_id');
    accountId = queryAccount ?? accounts[0]?.id ?? '';
    if (accountId) await load();
  });
</script>

<ReportShell
  title="Buku Besar"
  breadcrumb="Laporan / Buku Besar"
  subtitle={report ? `${report.account.code} — ${report.account.name}` : null}
>
  {#snippet toolbar()}
    <label class="text-sm">
      <span class="block font-medium mb-1">Akun</span>
      <select
        class="rounded-md border border-border-default px-2 py-1.5"
        bind:value={accountId}
        data-testid="report-account"
      >
        {#each accounts as a (a.id)}
          <option value={a.id}>{a.code} — {a.name}</option>
        {/each}
      </select>
    </label>
    <label class="text-sm">
      <span class="block font-medium mb-1">Mulai</span>
      <input type="date" class="rounded-md border border-border-default px-2 py-1.5" bind:value={periodStart} />
    </label>
    <label class="text-sm">
      <span class="block font-medium mb-1">Sampai</span>
      <input type="date" class="rounded-md border border-border-default px-2 py-1.5" bind:value={periodEnd} />
    </label>
    <button
      type="button"
      class="rounded-md bg-[#0F172A] px-4 py-2 text-sm font-semibold text-white hover:bg-[#1E293B] disabled:opacity-50"
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
  {:else}
    <table class="w-full text-sm">
      <thead class="bg-page-bg text-xs uppercase tracking-wider text-text-muted">
        <tr>
          <th class="px-4 py-3 text-left">Tanggal</th>
          <th class="px-4 py-3 text-left">No. Jurnal</th>
          <th class="px-4 py-3 text-left">Keterangan</th>
          <th class="px-4 py-3 text-right">Debit</th>
          <th class="px-4 py-3 text-right">Kredit</th>
          <th class="px-4 py-3 text-right">Saldo</th>
        </tr>
      </thead>
      <tbody>
        <tr class="bg-page-bg/60 italic">
          <td class="px-4 py-2" colspan="5">Saldo Awal ({report.period_start})</td>
          <td class="px-4 py-2 text-right font-mono tabnum">{formatRupiah(report.opening)}</td>
        </tr>
        {#each report.lines as l (l.line_id)}
          <tr
            class="border-t border-border-soft hover:bg-page-bg cursor-pointer"
            onclick={() => goto(`/journals/${l.journal_id}`)}
          >
            <td class="px-4 py-2">{l.date}</td>
            <td class="px-4 py-2 font-mono">{l.number}</td>
            <td class="px-4 py-2">{l.line_memo ?? l.journal_memo ?? '—'}</td>
            <td class="px-4 py-2 text-right font-mono tabnum">{Number(l.debit) > 0 ? formatRupiah(l.debit) : '—'}</td>
            <td class="px-4 py-2 text-right font-mono tabnum">{Number(l.credit) > 0 ? formatRupiah(l.credit) : '—'}</td>
            <td class="px-4 py-2 text-right font-mono tabnum">{formatRupiah(l.balance)}</td>
          </tr>
        {:else}
          <tr><td colspan="6" class="px-4 py-10 text-center text-text-muted">Tidak ada transaksi pada rentang ini.</td></tr>
        {/each}
      </tbody>
      {#if report.lines.length > 0}
        <tfoot class="bg-page-bg font-semibold">
          <tr class="border-t-2 border-border-default">
            <td class="px-4 py-2" colspan="3">Total</td>
            <td class="px-4 py-2 text-right font-mono tabnum">{formatRupiah(report.total_debit)}</td>
            <td class="px-4 py-2 text-right font-mono tabnum">{formatRupiah(report.total_credit)}</td>
            <td class="px-4 py-2 text-right font-mono tabnum text-base">{formatRupiah(report.ending)}</td>
          </tr>
        </tfoot>
      {/if}
    </table>
  {/if}
</ReportShell>
