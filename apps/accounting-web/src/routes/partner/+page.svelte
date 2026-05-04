<script lang="ts">
  import { onMount } from 'svelte';
  import { goto } from '$app/navigation';
  import { auth } from '$lib/stores/auth.svelte.js';
  import { partnerApi, type Partner, type PartnerInput, type PartnerType } from '$lib/api/partner.js';
  import { ApiError } from '$lib/api/client.js';

  let items = $state<Partner[]>([]);
  let total = $state(0);
  let page = $state(1);
  let lastPage = $state(1);
  let searchTerm = $state('');
  let typeFilter = $state<PartnerType | ''>('');
  let loading = $state(true);
  let error = $state<string | null>(null);

  let editing = $state<Partner | null>(null);
  let creating = $state(false);
  let form = $state<PartnerInput>({ type: 'customer', name: '', code: '', email: '' });
  let formErrors = $state<Record<string, string[]> | null>(null);
  let saving = $state(false);

  async function load() {
    loading = true;
    error = null;
    try {
      const res = await partnerApi.list({
        page,
        per_page: 25,
        search: searchTerm || undefined,
        type: typeFilter || undefined,
      });
      items = res.data;
      total = res.meta.total;
      lastPage = res.meta.last_page;
    } catch (e) {
      error = e instanceof Error ? e.message : String(e);
    } finally {
      loading = false;
    }
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
    form = { type: 'customer', name: '', code: '', email: '' };
    formErrors = null;
  }
  function openEdit(p: Partner) {
    creating = false; editing = p;
    form = {
      type: p.type, name: p.name, code: p.code, email: p.email,
      phone: p.phone, address: p.address, city: p.city,
      npwp: p.npwp, tax_id: p.tax_id, is_active: p.is_active,
    };
    formErrors = null;
  }
  function closeForm() { editing = null; creating = false; formErrors = null; }

  async function save() {
    saving = true; formErrors = null;
    try {
      if (editing) await partnerApi.update(editing.id, form);
      else await partnerApi.create(form);
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

  async function destroy(p: Partner) {
    if (!confirm(`Hapus partner ${p.name}?`)) return;
    try { await partnerApi.destroy(p.id); await load(); }
    catch (e) { alert(e instanceof Error ? e.message : String(e)); }
  }

  function fieldErr(name: string): string | null {
    return formErrors?.[name]?.[0] ?? null;
  }
</script>

<div class="px-6 py-6">
  <header class="mb-5 flex items-center justify-between">
    <div>
      <p class="text-xs font-medium text-text-muted">Master / Partner</p>
      <h1 class="text-2xl font-bold">Partner</h1>
      <p class="text-sm text-text-muted">{total} partner</p>
    </div>
    <button
      type="button"
      class="rounded-md bg-[#0F172A] px-4 py-2 text-sm font-semibold text-white hover:bg-[#1E293B]"
      onclick={openCreate}
    >
      + Partner Baru
    </button>
  </header>

  <div class="mb-3 flex flex-wrap items-end gap-3 rounded-md border border-border-default bg-card-bg p-3">
    <label class="text-sm">
      <span class="block font-medium mb-1">Cari</span>
      <input class="rounded-md border border-border-default px-2 py-1.5" bind:value={searchTerm} placeholder="Nama / kode / email" />
    </label>
    <label class="text-sm">
      <span class="block font-medium mb-1">Tipe</span>
      <select class="rounded-md border border-border-default px-2 py-1.5" bind:value={typeFilter}>
        <option value="">Semua</option>
        <option value="customer">Customer</option>
        <option value="vendor">Vendor</option>
        <option value="employee">Employee</option>
        <option value="other">Other</option>
      </select>
    </label>
    <button class="rounded-md bg-[#0F172A] px-3 py-1.5 text-sm font-semibold text-white" onclick={() => { page = 1; load(); }}>
      Filter
    </button>
  </div>

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
            <th class="px-4 py-3 text-left">Tipe</th>
            <th class="px-4 py-3 text-left">Email</th>
            <th class="px-4 py-3 text-center">Aktif</th>
            <th class="px-4 py-3"></th>
          </tr>
        </thead>
        <tbody>
          {#each items as p (p.id)}
            <tr class="border-t border-border-soft hover:bg-page-bg">
              <td class="px-4 py-2 font-mono">{p.code ?? '—'}</td>
              <td class="px-4 py-2">{p.name}</td>
              <td class="px-4 py-2 capitalize">{p.type}</td>
              <td class="px-4 py-2">{p.email ?? '—'}</td>
              <td class="px-4 py-2 text-center">{p.is_active ? '✓' : '—'}</td>
              <td class="px-4 py-2 text-right space-x-2">
                <button class="text-primary hover:underline text-xs" onclick={() => openEdit(p)}>Edit</button>
                <button class="text-danger hover:underline text-xs" onclick={() => destroy(p)}>Hapus</button>
              </td>
            </tr>
          {:else}
            <tr><td colspan="6" class="px-4 py-10 text-center text-text-muted">Belum ada partner.</td></tr>
          {/each}
        </tbody>
      </table>
    </div>

    {#if lastPage > 1}
      <div class="mt-3 flex items-center justify-between text-sm">
        <span class="text-text-muted">Hal {page} dari {lastPage}</span>
        <div class="flex gap-2">
          <button class="rounded-md border border-border-default px-3 py-1 disabled:opacity-50" onclick={() => { page = Math.max(1, page - 1); load(); }} disabled={page <= 1}>Prev</button>
          <button class="rounded-md border border-border-default px-3 py-1 disabled:opacity-50" onclick={() => { page = Math.min(lastPage, page + 1); load(); }} disabled={page >= lastPage}>Next</button>
        </div>
      </div>
    {/if}
  {/if}
</div>

{#if creating || editing}
  <div class="fixed inset-0 z-30 flex items-center justify-center bg-black/30 p-4" onclick={closeForm}>
    <div class="w-full max-w-2xl rounded-lg bg-card-bg p-6 shadow-lg" onclick={(e) => e.stopPropagation()} role="dialog">
      <h2 class="mb-4 text-lg font-bold">{editing ? `Edit ${editing.name}` : 'Partner Baru'}</h2>
      <div class="grid grid-cols-2 gap-3 text-sm">
        <label class="col-span-1">
          <span class="block font-medium mb-1">Tipe</span>
          <select class="w-full rounded-md border border-border-default px-2 py-1.5" bind:value={form.type}>
            <option value="customer">Customer</option>
            <option value="vendor">Vendor</option>
            <option value="employee">Employee</option>
            <option value="other">Other</option>
          </select>
        </label>
        <label class="col-span-1">
          <span class="block font-medium mb-1">Kode</span>
          <input class="w-full rounded-md border border-border-default px-2 py-1.5" bind:value={form.code} />
          {#if fieldErr('code')}<span class="text-xs text-danger">{fieldErr('code')}</span>{/if}
        </label>
        <label class="col-span-2">
          <span class="block font-medium mb-1">Nama <span class="text-danger">*</span></span>
          <input class="w-full rounded-md border border-border-default px-2 py-1.5" bind:value={form.name} />
          {#if fieldErr('name')}<span class="text-xs text-danger">{fieldErr('name')}</span>{/if}
        </label>
        <label class="col-span-1">
          <span class="block font-medium mb-1">Email</span>
          <input class="w-full rounded-md border border-border-default px-2 py-1.5" bind:value={form.email} />
        </label>
        <label class="col-span-1">
          <span class="block font-medium mb-1">Phone</span>
          <input class="w-full rounded-md border border-border-default px-2 py-1.5" bind:value={form.phone} />
        </label>
        <label class="col-span-1">
          <span class="block font-medium mb-1">NPWP</span>
          <input class="w-full rounded-md border border-border-default px-2 py-1.5" bind:value={form.npwp} />
        </label>
        <label class="col-span-1">
          <span class="block font-medium mb-1">Kota</span>
          <input class="w-full rounded-md border border-border-default px-2 py-1.5" bind:value={form.city} />
        </label>
        <label class="col-span-2">
          <span class="block font-medium mb-1">Alamat</span>
          <textarea class="w-full rounded-md border border-border-default px-2 py-1.5" rows="2" bind:value={form.address}></textarea>
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
