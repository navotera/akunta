<script lang="ts">
  import PanelHeader from './PanelHeader.svelte';
  import EntryRow from './EntryRow.svelte';
  import AddLineButton from './AddLineButton.svelte';
  import BalancePill from './BalancePill.svelte';
  import TemplateSidebar from './TemplateSidebar.svelte';
  import type { AccountOption } from '$lib/api/account.js';
  import { journalApi, type JournalMode, type JournalDetail } from '$lib/api/journal.js';
  import { templateApi, type JournalTemplateSummary } from '$lib/api/template.js';
  import DateInput from '$lib/components/ui/DateInput.svelte';

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
    onSaveDraft: (payload: FormPayload) => Promise<void> | void;
    onPosting: (payload: FormPayload) => Promise<void> | void;
    onCancel: () => void;
  }

  export interface FormPayload {
    number?: string;
    journal_mode: JournalMode;
    date: string;
    memo: string;
    reference: string | null;
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
    onSaveDraft,
    onPosting,
    onCancel,
  }: Props = $props();

  function fieldError(name: string): string | null {
    return serverErrors?.[name]?.[0] ?? null;
  }

  const blankRow = (): Row => ({ account_id: '', amount: '0', memo: null });

  let date = $state(initial?.date ?? new Date().toISOString().slice(0, 10));
  let number = $state(initial?.number ?? '');
  let previewNumber = $state<string | null>(null);
  let journalMode = $state<JournalMode>(initial?.journal_mode ?? 'internal');
  let memo = $state(initial?.memo ?? '');
  let reference = $state(initial?.reference ?? '');

  let debits = $state<Row[]>(
    (initial?.entries_debit ?? []).map((e) => ({
      account_id: e.account_id,
      amount: e.amount,
      memo: e.memo,
    })),
  );
  let credits = $state<Row[]>(
    (initial?.entries_credit ?? []).map((e) => ({
      account_id: e.account_id,
      amount: e.amount,
      memo: e.memo,
    })),
  );

  // ensure starting rows
  if (debits.length === 0) debits = [blankRow(), blankRow()];
  if (credits.length === 0) credits = [blankRow()];

  const debitTotal = $derived(debits.reduce((s, r) => s + Number(r.amount || 0), 0));
  const creditTotal = $derived(credits.reduce((s, r) => s + Number(r.amount || 0), 0));
  const balanced = $derived(debitTotal > 0 && Math.abs(debitTotal - creditTotal) < 0.005);
  const visibleAccounts = $derived(
    accounts.filter(
      (account) =>
        account.availability === 'both' ||
        (journalMode === 'internal'
          ? account.availability === 'intern'
          : account.availability === 'fiskal'),
    ),
  );
  const visibleTemplates = $derived(
    templates.filter((template) => (template.journal_mode ?? 'internal') === journalMode),
  );
  const displayedNumber = $derived(number || previewNumber || 'Memuat nomor jurnal…');

  let previewRequest = 0;
  $effect(() => {
    if (number) return;

    const request = ++previewRequest;
    previewNumber = null;
    void journalApi
      .nextNumber(date, journalMode)
      .then(({ number: nextNumber }) => {
        if (request === previewRequest) previewNumber = nextNumber;
      })
      .catch(() => {
        if (request === previewRequest) previewNumber = null;
      });
  });

  function toggleJournalMode() {
    if (initial || saving) return;

    journalMode = journalMode === 'fiscal' ? 'internal' : 'fiscal';
    const availableIds = new Set(visibleAccounts.map((account) => account.id));
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
      journal_mode: journalMode,
      date,
      memo,
      reference: reference || null,
      entries_debit: clean(debits),
      entries_credit: clean(credits),
    };
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

<div class="ak-journal-shell">
  <header class="px-6 pt-4 pb-2">
    <p class="text-xs font-medium text-text-muted">{breadcrumb}</p>
    <div class="flex flex-wrap items-center justify-between gap-3 mt-1">
      <div>
        <p class="text-sm font-medium text-text-muted">{title}</p>
        <h1
          class="font-mono text-2xl font-bold leading-tight text-text-default"
          data-testid="journal-number"
        >
          {displayedNumber}
        </h1>
        <span class="text-sm font-medium text-text-muted">Tampilan T-account</span>
      </div>
      <button
        type="button"
        class="inline-flex items-center gap-2 rounded-full border border-border-default bg-card-bg p-1 text-sm shadow-xs disabled:cursor-not-allowed disabled:opacity-70"
        onclick={toggleJournalMode}
        disabled={!!initial || saving}
        aria-label="Ubah mode jurnal"
        aria-pressed={journalMode === 'fiscal'}
        data-testid="journal-mode-toggle"
      >
        <span
          class="rounded-full px-3 py-1 font-medium {journalMode === 'internal'
            ? 'bg-primary text-white'
            : 'text-text-muted'}">Intern</span
        >
        <span
          class="rounded-full px-3 py-1 font-medium {journalMode === 'fiscal'
            ? 'bg-warning text-white'
            : 'text-text-muted'}">Fiskal</span
        >
      </button>
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
        <div
          class="rounded-md border border-dashed border-border-default p-6 text-center text-sm text-text-muted"
        >
          Klik atau seret file ke sini
          <p class="text-xs mt-1 opacity-70">Maks. 5MB per file (PDF, JPG, PNG)</p>
        </div>
      </section>
    </div>

    <!-- Sidebar -->
    <aside class="xl:col-span-3 flex flex-col gap-4 xl:sticky xl:top-4 xl:self-start">
      <section class="rounded-lg border border-border-default bg-card-bg p-4 shadow-xs">
        <div class="flex flex-col gap-3">
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
            <span class="block font-medium mb-1">Keterangan <span class="text-danger">*</span></span>
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
      <button
        type="button"
        onclick={() => onSaveDraft(payload())}
        disabled={saving}
        class="rounded-md border border-border-default bg-card-bg px-4 py-2 text-sm font-semibold hover:bg-page-bg disabled:opacity-50"
        data-testid="save-draft"
      >
        Simpan Draft
      </button>
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
        Posting Jurnal
      </button>
    </div>
  </div>
</div>
