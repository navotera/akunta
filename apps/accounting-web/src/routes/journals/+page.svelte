<script lang="ts">
  import { onMount } from 'svelte';
  import { goto } from '$app/navigation';
  import { page } from '$app/stores';
  import { auth } from '$lib/stores/auth.svelte.js';
  import { isFiscalInspector } from '$lib/stores/permissions.svelte.js';
  import { can, activeRoles, canManagePermissions } from '$lib/stores/permissions.svelte.js';
  import {
    journalApi,
    type JournalSummary,
    type AccountDeletionContext,
    type AccountReplacementPreview,
  } from '$lib/api/journal.js';
  import { accountApi, type AccountOption } from '$lib/api/account.js';
  import { periodApi, type Period } from '$lib/api/period.js';
  import Combobox, { type ComboOption } from '$lib/components/ui/Combobox.svelte';
  import AccountCombobox from '$lib/components/ui/AccountCombobox.svelte';
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
    reversed: 0,
  });
  let currentPage = $state(1);
  let lastPage = $state(1);
  let accountDeletion = $state<AccountDeletionContext | null>(null);
  const deletionAccountId = $derived($page.url.searchParams.get('delete_account_id'));
  let trashedCount = $state(0);
  let restoringId = $state<string | null>(null);
  let error = $state<string | null>(null);
  let periods = $state<Period[]>([]);
  let accounts = $state<AccountOption[]>([]);
  let filtersOpen = $state(false);
  let isInspector = $derived(isFiscalInspector());
  let selectedJournals = $state<JournalSummary[]>([]);
  let batchDialog = $state<HTMLDialogElement>();
  let replacementAccountId = $state('');
  let batchPreview = $state<AccountReplacementPreview | null>(null);
  let batchBusy = $state(false);
  let batchError = $state<string | null>(null);
  let batchMessage = $state<string | null>(null);
  const selectedIds = $derived(new Set(selectedJournals.map((journal) => journal.id)));
  const isSupervisor = $derived(
    canManagePermissions() ||
      activeRoles().some((role) =>
        ['admin', 'super_admin', 'supervisor'].includes(role.toLowerCase()),
      ),
  );
  const canBatchReplace = $derived(
    Boolean(
      deletionAccountId &&
      accountDeletion &&
      can('journal.update') &&
      can('account.read') &&
      !isInspector,
    ),
  );
  const selectablePage = $derived(items.filter((journal) => !batchSelectionReason(journal)));
  const unavailableOnPage = $derived(items.length - selectablePage.length);
  const allPageSelected = $derived(
    selectablePage.length > 0 && selectablePage.every((journal) => selectedIds.has(journal.id)),
  );
  const replacementAccounts = $derived(
    accounts.filter(
      (account) =>
        account.id !== deletionAccountId &&
        account.is_active &&
        account.is_postable &&
        selectedJournals.every(
          (journal) =>
            account.availability === 'both' ||
            account.availability === (journal.journal_mode === 'fiscal' ? 'fiskal' : 'intern'),
        ),
    ),
  );
  type JournalFilters = {
    period_id: string;
    account_id: string;
    type: string;
    date_from: string;
    date_to: string;
    search: string;
  };
  const emptyFilters = (): JournalFilters => ({
    period_id: '',
    account_id: '',
    type: '',
    date_from: '',
    date_to: '',
    search: '',
  });
  let appliedFilters = $state<JournalFilters>(emptyFilters());
  let draftFilters = $state<JournalFilters>(emptyFilters());
  let journalMode = $state<'internal' | 'fiscal' | 'all'>('internal');
  const activeFilterCount = $derived(Object.values(appliedFilters).filter(Boolean).length);
  const periodOptions = $derived<ComboOption[]>(
    periods.map((period) => ({
      id: period.id,
      label: period.name,
      sublabel: `${period.start_date} – ${period.end_date}`,
      trailing: period.journals_count === undefined ? null : `(${period.journals_count})`,
    })),
  );
  type JournalStatusTab = 'draft' | 'submitted' | 'posted' | 'rejected' | 'reversed';
  type JournalListTab = JournalStatusTab | 'trashed' | 'all';
  let statusTab = $state<JournalListTab>('draft');
  const statusTabs = $derived<{ value: JournalListTab; label: string }[]>([
    ...(deletionAccountId ? [{ value: 'all' as const, label: 'Semua status' }] : []),
    { value: 'draft', label: 'Draft' },
    { value: 'submitted', label: 'In Review' },
    { value: 'rejected', label: 'Need Revision' },
    { value: 'posted', label: 'Saved' },
    ...(deletionAccountId ? [{ value: 'reversed' as const, label: 'Dibalik' }] : []),
    { value: 'trashed', label: 'Trashed' },
  ]);
  const filteredJournalCount = $derived(
    Object.values(statusCounts).reduce((sum, count) => sum + count, 0) + trashedCount,
  );
  async function load(pageNumber = 1, keepSelection = false) {
    if (!keepSelection) selectedJournals = [];
    loading = true;
    error = null;
    try {
      const status = statusTab === 'all' ? undefined : statusTab;
      const res = await journalApi.list({
        per_page: 50,
        page: pageNumber,
        journal_mode: journalMode === 'all' ? undefined : journalMode,
        status,
        deletion_account_id: deletionAccountId || undefined,
        period_id: appliedFilters.period_id || undefined,
        account_id: appliedFilters.account_id || undefined,
        type: appliedFilters.type || undefined,
        date_from: appliedFilters.date_from || undefined,
        date_to: appliedFilters.date_to || undefined,
        search: appliedFilters.search || undefined,
      });
      items = res.data;
      total = res.meta.total;
      currentPage = res.meta.current_page;
      lastPage = res.meta.last_page;
      accountDeletion = res.meta.account_deletion ?? null;
      statusCounts = {
        draft: res.meta.status_counts?.draft ?? 0,
        submitted: res.meta.status_counts?.submitted ?? 0,
        posted: res.meta.status_counts?.posted ?? 0,
        rejected: res.meta.status_counts?.rejected ?? 0,
        reversed: res.meta.status_counts?.reversed ?? 0,
      };
      trashedCount = res.meta.trashed_count ?? 0;
    } catch (e) {
      error = e instanceof Error ? e.message : String(e);
    } finally {
      loading = false;
    }
  }

  async function loadFilterOptions() {
    const mode = journalMode === 'all' ? undefined : journalMode;
    periods = await periodApi.list(undefined, undefined, mode).catch(() => []);
    accounts = await accountApi.list('', undefined, true, mode, false).catch(() => []);
  }

  function applyFilters() {
    appliedFilters = { ...draftFilters };
    filtersOpen = false;
    void load();
  }

  function clearFilters() {
    draftFilters = emptyFilters();
    appliedFilters = emptyFilters();
    if (deletionAccountId) {
      draftFilters.account_id = deletionAccountId;
      appliedFilters.account_id = deletionAccountId;
    }
    void load();
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
    if (deletionAccountId) {
      journalMode = isInspector ? 'fiscal' : 'all';
      statusTab = 'all';
      appliedFilters.account_id = deletionAccountId;
      draftFilters.account_id = deletionAccountId;
    }
    await Promise.all([load(), loadFilterOptions()]);
  });

  function statusColor(s: JournalSummary['status']): string {
    return s === 'posted'
      ? 'bg-paid-light text-paid'
      : s === 'rejected'
        ? 'bg-danger-light text-danger'
        : s === 'submitted'
          ? 'bg-warning-light text-warning'
          : s === 'draft'
            ? 'bg-[#f1df9a] text-[#b38c00]'
            : 'bg-info-light text-info';
  }

  function statusLabel(s: JournalSummary['status']): string {
    return statusTabs.find((tab) => tab.value === s)?.label ?? s;
  }

  async function restoreJournal(journal: JournalSummary) {
    restoringId = journal.id;
    error = null;
    try {
      await journalApi.restore(journal.id);
      await load();
    } catch (e) {
      error = e instanceof Error ? e.message : String(e);
    } finally {
      restoringId = null;
    }
  }

  function journalUrl(id: string): string {
    return `/journals/${id}${deletionAccountId ? `?delete_account_id=${encodeURIComponent(deletionAccountId)}` : ''}`;
  }

  function batchSelectionReason(journal: JournalSummary): string {
    if (!canBatchReplace) return 'Anda tidak memiliki izin penggantian akun jurnal.';
    if (journal.deleted_at) return 'Pulihkan jurnal dari Trashed terlebih dahulu.';
    if (journal.status === 'reversed') return 'Jurnal Dibalik tidak dapat diubah.';
    const period = periods.find((period) => period.id === journal.period_id);
    if (!period) return 'Informasi periode belum tersedia. Muat ulang daftar jurnal.';
    if (period.status !== 'open')
      return period.status === 'closed'
        ? `Periode ${period.name} sudah ditutup.`
        : `Periode ${period.name} nonaktif. Aktifkan kembali sebelum mengubah jurnal.`;
    if (journal.status === 'posted' && !isSupervisor)
      return 'Jurnal Tersimpan memerlukan Supervisor/admin.';
    if (journal.status === 'submitted' && !can('journal.review'))
      return 'Jurnal Di review memerlukan izin review dan edit.';
    return '';
  }

  function selectJournal(journal: JournalSummary, checked: boolean) {
    batchMessage = null;
    if (!checked) {
      selectedJournals = selectedJournals.filter((item) => item.id !== journal.id);
    } else if (!selectedIds.has(journal.id) && !batchSelectionReason(journal)) {
      if (selectedJournals.length >= 100) {
        batchMessage =
          'Maksimal 100 jurnal per batch. Simpan pilihan ini sebelum melanjutkan jurnal berikutnya.';
        return;
      }
      selectedJournals = [...selectedJournals, journal];
    }
  }

  function batchDisabledReason(journal: JournalSummary): string {
    return (
      batchSelectionReason(journal) ||
      (!selectedIds.has(journal.id) && selectedJournals.length >= 100
        ? 'Batas 100 jurnal per batch telah tercapai. Kurangi pilihan atau selesaikan batch ini terlebih dahulu.'
        : '')
    );
  }

  function selectPage(checked: boolean) {
    for (const journal of selectablePage) selectJournal(journal, checked);
  }

  function openBatchDialog() {
    if (!canBatchReplace || selectedJournals.length === 0) return;
    replacementAccountId = '';
    batchPreview = null;
    batchError = null;
    batchDialog?.showModal();
  }

  function batchInput() {
    return {
      source_account_id: deletionAccountId!,
      replacement_account_id: replacementAccountId,
      journal_ids: selectedJournals.map((journal) => journal.id),
    };
  }

  async function previewBatch() {
    if (batchBusy || !replacementAccountId) return;
    batchBusy = true;
    batchError = null;
    batchPreview = null;
    try {
      batchPreview = await journalApi.previewAccountReplacement(batchInput());
    } catch (error) {
      batchError = error instanceof Error ? error.message : String(error);
    } finally {
      batchBusy = false;
    }
  }

  async function saveBatch() {
    if (batchBusy || !batchPreview) return;
    batchBusy = true;
    batchError = null;
    try {
      const result = await journalApi.replaceAccountsBatch({
        ...batchInput(),
        preview_token: batchPreview.preview_token,
      });
      batchMessage = `${result.entries_count} baris pada ${result.journal_count} jurnal berhasil diganti ke akun ${result.replacement_account.code} — ${result.replacement_account.name}. Perubahan tercatat di audit trail.`;
      batchDialog?.close();
      batchPreview = null;
      await load();
    } catch (error) {
      batchError = error instanceof Error ? error.message : String(error);
      batchPreview = null;
    } finally {
      batchBusy = false;
    }
  }
</script>

<div class="px-6 py-6">
  <header class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
    <div>
      <h1 class="text-2xl font-bold">Jurnal</h1>
      <p class="text-sm text-text-muted">{total} jurnal terdaftar</p>
    </div>
    <div class="flex flex-wrap items-center gap-2 sm:gap-3">
      {#if !isInspector}
        <div
          class="inline-flex items-center gap-1 rounded-full border border-border-default bg-card-bg p-1 text-sm shadow-xs"
          role="group"
          aria-label="Mode jurnal"
        >
          {#if deletionAccountId}
            <button
              type="button"
              class="rounded-full px-3 py-1 font-medium {journalMode === 'all'
                ? 'bg-primary text-white'
                : 'text-text-muted'}"
              onclick={() => {
                journalMode = 'all';
                void loadFilterOptions();
                void load();
              }}>Semua buku</button
            >
          {/if}
          <button
            type="button"
            class="rounded-full px-3 py-1 font-medium {journalMode === 'internal'
              ? 'bg-[#22c55e] text-white'
              : 'text-text-muted'}"
            onclick={() => {
              journalMode = 'internal';
              void loadFilterOptions();
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
              void loadFilterOptions();
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
      <button
        type="button"
        class="relative inline-flex items-center gap-2 rounded-md border px-3 py-2 text-sm font-semibold shadow-xs transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary {filtersOpen ||
        activeFilterCount > 0
          ? 'border-primary/40 bg-primary-light text-primary'
          : 'border-border-default bg-card-bg text-text-default hover:bg-page-bg'}"
        aria-label={activeFilterCount > 0 ? `Filter, ${activeFilterCount} aktif` : 'Filter jurnal'}
        aria-expanded={filtersOpen}
        aria-controls="journal-filter-panel"
        onclick={() => {
          draftFilters = { ...appliedFilters };
          filtersOpen = !filtersOpen;
        }}
        data-testid="journal-filter-button"
      >
        <svg
          aria-hidden="true"
          viewBox="0 0 20 20"
          fill="none"
          class="h-4 w-4"
          stroke="currentColor"
          stroke-width="1.7"
          stroke-linecap="round"
          stroke-linejoin="round"
        >
          <path d="M3 4.5h14l-5.5 6v4.2l-3 1.3v-5.5L3 4.5Z" />
        </svg>
        Filter
        {#if activeFilterCount > 0}
          <span
            class="absolute -right-1 -top-1 h-2.5 w-2.5 rounded-full border-2 border-card-bg bg-danger"
            aria-hidden="true"
            data-testid="journal-filter-active-dot"
          ></span>
        {/if}
      </button>
    </div>
  </header>

  {#if deletionAccountId && accountDeletion}
    <div
      class="mb-4 rounded-lg border border-border-default bg-page-bg p-4 text-sm text-text-default"
      role="status"
      data-testid="account-deletion-guidance"
    >
      <p class="font-semibold">
        Penghapusan akun {accountDeletion.account.code} — {accountDeletion.account.name}
      </p>
      {#if accountDeletion.journal_count > 0}
        <p class="mt-1">
          Terdapat {accountDeletion.journal_count} jurnal yang menggunakan akun ini. Anda dapat mengganti
          akun pada jurnal yang masih boleh diedit, atau menghapus akun secara soft delete melalui Bagan
          Akun tanpa mengubah jurnal lama.
        </p>
        <p class="mt-2 text-xs text-text-muted">
          {accountDeletion.fiscal_only
            ? 'Jumlah hanya mencakup buku Fiskal sesuai hak akses Anda.'
            : 'Jumlah mencakup buku Intern dan Fiskal serta seluruh status jurnal.'} Gunakan tab status
          dan halaman berikutnya untuk meninjau seluruh jurnal. Perubahan jurnal tetap mengikuti izin
          pengguna dan aturan periode. Periode nonaktif atau ditutup harus diaktifkan kembali sebelum
          edit.
        </p>
        {#if accountDeletion.trashed_count > 0}
          <p class="mt-2 text-xs text-text-muted">
            Termasuk {accountDeletion.trashed_count} jurnal di Trashed. Pulihkan jurnal tersebut sebagai
            draft sebelum mengganti akunnya; memindahkan jurnal ke Trashed belum menghapus referensi akun.
          </p>
        {/if}
      {:else}
        <p class="mt-1">
          Tidak ada lagi jurnal{accountDeletion.fiscal_only ? ' Fiskal' : ''} yang menggunakan akun ini.
          Kembali ke Bagan Akun untuk melakukan soft delete. Riwayat jurnal dan laporan tetap tersimpan.
        </p>
      {/if}
      {#if accountDeletion.other_blockers.length > 0}
        <p class="mt-2 text-xs text-text-muted">
          Referensi lain yang tetap tersimpan: {accountDeletion.other_blockers
            .map((blocker) => `${blocker.count} ${blocker.label}`)
            .join(', ')}.
        </p>
      {/if}
      {#if accountDeletion.is_system}<p class="mt-2 text-xs text-text-muted">
          Akun wajib sistem tidak dapat dihapus.
        </p>{/if}
      <a
        href={`/akun?review_account_id=${encodeURIComponent(accountDeletion.account.id)}`}
        class="mt-3 inline-flex rounded-md border border-border-default bg-card-bg px-3 py-2 font-semibold text-primary hover:bg-primary-light focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
        >Kembali ke Bagan Akun</a
      >
    </div>
  {/if}

  <nav
    class="mb-4 flex gap-1 overflow-x-auto rounded-lg border border-border-default bg-card-bg p-1"
  >
    {#each statusTabs as tab}
      <button
        type="button"
        class="whitespace-nowrap rounded-md px-4 py-2 text-sm font-semibold {tab.value === 'trashed'
          ? 'ml-auto'
          : ''} {statusTab === tab.value ? 'text-primary' : 'text-text-muted hover:text-primary'}"
        aria-current={statusTab === tab.value ? 'page' : undefined}
        onclick={() => {
          statusTab = tab.value;
          void load();
        }}
      >
        {tab.label}
        {#if (tab.value === 'all' ? filteredJournalCount : tab.value === 'trashed' ? trashedCount : statusCounts[tab.value]) > 0}
          <span
            class="ml-1 inline-flex h-5 min-w-5 items-center justify-center rounded-full border border-border-soft bg-page-bg px-1.5 text-[11px] font-semibold leading-none text-text-muted"
            >{tab.value === 'all'
              ? filteredJournalCount
              : tab.value === 'trashed'
                ? trashedCount
                : statusCounts[tab.value]}</span
          >
        {/if}
      </button>
    {/each}
  </nav>

  {#if canBatchReplace && accountDeletion && accountDeletion.journal_count > 0}
    <div
      class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-md border border-border-default bg-card-bg p-3 text-sm"
      data-testid="batch-account-toolbar"
    >
      <div class="text-text-muted">
        <p>
          {selectedJournals.length} jurnal dipilih. Pilihan dapat dilanjutkan di halaman berikutnya, maksimal
          100 jurnal per batch.
        </p>
        {#if unavailableOnPage > 0}<p class="mt-1 text-xs" data-testid="batch-unavailable-summary">
            {unavailableOnPage} jurnal di halaman ini tidak dapat dipilih. Alasan tercantum di bawah kode
            setiap jurnal.
          </p>{/if}
      </div>
      <button
        type="button"
        class="rounded-md bg-primary px-3 py-2 font-semibold text-white hover:bg-primary-active disabled:opacity-50"
        disabled={selectedJournals.length === 0 || loading}
        onclick={openBatchDialog}
        data-testid="batch-account-open">Ganti Akun Sekaligus</button
      >
    </div>
  {/if}
  {#if batchMessage}
    <p
      class="mb-4 rounded-md border border-border-default bg-page-bg p-3 text-sm text-text-default"
      role="status"
      data-testid="batch-account-result"
    >
      {batchMessage}
    </p>
  {/if}

  {#if filtersOpen}
    <section
      id="journal-filter-panel"
      class="mb-4 rounded-lg border border-border-default bg-card-bg p-4 shadow-xs"
      aria-label="Filter daftar jurnal"
      data-testid="journal-filter-panel"
    >
      <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        <label class="block text-xs font-semibold text-text-muted">
          Periode
          <div class="mt-1.5">
            <Combobox
              options={periodOptions}
              value={draftFilters.period_id}
              onSelect={(id) => (draftFilters.period_id = id)}
              placeholder="Semua periode"
              emptyText="Periode tidak ditemukan."
              testId="journal-filter-period"
            />
          </div>
        </label>

        <label class="block text-xs font-semibold text-text-muted">
          Akun
          <select
            bind:value={draftFilters.account_id}
            disabled={Boolean(deletionAccountId)}
            class="mt-1.5 block w-full rounded-md border border-border-default bg-card-bg px-3 py-2 text-sm font-normal text-text-default focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
            data-testid="journal-filter-account"
          >
            <option value="">Semua akun</option>
            {#each accounts as account (account.id)}
              <option value={account.id}>{account.code} – {account.name}</option>
            {/each}
          </select>
        </label>

        <label class="block text-xs font-semibold text-text-muted">
          Jenis jurnal
          <select
            bind:value={draftFilters.type}
            class="mt-1.5 block w-full rounded-md border border-border-default bg-card-bg px-3 py-2 text-sm font-normal text-text-default focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
            data-testid="journal-filter-type"
          >
            <option value="">Semua jenis</option>
            <option value="general">Umum</option>
            <option value="adjustment">Penyesuaian</option>
            <option value="reversing">Pembalik</option>
            <option value="closing">Penutup</option>
            <option value="opening">Pembukaan</option>
          </select>
        </label>

        <label class="block text-xs font-semibold text-text-muted">
          Tanggal mulai
          <input
            type="date"
            bind:value={draftFilters.date_from}
            class="mt-1.5 block w-full rounded-md border border-border-default bg-card-bg px-3 py-2 text-sm font-normal text-text-default focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
            data-testid="journal-filter-date-from"
          />
        </label>

        <label class="block text-xs font-semibold text-text-muted">
          Tanggal akhir
          <input
            type="date"
            bind:value={draftFilters.date_to}
            class="mt-1.5 block w-full rounded-md border border-border-default bg-card-bg px-3 py-2 text-sm font-normal text-text-default focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
            data-testid="journal-filter-date-to"
          />
        </label>

        <label class="block text-xs font-semibold text-text-muted">
          Kata kunci
          <input
            type="search"
            bind:value={draftFilters.search}
            placeholder="Nomor, kode, bukti, atau keterangan"
            class="mt-1.5 block w-full rounded-md border border-border-default bg-card-bg px-3 py-2 text-sm font-normal text-text-default placeholder:text-text-muted focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
            data-testid="journal-filter-search"
          />
        </label>
      </div>
      <div class="mt-4 flex flex-wrap justify-end gap-2 border-t border-border-soft pt-3">
        <button
          type="button"
          class="rounded-md px-3 py-2 text-sm font-semibold text-text-muted hover:bg-page-bg hover:text-text-default focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
          onclick={clearFilters}
          data-testid="journal-filter-clear"
        >
          Hapus filter
        </button>
        <button
          type="button"
          class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-active focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2"
          onclick={applyFilters}
          data-testid="journal-filter-apply"
        >
          Terapkan filter
        </button>
      </div>
    </section>
  {/if}

  {#if loading}
    <div class="text-text-muted">Memuat…</div>
  {:else if error}
    <div class="rounded-md border border-danger bg-danger-light p-3 text-sm text-danger">
      {error}
    </div>
  {:else}
    {#if statusTab === 'trashed'}
      <div
        class="mb-4 rounded-lg border border-warning bg-card-bg px-4 py-3 text-sm text-text-default"
        role="alert"
      >
        Jurnal yang masuk status Trashed akan dihapus per 30 hari
      </div>
    {/if}
    <div class="overflow-x-auto rounded-lg border border-border-default bg-card-bg shadow-xs">
      <table class="w-full text-sm">
        <thead class="bg-page-bg text-xs uppercase tracking-wider text-text-muted">
          <tr>
            {#if canBatchReplace}
              <th class="w-12 px-3 py-3 text-left"
                ><input
                  type="checkbox"
                  checked={allPageSelected}
                  disabled={selectablePage.length === 0}
                  onchange={(event) => selectPage(event.currentTarget.checked)}
                  aria-label="Pilih semua jurnal yang dapat diedit di halaman ini"
                  data-testid="batch-select-page"
                /></th
              >
            {/if}
            <th class="px-4 py-3 text-left">Kode Jurnal</th>
            <th class="px-4 py-3 text-left">No. Bukti</th>
            <th class="px-4 py-3 text-left">Mode</th>
            <th class="px-4 py-3 text-left">Tanggal</th>
            <th class="px-4 py-3 text-left">Keterangan</th>
            <th class="px-4 py-3 text-right">Total</th>
            <th class="px-4 py-3 text-left">Status</th>
            <th class="px-4 py-3 text-left">Aksi</th>
          </tr>
        </thead>
        <tbody>
          {#each items as j (j.id)}
            {@const selectionReason = canBatchReplace ? batchDisabledReason(j) : ''}
            <tr
              class="border-t border-border-soft hover:bg-page-bg {j.deleted_at
                ? ''
                : 'cursor-pointer'}"
              onclick={() => !j.deleted_at && goto(journalUrl(j.id))}
            >
              {#if canBatchReplace}
                <td class="px-3 py-3">
                  <input
                    type="checkbox"
                    checked={selectedIds.has(j.id)}
                    disabled={Boolean(selectionReason)}
                    title={selectionReason || 'Pilih jurnal untuk penggantian akun'}
                    aria-label={`Pilih jurnal ${j.number}`}
                    aria-describedby={selectionReason ? `batch-reason-${j.id}` : undefined}
                    onclick={(event) => event.stopPropagation()}
                    onchange={(event) => selectJournal(j, event.currentTarget.checked)}
                    data-testid={`batch-select-${j.id}`}
                  />
                </td>
              {/if}
              <td class="px-4 py-3 {canBatchReplace ? 'min-w-56' : ''}"
                ><span class="font-mono">{j.number}</span>
                {#if selectionReason}<p
                    id={`batch-reason-${j.id}`}
                    class="mt-1 max-w-64 whitespace-normal text-xs leading-5 text-text-muted"
                    data-testid={`batch-reason-${j.id}`}
                  >
                    Tidak dapat dipilih: {selectionReason}
                  </p>{/if}
              </td>
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
                  class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold {j.deleted_at
                    ? 'bg-danger-light text-danger'
                    : statusColor(j.status)}"
                >
                  {j.deleted_at ? 'Trashed' : statusLabel(j.status)}
                </span>
              </td>
              <td class="px-4 py-3">
                {#if j.deleted_at}
                  <button
                    type="button"
                    class="rounded-md border border-primary/40 px-3 py-1.5 text-xs font-semibold text-primary hover:bg-primary-light disabled:cursor-not-allowed disabled:opacity-50"
                    disabled={restoringId === j.id || !can('journal.delete')}
                    data-testid="restore-journal-{j.id}"
                    onclick={(event) => {
                      event.stopPropagation();
                      void restoreJournal(j);
                    }}
                  >
                    {restoringId === j.id ? 'Memulihkan…' : 'Restore as Draft'}
                  </button>
                {:else if deletionAccountId}
                  <a
                    href={journalUrl(j.id)}
                    class="font-semibold text-primary hover:underline"
                    onclick={(event) => event.stopPropagation()}>Buka jurnal</a
                  >
                {:else}
                  <span class="text-text-muted">—</span>
                {/if}
              </td>
            </tr>
          {:else}
            <tr
              ><td colspan={canBatchReplace ? 9 : 8} class="px-4 py-10 text-center text-text-muted"
                >{statusTab === 'trashed'
                  ? 'Belum ada jurnal di Trashed.'
                  : 'Belum ada jurnal.'}</td
              ></tr
            >
          {/each}
        </tbody>
      </table>
    </div>
    {#if lastPage > 1}
      <div class="mt-4 flex items-center justify-between gap-3 text-sm">
        <span class="text-text-muted">Halaman {currentPage} dari {lastPage} · {total} jurnal</span>
        <div class="flex gap-2">
          <button
            type="button"
            class="rounded-md border border-border-default px-3 py-2 disabled:opacity-50"
            disabled={currentPage <= 1 || loading}
            onclick={() => void load(currentPage - 1, true)}>Sebelumnya</button
          >
          <button
            type="button"
            class="rounded-md border border-border-default px-3 py-2 disabled:opacity-50"
            disabled={currentPage >= lastPage || loading}
            onclick={() => void load(currentPage + 1, true)}>Berikutnya</button
          >
        </div>
      </div>
    {/if}
  {/if}

  {#if canBatchReplace && accountDeletion}
    <dialog
      bind:this={batchDialog}
      class="w-[90vw] max-w-2xl max-h-[85vh] overflow-y-auto rounded-lg border border-border-default bg-card-bg p-0 text-text-default backdrop:bg-black/40"
      aria-labelledby="batch-account-title"
      oncancel={(event) => {
        if (batchBusy) event.preventDefault();
      }}
      data-testid="batch-account-dialog"
    >
      <div class="flex items-start justify-between gap-3 border-b border-border-soft p-4">
        <div>
          <h2 id="batch-account-title" class="text-lg font-bold">Ganti Akun Sekaligus</h2>
          <p class="mt-1 text-sm text-text-muted">
            Akun sumber: {accountDeletion.account.code} — {accountDeletion.account.name}
          </p>
        </div>
        <button
          type="button"
          class="rounded-md p-2 hover:bg-page-bg disabled:opacity-50"
          disabled={batchBusy}
          onclick={() => batchDialog?.close()}
          aria-label="Tutup penggantian akun"
          ><svg
            class="h-4 w-4"
            viewBox="0 0 20 20"
            fill="none"
            stroke="currentColor"
            stroke-width="1.5"
            aria-hidden="true"><path d="m5 5 10 10M15 5 5 15" /></svg
          ></button
        >
      </div>
      <div class="space-y-4 p-4">
        <p class="text-sm">
          Semua baris debit/kredit yang memakai akun sumber pada jurnal terpilih akan diganti.
          Nominal, status, dan buku jurnal tetap dipertahankan.
        </p>
        <fieldset disabled={batchBusy}>
          <label class="block text-sm font-semibold"
            >Akun pengganti
            <div class="mt-2">
              <AccountCombobox
                accounts={replacementAccounts}
                value={replacementAccountId}
                onSelect={(id) => {
                  replacementAccountId = id;
                  batchPreview = null;
                  batchError = null;
                }}
                testId="batch-replacement-account"
              />
            </div>
          </label>
          <p class="mt-2 text-xs text-text-muted">
            Pilih akun aktif yang dapat digunakan untuk transaksi dan tersedia pada semua buku
            jurnal terpilih.
          </p>
        </fieldset>
        <div class="rounded-md border border-border-default p-3 text-sm">
          <p class="font-semibold">
            {batchPreview
              ? `${batchPreview.journal_count} jurnal · ${batchPreview.entries_count} baris akan diganti`
              : `${selectedJournals.length} jurnal dipilih`}
          </p>
          <ul class="mt-2 max-h-48 overflow-y-auto divide-y divide-border-soft">
            {#each selectedJournals as journal (journal.id)}<li
                class="flex justify-between gap-3 py-2"
              >
                <span>{journal.number}</span><span class="shrink-0 text-text-muted"
                  >{journal.journal_mode === 'fiscal' ? 'Fiskal' : 'Intern'}</span
                >
              </li>{/each}
          </ul>
        </div>
        {#if batchPreview}<p
            class="rounded-md bg-page-bg p-3 text-sm"
            role="status"
            data-testid="batch-account-preview"
          >
            Ganti akun {batchPreview.source_account.code} — {batchPreview.source_account.name} menjadi
            {batchPreview.replacement_account.code} — {batchPreview.replacement_account.name} pada {batchPreview.journal_count}
            jurnal ({batchPreview.entries_count} baris). Perubahan akan dicatat di audit trail.
          </p>{/if}
        {#if batchError}<p
            class="rounded-md border border-danger/30 bg-danger-light p-3 text-sm text-danger"
            role="alert"
            data-testid="batch-account-error"
          >
            {batchError} Tinjau ulang ringkasan atau muat ulang daftar sebelum melanjutkan.
          </p>{/if}
      </div>
      <div
        class="sticky bottom-0 flex justify-end gap-2 border-t border-border-soft bg-card-bg p-4"
      >
        <button
          type="button"
          class="rounded-md border border-border-default px-3 py-2 text-sm font-semibold disabled:opacity-50"
          disabled={batchBusy}
          onclick={() => batchDialog?.close()}>Batal</button
        >
        {#if batchPreview}<button
            type="button"
            class="rounded-md bg-primary px-3 py-2 text-sm font-semibold text-white disabled:opacity-50"
            disabled={batchBusy}
            onclick={saveBatch}
            data-testid="batch-account-save"
            >{batchBusy ? 'Menyimpan…' : 'Simpan Penggantian'}</button
          >
        {:else}<button
            type="button"
            class="rounded-md bg-primary px-3 py-2 text-sm font-semibold text-white disabled:opacity-50"
            disabled={batchBusy || !replacementAccountId}
            onclick={previewBatch}
            data-testid="batch-account-review"
            >{batchBusy ? 'Menyiapkan…' : 'Tinjau Perubahan'}</button
          >{/if}
      </div>
    </dialog>
  {/if}
</div>
