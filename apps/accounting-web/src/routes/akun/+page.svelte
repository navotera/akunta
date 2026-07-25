<script lang="ts">
  import { onMount } from 'svelte';
  import { goto } from '$app/navigation';
  import { auth } from '$lib/stores/auth.svelte.js';
  import { accountApi, type Account, type AccountInput } from '$lib/api/account.js';
  import { ApiError } from '$lib/api/client.js';
  import CoaTreeNode from '$lib/components/coa/CoaTreeNode.svelte';

  type ViewMode = 'list' | 'tree' | 'tview';

  interface TreeNode {
    account: Account;
    children: TreeNode[];
  }

  let items = $state<Account[]>([]);
  let viewMode = $state<ViewMode>('list');
  let treeSearch = $state('');
  let loading = $state(true);
  let error = $state<string | null>(null);

  const TYPE_LABEL: Record<string, string> = {
    asset: 'Aktiva',
    liability: 'Kewajiban',
    equity: 'Ekuitas',
    revenue: 'Pendapatan',
    expense: 'Biaya',
    cogs: 'HPP',
    contra_asset: 'Kontra Aktiva',
    contra_liability: 'Kontra Kewajiban',
    contra_equity: 'Kontra Ekuitas',
    contra_revenue: 'Kontra Pendapatan',
  };

  const filtered = $derived.by(() => {
    if (!treeSearch.trim()) return items;
    const q = treeSearch.trim().toLowerCase();
    return items.filter(
      (a) => a.code.toLowerCase().includes(q) || a.name.toLowerCase().includes(q),
    );
  });

  function buildTree(rows: Account[]): TreeNode[] {
    const byParent = new Map<string, Account[]>();
    for (const a of rows) {
      const k = a.parent_account_id ?? '__root__';
      if (!byParent.has(k)) byParent.set(k, []);
      byParent.get(k)!.push(a);
    }
    const build = (parent: string | null): TreeNode[] =>
      (byParent.get(parent ?? '__root__') ?? [])
        .sort((a, b) => a.code.localeCompare(b.code))
        .map((a) => ({ account: a, children: build(a.id) }));
    return build(null);
  }

  function buildSideTree(rows: Account[], side: 'debit' | 'credit'): TreeNode[] {
    const subset = rows.filter((a) => a.normal_balance === side);
    const ids = new Set(subset.map((a) => a.id));
    const byParent = new Map<string, Account[]>();
    for (const a of subset) {
      const parentKey =
        a.parent_account_id && ids.has(a.parent_account_id) ? a.parent_account_id : '__root__';
      if (!byParent.has(parentKey)) byParent.set(parentKey, []);
      byParent.get(parentKey)!.push(a);
    }
    const build = (parent: string | null): TreeNode[] =>
      (byParent.get(parent ?? '__root__') ?? [])
        .sort((a, b) => a.code.localeCompare(b.code))
        .map((a) => ({ account: a, children: build(a.id) }));
    return build(null);
  }

  const treeData = $derived(buildTree(filtered));
  const debitTree = $derived(buildSideTree(filtered, 'debit'));
  const creditTree = $derived(buildSideTree(filtered, 'credit'));

  let editing = $state<Account | null>(null);
  let creating = $state(false);
  let form = $state<AccountInput>({
    code: '',
    name: '',
    type: 'asset',
    normal_balance: 'debit',
    parent_account_id: null,
    is_postable: true,
    is_active: true,
    is_fiskal: false,
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
      type: 'asset',
      normal_balance: 'debit',
      parent_account_id: null,
      is_postable: true,
      is_active: true,
      is_fiskal: false,
    };
    formErrors = null;
  }

  function openEdit(a: Account) {
    creating = false;
    editing = a;
    form = {
      code: a.code,
      name: a.name,
      type: a.type,
      normal_balance: a.normal_balance,
      parent_account_id: a.parent_account_id,
      is_postable: a.is_postable,
      is_active: a.is_active,
      is_fiskal: a.is_fiskal,
    };
    formErrors = null;
  }

  function closeForm() {
    editing = null;
    creating = false;
    formErrors = null;
  }

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
      closeForm();
      await load();
    } catch (e) {
      const msg =
        e instanceof ApiError
          ? JSON.stringify((e.body as { errors?: unknown })?.errors ?? e.body)
          : (e as Error).message;
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
      class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-active"
      onclick={openCreate}
      data-testid="account-create"
    >
      + Akun Baru
    </button>
  </header>

  <!-- Tabs + search -->
  <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
    <div class="inline-flex rounded-md border border-border-default bg-card-bg p-0.5 text-sm">
      {#each [{ id: 'list', label: 'List' }, { id: 'tree', label: 'Tree' }, { id: 'tview', label: 'T-view' }] as t}
        <button
          type="button"
          class="rounded px-3 py-1.5 font-medium transition-colors {viewMode === t.id
            ? 'bg-primary-light text-primary-active'
            : 'text-text-muted hover:text-text-default'}"
          onclick={() => (viewMode = t.id as ViewMode)}
          data-testid="coa-tab-{t.id}"
        >
          {t.label}
        </button>
      {/each}
    </div>
    {#if viewMode !== 'list'}
      <input
        type="search"
        placeholder="Cari kode atau nama akun…"
        class="w-72 rounded-md border border-border-default bg-card-bg px-3 py-1.5 text-sm focus:outline-none focus:border-primary"
        bind:value={treeSearch}
      />
    {/if}
  </div>

  {#if loading}
    <div class="text-text-muted">Memuat…</div>
  {:else if error}
    <div class="rounded-md border border-danger bg-danger-light p-3 text-sm text-danger">
      {error}
    </div>
  {:else if viewMode === 'list'}
    <div class="overflow-x-auto rounded-lg border border-border-default bg-card-bg shadow-xs">
      <table class="ak-table">
        <thead>
          <tr>
            <th>Kode</th>
            <th>Nama</th>
            <th>Tipe</th>
            <th>Normal</th>
            <th class="text-center">Postable</th>
            <th class="text-center">Aktif</th>
            <th class="text-center">Fiskal</th>
          </tr>
        </thead>
        <tbody>
          {#each items as a (a.id)}
            <tr class="cursor-pointer" onclick={() => openEdit(a)}>
              <td class="font-mono">{a.code}</td>
              <td>{a.name}</td>
              <td class="capitalize">{TYPE_LABEL[a.type] ?? a.type}</td>
              <td>
                <span
                  class="ak-pill {a.normal_balance === 'debit'
                    ? 'bg-info-light text-info'
                    : 'bg-warning-light text-warning'}"
                >
                  {a.normal_balance}
                </span>
              </td>
              <td class="text-center">{a.is_postable ? '✓' : '—'}</td>
              <td class="text-center">{a.is_active ? '✓' : '—'}</td>
              <td class="text-center">{a.is_fiskal ? '✓' : '-'}</td>
            </tr>
          {:else}
            <tr
              ><td colspan="7" class="px-4 py-10 text-center text-text-muted">Belum ada akun.</td
              ></tr
            >
          {/each}
        </tbody>
      </table>
    </div>
  {:else if viewMode === 'tree'}
    <div class="rounded-lg border border-border-default bg-card-bg shadow-xs">
      <div
        class="grid grid-cols-[1fr_8rem_5rem_3.5rem_3.5rem_3.5rem] gap-2 border-b border-border-default bg-page-bg px-3 py-2 text-[0.6875rem] font-semibold uppercase tracking-wider text-text-muted"
      >
        <span>Akun</span>
        <span>Tipe</span>
        <span>Normal</span>
        <span class="text-center">Post</span>
        <span class="text-center">Aktif</span>
        <span class="text-center">Fiskal</span>
      </div>
      {#each treeData as node (node.account.id)}
        <CoaTreeNode {node} onSelect={openEdit} />
      {:else}
        <div class="px-4 py-10 text-center text-sm text-text-muted">Belum ada akun.</div>
      {/each}
    </div>
  {:else if viewMode === 'tview'}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
      <section class="rounded-lg border border-border-default bg-card-bg shadow-xs">
        <header
          class="flex items-center justify-between border-b border-border-default bg-info-light px-4 py-2"
        >
          <strong class="text-info">Debit-normal</strong>
          <span class="ak-pill bg-card-bg text-info"
            >{filtered.filter((a) => a.normal_balance === 'debit').length}</span
          >
        </header>
        <div
          class="grid grid-cols-[1fr_8rem_5rem_3.5rem_3.5rem_3.5rem] gap-2 border-b border-border-soft bg-page-bg px-3 py-2 text-[0.625rem] font-semibold uppercase tracking-wider text-text-muted"
        >
          <span>Akun</span>
          <span>Tipe</span>
          <span>Normal</span>
          <span class="text-center">Post</span>
          <span class="text-center">Aktif</span>
          <span class="text-center">Fiskal</span>
        </div>
        {#each debitTree as node (node.account.id)}
          <CoaTreeNode {node} onSelect={openEdit} />
        {:else}
          <div class="px-4 py-10 text-center text-sm text-text-muted">Tidak ada.</div>
        {/each}
      </section>

      <section class="rounded-lg border border-border-default bg-card-bg shadow-xs">
        <header
          class="flex items-center justify-between border-b border-border-default bg-warning-light px-4 py-2"
        >
          <strong class="text-warning">Credit-normal</strong>
          <span class="ak-pill bg-card-bg text-warning"
            >{filtered.filter((a) => a.normal_balance === 'credit').length}</span
          >
        </header>
        <div
          class="grid grid-cols-[1fr_8rem_5rem_3.5rem_3.5rem_3.5rem] gap-2 border-b border-border-soft bg-page-bg px-3 py-2 text-[0.625rem] font-semibold uppercase tracking-wider text-text-muted"
        >
          <span>Akun</span>
          <span>Tipe</span>
          <span>Normal</span>
          <span class="text-center">Post</span>
          <span class="text-center">Aktif</span>
          <span class="text-center">Fiskal</span>
        </div>
        {#each creditTree as node (node.account.id)}
          <CoaTreeNode {node} onSelect={openEdit} />
        {:else}
          <div class="px-4 py-10 text-center text-sm text-text-muted">Tidak ada.</div>
        {/each}
      </section>
    </div>
  {/if}
</div>

{#if creating || editing}
  <div
    class="fixed inset-0 z-30 flex items-center justify-center bg-black/30 p-4"
    onclick={closeForm}
  >
    <div
      class="w-full max-w-lg rounded-lg bg-card-bg p-6 shadow-lg"
      onclick={(e) => e.stopPropagation()}
      role="dialog"
    >
      <h2 class="mb-4 text-lg font-bold">{editing ? `Edit Akun ${editing.code}` : 'Akun Baru'}</h2>
      <div class="grid grid-cols-2 gap-3 text-sm">
        <label class="col-span-1">
          <span class="block font-medium mb-1">Kode <span class="text-danger">*</span></span>
          <input
            class="w-full rounded-md border border-border-default px-2 py-1.5"
            bind:value={form.code}
          />
          {#if fieldErr('code')}<span class="text-xs text-danger">{fieldErr('code')}</span>{/if}
        </label>
        <label class="col-span-1">
          <span class="block font-medium mb-1">Nama <span class="text-danger">*</span></span>
          <input
            class="w-full rounded-md border border-border-default px-2 py-1.5"
            bind:value={form.name}
          />
          {#if fieldErr('name')}<span class="text-xs text-danger">{fieldErr('name')}</span>{/if}
        </label>
        <label class="col-span-1">
          <span class="block font-medium mb-1">Tipe</span>
          <select
            class="w-full rounded-md border border-border-default px-2 py-1.5"
            bind:value={form.type}
          >
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
          <select
            class="w-full rounded-md border border-border-default px-2 py-1.5"
            bind:value={form.normal_balance}
          >
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
        <label class="col-span-1 flex items-center gap-2 mt-2">
          <input type="checkbox" bind:checked={form.is_fiskal} />
          <span>Fiskal</span>
        </label>
      </div>

      <div class="mt-5 flex items-center justify-between gap-2">
        <div>
          {#if editing}
            <button
              type="button"
              class="rounded-md border border-danger/40 bg-danger-light px-3 py-2 text-sm font-semibold text-danger hover:bg-danger hover:text-white"
              onclick={() => destroy(editing!)}
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
