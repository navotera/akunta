<script lang="ts">
  import { onMount } from 'svelte';
  import { goto } from '$app/navigation';
  import { auth } from '$lib/stores/auth.svelte.js';
  import {
    templateApi,
    type JournalTemplateSummary,
    type JournalTemplateDetail,
    type JournalTemplateInput,
  } from '$lib/api/template.js';
  import { accountApi, type Account } from '$lib/api/account.js';
  import { ApiError } from '$lib/api/client.js';
  import AccountCombobox from '$lib/components/ui/AccountCombobox.svelte';

  interface LineDraft {
    account_id: string;
    side: 'debit' | 'credit';
    amount: string;
    memo: string;
  }

  let items = $state<JournalTemplateSummary[]>([]);
  let accounts = $state<Account[]>([]);
  let loading = $state(true);
  let error = $state<string | null>(null);

  let editing = $state<JournalTemplateDetail | null>(null);
  let creating = $state(false);
  let form = $state<{
    code: string;
    name: string;
    description: string;
    journal_mode: 'internal' | 'fiscal';
    lines: LineDraft[];
  }>({
    code: '',
    name: '',
    description: '',
    journal_mode: 'internal',
    lines: [],
  });
  let formErrors = $state<Record<string, string[]> | null>(null);
  let saving = $state(false);
  let bookmarking = $state<string | null>(null);
  const visibleAccounts = $derived(
    accounts.filter(
      (account) =>
        account.availability === 'both' ||
        (form.journal_mode === 'internal'
          ? account.availability === 'intern'
          : account.availability === 'fiskal'),
    ),
  );

  function changeMode(mode: 'internal' | 'fiscal') {
    form.journal_mode = mode;
    const availableAccountIds = new Set(
      accounts
        .filter(
          (account) =>
            account.availability === 'both' ||
            (mode === 'internal'
              ? account.availability === 'intern'
              : account.availability === 'fiskal'),
        )
        .map((account) => account.id),
    );
    form.lines = form.lines.map((line) =>
      availableAccountIds.has(line.account_id) ? line : { ...line, account_id: '' },
    );
  }

  const blankLine = (): LineDraft => ({ account_id: '', side: 'debit', amount: '0', memo: '' });

  async function load() {
    loading = true;
    error = null;
    try {
      [items, accounts] = await Promise.all([
        templateApi.list(50),
        accountApi.list('', null, false),
      ]);
    } catch (e) {
      error = e instanceof Error ? e.message : String(e);
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
    await load();
  });

  function openCreate() {
    creating = true;
    editing = null;
    form = {
      code: '',
      name: '',
      description: '',
      journal_mode: 'internal',
      lines: [blankLine(), blankLine()],
    };
    formErrors = null;
  }

  async function openEdit(s: JournalTemplateSummary) {
    creating = false;
    formErrors = null;
    const d = await templateApi.show(s.id);
    editing = d;
    form = {
      code: d.code,
      name: d.name,
      description: d.description ?? '',
      journal_mode: d.journal_mode ?? 'internal',
      lines: d.lines.map((l) => ({
        account_id: l.account_id,
        side: l.side,
        amount: l.amount,
        memo: l.memo ?? '',
      })),
    };
  }

  function closeForm() {
    editing = null;
    creating = false;
    formErrors = null;
  }

  function addLine() {
    form.lines = [...form.lines, blankLine()];
  }
  function removeLine(i: number) {
    form.lines = form.lines.filter((_, idx) => idx !== i);
  }

  async function save() {
    saving = true;
    formErrors = null;
    const payload: JournalTemplateInput = {
      code: form.code,
      name: form.name,
      description: form.description || null,
      journal_mode: form.journal_mode,
      lines: form.lines
        .filter((l) => l.account_id)
        .map((l) => ({
          account_id: l.account_id,
          side: l.side,
          amount: l.amount || '0',
          memo: l.memo || null,
        })),
    };
    try {
      if (editing) await templateApi.update(editing.id, payload);
      else await templateApi.create(payload);
      closeForm();
      await load();
    } catch (e) {
      if (e instanceof ApiError) {
        const body = e.body as { errors?: Record<string, string[]> } | null;
        formErrors = body?.errors ?? null;
      } else formErrors = { _: [(e as Error).message] };
    } finally {
      saving = false;
    }
  }

  async function destroy(s: JournalTemplateSummary) {
    if (!confirm(`Hapus template ${s.name}?`)) return;
    try {
      await templateApi.destroy(s.id);
      closeForm();
      await load();
    } catch (e) {
      alert(e instanceof Error ? e.message : String(e));
    }
  }

  async function toggleBookmark(event: MouseEvent, template: JournalTemplateSummary) {
    event.stopPropagation();
    if (bookmarking) return;
    const isBookmarked = !template.is_bookmarked;
    bookmarking = template.id;
    items = items.map((item) => (item.id === template.id ? { ...item, is_bookmarked: isBookmarked } : item));
    try {
      const updated = await templateApi.bookmark(template.id, isBookmarked);
      items = items.map((item) => (item.id === updated.id ? updated : item));
    } catch (e) {
      items = items.map((item) => (item.id === template.id ? { ...item, is_bookmarked: !isBookmarked } : item));
      error = e instanceof Error ? e.message : String(e);
    } finally {
      bookmarking = null;
    }
  }

  function fieldErr(name: string): string | null {
    return formErrors?.[name]?.[0] ?? null;
  }
</script>

<div class="px-6 py-6">
  <header class="mb-5 flex items-center justify-between">
    <div>
      <p class="text-xs font-medium text-text-muted">Master / Template Jurnal</p>
      <h1 class="text-2xl font-bold">Template Jurnal</h1>
      <p class="text-sm text-text-muted">{items.length} template</p>
    </div>
    <button
      type="button"
      class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-active"
      onclick={openCreate}
    >
      + Template Baru
    </button>
  </header>

  {#if loading}
    <div class="text-text-muted">Memuat…</div>
  {:else if error}
    <div class="rounded-md border border-danger bg-danger-light p-3 text-sm text-danger">
      {error}
    </div>
  {:else}
    <div class="overflow-x-auto rounded-lg border border-border-default bg-card-bg shadow-xs">
      <table class="w-full text-sm">
        <thead class="bg-page-bg text-xs uppercase tracking-wider text-text-muted">
          <tr>
            <th class="w-12 px-4 py-3 text-center">#</th>
            <th class="px-4 py-3 text-left">Kode</th>
            <th class="px-4 py-3 text-left">Nama</th>
            <th class="px-4 py-3 text-center">Mode</th>
            <th class="px-4 py-3 text-left">Deskripsi</th>
            <th class="px-4 py-3 text-center">Baris</th>
            <th class="px-4 py-3 text-center">Aktif</th>
          </tr>
        </thead>
        <tbody>
          {#each items as t, i (t.id)}
            <tr
              class="border-t border-border-soft hover:bg-page-bg cursor-pointer"
              onclick={() => openEdit(t)}
            >
              <td class="px-4 py-2 text-center text-text-muted">{i + 1}</td>
              <td class="px-4 py-2 font-mono">{t.code}</td>
              <td class="px-4 py-2 font-medium">
                <span class="flex items-center gap-2">
                  <button
                    type="button"
                    class="shrink-0 rounded p-1 transition-colors {t.is_bookmarked
                      ? 'text-primary'
                      : 'text-text-muted hover:text-primary'}"
                    aria-label={t.is_bookmarked ? `Hapus bookmark ${t.name}` : `Bookmark ${t.name}`}
                    aria-pressed={t.is_bookmarked === true}
                    title={t.is_bookmarked ? 'Hapus bookmark' : 'Bookmark template'}
                    onclick={(event) => toggleBookmark(event, t)}
                    disabled={bookmarking === t.id}
                  >
                    <svg width="16" height="16" viewBox="0 0 24 24" fill={t.is_bookmarked ? 'currentColor' : 'none'} stroke="currentColor" stroke-width="2" aria-hidden="true">
                      <path d="M6 4.75A1.75 1.75 0 0 1 7.75 3h8.5A1.75 1.75 0 0 1 18 4.75V21l-6-3.5L6 21V4.75Z" stroke-linejoin="round" />
                    </svg>
                  </button>
                  <span>{t.name}</span>
                </span>
              </td>
              <td class="px-4 py-2 text-center">
                <span
                  class="rounded-full px-2 py-1 text-xs font-semibold {t.journal_mode === 'fiscal'
                    ? 'bg-warning-light text-warning'
                    : 'bg-primary-light text-primary'}"
                >
                  {t.journal_mode === 'fiscal' ? 'Fiskal' : 'Intern'}
                </span>
              </td>
              <td class="px-4 py-2 text-text-muted">{t.description ?? '—'}</td>
              <td class="px-4 py-2 text-center">{t.lines_count}</td>
              <td class="px-4 py-2 text-center">{t.is_active ? '✓' : '—'}</td>
            </tr>
          {:else}
            <tr
                ><td colspan="7" class="px-4 py-10 text-center text-text-muted"
                >Belum ada template.</td
              ></tr
            >
          {/each}
        </tbody>
      </table>
    </div>
  {/if}
</div>

{#if creating || editing}
  <div
    class="fixed inset-0 z-30 flex items-center justify-center bg-black/30 p-4"
    onclick={closeForm}
  >
    <div
      class="w-full max-w-3xl rounded-lg bg-card-bg p-6 shadow-lg"
      onclick={(e) => e.stopPropagation()}
      role="dialog"
    >
      <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-lg font-bold">{editing ? `Edit ${editing.name}` : 'Template Baru'}</h2>
        <button
          type="button"
          class="inline-flex items-center gap-2 rounded-full border border-border-default bg-card-bg p-1 text-sm shadow-xs"
          onclick={() => changeMode(form.journal_mode === 'fiscal' ? 'internal' : 'fiscal')}
          aria-label="Ubah mode template jurnal"
          aria-pressed={form.journal_mode === 'fiscal'}
          data-testid="template-journal-mode-toggle"
        >
          <span
            class="rounded-full px-3 py-1 font-medium {form.journal_mode === 'internal'
              ? 'bg-primary text-white'
              : 'text-text-muted'}">Intern</span
          >
          <span
            class="rounded-full px-3 py-1 font-medium {form.journal_mode === 'fiscal'
              ? 'bg-warning text-white'
              : 'text-text-muted'}">Fiskal</span
          >
        </button>
      </div>

      <div class="grid grid-cols-3 gap-3 text-sm">
        <label class="col-span-1">
          <span class="block font-medium mb-1">Kode <span class="text-danger">*</span></span>
          <input
            class="w-full rounded-md border border-border-default px-2 py-1.5"
            bind:value={form.code}
          />
          {#if fieldErr('code')}<span class="text-xs text-danger">{fieldErr('code')}</span>{/if}
        </label>
        <label class="col-span-2">
          <span class="block font-medium mb-1">Nama <span class="text-danger">*</span></span>
          <input
            class="w-full rounded-md border border-border-default px-2 py-1.5"
            bind:value={form.name}
          />
          {#if fieldErr('name')}<span class="text-xs text-danger">{fieldErr('name')}</span>{/if}
        </label>
        <label class="col-span-3">
          <span class="block font-medium mb-1">Deskripsi</span>
          <textarea
            class="w-full rounded-md border border-border-default px-2 py-1.5"
            rows="2"
            bind:value={form.description}
          ></textarea>
        </label>
      </div>

      <h3 class="mt-5 mb-2 text-sm font-bold uppercase tracking-wider text-text-muted">
        Baris Template
      </h3>
      <div class="space-y-2">
        {#each form.lines as line, i (i)}
          <div class="grid grid-cols-[1.5rem_5fr_2fr_3fr_3fr_2rem] items-center gap-2 text-sm">
            <span class="text-xs text-text-muted text-center">{i + 1}</span>
            <AccountCombobox
              accounts={visibleAccounts}
              value={line.account_id}
              onSelect={(id) => (line.account_id = id)}
            />
            <select
              class="rounded-md border border-border-default px-2 py-1.5"
              bind:value={line.side}
            >
              <option value="debit">Debit</option>
              <option value="credit">Credit</option>
            </select>
            <input
              class="rounded-md border border-border-default px-2 py-1.5 text-right font-mono tabnum"
              type="number"
              min="0"
              step="0.01"
              bind:value={line.amount}
              placeholder="0"
            />
            <input
              class="rounded-md border border-border-default px-2 py-1.5"
              placeholder="Memo (opsional)"
              bind:value={line.memo}
            />
            <button
              type="button"
              class="text-text-muted hover:text-danger"
              onclick={() => removeLine(i)}
              title="Hapus">×</button
            >
          </div>
        {/each}
        <button
          type="button"
          class="mt-2 rounded-md border border-dashed border-border-default w-full py-2 text-sm font-semibold text-text-muted hover:border-primary hover:text-primary"
          onclick={addLine}
        >
          + Tambah baris
        </button>
      </div>

      <div class="mt-5 flex items-center justify-between gap-2">
        <div>
          {#if editing}
            <button
              type="button"
              class="rounded-md border border-danger/40 bg-danger-light px-3 py-2 text-sm font-semibold text-danger hover:bg-danger hover:text-white"
              onclick={() =>
                destroy({ id: editing!.id, name: editing!.name } as JournalTemplateSummary)}
            >
              Hapus
            </button>
          {/if}
        </div>
        <div class="flex gap-2">
          <button
            type="button"
            class="text-sm text-text-muted hover:text-text-default"
            onclick={closeForm}>Batal</button
          >
          <button
            type="button"
            class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-active disabled:opacity-50"
            onclick={save}
            disabled={saving}
          >
            {saving ? 'Menyimpan…' : 'Simpan'}
          </button>
        </div>
      </div>
    </div>
  </div>
{/if}
