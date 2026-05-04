<script lang="ts">
  import { onMount } from 'svelte';
  import { goto } from '$app/navigation';
  import { auth } from '$lib/stores/auth.svelte.js';
  import { periodApi, type Period, type PeriodInput } from '$lib/api/period.js';
  import { ApiError } from '$lib/api/client.js';

  let items = $state<Period[]>([]);
  let loading = $state(true);
  let error = $state<string | null>(null);

  let editing = $state<Period | null>(null);
  let creating = $state(false);
  let form = $state<PeriodInput>({ name: '', start_date: '', end_date: '' });
  let formErrors = $state<Record<string, string[]> | null>(null);
  let saving = $state(false);

  async function load() {
    loading = true;
    error = null;
    try { items = await periodApi.list(); }
    catch (e) { error = e instanceof Error ? e.message : String(e); }
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
    const today = new Date();
    const first = new Date(today.getFullYear(), today.getMonth(), 1).toISOString().slice(0, 10);
    const last = new Date(today.getFullYear(), today.getMonth() + 1, 0).toISOString().slice(0, 10);
    form = { name: today.toLocaleString('id-ID', { month: 'long', year: 'numeric' }), start_date: first, end_date: last };
    formErrors = null;
  }

  function openEdit(p: Period) {
    creating = false; editing = p;
    form = { name: p.name, start_date: p.start_date, end_date: p.end_date };
    formErrors = null;
  }

  function closeForm() { editing = null; creating = false; formErrors = null; }

  async function save() {
    saving = true; formErrors = null;
    try {
      if (editing) await periodApi.update(editing.id, form);
      else await periodApi.create(form);
      closeForm();
      await load();
    } catch (e) {
      if (e instanceof ApiError) {
        const body = e.body as { errors?: Record<string, string[]> } | null;
        formErrors = body?.errors ?? null;
      } else formErrors = { _: [(e as Error).message] };
    } finally { saving = false; }
  }

  async function close(p: Period) {
    if (!confirm(`Tutup periode ${p.name}?`)) return;
    try { await periodApi.close(p.id); await load(); }
    catch (e) {
      const msg = e instanceof ApiError ? JSON.stringify((e.body as { errors?: unknown })?.errors ?? e.body) : (e as Error).message;
      alert(msg);
    }
  }

  async function reopen(p: Period) {
    if (!confirm(`Buka kembali periode ${p.name}?`)) return;
    try { await periodApi.reopen(p.id); await load(); }
    catch (e) { alert(e instanceof Error ? e.message : String(e)); }
  }

  async function destroy(p: Period) {
    if (!confirm(`Hapus periode ${p.name}?`)) return;
    try { await periodApi.destroy(p.id); await load(); }
    catch (e) {
      const msg = e instanceof ApiError ? JSON.stringify((e.body as { errors?: unknown })?.errors ?? e.body) : (e as Error).message;
      alert(msg);
    }
  }

  function statusColor(s: Period['status']): string {
    return s === 'open' ? 'bg-paid-light text-paid'
      : s === 'closed' ? 'bg-page-bg text-text-muted'
      : 'bg-warning-light text-warning';
  }

  function fieldErr(name: string): string | null {
    return formErrors?.[name]?.[0] ?? null;
  }
</script>

<div class="px-6 py-6">
  <header class="mb-5 flex items-center justify-between">
    <div>
      <p class="text-xs font-medium text-text-muted">Master / Periode</p>
      <h1 class="text-2xl font-bold">Periode Akuntansi</h1>
    </div>
    <button
      type="button"
      class="rounded-md bg-[#0F172A] px-4 py-2 text-sm font-semibold text-white hover:bg-[#1E293B]"
      onclick={openCreate}
    >
      + Periode Baru
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
            <th class="px-4 py-3 text-left">Mulai</th>
            <th class="px-4 py-3 text-left">Selesai</th>
            <th class="px-4 py-3 text-left">Status</th>
            <th class="px-4 py-3"></th>
          </tr>
        </thead>
        <tbody>
          {#each items as p (p.id)}
            <tr class="border-t border-border-soft hover:bg-page-bg">
              <td class="px-4 py-2 font-medium">{p.name}</td>
              <td class="px-4 py-2">{p.start_date}</td>
              <td class="px-4 py-2">{p.end_date}</td>
              <td class="px-4 py-2">
                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold {statusColor(p.status)}">
                  {p.status}
                </span>
              </td>
              <td class="px-4 py-2 text-right space-x-2">
                {#if p.status === 'open'}
                  <button class="text-primary hover:underline text-xs" onclick={() => openEdit(p)}>Edit</button>
                  <button class="text-warning hover:underline text-xs" onclick={() => close(p)}>Tutup</button>
                  <button class="text-danger hover:underline text-xs" onclick={() => destroy(p)}>Hapus</button>
                {:else}
                  <button class="text-primary hover:underline text-xs" onclick={() => reopen(p)}>Buka Lagi</button>
                {/if}
              </td>
            </tr>
          {:else}
            <tr><td colspan="5" class="px-4 py-10 text-center text-text-muted">Belum ada periode.</td></tr>
          {/each}
        </tbody>
      </table>
    </div>
  {/if}
</div>

{#if creating || editing}
  <div class="fixed inset-0 z-30 flex items-center justify-center bg-black/30 p-4" onclick={closeForm}>
    <div class="w-full max-w-md rounded-lg bg-card-bg p-6 shadow-lg" onclick={(e) => e.stopPropagation()} role="dialog">
      <h2 class="mb-4 text-lg font-bold">{editing ? `Edit ${editing.name}` : 'Periode Baru'}</h2>
      <div class="space-y-3 text-sm">
        <label class="block">
          <span class="block font-medium mb-1">Nama <span class="text-danger">*</span></span>
          <input class="w-full rounded-md border border-border-default px-2 py-1.5" bind:value={form.name} />
          {#if fieldErr('name')}<span class="text-xs text-danger">{fieldErr('name')}</span>{/if}
        </label>
        <label class="block">
          <span class="block font-medium mb-1">Mulai</span>
          <input type="date" class="w-full rounded-md border border-border-default px-2 py-1.5" bind:value={form.start_date} />
          {#if fieldErr('start_date')}<span class="text-xs text-danger">{fieldErr('start_date')}</span>{/if}
        </label>
        <label class="block">
          <span class="block font-medium mb-1">Selesai</span>
          <input type="date" class="w-full rounded-md border border-border-default px-2 py-1.5" bind:value={form.end_date} />
          {#if fieldErr('end_date')}<span class="text-xs text-danger">{fieldErr('end_date')}</span>{/if}
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
