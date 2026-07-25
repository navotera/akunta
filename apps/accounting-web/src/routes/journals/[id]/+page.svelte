<script lang="ts">
  import { onMount } from 'svelte';
  import { goto } from '$app/navigation';
  import { page } from '$app/stores';
  import { auth } from '$lib/stores/auth.svelte.js';
  import JournalForm, { type FormPayload } from '$lib/components/journal/JournalForm.svelte';
  import { journalApi, type JournalDetail, type JournalSummary } from '$lib/api/journal.js';
  import { accountApi, type AccountOption } from '$lib/api/account.js';
  import { templateApi, type JournalTemplateSummary } from '$lib/api/template.js';
  import { ApiError } from '$lib/api/client.js';

  let detail = $state<JournalDetail | null>(null);
  let accounts = $state<AccountOption[]>([]);
  let templates = $state<JournalTemplateSummary[]>([]);
  let recent = $state<JournalSummary | null>(null);
  let saving = $state(false);
  let serverErrors = $state<Record<string, string[]> | null>(null);
  let serverMessage = $state<string | null>(null);

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
    const id = $page.params.id;
    if (!id) {
      goto('/journals', { replaceState: true });
      return;
    }
    [detail, accounts, templates, recent] = await Promise.all([
      journalApi.show(id),
      accountApi.list(),
      templateApi.list(4),
      journalApi.list({ per_page: 1 }).then((r) => r.data[0] ?? null),
    ]);
  });

  async function saveDraft(payload: FormPayload) {
    if (!detail) return;
    saving = true;
    serverErrors = null;
    serverMessage = null;
    try {
      const updated = await journalApi.update(detail.id, {
        journal_mode: payload.journal_mode,
        date: payload.date,
        memo: payload.memo,
        reference: payload.reference,
        entries_debit: payload.entries_debit,
        entries_credit: payload.entries_credit,
      });
      detail = updated;
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
        journal_mode: payload.journal_mode,
        date: payload.date,
        memo: payload.memo,
        reference: payload.reference,
        entries_debit: payload.entries_debit,
        entries_credit: payload.entries_credit,
      });
      await journalApi.post(updated.id);
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
</script>

{#if !detail}
  <div class="flex min-h-screen items-center justify-center text-text-muted">Memuat jurnal…</div>
{:else}
  <JournalForm
    initial={detail}
    {accounts}
    {templates}
    {recent}
    {saving}
    {serverErrors}
    {serverMessage}
    title={`Jurnal ${detail.number}`}
    breadcrumb={`Transaksi / Jurnal / ${detail.number}`}
    onSaveDraft={saveDraft}
    onPosting={postingJurnal}
    onCancel={cancel}
  />
{/if}
