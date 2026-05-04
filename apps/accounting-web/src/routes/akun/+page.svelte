<script lang="ts">
  import { onMount } from 'svelte';
  import { goto } from '$app/navigation';
  import { auth } from '$lib/stores/auth.svelte.js';
  import { accountApi, type Account, type AccountInput } from '$lib/api/account.js';
  import { ApiError } from '$lib/api/client.js';

  let items = $state<Account[]>([]);
  let loading = $state(true);
  let error = $state<string | null>(null);

  let editing = $state<Account | null>(null);
  let creating = $state(false);
  let form = $state<AccountInput>({
    code: '', name: '', type: 'asset', normal_balance: 'debit',
    parent_account_id: null, is_postable: true, is_active: true,
  });
  let formErrors = $state<Record<string, string[]> | null>(null);
  let saving = $state(false);

  async function load() {
    loading = true;
    error = null;
    try {
      items = await accountApi.list('', null, false);
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
    creating = true;
    editing = null;
    form = {
      code: '', name: '', type: 'asset', normal_balance: 'debit',
      parent_account_id: null, is_postable: true, is_active: true,
    };
    formErrors = null;
  }

  function openEdit(a: Account) {
    creating = false;
    editing = a;
    form = {
      code: a.code, name: a.name, type: a.type, normal_balance: a.normal_balance,
      parent_account_id: a.parent_account_id, is_postable: a.is_postable, is_active: a.is_active,
    };
    formErrors = null;
  }

  function closeForm() { editing = null; creating = false; formErrors = null; }

  async function save() {
    saving = true;
    formErrors = null;
    try {
      if (editing) {
        await accountApi.update(editing.id, form);
      } else {
        await accountApi.create(form);
      }
      closeForm();
      await load();
    } catch (e) {
      if (e instanceof ApiError) {
        const body = e.body as { errors?: Record<string, string[]> } | null;
        formErrors = body?.errors ?? null;
      } else {
        formErrors = { _: [(e as Error).message] };
      }
    } finally {
      saving = false;
    }
  }

  async function destroy(a: Account) {
    if (!confirm(`Hapus akun ${a.code} — ${a.name}?`)) return;
    try {
      await accountApi.destroy(a.id);
      await load();
    } catch (e) {
      const msg = e instanceof ApiError ? JSON.stringify((e.body as { errors?: unknown })?.errors ?? e.body) : (e as Error).message;
      alert(msg);
    }
  }

  function fieldErr(name: string): string | null {
    return formErrors?.[name]?.[0] ?? null;
  }
</script>

<div class="px-6 py-6">
  <header class="mb-5 flex items-center justify-between">
    <div>
      <p class="text-xs font-medium text-text-muted">Master / Akun</p>
      <h1 class="text-2xl font-bold">Bagan Akun</h1>
      <p class="text-sm text-text-muted">{items.length} akun</p>
    </div>
    <button
      type="button"
      class="rounded-md bg-[#0F172A] px-4 py-2 text-sm font-semibold text-white hover:bg-[#1E293B]"
      onclick={openCreate}
      data-testid="account-create"
    >
      + Akun Baru
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
            <th class="px-4 py-3 text-left">Tipe</th>
            <th class="px-4 py-3 text-left">Normal</th>
            <th class="px-4 py-3 text-center">Postable</th>
            <th class="px-4 py-3 text-center">Aktif</th>
            <th class="px-4 py-3"></th>
          </tr>
        </thead>
        <tbody>
          {#each items as a (a.id)}
            <tr class="border-t border-border-soft hover:bg-page-bg">
              <td class="px-4 py-2 font-mono">{a.code}</td>
              <td class="px-4 py-2">{a.name}</td>
              <td class="px-4 py-2 capitalize">{a.type}</td>
              <td class="px-4 py-2 capitalize">{a.normal_balance}</td>
              <td class="px-4 py-2 text-center">{a.is_postable ? '✓' : '—'}</td>
              <td class="px-4 py-2 text-center">{a.is_active ? '✓' : '—'}</td>
              <td class="px-4 py-2 text-right space-x-2">
                <button class="text-primary hover:underline text-xs" onclick={() => openEdit(a)}>Edit</button>
                <button class="text-danger hover:underline text-xs" onclick={() => destroy(a)}>Hapus</button>
              </td>
            </tr>
          {:else}
            <tr><td colspan="7" class="px-4 py-10 text-center text-text-muted">Belum ada akun.</td></tr>
          {/each}
        </tbody>
      </table>
    </div>
  {/if}
</div>

{#if creating || editing}
  <div class="fixed inset-0 z-30 flex items-center justify-center bg-black/30 p-4" onclick={closeForm}>
    <div class="w-full max-w-lg rounded-lg bg-card-bg p-6 shadow-lg" onclick={(e) => e.stopPropagation()} role="dialog">
      <h2 class="mb-4 text-lg font-bold">{editing ? `Edit Akun ${editing.code}` : 'Akun Baru'}</h2>
      <div class="grid grid-cols-2 gap-3 text-sm">
        <label class="col-span-1">
          <span class="block font-medium mb-1">Kode <span class="text-danger">*</span></span>
          <input class="w-full rounded-md border border-border-default px-2 py-1.5" bind:value={form.code} />
          {#if fieldErr('code')}<span class="text-xs text-danger">{fieldErr('code')}</span>{/if}
        </label>
        <label class="col-span-1">
          <span class="block font-medium mb-1">Nama <span class="text-danger">*</span></span>
          <input class="w-full rounded-md border border-border-default px-2 py-1.5" bind:value={form.name} />
          {#if fieldErr('name')}<span class="text-xs text-danger">{fieldErr('name')}</span>{/if}
        </label>
        <label class="col-span-1">
          <span class="block font-medium mb-1">Tipe</span>
          <select class="w-full rounded-md border border-border-default px-2 py-1.5" bind:value={form.type}>
            <option value="asset">Asset</option>
            <option value="liability">Liability</option>
            <option value="equity">Equity</option>
            <option value="revenue">Revenue</option>
            <option value="cogs">COGS</option>
            <option value="expense">Expense</option>
          </select>
        </label>
        <label class="col-span-1">
          <span class="block font-medium mb-1">Normal Balance</span>
          <select class="w-full rounded-md border border-border-default px-2 py-1.5" bind:value={form.normal_balance}>
            <option value="debit">Debit</option>
            <option value="credit">Credit</option>
          </select>
        </label>
        <label class="col-span-1 flex items-center gap-2 mt-2">
          <input type="checkbox" bind:checked={form.is_postable} />
          <span>Postable</span>
        </label>
        <label class="col-span-1 flex items-center gap-2 mt-2">
          <input type="checkbox" bind:checked={form.is_active} />
          <span>Aktif</span>
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
