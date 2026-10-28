<script lang="ts">
  import { onMount } from 'svelte';
  import { goto } from '$app/navigation';
  import { page } from '$app/stores';
  import { auth } from '$lib/stores/auth.svelte.js';
  import { can } from '$lib/stores/permissions.svelte.js';
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
  let reopenDenied = $state(false);
  let activationReason = $state('');
  let activationConfirmed = $state(false);
  let tab = $state<'open' | 'inactive' | 'closed'>('open');
  const tabs = [
    { status: 'open', label: 'Periode Aktif' },
    { status: 'inactive', label: 'Periode Non Aktif' },
    { status: 'closed', label: 'Ditutup' },
  ] as const;
  const tabItems = $derived(
    items.filter((p) => p.status === tab || (tab === 'inactive' && p.status === 'closing')),
  );
  const pageSize = 10;
  let currentPage = $state(1);
  let totalPages = $derived(Math.max(1, Math.ceil(tabItems.length / pageSize)));
  let visibleItems = $derived(tabItems.slice((currentPage - 1) * pageSize, currentPage * pageSize));
  const canReopenPeriod = $derived(can('period.reopen'));
  const canClosePeriod = $derived(can('period.close'));
  const isNativeFake = $derived(
    tenant.available.find((item) => item.id === tenant.id)?.is_fake_data ?? false,
  );

  async function load() {
    loading = true;
    error = null;
    try {
      items = await periodApi.list();
      currentPage = Math.min(currentPage, Math.max(1, Math.ceil(tabItems.length / pageSize)));
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
    const requested = items.find((p) => p.id === $page.url.searchParams.get('activate_period_id'));
    if (requested) {
      tab =
        requested.status === 'open'
          ? 'open'
          : requested.status === 'closed'
            ? 'closed'
            : 'inactive';
      openEdit(requested);
    }
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
    activationReason = '';
    activationConfirmed = false;
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
    if (
      !confirm(`Tutup periode ${p.name}? Jurnal akan terkunci sampai periode diaktifkan kembali.`)
    )
      return;
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
    if (!activationReason.trim() || !activationConfirmed || saving) return;
    saving = true;
    try {
      await periodApi.reopen(p.id, activationReason.trim());
      closeForm();
      tab = 'open';
      currentPage = 1;
      await load();
      const returnTo = $page.url.searchParams.get('return_to');
      if (returnTo?.startsWith('/journals/') && !returnTo.includes('\\')) await goto(returnTo);
    } catch (e) {
      if (e instanceof ApiError && e.status === 403) {
        reopenDenied = true;
        return;
      }
      formErrors = { _: [e instanceof Error ? e.message : String(e)] };
    } finally {
      saving = false;
    }
  }

  async function deactivate(p: Period) {
    if (!confirm(`Nonaktifkan periode ${p.name}? Single edit dan batch jurnal akan dikunci.`))
      return;
    try {
      await periodApi.deactivate(p.id);
      closeForm();
      tab = 'inactive';
      currentPage = 1;
      await load();
    } catch (e) {
      formErrors = { _: [e instanceof Error ? e.message : String(e)] };
    }
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

  function selectTab(status: typeof tab) {
    tab = status;
    currentPage = 1;
  }

  function fieldErr(name: string): string | null {
    return formErrors?.[name]?.[0] ?? null;
  }
</script>

<div class="px-6 py-6">
  <header class="mb-5 flex items-center justify-between">
    <div>
      <h1 class="text-2xl font-bold">Periode Akuntansi</h1>
    </div>
    <button
      type="button"
      class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-active"
      onclick={openCreate}
      disabled={!can('period.create')}
    >
      + Periode Baru
    </button>
  </header>

  <nav
    class="mb-4 flex gap-1 overflow-x-auto border-b border-border-default"
    aria-label="Status periode"
  >
    {#each tabs as item}
      <button
        type="button"
        class="shrink-0 border-b-2 px-4 py-3 text-sm font-semibold {tab === item.status
          ? 'border-primary text-primary'
          : 'border-transparent text-text-muted hover:text-text-default'}"
        aria-current={tab === item.status ? 'page' : undefined}
        onclick={() => selectTab(item.status)}
      >
        {item.label}
        <span class="ml-1 tabular-nums"
          >({items.filter(
            (p) =>
              p.status === item.status || (item.status === 'inactive' && p.status === 'closing'),
          ).length})</span
        >
      </button>
    {/each}
  </nav>
  <p class="mb-4 text-sm text-text-muted">
    Periode nonaktif dan ditutup mengunci perubahan jurnal, termasuk edit satuan, batch, dan
    lampiran. Aktifkan kembali dengan alasan dan konfirmasi sebelum mengedit.
  </p>

  {#if isNativeFake}
    <div
      class="mb-5 rounded-md border border-border-default bg-card-bg p-4 text-sm text-text-default"
      data-testid="native-demo-period-sandbox"
    >
      <strong>Workspace demo untuk latihan.</strong> Periode dapat dikelola sesuai izin dan aturan periode
      normal. Gunakan Pulihkan Data Demo di Settings untuk mengembalikan periode bawaan.
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
            <th class="px-4 py-3 text-right">Tindakan</th>
          </tr>
        </thead>
        <tbody>
          {#each visibleItems as p, index (p.id)}
            <tr
              class="border-t border-border-soft cursor-pointer hover:bg-page-bg"
              onclick={() => openEdit(p)}
            >
              <td class="px-4 py-2 text-text-muted">{(currentPage - 1) * pageSize + index + 1}</td>
              <td class="px-4 py-2 font-medium">{p.name}</td>
              <td class="px-4 py-2">{formatDate(p.start_date)}</td>
              <td class="px-4 py-2">{formatDate(p.end_date)}</td>
              <td class="px-4 py-2">
                <span
                  class="inline-flex rounded-full px-2 py-1 text-xs font-semibold {statusColor(
                    p.status,
                  )}"
                  >{p.status === 'open'
                    ? 'Aktif'
                    : p.status === 'closed'
                      ? 'Ditutup'
                      : p.status === 'closing'
                        ? 'Proses penutupan'
                        : 'Non Aktif'}</span
                >
              </td>
              <td class="px-4 py-2 text-right">
                <button
                  type="button"
                  class="whitespace-nowrap text-sm font-semibold text-primary hover:underline"
                  onclick={(event) => {
                    event.stopPropagation();
                    openEdit(p);
                  }}
                  >{p.status === 'open'
                    ? 'Lihat Periode'
                    : canReopenPeriod
                      ? 'Aktifkan Kembali'
                      : 'Lihat Periode'}</button
                >
              </td>
            </tr>
          {:else}
            <tr
              ><td colspan="6" class="px-4 py-10 text-center text-text-muted"
                >Tidak ada periode pada tab ini.</td
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
      class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-lg bg-card-bg p-6 shadow-lg"
      onclick={(e) => e.stopPropagation()}
      role="dialog"
    >
      <h2 class="mb-4 text-lg font-bold">{editing ? `Periode ${editing.name}` : 'Periode Baru'}</h2>
      {#if fieldErr('_')}<p role="alert" class="mb-3 text-sm text-danger">{fieldErr('_')}</p>{/if}
      <fieldset disabled={Boolean(editing && (editing.status !== 'open' || !can('period.update')))}>
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
      </fieldset>

      {#if editing && editing.status !== 'open'}
        <div class="mt-4 border-t border-border-default pt-4">
          <p class="mb-3 text-sm text-text-muted">
            {canReopenPeriod
              ? 'Aktivasi membuka akses edit sesuai permission pengguna. Alasan dan pelaku dicatat di audit.'
              : 'Periode terkunci. Hubungi admin atau pengguna dengan izin membuka kembali periode.'}
          </p>
          {#if canReopenPeriod}
            <label class="block text-sm font-medium"
              >Alasan aktivasi <span class="text-danger">*</span>
              <textarea
                class="mt-1 w-full rounded-md border border-border-default bg-card-bg px-3 py-2"
                bind:value={activationReason}
                maxlength="1000"
                rows="3"
                data-testid="period-activation-reason"
              ></textarea>
            </label>
            <label class="mt-3 flex items-start gap-2 text-sm"
              ><input
                class="mt-1"
                type="checkbox"
                bind:checked={activationConfirmed}
                data-testid="period-activation-confirmed"
              />Saya memahami jurnal pada periode ini dapat diubah kembali sesuai izin pengguna.</label
            >
          {/if}
        </div>
      {/if}

      <div class="mt-5 flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap gap-2">
          {#if editing}
            {#if editing.status === 'open'}
              <button
                type="button"
                class="rounded-md border border-border-default px-3 py-2 text-sm font-semibold disabled:opacity-50"
                onclick={() => deactivate(editing!)}
                disabled={!can('period.deactivate')}>Nonaktifkan</button
              >
              <button
                type="button"
                class="rounded-md border border-warning/40 bg-warning-light px-3 py-2 text-sm font-semibold text-warning hover:bg-warning hover:text-white"
                onclick={() => close(editing!)}
                disabled={!canClosePeriod}
              >
                Tutup
              </button>
              <button
                type="button"
                class="rounded-md border border-danger/40 bg-danger-light px-3 py-2 text-sm font-semibold text-danger hover:bg-danger hover:text-white"
                onclick={() => destroy(editing!)}
                disabled={!can('period.delete')}
              >
                Hapus
              </button>
            {:else}
              {#if editing.status === 'inactive'}
                <button
                  type="button"
                  class="rounded-md border border-border-default px-3 py-2 text-sm font-semibold disabled:opacity-50"
                  onclick={() => close(editing!)}
                  disabled={!canClosePeriod}>Tutup Periode</button
                >
              {/if}
              <button
                type="button"
                class="rounded-md bg-primary px-3 py-2 text-sm font-semibold text-white hover:bg-primary-active disabled:opacity-50"
                onclick={() => reopen(editing!)}
                disabled={saving ||
                  !canReopenPeriod ||
                  !activationReason.trim() ||
                  !activationConfirmed}
                data-testid="period-activate"
              >
                Aktifkan Kembali
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
              disabled={saving || !can(editing ? 'period.update' : 'period.create')}
            >
              {saving ? 'Menyimpan…' : 'Simpan'}
            </button>
          {/if}
        </div>
      </div>
    </div>
  </div>
{/if}

{#if reopenDenied}
  <div class="fixed inset-0 z-40 flex items-center justify-center bg-black/30 p-4">
    <div
      class="w-full max-w-sm rounded-lg bg-card-bg p-6 text-center shadow-lg"
      role="dialog"
      aria-modal="true"
      aria-labelledby="reopen-denied-title"
    >
      <h2 id="reopen-denied-title" class="text-lg font-bold">Akses Ditolak</h2>
      <p class="mt-3 text-sm text-text-muted">Hubungi admin untuk mengaktifkan periode lain</p>
      <button
        type="button"
        class="mt-5 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-active"
        onclick={() => (reopenDenied = false)}
      >
        Tutup
      </button>
    </div>
  </div>
{/if}
