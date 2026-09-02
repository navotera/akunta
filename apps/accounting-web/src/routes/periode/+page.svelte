<script lang="ts">
  import { onMount } from 'svelte';
  import { goto } from '$app/navigation';
  import { auth } from '$lib/stores/auth.svelte.js';
  import { periodApi, type Period, type PeriodInput } from '$lib/api/period.js';
  import { period } from '$lib/stores/period.svelte.js';
  import { tenant } from '$lib/stores/tenant.svelte.js';
  import { ApiError } from '$lib/api/client.js';
  import DateInput from '$lib/components/ui/DateInput.svelte';
  import { formatDate } from '$lib/utils/date.js';

  let items = $state<Period[]>([]);
  let loading = $state(true);
  let error = $state<string | null>(null);

  let editing = $state<Period | null>(null);
  let creating = $state(false);
  let form = $state<PeriodInput>({ name: '', start_date: '', end_date: '' });
  let formErrors = $state<Record<string, string[]> | null>(null);
  let saving = $state(false);
  const pageSize = 10;
  let currentPage = $state(1);
  let totalPages = $derived(Math.max(1, Math.ceil(items.length / pageSize)));
  let visibleItems = $derived(items.slice((currentPage - 1) * pageSize, currentPage * pageSize));
  const isAdmin = $derived(auth.user?.is_admin ?? auth.user?.is_sso_admin ?? false);
  const canSwitchPeriod = $derived(
    Boolean(isAdmin || auth.user?.roles.some((role) => role.toLowerCase() === 'accountant')),
  );
  const isNativeFake = $derived(
    tenant.available.find((item) => item.id === tenant.id)?.is_fake_data ?? false,
  );

  async function load() {
    loading = true;
    error = null;
    try {
      items = await periodApi.list();
      currentPage = Math.min(currentPage, Math.max(1, Math.ceil(items.length / pageSize)));
      await period.refresh();
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
    form = { name: '', start_date: '', end_date: '' };
    formErrors = null;
  }

  function openEdit(p: Period) {
    creating = false;
    editing = p;
    form = { name: p.name, start_date: p.start_date, end_date: p.end_date };
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
      if (editing) await periodApi.update(editing.id, form);
      else await periodApi.create(form);
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

  async function close(p: Period) {
    try {
      await periodApi.close(p.id);
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

  async function reopen(p: Period) {
    try {
      await periodApi.reopen(p.id);
      closeForm();
      await load();
    } catch (e) {
      alert(e instanceof Error ? e.message : String(e));
    }
  }

  async function togglePeriod(p: Period, event: MouseEvent) {
    event.stopPropagation();
    if (p.status === 'open') await close(p);
    else if (p.status === 'closed') await reopen(p);
  }

  async function destroy(p: Period) {
    if (!confirm(`Hapus periode ${p.name}?`)) return;
    try {
      await periodApi.destroy(p.id);
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

  function statusColor(s: Period['status']): string {
    return s === 'open'
      ? 'bg-paid-light text-paid'
      : s === 'closed'
        ? 'bg-page-bg text-text-muted'
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
    {#if !isNativeFake}
      <button
        type="button"
        class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-active"
        onclick={openCreate}
      >
        + Periode Baru
      </button>
    {/if}
  </header>

  {#if isNativeFake}
    <div
      class="mb-5 rounded-md border border-warning/40 bg-warning-light p-4 text-sm text-warning"
      data-testid="native-demo-period-lock"
    >
      <strong>Periode Demo 2026 terkunci.</strong> Penambahan, perubahan, penutupan, dan penghapusan periode
      dinonaktifkan untuk menjaga dataset simulasi tetap konsisten. Gunakan Reset Dataset Demo pada Settings
      jika ingin memulihkan data bawaan.
    </div>
  {/if}

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
            <th class="w-14 px-4 py-3 text-left">#</th>
            <th class="px-4 py-3 text-left">Nama</th>
            <th class="px-4 py-3 text-left">Mulai</th>
            <th class="px-4 py-3 text-left">Selesai</th>
            <th class="px-4 py-3 text-left">Status</th>
          </tr>
        </thead>
        <tbody>
          {#each visibleItems as p, index (p.id)}
            <tr
              class="border-t border-border-soft {isNativeFake
                ? ''
                : 'cursor-pointer hover:bg-page-bg'}"
              onclick={() => !isNativeFake && openEdit(p)}
            >
              <td class="px-4 py-2 text-text-muted">{(currentPage - 1) * pageSize + index + 1}</td>
              <td class="px-4 py-2 font-medium">{p.name}</td>
              <td class="px-4 py-2">{formatDate(p.start_date)}</td>
              <td class="px-4 py-2">{formatDate(p.end_date)}</td>
              <td class="px-4 py-2">
                <button
                  type="button"
                  role="switch"
                  aria-checked={p.status === 'open'}
                  aria-label={`${p.name}: ${p.status === 'open' ? 'Aktif' : 'Nonaktif'}`}
                  class="inline-flex items-center gap-2 rounded-full px-2 py-1 text-xs font-semibold {statusColor(
                    p.status,
                  )}"
                  onclick={(event) => togglePeriod(p, event)}
                  disabled={isNativeFake || !canSwitchPeriod || p.status === 'closing'}
                >
                  <span
                    class="relative h-4 w-7 rounded-full {p.status === 'open'
                      ? 'bg-paid'
                      : 'bg-border-default'}"
                    aria-hidden="true"
                  >
                    <span
                      class="absolute top-0.5 h-3 w-3 rounded-full bg-white shadow {p.status ===
                      'open'
                        ? 'right-0.5'
                        : 'left-0.5'}"
                    ></span>
                  </span>
                  <span>{p.status === 'open' ? 'ON' : p.status === 'closed' ? 'OFF' : '...'}</span>
                </button>
              </td>
            </tr>
          {:else}
            <tr
              ><td colspan="5" class="px-4 py-10 text-center text-text-muted">Belum ada periode.</td
              ></tr
            >
          {/each}
        </tbody>
      </table>
      {#if totalPages > 1}
        <nav
          class="flex items-center justify-between border-t border-border-default px-4 py-3 text-sm"
          aria-label="Navigasi halaman periode"
        >
          <span class="text-text-muted">Halaman {currentPage} dari {totalPages}</span>
          <div class="flex items-center gap-1">
            <button
              type="button"
              class="rounded-md border border-border-default px-3 py-1.5 text-text-muted hover:bg-page-bg disabled:cursor-not-allowed disabled:opacity-40"
              onclick={() => (currentPage = Math.max(1, currentPage - 1))}
              disabled={currentPage === 1}
            >
              Sebelumnya
            </button>
            {#each Array(totalPages) as _, index}
              <button
                type="button"
                class="rounded-md px-3 py-1.5 {currentPage === index + 1
                  ? 'bg-primary text-white'
                  : 'text-text-muted hover:bg-page-bg'}"
                aria-current={currentPage === index + 1 ? 'page' : undefined}
                onclick={() => (currentPage = index + 1)}
              >
                {index + 1}
              </button>
            {/each}
            <button
              type="button"
              class="rounded-md border border-border-default px-3 py-1.5 text-text-muted hover:bg-page-bg disabled:cursor-not-allowed disabled:opacity-40"
              onclick={() => (currentPage = Math.min(totalPages, currentPage + 1))}
              disabled={currentPage === totalPages}
            >
              Berikutnya
            </button>
          </div>
        </nav>
      {/if}
    </div>
  {/if}
</div>

{#if creating || editing}
  <div
    class="fixed inset-0 z-30 flex items-center justify-center bg-black/30 p-4"
    onclick={closeForm}
  >
    <div
      class="w-full max-w-md rounded-lg bg-card-bg p-6 shadow-lg"
      onclick={(e) => e.stopPropagation()}
      role="dialog"
    >
      <h2 class="mb-4 text-lg font-bold">{editing ? `Edit ${editing.name}` : 'Periode Baru'}</h2>
      <div class="space-y-3 text-sm">
        <label class="block">
          <span class="block font-medium mb-1">Nama <span class="text-danger">*</span></span>
          <input
            class="w-full rounded-md border border-border-default px-2 py-1.5"
            bind:value={form.name}
          />
          {#if fieldErr('name')}<span class="text-xs text-danger">{fieldErr('name')}</span>{/if}
        </label>
        <label class="block">
          <span class="block font-medium mb-1">Mulai</span>
          <DateInput value={form.start_date} onChange={(iso) => (form.start_date = iso)} />
          {#if fieldErr('start_date')}<span class="text-xs text-danger"
              >{fieldErr('start_date')}</span
            >{/if}
        </label>
        <label class="block">
          <span class="block font-medium mb-1">Selesai</span>
          <DateInput value={form.end_date} onChange={(iso) => (form.end_date = iso)} />
          {#if fieldErr('end_date')}<span class="text-xs text-danger">{fieldErr('end_date')}</span
            >{/if}
        </label>
      </div>

      <div class="mt-5 flex items-center justify-between gap-2">
        <div class="flex gap-2">
          {#if editing}
            {#if editing.status === 'open'}
              <button
                type="button"
                class="rounded-md border border-warning/40 bg-warning-light px-3 py-2 text-sm font-semibold text-warning hover:bg-warning hover:text-white"
                onclick={() => close(editing!)}
              >
                Tutup
              </button>
              <button
                type="button"
                class="rounded-md border border-danger/40 bg-danger-light px-3 py-2 text-sm font-semibold text-danger hover:bg-danger hover:text-white"
                onclick={() => destroy(editing!)}
              >
                Hapus
              </button>
            {:else}
              <button
                type="button"
                class="rounded-md border border-paid/40 bg-paid-light px-3 py-2 text-sm font-semibold text-paid hover:bg-paid hover:text-white"
                onclick={() => reopen(editing!)}
              >
                Buka Lagi
              </button>
            {/if}
          {/if}
        </div>
        <div class="flex gap-2">
          <button
            type="button"
            class="text-sm text-text-muted hover:text-text-default"
            onclick={closeForm}>Batal</button
          >
          {#if !editing || editing.status === 'open'}
            <button
              type="button"
              class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-active disabled:opacity-50"
              onclick={save}
              disabled={saving}
            >
              {saving ? 'Menyimpan…' : 'Simpan'}
            </button>
          {/if}
        </div>
      </div>
    </div>
  </div>
{/if}
