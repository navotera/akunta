<script lang="ts">
  import { onMount } from 'svelte';
  import { goto } from '$app/navigation';
  import { auth } from '$lib/stores/auth.svelte.js';
  import { journalApi, type JournalSummary } from '$lib/api/journal.js';
  import { formatRupiah } from '@akunta/ui';
  import { formatDate } from '$lib/utils/date.js';

  let items = $state<JournalSummary[]>([]);
  let loading = $state(true);
  let total = $state(0);
  let statusCounts = $state<Record<JournalStatusTab, number>>({
    draft: 0,
    submitted: 0,
    posted: 0,
    rejected: 0,
  });
  let error = $state<string | null>(null);
  let journalMode = $state<'internal' | 'fiscal'>('internal');
  type JournalStatusTab = 'draft' | 'submitted' | 'posted' | 'rejected';
  let statusTab = $state<JournalStatusTab>('draft');
  const statusTabs: { value: JournalStatusTab; label: string }[] = [
    { value: 'draft', label: 'Draft' },
    { value: 'submitted', label: 'In Review' },
    { value: 'posted', label: 'Saved' },
    { value: 'rejected', label: 'Need Revision' },
  ];
  let isInspector = $derived(
    auth.user?.roles.some((role) => role.toLowerCase() === 'inspector') ?? false,
  );

  async function load() {
    loading = true;
    error = null;
    try {
      const [res, ...countResponses] = await Promise.all([
        journalApi.list({ per_page: 50, journal_mode: journalMode, status: statusTab }),
        ...statusTabs.map((tab) =>
          journalApi.list({ per_page: 5, journal_mode: journalMode, status: tab.value }),
        ),
      ]);
      items = res.data;
      total = res.meta.total;
      statusCounts = Object.fromEntries(
        statusTabs.map((tab, index) => [tab.value, countResponses[index].meta.total]),
      ) as Record<JournalStatusTab, number>;
    } catch (e) {
      error = e instanceof Error ? e.message : String(e);
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

  function statusColor(s: JournalSummary['status']): string {
    return s === 'posted'
      ? 'bg-paid-light text-paid'
      : s === 'rejected'
        ? 'bg-danger-light text-danger'
        : s === 'submitted'
          ? 'bg-warning-light text-warning'
          : 'bg-info-light text-info';
  }

  function statusLabel(s: JournalSummary['status']): string {
    return statusTabs.find((tab) => tab.value === s)?.label ?? s;
  }
</script>

<div class="px-6 py-6">
  <header class="mb-5 flex items-center justify-between">
    <div>
      <h1 class="text-2xl font-bold">Jurnal</h1>
      <p class="text-sm text-text-muted">{total} jurnal terdaftar</p>
    </div>
    <div class="flex items-center gap-3">
      {#if !isInspector}
        <div
          class="inline-flex items-center gap-1 rounded-full border border-border-default bg-card-bg p-1 text-sm shadow-xs"
          role="group"
          aria-label="Mode jurnal"
        >
          <button
            type="button"
            class="rounded-full px-3 py-1 font-medium {journalMode === 'internal'
              ? 'bg-[#22c55e] text-white'
              : 'text-text-muted'}"
            onclick={() => {
              journalMode = 'internal';
              void load();
            }}>Intern</button
          >
          <button
            type="button"
            class="rounded-full px-3 py-1 font-medium {journalMode === 'fiscal'
              ? 'bg-[#facc15] text-[#5a4300]'
              : 'text-text-muted'}"
            onclick={() => {
              journalMode = 'fiscal';
              void load();
            }}>Fiskal</button
          >
        </div>
        <a
          href="/journals/new"
          class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-active"
          data-testid="create-journal">+ Buat Jurnal</a
        >
      {/if}
    </div>
  </header>

  <nav
    class="mb-4 flex gap-1 overflow-x-auto rounded-lg border border-border-default bg-card-bg p-1"
  >
    {#each statusTabs as tab}
      <button
        type="button"
        class="whitespace-nowrap rounded-md px-4 py-2 text-sm font-semibold {statusTab === tab.value
          ? 'text-primary'
          : 'text-text-muted hover:text-primary'}"
        aria-current={statusTab === tab.value ? 'page' : undefined}
        onclick={() => {
          statusTab = tab.value;
          void load();
        }}
      >
        {tab.label}
        {#if statusCounts[tab.value] > 0}
          <span
            class="ml-1 inline-flex h-5 min-w-5 items-center justify-center rounded-full border border-border-soft bg-page-bg px-1.5 text-[11px] font-semibold leading-none text-text-muted"
            >{statusCounts[tab.value]}</span
          >
        {/if}
      </button>
    {/each}
  </nav>

  {#if loading}
    <div class="text-text-muted">Memuat…</div>
  {:else if error}
    <div class="rounded-md border border-danger bg-danger-light p-3 text-sm text-danger">
      {error}
    </div>
  {:else}
    <div class="overflow-x-auto rounded-lg border border-border-default bg-card-bg shadow-xs">
      <table class="w-full text-sm">
        <thead class="bg-page-bg text-xs uppercase tracking-wider text-text-muted">
          <tr>
            <th class="px-4 py-3 text-left">Kode Jurnal</th>
            <th class="px-4 py-3 text-left">No. Bukti</th>
            <th class="px-4 py-3 text-left">Mode</th>
            <th class="px-4 py-3 text-left">Tanggal</th>
            <th class="px-4 py-3 text-left">Keterangan</th>
            <th class="px-4 py-3 text-right">Total</th>
            <th class="px-4 py-3 text-left">Status</th>
          </tr>
        </thead>
        <tbody>
          {#each items as j (j.id)}
            <tr
              class="border-t border-border-soft hover:bg-page-bg cursor-pointer"
              onclick={() => goto(`/journals/${j.id}`)}
            >
              <td class="px-4 py-3 font-mono">{j.number}</td>
              <td class="px-4 py-3">{j.reference ?? '—'}</td>
              <td class="px-4 py-3">
                <span
                  class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold {j.journal_mode ===
                  'fiscal'
                    ? 'bg-[#fff0b8] text-[#8a5a00]'
                    : 'bg-paid-light text-paid'}"
                >
                  {j.journal_mode === 'fiscal' ? 'Fiskal' : 'Intern'}
                </span>
              </td>
              <td class="px-4 py-3">{formatDate(j.date)}</td>
              <td class="px-4 py-3">{j.memo ?? '—'}</td>
              <td class="px-4 py-3 text-right font-mono tabnum">{formatRupiah(j.total)}</td>
              <td class="px-4 py-3">
                <span
                  class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold {statusColor(
                    j.status,
                  )}"
                >
                  {statusLabel(j.status)}
                </span>
              </td>
            </tr>
          {:else}
            <tr
              ><td colspan="7" class="px-4 py-10 text-center text-text-muted">Belum ada jurnal.</td
              ></tr
            >
          {/each}
        </tbody>
      </table>
    </div>
  {/if}
</div>
