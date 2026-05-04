<script lang="ts">
  import { onMount } from 'svelte';
  import { goto } from '$app/navigation';
  import { auth } from '$lib/stores/auth.svelte.js';
  import { journalApi, type JournalSummary } from '$lib/api/journal.js';
  import { formatRupiah } from '@akunta/ui';

  let items = $state<JournalSummary[]>([]);
  let loading = $state(true);
  let total = $state(0);
  let error = $state<string | null>(null);

  onMount(async () => {
    if (!auth.user) {
      const u = await auth.refresh();
      if (!u) {
        goto('/login', { replaceState: true });
        return;
      }
    }
    try {
      const res = await journalApi.list({ per_page: 50 });
      items = res.data;
      total = res.meta.total;
    } catch (e) {
      error = e instanceof Error ? e.message : String(e);
    } finally {
      loading = false;
    }
  });

  function statusColor(s: JournalSummary['status']): string {
    return s === 'posted' ? 'bg-paid-light text-paid'
      : s === 'reversed' ? 'bg-warning-light text-warning'
      : 'bg-page-bg text-text-muted';
  }
</script>

<div class="px-6 py-6">
  <header class="mb-5 flex items-center justify-between">
    <div>
      <h1 class="text-2xl font-bold">Jurnal</h1>
      <p class="text-sm text-text-muted">{total} jurnal terdaftar</p>
    </div>
    <a
      href="/journals/new"
      class="rounded-md bg-[#0F172A] px-4 py-2 text-sm font-semibold text-white hover:bg-[#1E293B]"
      data-testid="create-journal"
    >
      + Buat Jurnal
    </a>
  </header>

  {#if loading}
    <div class="text-text-muted">Memuat…</div>
  {:else if error}
    <div class="rounded-md border border-danger bg-danger-light p-3 text-sm text-danger">{error}</div>
  {:else}
    <div class="overflow-x-auto rounded-lg border border-border-default bg-card-bg shadow-xs">
      <table class="w-full text-sm">
        <thead class="bg-page-bg text-xs uppercase tracking-wider text-text-muted">
          <tr>
            <th class="px-4 py-3 text-left">No. Jurnal</th>
            <th class="px-4 py-3 text-left">Tanggal</th>
            <th class="px-4 py-3 text-left">Keterangan</th>
            <th class="px-4 py-3 text-right">Total</th>
            <th class="px-4 py-3 text-left">Status</th>
          </tr>
        </thead>
        <tbody>
          {#each items as j (j.id)}
            <tr class="border-t border-border-soft hover:bg-page-bg cursor-pointer" onclick={() => goto(`/journals/${j.id}`)}>
              <td class="px-4 py-3 font-mono">{j.number}</td>
              <td class="px-4 py-3">{j.date}</td>
              <td class="px-4 py-3">{j.memo ?? '—'}</td>
              <td class="px-4 py-3 text-right font-mono tabnum">{formatRupiah(j.total)}</td>
              <td class="px-4 py-3">
                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold {statusColor(j.status)}">
                  {j.status}
                </span>
              </td>
            </tr>
          {:else}
            <tr><td colspan="5" class="px-4 py-10 text-center text-text-muted">Belum ada jurnal.</td></tr>
          {/each}
        </tbody>
      </table>
    </div>
  {/if}
</div>
