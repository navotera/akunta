<script lang="ts">
  import { onMount } from 'svelte';
  import { afterNavigate, goto } from '$app/navigation';
  import { auth } from '$lib/stores/auth.svelte.js';
  import JournalForm, { type FormPayload } from '$lib/components/journal/JournalForm.svelte';
  import { journalApi } from '$lib/api/journal.js';
  import { accountApi, type AccountOption } from '$lib/api/account.js';
  import { templateApi, type JournalTemplateSummary } from '$lib/api/template.js';
  import { ApiError } from '$lib/api/client.js';
  import { attachmentApi } from '$lib/api/attachment.js';
  import { clearJournalDraft } from '$lib/stores/journalDraft.js';

  const JOURNAL_ATTACHABLE_TYPE = 'App\\Models\\Journal';

  let accounts = $state<AccountOption[]>([]);
  let templates = $state<JournalTemplateSummary[]>([]);
  let saving = $state(false);
  let serverErrors = $state<Record<string, string[]> | null>(null);
  let serverMessage = $state<string | null>(null);
  let accountsRequest = 0;

  async function refreshAccounts() {
    const request = ++accountsRequest;
    const loadedAccounts = await accountApi.list();
    if (request === accountsRequest) accounts = loadedAccounts;
  }

  afterNavigate(({ to }) => {
    if (to?.url.pathname === '/journals/new') void refreshAccounts();
  });

  function captureError(e: unknown) {
    if (e instanceof ApiError) {
      const body = e.body as { message?: string; errors?: Record<string, string[]> } | null;
      serverErrors = body?.errors ?? null;
      serverMessage = body?.message ?? `Server error ${e.status}`;
    } else {
      serverErrors = null;
      serverMessage = e instanceof Error ? e.message : String(e);
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
    const [internalTemplates, fiscalTemplates] = await Promise.all([
      templateApi.list(50, undefined, 'internal'),
      templateApi.list(50, undefined, 'fiscal'),
    ]);
    await refreshAccounts();
    templates = [...internalTemplates, ...fiscalTemplates];
  });

  async function uploadAttachments(
    created: Awaited<ReturnType<typeof journalApi.create>>,
    files: File[],
  ) {
    const journals = [created, ...(created.paired_journal ? [created.paired_journal] : [])];
    await Promise.all(
      journals.flatMap((journal) =>
        files.map((file) => attachmentApi.upload(JOURNAL_ATTACHABLE_TYPE, journal.id, file)),
      ),
    );
  }

  async function saveDraft(payload: FormPayload) {
    if (saving) return;
    saving = true;
    serverErrors = null;
    serverMessage = null;
    try {
      const created = await journalApi.create({
        transaction_code: payload.transaction_code,
        journal_mode: payload.journal_mode,
        type: payload.type,
        date: payload.date,
        memo: payload.memo,
        reference: payload.reference,
        entries_debit: payload.entries_debit,
        entries_credit: payload.entries_credit,
      });
      await uploadAttachments(created, payload.attachments);
      clearJournalDraft('/journals/new');
      goto(created.paired_journal ? '/journals' : `/journals/${created.id}`);
    } catch (e) {
      captureError(e);
    } finally {
      saving = false;
    }
  }

  async function postingJurnal(payload: FormPayload) {
    if (saving) return;
    saving = true;
    serverErrors = null;
    serverMessage = null;
    try {
      const created = await journalApi.create({
        transaction_code: payload.transaction_code,
        journal_mode: payload.journal_mode,
        type: payload.type,
        date: payload.date,
        memo: payload.memo,
        reference: payload.reference,
        entries_debit: payload.entries_debit,
        entries_credit: payload.entries_credit,
      });
      await uploadAttachments(created, payload.attachments);
      const journals = [created, ...(created.paired_journal ? [created.paired_journal] : [])];
      await Promise.all(
        journals.map((journal) =>
          auth.user?.roles?.includes('operator')
            ? journalApi.submit(journal.id)
            : journalApi.post(journal.id),
        ),
      );
      clearJournalDraft('/journals/new');
      goto('/journals');
    } catch (e) {
      captureError(e);
    } finally {
      saving = false;
    }
  }

  function cancel() {
    clearJournalDraft('/journals/new');
    goto('/journals');
  }
</script>

{#if accounts.length === 0}
  <div class="flex min-h-screen items-center justify-center text-text-muted">Memuat akun…</div>
{:else}
  <JournalForm
    {accounts}
    {templates}
    {saving}
    {serverErrors}
    {serverMessage}
    onSaveDraft={saveDraft}
    onPosting={postingJurnal}
    onCancel={cancel}
  />
{/if}
