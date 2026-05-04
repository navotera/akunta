<script lang="ts">
  import { onMount } from 'svelte';
  import { goto } from '$app/navigation';
  import { auth } from '$lib/stores/auth.svelte.js';
  import JournalForm, { type FormPayload } from '$lib/components/journal/JournalForm.svelte';
  import { journalApi, type JournalSummary } from '$lib/api/journal.js';
  import { accountApi, type AccountOption } from '$lib/api/account.js';
  import { templateApi, type JournalTemplateSummary } from '$lib/api/template.js';
  import { ApiError } from '$lib/api/client.js';

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
    [accounts, templates, recent] = await Promise.all([
      accountApi.list(),
      templateApi.list(4),
      journalApi.list({ per_page: 1 }).then((r) => r.data[0] ?? null),
    ]);
  });

  async function saveDraft(payload: FormPayload) {
    if (saving) return;
    saving = true;
    serverErrors = null;
    serverMessage = null;
    try {
      const created = await journalApi.create({
        number: payload.number,
        date: payload.date,
        memo: payload.memo,
        entries_debit: payload.entries_debit,
        entries_credit: payload.entries_credit,
      });
      goto(`/journals/${created.id}`);
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
        number: payload.number,
        date: payload.date,
        memo: payload.memo,
        entries_debit: payload.entries_debit,
        entries_credit: payload.entries_credit,
      });
      await journalApi.post(created.id);
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

{#if accounts.length === 0}
  <div class="flex min-h-screen items-center justify-center text-text-muted">Memuat akun…</div>
{:else}
  <JournalForm
    {accounts}
    {templates}
    {recent}
    {saving}
    {serverErrors}
    {serverMessage}
    onSaveDraft={saveDraft}
    onPosting={postingJurnal}
    onCancel={cancel}
  />
{/if}
