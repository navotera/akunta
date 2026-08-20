<script lang="ts">
  import PanelHeader from './PanelHeader.svelte';
  import EntryRow from './EntryRow.svelte';
  import AddLineButton from './AddLineButton.svelte';
  import BalancePill from './BalancePill.svelte';
  import TemplateSidebar from './TemplateSidebar.svelte';
  import type { AccountOption } from '$lib/api/account.js';
  import {
    journalApi,
    type JournalMode,
    type JournalType,
    type JournalDetail,
  } from '$lib/api/journal.js';
  import { templateApi, type JournalTemplateSummary } from '$lib/api/template.js';
  import { auth } from '$lib/stores/auth.svelte.js';
  import DateInput from '$lib/components/ui/DateInput.svelte';
  import { page } from '$app/stores';
  import {
    loadJournalDraft,
    saveJournalDraft,
    type JournalDraft,
  } from '$lib/stores/journalDraft.js';

  interface Row {
    account_id: string;
    amount: string;
    memo: string | null;
  }

  interface Props {
    initial?: Partial<JournalDetail>;
    accounts: AccountOption[];
    templates: JournalTemplateSummary[];
    title?: string;
    breadcrumb?: string;
    saving?: boolean;
    serverErrors?: Record<string, string[]> | null;
    serverMessage?: string | null;
    allowPosting?: boolean;
    onSaveDraft: (payload: FormPayload) => Promise<void> | void;
    onPosting: (payload: FormPayload) => Promise<void> | void;
    onCancel: () => void;
  }

  export interface FormPayload {
    number?: string;
    transaction_code: string;
    journal_mode: JournalMode;
    type: JournalType;
    date: string;
    memo: string;
    reference: string | null;
    attachments: File[];
    entries_debit: Row[];
    entries_credit: Row[];
  }

  let {
    initial,
    accounts,
    templates,
    title = 'Jurnal Umum Baru',
    breadcrumb = 'Transaksi / Jurnal Umum / Baru',
    saving = false,
    serverErrors = null,
    serverMessage = null,
    allowPosting = true,
    onSaveDraft,
    onPosting,
    onCancel,
  }: Props = $props();

  const draftPath = $page.url.pathname;
  const restoredDraft = initial ? null : loadJournalDraft(draftPath);

  function fieldError(name: string): string | null {
    return serverErrors?.[name]?.[0] ?? null;
  }

  const blankRow = (): Row => ({ account_id: '', amount: '0', memo: null });

  let date = $state(restoredDraft?.date ?? initial?.date ?? new Date().toISOString().slice(0, 10));
  let number = $state(restoredDraft?.number ?? initial?.number ?? '');
  let transactionCode = $state(restoredDraft?.transaction_code ?? initial?.transaction_code ?? '');
  let previewNumber = $state<string | null>(null);
  let journalMode = $state<JournalMode>(
    restoredDraft?.journal_mode ?? initial?.journal_mode ?? 'internal',
  );
  let journalType = $state<JournalType>(
    restoredDraft?.type ?? (initial?.type as JournalType | undefined) ?? 'general',
  );
  let memo = $state(restoredDraft?.memo ?? initial?.memo ?? '');
  let reference = $state(restoredDraft?.reference ?? initial?.reference ?? '');
  let attachments = $state<File[]>([]);
  let attachmentInput = $state<HTMLInputElement>();
  let attachmentToRemove = $state<number | null>(null);
  let attachmentToPreview = $state<File | null>(null);
  let attachmentPreviewUrl = $state<string | null>(null);
  let pendingJournalMode = $state<JournalMode | null>(null);

  function modeLabel(mode: JournalMode): string {
    return mode === 'both' ? 'Intern & Fiskal' : mode === 'fiscal' ? 'Fiskal' : 'Intern';
  }

  let debits = $state<Row[]>(
    (restoredDraft?.entries_debit ?? initial?.entries_debit ?? []).map((e) => ({
      account_id: e.account_id,
      amount: e.amount,
      memo: e.memo,
    })),
  );
  let credits = $state<Row[]>(
    (restoredDraft?.entries_credit ?? initial?.entries_credit ?? []).map((e) => ({
      account_id: e.account_id,
      amount: e.amount,
      memo: e.memo,
    })),
  );

  // ensure starting rows
  if (debits.length === 0) debits = [blankRow(), blankRow()];
  if (credits.length === 0) credits = [blankRow()];

  $effect(() => {
    if (initial) return;

    const draft: JournalDraft = {
      date,
      number,
      transaction_code: transactionCode,
      journal_mode: journalMode,
      type: journalType,
      memo,
      reference,
      entries_debit: debits,
      entries_credit: credits,
    };
    saveJournalDraft(draftPath, draft);
  });

  $effect(() => {
    if (initial || transactionCode) return;
    void journalApi
      .nextTransactionCode(date)
      .then(({ transaction_code }) => {
        if (!transactionCode) {
          transactionCode = transaction_code;
        }
      })
      .catch(() => undefined);
  });

  const debitTotal = $derived(debits.reduce((s, r) => s + Number(r.amount || 0), 0));
  const creditTotal = $derived(credits.reduce((s, r) => s + Number(r.amount || 0), 0));
  const balanced = $derived(debitTotal > 0 && Math.abs(debitTotal - creditTotal) < 0.005);
  const visibleAccounts = $derived(
    accounts.filter((account) =>
      journalMode === 'both'
        ? account.availability === 'both'
        : account.availability === 'both' ||
          (journalMode === 'internal'
            ? account.availability === 'intern'
            : account.availability === 'fiskal'),
    ),
  );
  const visibleTemplates = $derived(
    journalMode === 'both'
      ? []
      : templates.filter(
          (template) =>
            (template.journal_mode ?? 'internal') === journalMode && template.is_bookmarked === true,
        ),
  );
  const displayedNumber = $derived(number || previewNumber || 'Memuat nomor jurnal…');

  let previewRequest = 0;
  $effect(() => {
    if (number) return;

    const request = ++previewRequest;
    previewNumber = null;
    void journalApi
      .nextNumber(date, journalMode === 'both' ? 'internal' : journalMode, journalType)
      .then(({ number: nextNumber }) => {
        if (request === previewRequest) previewNumber = nextNumber;
      })
      .catch(() => {
        if (request === previewRequest) previewNumber = null;
      });
  });

  function requestJournalMode(nextMode: JournalMode) {
    if (initial || saving) return;
    if (nextMode === journalMode) return;
    if (attachments.length > 0) {
      pendingJournalMode = nextMode;
      return;
    }

    changeJournalMode(nextMode);
  }

  function changeJournalMode(nextMode: JournalMode) {
    journalMode = nextMode;
    const availableIds = new Set(
      accounts
        .filter((account) =>
          nextMode === 'both'
            ? account.availability === 'both'
            : account.availability === 'both' ||
              (nextMode === 'internal'
                ? account.availability === 'intern'
                : account.availability === 'fiskal'),
        )
        .map((account) => account.id),
    );
    debits = debits.map((row) =>
      availableIds.has(row.account_id) ? row : { ...row, account_id: '' },
    );
    credits = credits.map((row) =>
      availableIds.has(row.account_id) ? row : { ...row, account_id: '' },
    );
  }

  function addDebit() {
    debits = [...debits, blankRow()];
  }
  function addCredit() {
    credits = [...credits, blankRow()];
  }
  function updateDebit(i: number, next: Row) {
    debits = debits.map((r, idx) => (idx === i ? next : r));
  }
  function updateCredit(i: number, next: Row) {
    credits = credits.map((r, idx) => (idx === i ? next : r));
  }
  function removeDebit(i: number) {
    debits = debits.filter((_, idx) => idx !== i);
  }
  function removeCredit(i: number) {
    credits = credits.filter((_, idx) => idx !== i);
  }

  function payload(): FormPayload {
    const clean = (rows: Row[]) => rows.filter((r) => r.account_id && Number(r.amount) > 0);
    return {
      number,
      transaction_code: transactionCode,
      journal_mode: journalMode,
      type: journalType,
      date,
      memo,
      reference: reference || null,
      attachments,
      entries_debit: clean(debits),
      entries_credit: clean(credits),
    };
  }

  function selectAttachments(files: FileList | null) {
    if (!files) return;

    const allowedTypes = ['application/pdf', 'image/jpeg', 'image/png'];
    const validFiles = Array.from(files).filter(
      (file) => file.size <= 5 * 1024 * 1024 && allowedTypes.includes(file.type),
    );
    attachments = [...attachments, ...validFiles];
  }

  function confirmRemoveAttachment() {
    if (attachmentToRemove === null) return;

    attachments = attachments.filter((_, i) => i !== attachmentToRemove);
    attachmentToRemove = null;
  }

  function openAttachmentPreview(file: File) {
    closeAttachmentPreview();
    attachmentPreviewUrl = URL.createObjectURL(file);
    attachmentToPreview = file;
  }

  function closeAttachmentPreview() {
    if (attachmentPreviewUrl) URL.revokeObjectURL(attachmentPreviewUrl);
    attachmentPreviewUrl = null;
    attachmentToPreview = null;
  }

  function handleWindowKeydown(event: KeyboardEvent) {
    if (event.key === 'Escape' && attachmentToPreview) closeAttachmentPreview();
  }

  function confirmModeChange(includeAttachments: boolean) {
    if (!pendingJournalMode) return;

    if (!includeAttachments) attachments = [];
    changeJournalMode(pendingJournalMode);
    pendingJournalMode = null;
  }

  let templateLoading = $state(false);
  let templateError = $state<string | null>(null);

  async function handlePickTemplate(t: JournalTemplateSummary) {
    if (templateLoading) return;
    templateLoading = true;
    templateError = null;
    try {
      journalMode = t.journal_mode ?? 'internal';
      const detail = await templateApi.show(t.id);
      const dRows: Row[] = [];
      const cRows: Row[] = [];
      for (const ln of detail.lines) {
        const row: Row = {
          account_id: ln.account_id,
          amount: String(ln.amount ?? '0'),
          memo: ln.memo,
        };
        (ln.side === 'debit' ? dRows : cRows).push(row);
      }
      if (!memo && detail.name) memo = detail.name;
      debits = dRows.length ? dRows : [blankRow(), blankRow()];
      credits = cRows.length ? cRows : [blankRow()];
    } catch (e) {
      templateError = e instanceof Error ? e.message : String(e);
    } finally {
      templateLoading = false;
    }
  }
</script>

<svelte:window on:keydown={handleWindowKeydown} />

<div class="ak-journal-shell">
  <header class="px-6 pt-4 pb-2">
    <p class="text-xs font-medium text-text-muted">{breadcrumb}</p>
    <div class="flex flex-wrap items-center justify-between gap-3 mt-1">
      <div>
        <p class="text-sm font-medium text-text-muted">{title}</p>
      </div>
      <div class="group relative">
        <div
          class="inline-flex items-center gap-1 rounded-full border border-border-default bg-card-bg p-1 text-sm shadow-xs"
          role="group"
          aria-label="Mode input jurnal"
          aria-describedby="journal-mode-tooltip"
          data-testid="journal-mode-toggle"
        >
          <button
            type="button"
            class="rounded-full px-3 py-1 font-medium disabled:cursor-not-allowed disabled:opacity-70 {journalMode ===
            'internal'
              ? 'bg-[#22c55e] text-white'
              : 'text-text-muted'}"
            onclick={() => requestJournalMode('internal')}
            disabled={!!initial || saving}
            aria-pressed={journalMode === 'internal'}>Intern</button
          >
          <button
            type="button"
            class="rounded-full px-3 py-1 font-medium disabled:cursor-not-allowed disabled:opacity-70 {journalMode ===
            'both'
              ? 'bg-gradient-to-r from-[#22c55e] to-[#facc15] text-white'
              : 'text-text-muted'}"
            onclick={() => requestJournalMode('both')}
            disabled={!!initial || saving}
            aria-pressed={journalMode === 'both'}>Intern &amp; Fiskal</button
          >
          <button
            type="button"
            class="rounded-full px-3 py-1 font-medium disabled:cursor-not-allowed disabled:opacity-70 {journalMode ===
            'fiscal'
              ? 'bg-[#facc15] text-[#5a4300]'
              : 'text-text-muted'}"
            onclick={() => requestJournalMode('fiscal')}
            disabled={!!initial || saving}
            aria-pressed={journalMode === 'fiscal'}>Fiskal</button
          >
        </div>
        <div
          id="journal-mode-tooltip"
          role="tooltip"
          class="pointer-events-none absolute right-0 top-full z-10 mt-2 hidden w-72 rounded-md border border-border-default bg-card-bg px-3 py-2 text-left text-xs shadow-lg group-hover:block group-focus-within:block"
        >
          <strong class="block text-text-default">
            Mode Jurnal: {modeLabel(journalMode)}
          </strong>
          <span class="mt-1 block text-text-muted">
            {journalMode === 'fiscal'
              ? 'Akun dan template untuk pelaporan pajak.'
              : journalMode === 'both'
                ? 'Jurnal yang sama dibuat sebagai draft di buku Intern dan Fiskal.'
                : 'Akun dan template untuk pembukuan internal.'}
          </span>
        </div>
      </div>
    </div>
  </header>

  <div class="grid grid-cols-1 xl:grid-cols-12 gap-4 px-6 pb-32">
    {#if serverMessage || fieldError('number') || fieldError('entries') || fieldError('tenant')}
      <div
        class="xl:col-span-12 rounded-md border border-danger/40 bg-danger/5 px-3 py-2 text-sm text-danger"
        data-testid="form-banner"
      >
        {fieldError('number') ?? fieldError('entries') ?? fieldError('tenant') ?? serverMessage}
      </div>
    {/if}

    <!-- Main: debit + credit panels + lampiran -->
    <div class="xl:col-span-9 flex flex-col gap-4">
      <section
        class="rounded-lg border border-border-default bg-card-bg p-4 shadow-xs"
        data-testid="debit-panel"
      >
        <PanelHeader side="debit" total={debitTotal} />
        <div class="flex flex-col gap-2">
          {#each debits as row, i (i)}
            <EntryRow
              {row}
              index={i}
              accounts={visibleAccounts}
              onChange={(n) => updateDebit(i, n)}
              onRemove={() => removeDebit(i)}
            />
          {/each}
        </div>
        <AddLineButton side="debit" onclick={addDebit} />
      </section>

      <section
        class="rounded-lg border border-border-default bg-card-bg p-4 shadow-xs"
        data-testid="credit-panel"
      >
        <PanelHeader side="credit" total={creditTotal} />
        <div class="flex flex-col gap-2">
          {#each credits as row, i (i)}
            <EntryRow
              {row}
              index={i}
              accounts={visibleAccounts}
              onChange={(n) => updateCredit(i, n)}
              onRemove={() => removeCredit(i)}
            />
          {/each}
        </div>
        <AddLineButton side="credit" onclick={addCredit} />
      </section>

      <section class="rounded-lg border border-border-default bg-card-bg p-4 shadow-xs">
        <h2 class="text-xs font-bold uppercase tracking-wider text-text-muted mb-2">Lampiran</h2>
        <input
          bind:this={attachmentInput}
          class="sr-only"
          type="file"
          accept="application/pdf,image/jpeg,image/png"
          multiple
          onchange={(event) => selectAttachments(event.currentTarget.files)}
          data-testid="journal-attachments-input"
        />
        <button
          type="button"
          class="w-full rounded-md border border-dashed border-border-default p-6 text-center text-sm text-text-muted hover:border-primary hover:text-primary"
          onclick={() => attachmentInput?.click()}
          data-testid="journal-attachments-picker"
        >
          Klik untuk pilih file
          <span class="block text-xs mt-1 opacity-70">Maks. 5MB per file (PDF, JPG, PNG)</span>
        </button>
        {#if attachments.length > 0}
          <ul class="mt-3 space-y-2">
            {#each attachments as file, index (file.name + file.lastModified)}
              <li
                class="flex items-center justify-between gap-2 rounded-md bg-page-bg px-3 py-2 text-sm"
              >
                <button
                  type="button"
                  class="flex min-w-0 flex-1 items-center gap-2 text-left hover:text-primary"
                  onclick={() => openAttachmentPreview(file)}
                  title="Klik untuk melihat lampiran"
                  data-testid="journal-attachment-preview-{index}"
                >
                  <span class="shrink-0 text-xs font-semibold uppercase text-text-muted">
                    {file.type === 'application/pdf' ? 'PDF' : 'IMG'}
                  </span>
                  <span class="min-w-0 truncate">{file.name}</span>
                </button>
                <button
                  type="button"
                  class="shrink-0 text-xs font-medium text-danger hover:underline"
                  onclick={() => (attachmentToRemove = index)}>Hapus</button
                >
              </li>
            {/each}
          </ul>
        {/if}
      </section>
    </div>

    <!-- Sidebar -->
    <aside class="xl:col-span-3 flex flex-col gap-4 xl:sticky xl:top-4 xl:self-start">
      <section class="rounded-lg border border-border-default bg-card-bg p-4 shadow-xs">
        <div class="flex flex-col gap-3">
          <label class="text-sm">
            <span class="block font-medium mb-1"
              >Kode Transaksi <span class="text-danger">*</span></span
            >
            <input
              type="text"
              class="w-full rounded-md border border-border-default bg-page-bg px-2 py-1.5 focus:outline-none focus:border-primary"
              bind:value={transactionCode}
              placeholder="Mis. TRX-2026-0001"
              maxlength="80"
              required
              readonly
              aria-readonly="true"
              data-testid="journal-transaction-code"
            />
            {#if fieldError('transaction_code')}
              <span class="block mt-1 text-xs text-danger">{fieldError('transaction_code')}</span>
            {/if}
          </label>
          <label class="text-sm">
            <span class="block font-medium mb-1">Kode Jurnal</span>
            <input
              type="text"
              class="w-full rounded-md border border-border-default bg-page-bg px-2 py-1.5 text-text-muted focus:outline-none"
              value={displayedNumber}
              readonly
              aria-readonly="true"
              data-testid="journal-number-input"
            />
          </label>
          <label class="text-sm">
            <span class="block font-medium mb-1">Tipe Jurnal</span>
            <select
              class="w-full rounded-md border border-border-default bg-page-bg px-2 py-1.5 focus:outline-none focus:border-primary disabled:opacity-60"
              bind:value={journalType}
              disabled={!!initial || saving}
              data-testid="journal-type"
            >
              <option value="general">Jurnal Umum</option>
              <option value="adjustment">Jurnal Penyesuaian</option>
              <option value="reversing">Jurnal Koreksi</option>
              <option value="closing">Jurnal Penutup</option>
              <option value="opening">Jurnal Pembukaan</option>
            </select>
          </label>
          <label class="text-sm">
            <span class="block font-medium mb-1">Tanggal <span class="text-danger">*</span></span>
            <DateInput
              value={date}
              onChange={(iso) => (date = iso)}
              testId="journal-date"
              class={fieldError('date') ? 'ring-1 ring-danger rounded-md' : ''}
            />
            {#if fieldError('date')}
              <span class="block mt-1 text-xs text-danger" data-testid="error-date"
                >{fieldError('date')}</span
              >
            {/if}
          </label>
          <label class="text-sm">
            <span class="block font-medium mb-1">No. Bukti</span>
            <input
              type="text"
              class="w-full rounded-md border border-border-default bg-page-bg px-2 py-1.5 focus:outline-none focus:border-primary"
              bind:value={reference}
              placeholder="Mis. INV-2026-001"
              maxlength="120"
              data-testid="journal-reference"
            />
            {#if fieldError('reference')}
              <span class="block mt-1 text-xs text-danger" data-testid="error-reference"
                >{fieldError('reference')}</span
              >
            {/if}
          </label>
          <label class="text-sm">
            <span class="block font-medium mb-1">Keterangan <span class="text-danger">*</span></span
            >
            <textarea
              class="w-full resize-y rounded-md border px-2 py-1.5 focus:outline-none focus:border-primary {fieldError(
                'memo',
              )
                ? 'border-danger'
                : 'border-border-default'}"
              placeholder="Mis. Pembelian persediaan dari PT Surya Distribusi"
              bind:value={memo}
              rows="3"
              required
              data-testid="journal-memo"
            ></textarea>
            {#if fieldError('memo')}
              <span class="block mt-1 text-xs text-danger" data-testid="error-memo"
                >{fieldError('memo')}</span
              >
            {/if}
          </label>
        </div>
      </section>
      {#if templateError}
        <p class="rounded-md border border-danger bg-danger-light p-3 text-xs text-danger">
          Template gagal diterapkan: {templateError}
        </p>
      {/if}
      <TemplateSidebar templates={visibleTemplates} onPick={handlePickTemplate} />
    </aside>
  </div>

  <!-- Sticky footer -->
  <div
    class="fixed bottom-0 left-0 right-0 z-20 flex items-center justify-between gap-3 border-t border-border-default bg-card-bg px-6 py-3 shadow-[0_-8px_24px_rgba(15,23,42,0.08)]"
  >
    <BalancePill {debits} {credits} />
    <div class="flex items-center gap-3">
      {#if allowPosting}
        <button
          type="button"
          onclick={() => onSaveDraft(payload())}
          disabled={saving}
          class="rounded-md border border-border-default bg-card-bg px-4 py-2 text-sm font-semibold hover:bg-page-bg disabled:opacity-50"
          data-testid="save-draft"
        >
          Simpan Draft
        </button>
      {/if}
      <button
        type="button"
        onclick={onCancel}
        disabled={saving}
        class="text-sm font-medium text-text-muted hover:text-text-default disabled:opacity-50"
        data-testid="cancel"
      >
        Batal
      </button>
      <button
        type="button"
        onclick={() => onPosting(payload())}
        disabled={saving || !balanced}
        title={!balanced ? 'Total debit harus sama dengan kredit dan lebih dari nol.' : undefined}
        class="rounded-md bg-[#0F172A] px-4 py-2 text-sm font-semibold text-white hover:bg-[#1E293B] disabled:opacity-50 disabled:cursor-not-allowed"
        data-testid="posting-jurnal"
      >
        {auth.user?.roles?.includes('operator') ? 'Ajukan Review' : 'Posting Jurnal'}
      </button>
    </div>
  </div>

  {#if attachmentToPreview && attachmentPreviewUrl}
    <div
      class="fixed inset-0 z-40 flex items-center justify-center bg-black/60 p-4"
      role="presentation"
      onclick={closeAttachmentPreview}
    >
      <div
        class="flex max-h-[90vh] w-full max-w-5xl flex-col overflow-hidden rounded-lg bg-card-bg shadow-2xl"
        role="dialog"
        aria-modal="true"
        aria-labelledby="attachment-preview-title"
        tabindex="-1"
        onclick={(event) => event.stopPropagation()}
        onkeydown={(event) => event.stopPropagation()}
      >
        <header
          class="flex items-center justify-between gap-3 border-b border-border-default px-4 py-3"
        >
          <h2 id="attachment-preview-title" class="min-w-0 truncate text-sm font-bold">
            {attachmentToPreview.name}
          </h2>
          <button
            type="button"
            class="shrink-0 rounded-md border border-border-default px-3 py-1.5 text-sm font-semibold hover:bg-page-bg"
            onclick={closeAttachmentPreview}
            aria-label="Tutup preview lampiran"
          >
            Tutup
          </button>
        </header>
        <div class="flex min-h-[24rem] items-center justify-center overflow-auto bg-page-bg p-4">
          {#if attachmentToPreview.type.startsWith('image/')}
            <img
              src={attachmentPreviewUrl}
              alt={attachmentToPreview.name}
              class="max-h-[72vh] max-w-full object-contain"
            />
          {:else if attachmentToPreview.type === 'application/pdf'}
            <iframe
              src={attachmentPreviewUrl}
              title={`Preview ${attachmentToPreview.name}`}
              class="h-[72vh] w-full rounded border border-border-default bg-white"
            ></iframe>
          {:else}
            <a
              href={attachmentPreviewUrl}
              target="_blank"
              rel="noreferrer"
              class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white"
            >
              Buka lampiran
            </a>
          {/if}
        </div>
      </div>
    </div>
  {/if}

  {#if attachmentToRemove !== null && attachments[attachmentToRemove]}
    <div class="fixed inset-0 z-30 flex items-center justify-center bg-black/30 p-4">
      <div
        class="w-full max-w-sm rounded-lg border border-border-default bg-card-bg p-5 shadow-lg"
        role="dialog"
        aria-modal="true"
        aria-labelledby="remove-attachment-title"
        tabindex="-1"
      >
        <h2 id="remove-attachment-title" class="text-base font-bold text-text-default">
          Hapus lampiran?
        </h2>
        <p class="mt-2 text-sm text-text-muted">
          Lampiran <span class="font-medium text-text-default"
            >{attachments[attachmentToRemove].name}</span
          > akan dihapus dari formulir.
        </p>
        <div class="mt-5 flex justify-end gap-3">
          <button
            type="button"
            class="rounded-md border border-border-default px-3 py-2 text-sm font-semibold hover:bg-page-bg"
            onclick={() => (attachmentToRemove = null)}>Batal</button
          >
          <button
            type="button"
            class="rounded-md bg-danger px-3 py-2 text-sm font-semibold text-white hover:opacity-90"
            onclick={confirmRemoveAttachment}>Hapus Lampiran</button
          >
        </div>
      </div>
    </div>
  {/if}

  {#if pendingJournalMode}
    <div class="fixed inset-0 z-30 flex items-center justify-center bg-black/30 p-4">
      <div
        class="w-full max-w-md rounded-lg border border-border-default bg-card-bg p-5 shadow-lg"
        role="dialog"
        aria-modal="true"
        aria-labelledby="mode-attachment-warning-title"
        tabindex="-1"
        data-testid="journal-mode-attachment-warning"
      >
        <h2 id="mode-attachment-warning-title" class="text-base font-bold text-text-default">
          Sertakan lampiran ke mode {modeLabel(pendingJournalMode)}?
        </h2>
        <p class="mt-2 text-sm text-text-muted">
          {attachments.length} lampiran yang dipilih pada mode {modeLabel(journalMode)} masih ada di formulir.
          Apakah lampiran ini ingin disertakan ke mode {modeLabel(pendingJournalMode)}?
        </p>
        <div class="mt-5 flex flex-wrap justify-end gap-3">
          <button
            type="button"
            class="rounded-md border border-border-default px-3 py-2 text-sm font-semibold hover:bg-page-bg"
            onclick={() => (pendingJournalMode = null)}>Batal</button
          >
          <button
            type="button"
            class="rounded-md border border-danger/40 px-3 py-2 text-sm font-semibold text-danger hover:bg-danger-light"
            onclick={() => confirmModeChange(false)}>Jangan Sertakan</button
          >
          <button
            type="button"
            class="rounded-md bg-primary px-3 py-2 text-sm font-semibold text-white hover:bg-primary-active"
            onclick={() => confirmModeChange(true)}>Sertakan Lampiran</button
          >
        </div>
      </div>
    </div>
  {/if}
</div>
