<script lang="ts">
  import { onMount } from 'svelte';
  import { goto } from '$app/navigation';
  import { auth } from '$lib/stores/auth.svelte.js';
  import { templateApi, type JournalTemplateSummary, type JournalTemplateDetail, type JournalTemplateInput } from '$lib/api/template.js';
  import { accountApi, type Account } from '$lib/api/account.js';
  import { ApiError } from '$lib/api/client.js';

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
  let form = $state<{ code: string; name: string; description: string; lines: LineDraft[] }>({
    code: '', name: '', description: '', lines: [],
  });
  let formErrors = $state<Record<string, string[]> | null>(null);
  let saving = $state(false);

  const blankLine = (): LineDraft => ({ account_id: '', side: 'debit', amount: '0', memo: '' });

  async function load() {
    loading = true; error = null;
    try {
      [items, accounts] = await Promise.all([
        templateApi.list(50),
        accountApi.list('', null, false),
      ]);
    } catch (e) { error = e instanceof Error ? e.message : String(e); }
    finally { loading = false; }
  }

  onMount(async () => {
    if (!auth.user) {
      const u = await auth.refresh();
      if (!u) { goto('/login', { replaceState: true }); return; }
    }
    await load();
  });

  function openCreate() {
    creating = true; editing = null;
    form = { code: '', name: '', description: '', lines: [blankLine(), blankLine()] };
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
      lines: d.lines.map((l) => ({
        account_id: l.account_id,
        side: l.side,
        amount: l.amount,
        memo: l.memo ?? '',
      })),
    };
  }

  function closeForm() { editing = null; creating = false; formErrors = null; }

  function addLine() { form.lines = [...form.lines, blankLine()]; }
  function removeLine(i: number) { form.lines = form.lines.filter((_, idx) => idx !== i); }

  async function save() {
    saving = true; formErrors = null;
    const payload: JournalTemplateInput = {
      code: form.code,
      name: form.name,
      description: form.description || null,
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
    } finally { saving = false; }
  }

  async function destroy(s: JournalTemplateSummary) {
    if (!confirm(`Hapus template ${s.name}?`)) return;
    try { await templateApi.destroy(s.id); await load(); }
    catch (e) { alert(e instanceof Error ? e.message : String(e)); }
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
      class="rounded-md bg-[#0F172A] px-4 py-2 text-sm font-semibold text-white hover:bg-[#1E293B]"
      onclick={openCreate}
    >
      + Template Baru
    </button>
  </header>

  {#if loading}
    <div class="text-text-muted">Memuat…</div>
  {:else if error}
    <div class="rounded-md border border-danger bg-danger-light p-3 text-sm text-danger">{error}</div>
  {:else}
    <div class="overflow-x-auto rounded-lg border border-border-default bg-card-bg shadow-xs">
      <table class="w-full text-sm">
        <thead class="bg-page-bg text-xs uppercase tracking-wider text-text-muted">
          <tr>
            <th class="px-4 py-3 text-left">Kode</th>
            <th class="px-4 py-3 text-left">Nama</th>
            <th class="px-4 py-3 text-left">Deskripsi</th>
            <th class="px-4 py-3 text-center">Baris</th>
            <th class="px-4 py-3 text-center">Aktif</th>
            <th class="px-4 py-3"></th>
          </tr>
        </thead>
        <tbody>
          {#each items as t (t.id)}
            <tr class="border-t border-border-soft hover:bg-page-bg">
              <td class="px-4 py-2 font-mono">{t.code}</td>
              <td class="px-4 py-2 font-medium">{t.name}</td>
              <td class="px-4 py-2 text-text-muted">{t.description ?? '—'}</td>
              <td class="px-4 py-2 text-center">{t.lines_count}</td>
              <td class="px-4 py-2 text-center">{t.is_active ? '✓' : '—'}</td>
              <td class="px-4 py-2 text-right space-x-2">
                <button class="text-primary hover:underline text-xs" onclick={() => openEdit(t)}>Edit</button>
                <button class="text-danger hover:underline text-xs" onclick={() => destroy(t)}>Hapus</button>
              </td>
            </tr>
          {:else}
            <tr><td colspan="6" class="px-4 py-10 text-center text-text-muted">Belum ada template.</td></tr>
          {/each}
        </tbody>
      </table>
    </div>
  {/if}
</div>

{#if creating || editing}
  <div class="fixed inset-0 z-30 flex items-center justify-center bg-black/30 p-4" onclick={closeForm}>
    <div class="w-full max-w-3xl rounded-lg bg-card-bg p-6 shadow-lg" onclick={(e) => e.stopPropagation()} role="dialog">
      <h2 class="mb-4 text-lg font-bold">{editing ? `Edit ${editing.name}` : 'Template Baru'}</h2>

      <div class="grid grid-cols-3 gap-3 text-sm">
        <label class="col-span-1">
          <span class="block font-medium mb-1">Kode <span class="text-danger">*</span></span>
          <input class="w-full rounded-md border border-border-default px-2 py-1.5" bind:value={form.code} />
          {#if fieldErr('code')}<span class="text-xs text-danger">{fieldErr('code')}</span>{/if}
        </label>
        <label class="col-span-2">
          <span class="block font-medium mb-1">Nama <span class="text-danger">*</span></span>
          <input class="w-full rounded-md border border-border-default px-2 py-1.5" bind:value={form.name} />
          {#if fieldErr('name')}<span class="text-xs text-danger">{fieldErr('name')}</span>{/if}
        </label>
        <label class="col-span-3">
          <span class="block font-medium mb-1">Deskripsi</span>
          <textarea class="w-full rounded-md border border-border-default px-2 py-1.5" rows="2" bind:value={form.description}></textarea>
        </label>
      </div>

      <h3 class="mt-5 mb-2 text-sm font-bold uppercase tracking-wider text-text-muted">Baris Template</h3>
      <div class="space-y-2">
        {#each form.lines as line, i (i)}
          <div class="grid grid-cols-[1.5rem_5fr_2fr_3fr_3fr_2rem] items-center gap-2 text-sm">
            <span class="text-xs text-text-muted text-center">{i + 1}</span>
            <select class="rounded-md border border-border-default px-2 py-1.5" bind:value={line.account_id}>
              <option value="">Pilih akun…</option>
              {#each accounts as a (a.id)}
                <option value={a.id}>{a.code} — {a.name}</option>
              {/each}
            </select>
            <select class="rounded-md border border-border-default px-2 py-1.5" bind:value={line.side}>
              <option value="debit">Debit</option>
              <option value="credit">Credit</option>
            </select>
            <input class="rounded-md border border-border-default px-2 py-1.5 text-right font-mono tabnum" type="number" min="0" step="0.01" bind:value={line.amount} placeholder="0" />
            <input class="rounded-md border border-border-default px-2 py-1.5" placeholder="Memo (opsional)" bind:value={line.memo} />
            <button type="button" class="text-text-muted hover:text-danger" onclick={() => removeLine(i)} title="Hapus">×</button>
          </div>
        {/each}
        <button type="button" class="mt-2 rounded-md border border-dashed border-border-default w-full py-2 text-sm font-semibold text-text-muted hover:border-primary hover:text-primary" onclick={addLine}>
          + Tambah baris
        </button>
      </div>

      <div class="mt-5 flex justify-end gap-2">
        <button type="button" class="text-sm text-text-muted hover:text-text-default" onclick={closeForm}>Batal</button>
        <button
          type="button"
          class="rounded-md bg-[#0F172A] px-4 py-2 text-sm font-semibold text-white hover:bg-[#1E293B] disabled:opacity-50"
          onclick={save}
          disabled={saving}
        >
          {saving ? 'Menyimpan…' : 'Simpan'}
        </button>
      </div>
    </div>
  </div>
{/if}
