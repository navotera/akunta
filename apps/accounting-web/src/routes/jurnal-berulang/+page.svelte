<script lang="ts">
  import { onMount } from 'svelte';
  import { goto } from '$app/navigation';
  import { auth } from '$lib/stores/auth.svelte.js';
  import { recurringApi, type RecurringJournal, type RecurringInput, type Frequency } from '$lib/api/recurring.js';
  import { templateApi, type JournalTemplateSummary } from '$lib/api/template.js';
  import { ApiError } from '$lib/api/client.js';

  let items = $state<RecurringJournal[]>([]);
  let templates = $state<JournalTemplateSummary[]>([]);
  let loading = $state(true);
  let error = $state<string | null>(null);

  let editing = $state<RecurringJournal | null>(null);
  let creating = $state(false);
  let form = $state<RecurringInput>({
    template_id: '', name: '', frequency: 'monthly', day: 1,
    start_date: new Date().toISOString().slice(0, 10),
  });
  let formErrors = $state<Record<string, string[]> | null>(null);
  let saving = $state(false);

  async function load() {
    loading = true; error = null;
    try {
      [items, templates] = await Promise.all([
        recurringApi.list(),
        templateApi.list(50),
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
    form = {
      template_id: templates[0]?.id ?? '',
      name: '',
      frequency: 'monthly',
      day: 1,
      start_date: new Date().toISOString().slice(0, 10),
    };
    formErrors = null;
  }

  function openEdit(r: RecurringJournal) {
    creating = false; editing = r;
    form = {
      template_id: r.template_id,
      name: r.name,
      frequency: r.frequency,
      day: r.day,
      month: r.month,
      start_date: r.start_date,
      end_date: r.end_date,
      next_run_at: r.next_run_at,
      auto_post: r.auto_post,
    };
    formErrors = null;
  }

  function closeForm() { editing = null; creating = false; formErrors = null; }

  async function save() {
    saving = true; formErrors = null;
    try {
      if (editing) await recurringApi.update(editing.id, form);
      else await recurringApi.create(form);
      closeForm();
      await load();
    } catch (e) {
      if (e instanceof ApiError) {
        const body = e.body as { errors?: Record<string, string[]> } | null;
        formErrors = body?.errors ?? null;
      } else formErrors = { _: [(e as Error).message] };
    } finally { saving = false; }
  }

  async function pause(r: RecurringJournal) {
    try { await recurringApi.pause(r.id); await load(); }
    catch (e) { alert(e instanceof Error ? e.message : String(e)); }
  }
  async function resume(r: RecurringJournal) {
    try { await recurringApi.resume(r.id); await load(); }
    catch (e) { alert(e instanceof Error ? e.message : String(e)); }
  }
  async function run(r: RecurringJournal) {
    if (!confirm(`Jalankan ${r.name} sekarang?`)) return;
    try {
      const res = await recurringApi.run(r.id);
      await load();
      if (res.journal_id) goto(`/journals/${res.journal_id}`);
      else alert('Tidak ada jurnal yang dibuat (cek tanggal next_run).');
    } catch (e) { alert(e instanceof Error ? e.message : String(e)); }
  }
  async function destroy(r: RecurringJournal) {
    if (!confirm(`Hapus ${r.name}?`)) return;
    try { await recurringApi.destroy(r.id); await load(); }
    catch (e) { alert(e instanceof Error ? e.message : String(e)); }
  }

  function statusColor(s: RecurringJournal['status']): string {
    return s === 'active' ? 'bg-paid-light text-paid'
      : s === 'paused' ? 'bg-warning-light text-warning'
      : 'bg-page-bg text-text-muted';
  }

  function fieldErr(name: string): string | null {
    return formErrors?.[name]?.[0] ?? null;
  }
</script>

<div class="px-6 py-6">
  <header class="mb-5 flex items-center justify-between">
    <div>
      <p class="text-xs font-medium text-text-muted">Operasional / Jurnal Berulang</p>
      <h1 class="text-2xl font-bold">Jurnal Berulang</h1>
      <p class="text-sm text-text-muted">{items.length} schedule</p>
    </div>
    <button
      type="button"
      class="rounded-md bg-[#0F172A] px-4 py-2 text-sm font-semibold text-white hover:bg-[#1E293B] disabled:opacity-50"
      onclick={openCreate}
      disabled={templates.length === 0}
      title={templates.length === 0 ? 'Buat template jurnal dulu' : undefined}
    >
      + Schedule Baru
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
            <th class="px-4 py-3 text-left">Nama</th>
            <th class="px-4 py-3 text-left">Template</th>
            <th class="px-4 py-3 text-left">Frekuensi</th>
            <th class="px-4 py-3 text-left">Mulai</th>
            <th class="px-4 py-3 text-left">Next Run</th>
            <th class="px-4 py-3 text-left">Status</th>
            <th class="px-4 py-3"></th>
          </tr>
        </thead>
        <tbody>
          {#each items as r (r.id)}
            <tr class="border-t border-border-soft hover:bg-page-bg">
              <td class="px-4 py-2 font-medium">{r.name}</td>
              <td class="px-4 py-2 font-mono text-xs">{r.template_code ?? r.template_id.slice(0, 8)}</td>
              <td class="px-4 py-2 capitalize">{r.frequency}</td>
              <td class="px-4 py-2">{r.start_date}</td>
              <td class="px-4 py-2">{r.next_run_at ?? '—'}</td>
              <td class="px-4 py-2">
                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold {statusColor(r.status)}">
                  {r.status}
                </span>
              </td>
              <td class="px-4 py-2 text-right space-x-2">
                <button class="text-primary hover:underline text-xs" onclick={() => openEdit(r)}>Edit</button>
                {#if r.status === 'active'}
                  <button class="text-warning hover:underline text-xs" onclick={() => pause(r)}>Pause</button>
                {:else if r.status === 'paused'}
                  <button class="text-paid hover:underline text-xs" onclick={() => resume(r)}>Resume</button>
                {/if}
                <button class="text-primary hover:underline text-xs" onclick={() => run(r)}>Run</button>
                <button class="text-danger hover:underline text-xs" onclick={() => destroy(r)}>Hapus</button>
              </td>
            </tr>
          {:else}
            <tr><td colspan="7" class="px-4 py-10 text-center text-text-muted">Belum ada schedule.</td></tr>
          {/each}
        </tbody>
      </table>
    </div>
  {/if}
</div>

{#if creating || editing}
  <div class="fixed inset-0 z-30 flex items-center justify-center bg-black/30 p-4" onclick={closeForm}>
    <div class="w-full max-w-lg rounded-lg bg-card-bg p-6 shadow-lg" onclick={(e) => e.stopPropagation()} role="dialog">
      <h2 class="mb-4 text-lg font-bold">{editing ? `Edit ${editing.name}` : 'Schedule Baru'}</h2>
      <div class="grid grid-cols-2 gap-3 text-sm">
        <label class="col-span-2">
          <span class="block font-medium mb-1">Template <span class="text-danger">*</span></span>
          <select class="w-full rounded-md border border-border-default px-2 py-1.5" bind:value={form.template_id}>
            {#each templates as t (t.id)}
              <option value={t.id}>{t.code} — {t.name}</option>
            {/each}
          </select>
          {#if fieldErr('template_id')}<span class="text-xs text-danger">{fieldErr('template_id')}</span>{/if}
        </label>
        <label class="col-span-2">
          <span class="block font-medium mb-1">Nama <span class="text-danger">*</span></span>
          <input class="w-full rounded-md border border-border-default px-2 py-1.5" bind:value={form.name} />
          {#if fieldErr('name')}<span class="text-xs text-danger">{fieldErr('name')}</span>{/if}
        </label>
        <label>
          <span class="block font-medium mb-1">Frekuensi</span>
          <select class="w-full rounded-md border border-border-default px-2 py-1.5" bind:value={form.frequency}>
            <option value="daily">Harian</option>
            <option value="weekly">Mingguan</option>
            <option value="monthly">Bulanan</option>
            <option value="quarterly">Triwulan</option>
            <option value="yearly">Tahunan</option>
          </select>
        </label>
        <label>
          <span class="block font-medium mb-1">Tgl/Hari</span>
          <input type="number" min="1" max="31" class="w-full rounded-md border border-border-default px-2 py-1.5" bind:value={form.day} />
        </label>
        <label>
          <span class="block font-medium mb-1">Mulai <span class="text-danger">*</span></span>
          <input type="date" class="w-full rounded-md border border-border-default px-2 py-1.5" bind:value={form.start_date} />
        </label>
        <label>
          <span class="block font-medium mb-1">Selesai (opsional)</span>
          <input type="date" class="w-full rounded-md border border-border-default px-2 py-1.5" bind:value={form.end_date} />
        </label>
        <label class="col-span-2 flex items-center gap-2">
          <input type="checkbox" bind:checked={form.auto_post} />
          <span>Auto-post setelah jalankan</span>
        </label>
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
