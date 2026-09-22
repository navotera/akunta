<script lang="ts">
  import { onMount } from 'svelte';
  import { afterNavigate, goto } from '$app/navigation';
  import { page } from '$app/stores';
  import { auth } from '$lib/stores/auth.svelte.js';
  import JournalForm, { type FormPayload } from '$lib/components/journal/JournalForm.svelte';
  import { journalApi } from '$lib/api/journal.js';
  import { accountApi, type AccountOption } from '$lib/api/account.js';
  import {
    templateApi,
    type JournalTemplateSummary,
    type JournalTemplateDetail,
  } from '$lib/api/template.js';
  import { ApiError } from '$lib/api/client.js';
  import { attachmentApi } from '$lib/api/attachment.js';
  import { clearJournalDraft } from '$lib/stores/journalDraft.js';
  import { formatMessageDates } from '$lib/utils/date.js';

  const JOURNAL_ATTACHABLE_TYPE = 'App\\Models\\Journal';

  let accounts = $state<AccountOption[]>([]);
  let templates = $state<JournalTemplateSummary[]>([]);
  let saving = $state(false);
  let serverErrors = $state<Record<string, string[]> | null>(null);
  let serverMessage = $state<string | null>(null);
  let accountsRequest = 0;
  let templateMode = $derived($page.url.searchParams.get('as_template') === '1');
  let templateId = $derived($page.url.searchParams.get('template_id'));
  let editingTemplate = $state<JournalTemplateDetail | null>(null);

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
    const availableTemplates = await templateApi.list(50);
    await refreshAccounts();
    templates = availableTemplates;
    if (templateId) editingTemplate = await templateApi.show(templateId);
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
    if (saving || templateMode) return;
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
    if (saving || templateMode) return;
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
          auth.user?.roles?.some((role) => role.toLowerCase() === 'accountant')
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

  async function saveAsTemplate(payload: FormPayload) {
    const name = payload.memo.trim();
    if (!name) {
      serverErrors = { name: ['Nama template wajib diisi.'] };
      serverMessage = 'Nama template wajib diisi.';
      return;
    }
    const code = window.prompt(
      'Kode template jurnal:',
      payload.transaction_code || `TPL-${new Date().getTime()}`,
    );
    if (!code?.trim()) return;

    saving = true;
    serverErrors = null;
    serverMessage = null;
    try {
      await templateApi.create({
        code: code.trim(),
        name,
        description: payload.description.trim() || null,
        journal_mode: payload.journal_mode,
        is_bookmarked: payload.is_bookmarked ?? false,
        lines: [
          ...payload.entries_debit.map((line) => ({ ...line, side: 'debit' as const })),
          ...payload.entries_credit.map((line) => ({ ...line, side: 'credit' as const })),
        ],
      });
      clearJournalDraft('/journals/new');
      goto('/template-jurnal');
    } catch (e) {
      captureError(e);
    } finally {
      saving = false;
    }
  }

  async function updateTemplate(payload: FormPayload, template: JournalTemplateDetail) {
    const name = payload.memo.trim();
    if (!name) {
      serverErrors = { name: ['Nama template wajib diisi.'] };
      serverMessage = 'Nama template wajib diisi.';
      return;
    }

    saving = true;
    serverErrors = null;
    serverMessage = null;
    try {
      await templateApi.update(template.id, {
        code: template.code,
        name,
        description: payload.description.trim() || null,
        journal_mode: payload.journal_mode,
        is_bookmarked: payload.is_bookmarked ?? false,
        lines: [
          ...payload.entries_debit.map((line) => ({ ...line, side: 'debit' as const })),
          ...payload.entries_credit.map((line) => ({ ...line, side: 'credit' as const })),
        ],
      });
      goto('/template-jurnal');
    } catch (e) {
      captureError(e);
    } finally {
      saving = false;
    }
  }

  function cancel() {
    clearJournalDraft('/journals/new');
    goto(templateMode ? '/template-jurnal' : '/journals');
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
    allowSaveAsTemplate={templateMode}
    {templateMode}
    template={editingTemplate}
    title={templateMode ? 'Template Jurnal Baru' : undefined}
    breadcrumb={templateMode ? 'Jurnal / Template Baru' : undefined}
    onSaveDraft={saveDraft}
    onPosting={postingJurnal}
    onSaveAsTemplate={saveAsTemplate}
    onUpdateTemplate={updateTemplate}
    onCancel={cancel}
  />
{/if}
