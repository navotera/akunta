<script lang="ts">
  import { onMount } from 'svelte';
  import { goto } from '$app/navigation';
  import { auth } from '$lib/stores/auth.svelte.js';
  import { reportingApi, type BalanceSheetData } from '$lib/api/reporting.js';
  import ReportShell from '$lib/components/reporting/ReportShell.svelte';
  import DateInput from '$lib/components/ui/DateInput.svelte';
  import BalanceSheetTable from '$lib/components/reporting/BalanceSheetTable.svelte';
  import { formatDate } from '$lib/utils/date.js';
  import BookToggle, { type BookToggleValue } from '$lib/components/reporting/BookToggle.svelte';

  let asOf = $state(new Date().toISOString().slice(0, 10));
  let report = $state<BalanceSheetData | null>(null);
  let journalMode = $state<BookToggleValue>('internal');
  let isInspector = $derived(
    auth.user?.roles.some((role) => role.toLowerCase() === 'inspector') ?? false,
  );
  let loading = $state(false);
  let error = $state<string | null>(null);

  async function load() {
    loading = true;
    error = null;
    try {
      const res = await reportingApi.balanceSheet(asOf, undefined, false, journalMode);
      report = res.data;
    } catch (e) {
      error = (e as Error).message;
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
</script>

<ReportShell
  title="Neraca"
  breadcrumb="Laporan / Neraca"
  subtitle={report ? `Per ${formatDate(report.as_of)}` : null}
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
    <div class="p-5">
      <BalanceSheetTable
        {report}
        fiscal={journalMode === 'both' ? report.fiscal : undefined}
        primaryBook={journalMode === 'fiscal' ? 'fiscal' : 'internal'}
      />
    </div>
  {/if}
</ReportShell>
