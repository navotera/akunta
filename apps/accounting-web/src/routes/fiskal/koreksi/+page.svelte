<script lang="ts">
  import { onMount } from 'svelte';
  import { goto } from '$app/navigation';
  import { formatRupiah } from '@akunta/ui';
  import { auth } from '$lib/stores/auth.svelte.js';
  import { accountApi, type Account } from '$lib/api/account.js';
  import { attachmentApi, type Attachment } from '$lib/api/attachment.js';
  import { ApiError } from '$lib/api/client.js';
  import {
    FISCAL_ADJUSTMENT_ATTACHABLE_TYPE,
    fiscalAdjustmentApi,
    type FiscalAdjustment,
  } from '$lib/api/fiscal-adjustment.js';
  import { journalApi, type JournalSummary } from '$lib/api/journal.js';
  import { reportingApi, type FiscalReconciliationData } from '$lib/api/reporting.js';
  import { taxProvisionApi, type TaxProvision } from '$lib/api/tax-provision.js';
  import TaxProvisionPanel from '$lib/components/fiscal/TaxProvisionPanel.svelte';
  import DateInput from '$lib/components/ui/DateInput.svelte';
  import { formatDate, formatDateTime } from '$lib/utils/date.js';
  import { period } from '$lib/stores/period.svelte.js';

  const now = new Date();
  let periodStart = $state(`${now.getFullYear()}-01-01`);
  let periodEnd = $state(now.toISOString().slice(0, 10));
  let items = $state<FiscalAdjustment[]>([]);
  let accounts = $state<Account[]>([]);
  let internalAccounts = $state<Account[]>([]);
  let journals = $state<JournalSummary[]>([]);
  let journalSearch = $state('');
  let journalPickerOpen = $state(false);
  let activePeriodId = $state<string | null>(null);
  let report = $state<FiscalReconciliationData | null>(null);
  let taxProvision = $state<TaxProvision | null>(null);
  let loading = $state(true);
  let saving = $state(false);
  let error = $state<string | null>(null);
  let formError = $state<string | null>(null);
  let formOpen = $state(false);
  let editing = $state<FiscalAdjustment | null>(null);
  let evidence = $state<File[]>([]);
  let savedEvidence = $state<Attachment[]>([]);
  let evidenceLoading = $state(false);
  let accountLocked = $state(false);
  let readOnly = $derived(editing?.status === 'approved');
  let canManage = $state(false);
  let canApprove = $state(false);
  let canReadTaxProvision = $state(false);
  let canManageTaxProvision = $state(false);
  let activeTab = $state<'accounts' | 'adjustments' | 'provision'>('accounts');
  let selectedAccountId = $state<string | null>(null);
  const ACTIVE_TAB_STORAGE_KEY = 'akunta:fiscal-correction-active-tab';
  let form = $state({
    journal_id: '',
    account_id: '',
    date: now.toISOString().slice(0, 10),
    direction: 'positive' as 'positive' | 'negative',
    amount: '',
    reason: '',
  });

  let filteredJournals = $derived(
    journals.filter((journal) => {
      const query = journalSearch.trim().toLowerCase();
      return (
        !query ||
        [journal.number, journal.memo, journal.transaction_code, journal.reference]
          .filter(Boolean)
          .some((value) => String(value).toLowerCase().includes(query))
      );
    }),
  );

  function errorMessage(caught: unknown, fallback: string): string {
    const body =
      caught instanceof ApiError
        ? caught.body
        : typeof caught === 'object' && caught !== null && 'body' in caught
          ? caught.body
          : null;
    if (typeof body === 'object' && body !== null) {
      const message = 'message' in body && typeof body.message === 'string' ? body.message : null;
      const errors =
        'errors' in body && typeof body.errors === 'object' && body.errors !== null
          ? Object.values(body.errors as Record<string, unknown>).flat()
          : [];
      const firstError = errors.find((value): value is string => typeof value === 'string');
      if (firstError) return firstError;
      if (message) return message;
    }
    return caught instanceof Error ? caught.message : fallback;
  }

  function resetForm(): void {
    form = {
      journal_id: '',
      account_id: '',
      date: now.toISOString().slice(0, 10),
      direction: 'positive',
      amount: '',
      reason: '',
    };
    evidence = [];
    savedEvidence = [];
    formError = null;
    journalSearch = '';
    journalPickerOpen = false;
    accountLocked = false;
  }

  const filteredItems = $derived(
    selectedAccountId ? items.filter((item) => item.account_id === selectedAccountId) : items,
  );
  const selectedAccount = $derived(
    report?.rows.find((row) => row.account_id === selectedAccountId) ?? null,
  );

  function openAccountAdjustments(accountId: string): void {
    selectedAccountId = accountId;
    selectTab('adjustments');
  }

  function clearAccountFilter(): void {
    selectedAccountId = null;
  }

  function selectTab(tab: 'accounts' | 'adjustments' | 'provision'): void {
    activeTab = tab;
    if (typeof localStorage !== 'undefined') {
      localStorage.setItem(ACTIVE_TAB_STORAGE_KEY, tab);
    }
  }

  function formatAmountInput(value: string): string {
    const amount = Number(value);
    return Number.isFinite(amount) && amount > 0
      ? new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 }).format(amount)
      : '';
  }

  function updateAmount(value: string): void {
    const normalized = value
      .replace(/\./g, '')
      .replace(',', '.')
      .replace(/[^\d.]/g, '');
    const amount = Number(normalized);
    form.amount = Number.isFinite(amount) && amount > 0 ? String(amount) : '';
  }

  function handleAmountInput(event: Event): void {
    const input = event.currentTarget as HTMLInputElement;
    updateAmount(input.value);
    input.value = formatAmountInput(form.amount);
  }

  function restrictAmountKeys(event: KeyboardEvent): void {
    if (event.ctrlKey || event.metaKey || event.altKey) return;
    if (
      !/[\d.,]/.test(event.key) &&
      !['Backspace', 'Delete', 'Tab', 'ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)
    ) {
      event.preventDefault();
    }
  }

  function openQuickAdjustment(accountId: string, direction: 'positive' | 'negative'): void {
    editing = null;
    resetForm();
    form.account_id = accountId;
    form.date = periodEnd;
    form.direction = direction;
    accountLocked = true;
    formOpen = true;
  }

  function openCreate(): void {
    editing = null;
    resetForm();
    formOpen = true;
  }

  async function openEdit(item: FiscalAdjustment): Promise<void> {
    editing = item;
    accountLocked = true;
    form = {
      journal_id: item.journal_id ?? '',
      account_id: item.account_id,
      date: item.date,
      direction: item.direction,
      amount: item.amount,
      reason: item.reason,
    };
    evidence = [];
    savedEvidence = [];
    formError = null;
    const selectedJournal = journals.find((journal) => journal.id === item.journal_id);
    journalSearch = selectedJournal ? journalLabel(selectedJournal) : '';
    journalPickerOpen = false;
    formOpen = true;
    evidenceLoading = true;
    try {
      savedEvidence = await attachmentApi.listFor(FISCAL_ADJUSTMENT_ATTACHABLE_TYPE, item.id);
    } catch (caught) {
      formError = errorMessage(caught, 'Bukti pendukung gagal dimuat.');
    } finally {
      evidenceLoading = false;
    }
  }

  function closeForm(): void {
    if (saving) return;
    formOpen = false;
    editing = null;
    resetForm();
  }

  async function load() {
    loading = true;
    error = null;
    try {
      const provisionRequest = taxProvisionApi.current(periodStart, periodEnd).catch((caught) => {
        if (caught instanceof ApiError && caught.status === 403) {
          return { data: null, meta: { can_manage: false, can_read: false } };
        }
        throw caught;
      });
      const [
        adjustmentResult,
        loadedAccounts,
        loadedInternalAccounts,
        reportResult,
        journalResult,
        provisionResult,
      ] = await Promise.all([
        fiscalAdjustmentApi.list(periodStart, periodEnd),
        accountApi.list('', undefined, true, 'fiscal'),
        accountApi.list('', undefined, true, 'internal'),
        reportingApi.fiscalReconciliation(periodStart, periodEnd).then((response) => response.data),
        journalApi.list({
          per_page: 100,
          journal_mode: 'fiscal',
          status: 'posted',
          period_id: activePeriodId ?? undefined,
        }),
        provisionRequest,
      ]);
      items = adjustmentResult.data;
      canManage = adjustmentResult.meta.can_manage;
      canApprove = adjustmentResult.meta.can_approve;
      accounts = loadedAccounts;
      internalAccounts = loadedInternalAccounts;
      report = reportResult;
      journals = journalResult.data;
      activePeriodId = period.active?.id ?? activePeriodId;
      taxProvision = provisionResult.data;
      canManageTaxProvision = provisionResult.meta.can_manage;
      canReadTaxProvision = provisionResult.meta.can_read !== false;
      if (!canReadTaxProvision && activeTab === 'provision') selectTab('adjustments');
    } catch (caught) {
      error = caught instanceof Error ? caught.message : 'Data Fiskal gagal dimuat.';
    } finally {
      loading = false;
    }
  }

  onMount(async () => {
    const storedTab = localStorage.getItem(ACTIVE_TAB_STORAGE_KEY);
    if (storedTab === 'accounts' || storedTab === 'adjustments' || storedTab === 'provision') {
      activeTab = storedTab;
    }
    if (!auth.user) {
      const user = await auth.refresh();
      if (!user) {
        await goto('/login', { replaceState: true });
        return;
      }
    }
    await period.refresh();
    activePeriodId = period.active?.id ?? null;
    await load();
  });

  function journalLabel(journal: JournalSummary): string {
    return `${journal.number} · ${journal.memo ?? ''}`.trim();
  }

  function selectJournal(journal: JournalSummary): void {
    form.journal_id = journal.id;
    journalSearch = journalLabel(journal);
    journalPickerOpen = false;
  }

  function updateJournalSearch(value: string): void {
    journalSearch = value;
    form.journal_id = '';
    journalPickerOpen = true;
  }

  async function saveAdjustment() {
    if (!form.account_id || !form.amount || !form.reason.trim()) return;
    saving = true;
    formError = null;
    try {
      const input = {
        ...form,
        journal_id: form.journal_id || null,
      };
      const saved = editing
        ? await fiscalAdjustmentApi.update(editing.id, input)
        : await fiscalAdjustmentApi.create(input);
      editing = saved;
      for (const file of [...evidence]) {
        const uploaded = await attachmentApi.upload(
          FISCAL_ADJUSTMENT_ATTACHABLE_TYPE,
          saved.id,
          file,
        );
        savedEvidence = [...savedEvidence, uploaded];
        evidence = evidence.filter((candidate) => candidate !== file);
      }
      formOpen = false;
      editing = null;
      resetForm();
      await load();
    } catch (caught) {
      formError = errorMessage(caught, 'Koreksi Fiskal gagal disimpan.');
    } finally {
      saving = false;
    }
  }

  async function approve(item: FiscalAdjustment) {
    if (
      !window.confirm(
        'Setujui koreksi Fiskal ini? Setelah disetujui data dan bukti tidak dapat diubah.',
      )
    )
      return;
    error = null;
    try {
      await fiscalAdjustmentApi.approve(item.id);
      await load();
    } catch (caught) {
      const message = errorMessage(caught, 'Koreksi Fiskal gagal disetujui.');
      error = message;
      if (message.toLowerCase().includes('bukti') && canManage) await openEdit(item);
    }
  }

  async function remove(id: string) {
    if (!window.confirm('Hapus koreksi Fiskal draft ini?')) return;
    error = null;
    try {
      await fiscalAdjustmentApi.destroy(id);
      await load();
    } catch (caught) {
      error = errorMessage(caught, 'Koreksi Fiskal gagal dihapus.');
    }
  }

  async function viewEvidence(id: string): Promise<void> {
    formError = null;
    try {
      const attachment = await attachmentApi.show(id);
      if (attachment.url) window.open(attachment.url, '_blank', 'noopener,noreferrer');
    } catch (caught) {
      formError = errorMessage(caught, 'Bukti pendukung gagal dibuka.');
    }
  }

  async function removeEvidence(id: string): Promise<void> {
    if (!window.confirm('Hapus bukti pendukung ini?')) return;
    formError = null;
    try {
      await attachmentApi.destroy(id);
      savedEvidence = savedEvidence.filter((attachment) => attachment.id !== id);
    } catch (caught) {
      formError = errorMessage(caught, 'Bukti pendukung gagal dihapus.');
    }
  }
</script>

<div class="px-6 py-6">
  <header class="mb-5 flex flex-wrap items-start justify-between gap-4">
    <div>
      <p class="text-xs font-semibold uppercase tracking-wider text-warning">Buku Fiskal</p>
      <h1 class="text-2xl font-bold">Koreksi Fiskal, Rekonsiliasi &amp; Provisi Pajak</h1>
      <p class="mt-1 max-w-3xl text-sm text-text-muted">
        Jurnal Fiskal berdiri sendiri dari Intern. Koreksi di halaman ini tidak mengubah jurnal mana
        pun. Dampak pajak ke laporan keuangan dicatat melalui jurnal provisi Intern yang terpisah.
      </p>
    </div>
    {#if canManage}
      <button
        type="button"
        class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white"
        onclick={openCreate}>+ Koreksi Fiskal</button
      >
    {/if}
  </header>

  <div
    class="mb-5 flex flex-wrap items-end gap-3 rounded-lg border border-border-default bg-card-bg p-4"
  >
    <label class="text-sm"
      ><span class="mb-1 block font-medium">Mulai</span><DateInput
        value={periodStart}
        onChange={(value) => (periodStart = value)}
      /></label
    >
    <label class="text-sm"
      ><span class="mb-1 block font-medium">Sampai</span><DateInput
        value={periodEnd}
        onChange={(value) => (periodEnd = value)}
      /></label
    >
    <button
      type="button"
      class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white"
      onclick={load}
      disabled={loading}>{loading ? 'Memuat…' : 'Tampilkan'}</button
    >
  </div>

  {#if error}<div
      class="mb-4 rounded-md border border-danger bg-danger-light p-3 text-sm text-danger"
    >
      {error}
    </div>{/if}

  <div
    class="mb-5 inline-flex max-w-full flex-wrap rounded-lg border border-border-default bg-card-bg p-1"
    aria-label="Tampilan Koreksi Fiskal"
    role="tablist"
  >
    <button
      type="button"
      role="tab"
      aria-selected={activeTab === 'accounts'}
      class="inline-flex items-center gap-2 rounded-md px-3 py-2 text-sm font-semibold transition {activeTab ===
      'accounts'
        ? 'bg-primary-light text-primary'
        : 'text-text-muted hover:bg-page-bg hover:text-text-default'}"
      onclick={() => selectTab('accounts')}
    >
      Daftar Akun (Pendapatan / Biaya)
      <span
        class="rounded-full px-2 py-0.5 text-xs font-bold {activeTab === 'accounts'
          ? 'bg-primary text-white'
          : 'bg-page-bg text-text-muted'}">{report?.rows.length ?? 0}</span
      >
    </button>
    <button
      type="button"
      role="tab"
      aria-selected={activeTab === 'adjustments'}
      class="inline-flex items-center gap-2 rounded-md px-3 py-2 text-sm font-semibold transition {activeTab ===
      'adjustments'
        ? 'bg-primary-light text-primary'
        : 'text-text-muted hover:bg-page-bg hover:text-text-default'}"
      onclick={() => selectTab('adjustments')}
    >
      Daftar Koreksi Fiskal
      <span
        class="rounded-full px-2 py-0.5 text-xs font-bold {activeTab === 'adjustments'
          ? 'bg-primary text-white'
          : 'bg-page-bg text-text-muted'}">{filteredItems.length}</span
      >
    </button>
    {#if canReadTaxProvision}
      <button
        type="button"
        role="tab"
        aria-selected={activeTab === 'provision'}
        class="inline-flex items-center gap-2 rounded-md px-3 py-2 text-sm font-semibold transition {activeTab ===
        'provision'
          ? 'bg-primary-light text-primary'
          : 'text-text-muted hover:bg-page-bg hover:text-text-default'}"
        onclick={() => selectTab('provision')}
      >
        Perhitungan Pajak dan Jurnal Provisi
        <span
          class="rounded-full px-2 py-0.5 text-xs font-bold {activeTab === 'provision'
            ? 'bg-primary text-white'
            : 'bg-page-bg text-text-muted'}">{taxProvision?.journal ? 1 : 0}</span
        >
      </button>
    {/if}
  </div>

  {#if activeTab === 'accounts'}
    {#if report}
      <div class="mb-5 grid gap-3 md:grid-cols-4">
        <div class="ak-card p-4">
          <p class="text-xs text-black">Laba Buku Fiskal</p>
          <p class="mt-1 font-mono text-lg text-black">{formatRupiah(report.book_net_income)}</p>
        </div>
        <div class="ak-card p-4">
          <p class="text-xs text-black">Koreksi Positif</p>
          <p
            class="mt-1 font-mono text-lg text-[#314033]"
            style={Number(report.positive_adjustments) === 0
              ? undefined
              : 'color: rgb(84 122 89) !important'}
          >
            {formatRupiah(report.positive_adjustments)}
          </p>
        </div>
        <div class="ak-card p-4">
          <p class="text-xs text-black">Koreksi Negatif</p>
          <p
            class="mt-1 font-mono text-lg text-[#47333b]"
            style={Number(report.negative_adjustments) === 0
              ? undefined
              : 'color: rgb(124 89 103) !important'}
          >
            {formatRupiah(report.negative_adjustments)}
          </p>
        </div>
        <div class="ak-card p-4">
          <p class="text-xs text-black">Penghasilan Neto Setelah Koreksi</p>
          <p class="mt-1 font-mono text-lg font-bold text-black">
            {formatRupiah(report.final_net_income)}
          </p>
        </div>
      </div>

      <section class="overflow-x-auto rounded-lg border border-border-default bg-card-bg">
        <div class="border-b border-border-soft px-4 py-3">
          <h2 class="font-bold">Daftar Akun (Pendapatan / Biaya)</h2>
          <p class="mt-1 text-sm text-text-muted">
            Ringkasan buku Fiskal dan dampak koreksi positif atau negatif untuk setiap akun.
          </p>
        </div>
        <table class="w-full text-sm">
          <thead class="bg-page-bg text-xs uppercase text-text-muted">
            <tr>
              <th class="px-4 py-3 text-left">Akun</th>
              <th class="px-4 py-3 text-right">Buku Fiskal</th>
              <th class="px-4 py-3 text-right text-paid">Koreksi +</th>
              <th class="px-4 py-3 text-right text-danger">Koreksi −</th>
              <th class="px-4 py-3 text-right">Setelah Koreksi</th>
            </tr>
          </thead>
          <tbody>
            {#each report.rows as row (row.account_id)}
              <tr class="border-t border-border-soft">
                <td class="px-4 py-3">
                  <button
                    type="button"
                    class="text-left hover:text-primary hover:underline"
                    title="Lihat koreksi akun ini"
                    onclick={() => openAccountAdjustments(row.account_id)}
                  >
                    <span class="font-mono text-xs text-text-muted">{row.code}</span>
                    <span class="mx-1 text-text-muted opacity-20">|</span>
                    {row.name}
                  </button>
                </td>
                <td class="px-4 py-3 text-right font-mono">{formatRupiah(row.book_amount)}</td>
                <td class="group px-4 py-3 text-right font-mono">
                  <span
                    class="text-[#314033]"
                    style={Number(row.positive_adjustment) === 0
                      ? undefined
                      : 'color: rgb(84 122 89) !important'}
                    >{formatRupiah(row.positive_adjustment)}</span
                  >
                  <button
                    type="button"
                    class="ml-1 rounded px-1.5 font-sans text-sm font-bold text-primary opacity-0 transition group-hover:opacity-100 focus:opacity-100 hover:bg-primary-light"
                    title="Tambah koreksi positif"
                    aria-label="Tambah koreksi positif untuk {row.name}"
                    onclick={() => openQuickAdjustment(row.account_id, 'positive')}>+</button
                  >
                </td>
                <td class="group px-4 py-3 text-right font-mono">
                  <span
                    class="text-[#47333b]"
                    style={Number(row.negative_adjustment) === 0
                      ? undefined
                      : 'color: rgb(124 89 103) !important'}
                    >{formatRupiah(row.negative_adjustment)}</span
                  >
                  <button
                    type="button"
                    class="ml-1 rounded px-1.5 font-sans text-sm font-bold text-primary opacity-0 transition group-hover:opacity-100 focus:opacity-100 hover:bg-primary-light"
                    title="Tambah koreksi negatif"
                    aria-label="Tambah koreksi negatif untuk {row.name}"
                    onclick={() => openQuickAdjustment(row.account_id, 'negative')}>+</button
                  >
                </td>
                <td class="px-4 py-3 text-right font-mono font-semibold">
                  {formatRupiah(row.final_amount)}
                </td>
              </tr>
            {:else}
              <tr>
                <td colspan="5" class="px-4 py-10 text-center text-text-muted"
                  >Belum ada jurnal atau koreksi Fiskal pada periode ini.</td
                >
              </tr>
            {/each}
          </tbody>
        </table>
      </section>
    {:else if !loading}
      <section
        class="rounded-lg border border-border-default bg-card-bg px-4 py-10 text-center text-sm text-text-muted"
      >
        Data daftar akun belum tersedia untuk periode ini.
      </section>
    {/if}
  {:else if activeTab === 'adjustments'}
    <section class="overflow-x-auto rounded-lg border border-border-default bg-card-bg">
      <div class="border-b border-border-soft px-4 py-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <div>
            <h2 class="font-bold">Daftar Koreksi Fiskal</h2>
            {#if selectedAccount}
              <p class="mt-1 text-sm text-text-muted">
                Filter akun:
                <span class="font-semibold text-text-default"
                  >{selectedAccount.code}
                  <span class="mx-1 text-text-muted opacity-20">|</span>
                  {selectedAccount.name}</span
                >
              </p>
            {/if}
          </div>
          {#if selectedAccountId}
            <button
              type="button"
              class="rounded-md border border-border-default px-3 py-1.5 text-xs font-semibold text-text-muted hover:border-primary hover:text-primary"
              onclick={clearAccountFilter}>Tampilkan Semua</button
            >
          {/if}
        </div>
      </div>
      <table class="w-full text-sm">
        <thead class="bg-page-bg text-xs uppercase text-text-muted"
          ><tr
            ><th class="px-4 py-3 text-left">Tanggal</th><th class="px-4 py-3 text-left"
              >Akun/Jurnal</th
            ><th class="px-4 py-3 text-left">Alasan</th><th class="px-4 py-3 text-right">Nilai</th
            ><th class="px-4 py-3 text-left">Status/Bukti</th><th class="px-4 py-3"></th></tr
          ></thead
        ><tbody>
          {#each filteredItems as item (item.id)}
            <tr class="border-t border-border-soft"
              ><td class="px-4 py-3">{formatDate(item.date)}</td><td class="px-4 py-3"
                ><strong
                  >{item.account_code} <span class="text-text-muted opacity-20">|</span>
                  {item.account_name}</strong
                ><span class="block text-xs text-text-muted"
                  >{item.journal_number ?? 'Tanpa jurnal sumber'}</span
                ></td
              ><td class="max-w-md px-4 py-3"
                ><span class="line-clamp-2">{item.reason}</span>{#if item.legal_basis}<span
                    class="mt-1 block text-xs text-text-muted">Dasar: {item.legal_basis}</span
                  >{/if}</td
              ><td
                class="px-4 py-3 text-right font-mono {item.direction === 'positive'
                  ? 'text-warning'
                  : 'text-info'}"
                >{item.direction === 'positive' ? '+' : '−'} {formatRupiah(item.amount)}</td
              ><td class="px-4 py-3"
                ><span
                  class="rounded-full px-2 py-1 text-xs font-semibold {item.status === 'approved'
                    ? 'bg-paid-light text-paid'
                    : 'bg-warning-light text-warning'}"
                  >{item.status === 'approved' ? 'Disetujui' : 'Draft'}</span
                ><span class="ml-2 text-xs text-text-muted">{item.attachments_count} bukti</span
                ><span class="mt-1 block text-xs text-text-muted"
                  >{item.status === 'approved'
                    ? `Disetujui oleh ${item.approved_by_name ?? 'pengguna'}`
                    : `Dibuat oleh ${item.created_by_name ?? 'pengguna'}`}</span
                ></td
              ><td class="px-4 py-3 text-right"
                >{#if item.status === 'approved'}<button
                    type="button"
                    class="mr-2 text-xs font-semibold text-primary"
                    onclick={() => openEdit(item)}>Lihat Detail</button
                  >{/if}{#if item.status === 'draft' && canManage}<button
                    type="button"
                    class="mr-2 text-xs font-semibold text-primary"
                    onclick={() => openEdit(item)}>Edit</button
                  >{/if}{#if item.status === 'draft' && canApprove}<button
                    type="button"
                    class="mr-2 text-xs font-semibold text-paid"
                    onclick={() => approve(item)}>Setujui</button
                  >{/if}{#if item.status === 'draft' && canManage}<button
                    type="button"
                    class="text-xs font-semibold text-danger"
                    onclick={() => remove(item.id)}>Hapus</button
                  >{/if}</td
              ></tr
            >
          {:else}<tr
              ><td colspan="6" class="px-4 py-10 text-center text-text-muted"
                >{selectedAccountId
                  ? 'Belum ada koreksi Fiskal untuk akun ini.'
                  : 'Belum ada koreksi Fiskal.'}</td
              ></tr
            >{/each}
        </tbody>
      </table>
    </section>
  {:else if report}
    <TaxProvisionPanel
      {report}
      accounts={internalAccounts}
      provision={taxProvision}
      canManage={canManageTaxProvision}
      {periodStart}
      {periodEnd}
    />
  {:else if !loading}
    <section
      class="rounded-lg border border-border-default bg-card-bg px-4 py-10 text-center text-sm text-text-muted"
    >
      Rekonsiliasi belum tersedia untuk menghitung provisi pajak pada periode ini.
    </section>
  {/if}
</div>

{#if formOpen}
  <div
    class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
    role="presentation"
    onclick={(event) => event.currentTarget === event.target && closeForm()}
  >
    <div
      class="w-full max-w-2xl rounded-lg bg-card-bg p-6 shadow-xl"
      role="dialog"
      aria-modal="true"
      aria-labelledby="fiscal-form-title"
      tabindex="-1"
    >
      <h2 id="fiscal-form-title" class="text-lg font-bold">
        {readOnly
          ? 'Detail Koreksi Fiskal'
          : editing
            ? 'Edit Koreksi Fiskal'
            : 'Koreksi Fiskal Baru'}
      </h2>
      <p class="mt-1 text-sm text-text-muted">
        Koreksi hanya memengaruhi rekonsiliasi pajak; jurnal Fiskal dan Intern tidak diubah.
      </p>
      {#if editing}
        <div
          class="mt-3 rounded-md border border-border-soft bg-page-bg px-3 py-2 text-xs text-text-muted"
        >
          Dibuat oleh <strong class="text-text-default"
            >{editing.created_by_name ?? 'pengguna'}</strong
          >{#if editing.created_at}
            pada {formatDateTime(editing.created_at)}{/if}.
          {#if editing.status === 'approved'}
            Disetujui oleh <strong class="text-text-default"
              >{editing.approved_by_name ?? 'pengguna'}</strong
            >{#if editing.approved_at}
              pada {formatDateTime(editing.approved_at)}{/if}.
          {/if}
        </div>
      {/if}
      {#if formError}
        <div class="mt-4 rounded-md border border-danger bg-danger-light p-3 text-sm text-danger">
          {formError}
        </div>
      {/if}
      <div class="mt-5 grid gap-4 sm:grid-cols-2">
        <label class="text-sm"
          ><span class="mb-1 block font-medium">Tanggal</span><DateInput
            value={form.date}
            onChange={(value) => (form.date = value)}
            disabled={readOnly}
          /></label
        >
        <label class="text-sm"
          ><span class="mb-1 block font-medium">Arah koreksi</span><select
            class="w-full rounded-md border border-border-default px-3 py-2 {form.direction ===
            'positive'
              ? 'text-paid'
              : 'text-danger'}"
            bind:value={form.direction}
            disabled={readOnly}
            ><option value="positive">Positif — menambah laba fiskal</option><option
              value="negative">Negatif — mengurangi laba fiskal</option
            ></select
          ></label
        >
        {#if editing}
          <div class="sm:col-span-2">
            <span class="mb-2 block text-sm font-medium">Bukti tersimpan</span>
            {#if evidenceLoading}
              <p class="text-sm text-text-muted">Memuat bukti…</p>
            {:else if savedEvidence.length === 0}
              <div
                class="rounded-md border border-dashed border-warning/50 bg-warning-light p-3 text-sm text-text-muted"
              >
                Belum ada bukti. Tambahkan minimal satu file sebelum koreksi disetujui.
              </div>
            {:else}
              <div class="space-y-2">
                {#each savedEvidence as attachment (attachment.id)}
                  <div
                    class="flex items-center justify-between gap-3 rounded-md border border-border-default px-3 py-2 text-sm"
                  >
                    <button
                      type="button"
                      class="min-w-0 truncate text-left font-medium text-primary hover:underline"
                      onclick={() => viewEvidence(attachment.id)}>{attachment.filename}</button
                    >
                    {#if !readOnly}
                      <button
                        type="button"
                        class="shrink-0 text-xs font-semibold text-danger"
                        onclick={() => removeEvidence(attachment.id)}>Hapus</button
                      >
                    {/if}
                  </div>
                {/each}
              </div>
            {/if}
          </div>
        {/if}
        <label class="text-sm sm:col-span-2"
          ><span class="mb-1 block font-medium">Akun Fiskal</span>
          {#if accountLocked}
            {@const selectedFiscalAccount = accounts.find(
              (account) => account.id === form.account_id,
            )}
            <div
              class="w-full rounded-md border border-border-default bg-page-bg px-3 py-2 text-text-default"
              aria-readonly="true"
            >
              {selectedFiscalAccount
                ? `${selectedFiscalAccount.code} · ${selectedFiscalAccount.name}`
                : 'Akun tidak ditemukan'}
            </div>
          {:else}
            <select
              class="w-full rounded-md border border-border-default px-3 py-2"
              bind:value={form.account_id}
              disabled={readOnly}
              ><option value="">Pilih akun</option
              >{#each accounts.filter( (account) => ['revenue', 'cogs', 'expense'].includes(account.type), ) as account (account.id)}<option
                  value={account.id}>{account.code} · {account.name}</option
                >{/each}</select
            >
          {/if}
        </label>
        <div class="relative text-sm sm:col-span-2">
          <span class="mb-1 block font-medium">Jurnal Fiskal sumber (opsional)</span>
          <div class="flex gap-2">
            <input
              class="w-full rounded-md border border-border-default px-3 py-2"
              value={journalSearch}
              oninput={(event) => updateJournalSearch(event.currentTarget.value)}
              onfocus={() => (journalPickerOpen = true)}
              placeholder="Cari nomor atau keterangan jurnal"
              disabled={readOnly}
              autocomplete="off"
            />
            {#if form.journal_id && !readOnly}
              <button
                type="button"
                class="rounded-md border border-border-default px-3 py-2 text-text-muted hover:bg-page-bg"
                onclick={() => {
                  form.journal_id = '';
                  journalSearch = '';
                  journalPickerOpen = false;
                }}
                aria-label="Hapus jurnal Fiskal sumber"
              >
                ×
              </button>
            {/if}
          </div>
          {#if journalPickerOpen && !readOnly}
            <div
              class="absolute left-0 right-0 top-full z-20 mt-1 max-h-56 overflow-y-auto rounded-md border border-border-default bg-card-bg shadow-lg"
            >
              <button
                type="button"
                class="w-full px-3 py-2 text-left text-sm text-text-muted hover:bg-page-bg"
                onclick={() => {
                  form.journal_id = '';
                  journalSearch = '';
                  journalPickerOpen = false;
                }}
              >
                Tanpa jurnal tertentu
              </button>
              {#each filteredJournals as journal (journal.id)}
                <button
                  type="button"
                  class="block w-full border-t border-border-soft px-3 py-2 text-left text-sm hover:bg-page-bg"
                  onclick={() => selectJournal(journal)}
                >
                  <span class="block font-medium">{journal.number}</span>
                  <span class="block truncate text-xs text-text-muted">{journal.memo}</span>
                </button>
              {:else}
                <p class="border-t border-border-soft px-3 py-2 text-xs text-text-muted">
                  Tidak ada jurnal Fiskal pada periode aktif yang cocok.
                </p>
              {/each}
            </div>
          {/if}
          <span class="mt-1 block text-xs text-text-muted"
            >Hanya jurnal Fiskal tersimpan pada periode aktif yang tersedia.</span
          >
        </div>
        <label class="text-sm"
          ><span class="mb-1 block font-medium">Nilai</span><input
            class="w-full rounded-md border border-border-default px-3 py-2 text-right font-mono"
            type="text"
            inputmode="decimal"
            value={formatAmountInput(form.amount)}
            onkeydown={restrictAmountKeys}
            oninput={handleAmountInput}
            placeholder="0"
            disabled={readOnly}
          /></label
        >
        <label class="text-sm sm:col-span-2"
          ><span class="mb-1 block font-medium">Dasar hukum atau Catatan</span><textarea
            class="min-h-24 w-full rounded-md border border-border-default px-3 py-2"
            bind:value={form.reason}
            disabled={readOnly}
          ></textarea></label
        >
        {#if !readOnly}
          <label class="text-sm sm:col-span-2"
            ><span class="mb-1 block font-medium">Bukti pendukung</span><input
              type="file"
              multiple
              onchange={(event) => (evidence = Array.from(event.currentTarget.files ?? []))}
            /><span class="mt-1 block text-xs text-text-muted"
              >{evidence.length} file dipilih. Maksimal 5 MB per file.</span
            ></label
          >
        {/if}
      </div>
      <div class="mt-6 flex justify-end gap-3">
        <button
          type="button"
          class="rounded-md border border-border-default px-4 py-2 text-sm"
          onclick={closeForm}>{readOnly ? 'Tutup' : 'Batal'}</button
        >{#if !readOnly}<button
            type="button"
            class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
            onclick={saveAdjustment}
            disabled={saving || !form.account_id || !form.amount || !form.reason.trim()}
            >{saving ? 'Menyimpan…' : editing ? 'Simpan Perubahan' : 'Simpan Draft'}</button
          >{/if}
      </div>
    </div>
  </div>
{/if}
