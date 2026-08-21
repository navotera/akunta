<script lang="ts">
  import { onMount } from 'svelte';
  import { afterNavigate, goto } from '$app/navigation';
  import { page } from '$app/stores';
  import { auth } from '$lib/stores/auth.svelte.js';
  import JournalForm, { type FormPayload } from '$lib/components/journal/JournalForm.svelte';
  import { journalApi, type JournalDetail } from '$lib/api/journal.js';
  import { accountApi, type AccountOption } from '$lib/api/account.js';
  import { templateApi, type JournalTemplateSummary } from '$lib/api/template.js';
  import { ApiError } from '$lib/api/client.js';
  import { attachmentApi } from '$lib/api/attachment.js';
  import { formatMessageDates } from '$lib/utils/date.js';

  const JOURNAL_ATTACHABLE_TYPE = 'App\\Models\\Journal';

  let detail = $state<JournalDetail | null>(null);
  let accounts = $state<AccountOption[]>([]);
  let templates = $state<JournalTemplateSummary[]>([]);
  let saving = $state(false);
  let serverErrors = $state<Record<string, string[]> | null>(null);
  let serverMessage = $state<string | null>(null);
  let accountsRequest = 0;
  let isSupervisor = $derived(
    Boolean(
      auth.user?.is_admin ||
      auth.user?.roles?.some((role) =>
        ['admin', 'super_admin', 'supervisor'].includes(role.toLowerCase()),
      ),
    ),
  );
  let isStoredLocked = $derived(detail?.status === 'posted' && !isSupervisor);

  async function refreshAccounts() {
    const request = ++accountsRequest;
    const loadedAccounts = await accountApi.list();
    if (request === accountsRequest) accounts = loadedAccounts;
  }

  afterNavigate(({ to }) => {
    if (to?.url.pathname.startsWith('/journals/')) void refreshAccounts();
  });

  function captureError(e: unknown) {
    if (e instanceof ApiError) {
      const body = e.body as { message?: string; errors?: Record<string, string[]> } | null;
      serverErrors = body?.errors
        ? Object.fromEntries(
            Object.entries(body.errors).map(([key, messages]) => [
              key,
              messages.map((message) => formatMessageDates(message)),
            ]),
          )
        : null;
      serverMessage = formatMessageDates(body?.message ?? `Server error ${e.status}`);
    } else {
      serverErrors = null;
      serverMessage = formatMessageDates(e instanceof Error ? e.message : String(e));
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
    const id = $page.params.id;
    if (!id) {
      goto('/journals', { replaceState: true });
      return;
    }
    [detail, templates] = await Promise.all([journalApi.show(id), templateApi.list(4)]);
    await refreshAccounts();
  });

  async function saveDraft(payload: FormPayload) {
    if (!detail) return;
    saving = true;
    serverErrors = null;
    serverMessage = null;
    try {
      const updated = await journalApi.update(detail.id, {
        transaction_code: payload.transaction_code,
        journal_mode: payload.journal_mode,
        type: payload.type,
        date: payload.date,
        memo: payload.memo,
        reference: payload.reference,
        entries_debit: payload.entries_debit,
        entries_credit: payload.entries_credit,
      });
      await Promise.all(
        payload.attachments.map((file) =>
          attachmentApi.upload(JOURNAL_ATTACHABLE_TYPE, updated.id, file),
        ),
      );
      detail = await journalApi.show(updated.id);
    } catch (e) {
      captureError(e);
    } finally {
      saving = false;
    }
  }

  async function postingJurnal(payload: FormPayload) {
    if (!detail) return;
    saving = true;
    serverErrors = null;
    serverMessage = null;
    try {
      const updated = await journalApi.update(detail.id, {
        transaction_code: payload.transaction_code,
        journal_mode: payload.journal_mode,
        type: payload.type,
        date: payload.date,
        memo: payload.memo,
        reference: payload.reference,
        entries_debit: payload.entries_debit,
        entries_credit: payload.entries_credit,
      });
      await Promise.all(
        payload.attachments.map((file) =>
          attachmentApi.upload(JOURNAL_ATTACHABLE_TYPE, updated.id, file),
        ),
      );
      if (auth.user?.roles?.includes('operator')) {
        await journalApi.submit(updated.id);
      } else {
        await journalApi.post(updated.id);
      }
      goto('/journals');
    } catch (e) {
      captureError(e);
    } finally {
      saving = false;
    }
  }

  function cancel() {
    goto('/journals');
  }

  async function approveReview(payload: FormPayload) {
    if (!detail) return;
    saving = true;
    serverErrors = null;
    serverMessage = null;
    try {
      const updated = await journalApi.update(detail.id, {
        transaction_code: payload.transaction_code,
        journal_mode: payload.journal_mode,
        type: payload.type,
        date: payload.date,
        memo: payload.memo,
        reference: payload.reference,
        entries_debit: payload.entries_debit,
        entries_credit: payload.entries_credit,
      });
      await Promise.all(
        payload.attachments.map((file) =>
          attachmentApi.upload(JOURNAL_ATTACHABLE_TYPE, updated.id, file),
        ),
      );
      const journalsToPost = [updated, ...(updated.paired_journal ? [updated.paired_journal] : [])];
      await Promise.all(journalsToPost.map((journal) => journalApi.post(journal.id)));
      goto('/journals');
    } catch (e) {
      captureError(e);
    } finally {
      saving = false;
    }
  }

  async function rejectReview(payload: FormPayload) {
    if (!detail) return;
    const note = window.prompt('Catatan revisi untuk operator:');
    if (!note?.trim()) return;
    saving = true;
    serverErrors = null;
    serverMessage = null;
    try {
      const updated = await journalApi.update(detail.id, {
        transaction_code: payload.transaction_code,
        journal_mode: payload.journal_mode,
        type: payload.type,
        date: payload.date,
        memo: payload.memo,
        reference: payload.reference,
        entries_debit: payload.entries_debit,
        entries_credit: payload.entries_credit,
      });
      await Promise.all(
        payload.attachments.map((file) =>
          attachmentApi.upload(JOURNAL_ATTACHABLE_TYPE, updated.id, file),
        ),
      );
      const journalsToRevise = [
        updated,
        ...(updated.paired_journal ? [updated.paired_journal] : []),
      ];
      detail = await journalApi.reject(updated.id, note.trim());
      if (journalsToRevise.length > 1) {
        await Promise.all(
          journalsToRevise.slice(1).map((journal) => journalApi.reject(journal.id, note.trim())),
        );
      }
    } catch (e) {
      captureError(e);
    } finally {
      saving = false;
    }
  }
</script>

{#if !detail}
  <div class="flex min-h-screen items-center justify-center text-text-muted">Memuat jurnal…</div>
{:else if isStoredLocked}
  <div class="mx-auto max-w-4xl space-y-4 p-6">
    <div class="rounded-xl border border-paid/30 bg-paid-light p-5">
      <div class="flex items-center justify-between gap-3">
        <div>
          <h1 class="text-xl font-bold">Jurnal {detail.number}</h1>
          <p class="mt-1 text-sm text-text-muted">Jurnal Tersimpan dan terkunci untuk operator.</p>
        </div>
        <span class="rounded-full bg-paid px-3 py-1 text-xs font-semibold text-white"
          >Tersimpan</span
        >
      </div>
      <div class="mt-4 grid gap-3 text-sm sm:grid-cols-3">
        <div>
          <div class="text-text-muted">Tanggal</div>
          <div class="font-medium">{detail.date}</div>
        </div>
        <div>
          <div class="text-text-muted">No. Bukti</div>
          <div class="font-medium">{detail.reference ?? '—'}</div>
        </div>
        <div>
          <div class="text-text-muted">Mode</div>
          <div class="font-medium">{detail.journal_mode === 'fiscal' ? 'Fiskal' : 'Intern'}</div>
        </div>
      </div>
      <p class="mt-4 text-sm">{detail.memo}</p>
    </div>
    <div class="overflow-x-auto rounded-xl border border-border-default bg-card-bg">
      <table class="w-full text-sm">
        <thead class="bg-page-bg text-left text-xs uppercase tracking-wider text-text-muted">
          <tr
            ><th class="px-4 py-3">Akun</th><th class="px-4 py-3">Keterangan</th><th
              class="px-4 py-3 text-right">Debit</th
            ><th class="px-4 py-3 text-right">Kredit</th></tr
          >
        </thead>
        <tbody>
          {#each detail.entries_debit as entry}
            <tr class="border-t border-border-soft"
              ><td class="px-4 py-3">{entry.account_code} — {entry.account_name}</td><td
                class="px-4 py-3">{entry.memo ?? '—'}</td
              ><td class="px-4 py-3 text-right font-mono">{entry.amount}</td><td
                class="px-4 py-3 text-right">—</td
              ></tr
            >
          {/each}
          {#each detail.entries_credit as entry}
            <tr class="border-t border-border-soft"
              ><td class="px-4 py-3">{entry.account_code} — {entry.account_name}</td><td
                class="px-4 py-3">{entry.memo ?? '—'}</td
              ><td class="px-4 py-3 text-right">—</td><td class="px-4 py-3 text-right font-mono"
                >{entry.amount}</td
              ></tr
            >
          {/each}
        </tbody>
      </table>
    </div>
    <button
      type="button"
      class="rounded-md border border-border-default px-4 py-2 text-sm font-semibold"
      onclick={cancel}>Kembali ke daftar jurnal</button
    >
  </div>
{:else if detail.status === 'submitted' && isSupervisor}
  <JournalForm
    initial={detail}
    {accounts}
    {templates}
    {saving}
    {serverErrors}
    {serverMessage}
    reviewMode={true}
    allowPosting={false}
    title={`Review Jurnal ${detail.number}`}
    breadcrumb={`Transaksi / Review Jurnal / ${detail.number}`}
    onSaveDraft={saveDraft}
    onPosting={postingJurnal}
    onApprove={approveReview}
    onRevise={rejectReview}
    onCancel={cancel}
    auditTrail={detail.audit_trail}
  />
{:else if detail.status === 'submitted'}
  <div class="mx-auto max-w-3xl space-y-4 p-6">
    <div class="rounded-xl border border-warning/30 bg-warning-light p-5">
      <h1 class="text-xl font-bold">Jurnal {detail.number} menunggu review</h1>
      <p class="mt-1 text-sm text-text-muted">Jurnal sedang menunggu pemeriksaan supervisor.</p>
      {#if serverMessage}<p class="mt-3 text-sm text-danger">{serverMessage}</p>{/if}
    </div>
  </div>
{:else}
  <JournalForm
    initial={detail}
    {accounts}
    {templates}
    {saving}
    {serverErrors}
    {serverMessage}
    allowPosting={detail.status !== 'posted'}
    title={`Jurnal ${detail.number}`}
    breadcrumb={`Transaksi / Jurnal / ${detail.number}`}
    onSaveDraft={saveDraft}
    onPosting={postingJurnal}
    onCancel={cancel}
    auditTrail={detail.audit_trail}
  />
{/if}
