<script lang="ts">
  import { onMount } from 'svelte';
  import { goto } from '$app/navigation';
  import { auth } from '$lib/stores/auth.svelte.js';
  import {
    recurringApi,
    type RecurringJournal,
    type RecurringInput,
    type Frequency,
  } from '$lib/api/recurring.js';
  import { templateApi, type JournalTemplateSummary } from '$lib/api/template.js';
  import { ApiError } from '$lib/api/client.js';
  import Combobox from '$lib/components/ui/Combobox.svelte';
  import DateInput from '$lib/components/ui/DateInput.svelte';
  import { formatDate } from '$lib/utils/date.js';

  let items = $state<RecurringJournal[]>([]);
  let templates = $state<JournalTemplateSummary[]>([]);
  let loading = $state(true);
  let error = $state<string | null>(null);

  let editing = $state<RecurringJournal | null>(null);
  let creating = $state(false);
  let form = $state<RecurringInput>({
    template_id: '',
    name: '',
    frequency: 'monthly',
    day: 1,
    start_date: new Date().toISOString().slice(0, 10),
  });
  let formErrors = $state<Record<string, string[]> | null>(null);
  let saving = $state(false);

  async function load() {
    loading = true;
    error = null;
    try {
      [items, templates] = await Promise.all([recurringApi.list(), templateApi.list(50)]);
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
      template_id: templates[0]?.id ?? '',
      name: '',
      frequency: 'monthly',
      day: 1,
      start_date: new Date().toISOString().slice(0, 10),
    };
    formErrors = null;
  }

  function openEdit(r: RecurringJournal) {
    creating = false;
    editing = r;
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

  function closeForm() {
    editing = null;
    creating = false;
    formErrors = null;
  }

  async function save() {
    saving = true;
    formErrors = null;
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
    } finally {
      saving = false;
    }
  }

  async function pause(r: RecurringJournal) {
    try {
      await recurringApi.pause(r.id);
      closeForm();
      await load();
    } catch (e) {
      alert(e instanceof Error ? e.message : String(e));
    }
  }
  async function resume(r: RecurringJournal) {
    try {
      await recurringApi.resume(r.id);
      closeForm();
      await load();
    } catch (e) {
      alert(e instanceof Error ? e.message : String(e));
    }
  }
  async function run(r: RecurringJournal) {
    if (!confirm(`Jalankan ${r.name} sekarang?`)) return;
    try {
      const res = await recurringApi.run(r.id);
      closeForm();
      await load();
      if (res.journal_id) goto(`/journals/${res.journal_id}`);
      else alert('Tidak ada jurnal yang dibuat (cek tanggal next_run).');
    } catch (e) {
      alert(e instanceof Error ? e.message : String(e));
    }
  }
  async function destroy(r: RecurringJournal) {
    if (!confirm(`Hapus ${r.name}?`)) return;
    try {
      await recurringApi.destroy(r.id);
      closeForm();
      await load();
    } catch (e) {
      alert(e instanceof Error ? e.message : String(e));
    }
  }

  function statusColor(s: RecurringJournal['status']): string {
    return s === 'active'
      ? 'bg-paid-light text-paid'
      : s === 'paused'
        ? 'bg-warning-light text-warning'
        : 'bg-page-bg text-text-muted';
  }

  function fieldErr(name: string): string | null {
    return formErrors?.[name]?.[0] ?? null;
  }
</script>

<div class="px-6 py-6">
  <header class="mb-5 flex items-center justify-between">
    <div>
      <p class="text-xs font-medium text-text-muted">Jurnal / Jurnal Berulang</p>
      <h1 class="text-2xl font-bold">Jurnal Berulang</h1>
      <p class="text-sm text-text-muted">{items.length} schedule</p>
    </div>
    <button
      type="button"
      class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-active disabled:opacity-50"
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
    <div class="rounded-md border border-danger bg-danger-light p-3 text-sm text-danger">
      {error}
    </div>
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
          </tr>
        </thead>
        <tbody>
          {#each items as r (r.id)}
            <tr
              class="border-t border-border-soft hover:bg-page-bg cursor-pointer"
              onclick={() => openEdit(r)}
            >
              <td class="px-4 py-2 font-medium">{r.name}</td>
              <td class="px-4 py-2 font-mono text-xs"
                >{r.template_code ?? r.template_id.slice(0, 8)}</td
              >
              <td class="px-4 py-2 capitalize">{r.frequency}</td>
              <td class="px-4 py-2">{formatDate(r.start_date)}</td>
              <td class="px-4 py-2">{r.next_run_at ?? '—'}</td>
              <td class="px-4 py-2">
                <span
                  class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold {statusColor(
                    r.status,
                  )}"
                >
                  {r.status}
                </span>
              </td>
            </tr>
          {:else}
            <tr
              ><td colspan="6" class="px-4 py-10 text-center text-text-muted"
                >Belum ada schedule.</td
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
      class="w-full max-w-lg rounded-lg bg-card-bg p-6 shadow-lg"
      onclick={(e) => e.stopPropagation()}
      role="dialog"
    >
      <h2 class="mb-4 text-lg font-bold">{editing ? `Edit ${editing.name}` : 'Schedule Baru'}</h2>
      <div class="grid grid-cols-2 gap-3 text-sm">
        <label class="col-span-2">
          <span class="block font-medium mb-1">Template <span class="text-danger">*</span></span>
          <Combobox
            options={templates.map((t) => ({
              id: t.id,
              label: t.name,
              code: t.code,
              sublabel: t.description ?? null,
            }))}
            value={form.template_id}
            placeholder="Cari template (kode atau nama)…"
            onSelect={(id) => (form.template_id = id)}
          />
          {#if fieldErr('template_id')}<span class="text-xs text-danger"
              >{fieldErr('template_id')}</span
            >{/if}
        </label>
        <label class="col-span-2">
          <span class="block font-medium mb-1">Nama <span class="text-danger">*</span></span>
          <input
            class="w-full rounded-md border border-border-default px-2 py-1.5"
            bind:value={form.name}
          />
          {#if fieldErr('name')}<span class="text-xs text-danger">{fieldErr('name')}</span>{/if}
        </label>
        <label>
          <span class="block font-medium mb-1">Frekuensi</span>
          <select
            class="w-full rounded-md border border-border-default px-2 py-1.5"
            bind:value={form.frequency}
          >
            <option value="daily">Harian</option>
            <option value="weekly">Mingguan</option>
            <option value="monthly">Bulanan</option>
            <option value="quarterly">Triwulan</option>
            <option value="yearly">Tahunan</option>
          </select>
        </label>
        <label>
          <span class="block font-medium mb-1">Tgl/Hari</span>
          <input
            type="number"
            min="1"
            max="31"
            class="w-full rounded-md border border-border-default px-2 py-1.5"
            bind:value={form.day}
          />
        </label>
        <label>
          <span class="block font-medium mb-1">Mulai <span class="text-danger">*</span></span>
          <DateInput value={form.start_date} onChange={(iso) => (form.start_date = iso)} />
        </label>
        <label>
          <span class="block font-medium mb-1">Selesai (opsional)</span>
          <DateInput
            value={form.end_date ?? ''}
            onChange={(iso) => (form.end_date = iso || null)}
          />
        </label>
        <label class="col-span-2 flex items-center gap-2">
          <input type="checkbox" bind:checked={form.auto_post} />
          <span>Auto-post setelah jalankan</span>
        </label>
      </div>

      <div class="mt-5 flex flex-wrap items-center justify-between gap-2">
        <div class="flex flex-wrap gap-2">
          {#if editing}
            {#if editing.status === 'active'}
              <button
                type="button"
                class="rounded-md border border-warning/40 bg-warning-light px-3 py-2 text-sm font-semibold text-warning hover:bg-warning hover:text-white"
                onclick={() => pause(editing!)}
              >
                Pause
              </button>
            {:else if editing.status === 'paused'}
              <button
                type="button"
                class="rounded-md border border-paid/40 bg-paid-light px-3 py-2 text-sm font-semibold text-paid hover:bg-paid hover:text-white"
                onclick={() => resume(editing!)}
              >
                Resume
              </button>
            {/if}
            <button
              type="button"
              class="rounded-md border border-primary/40 bg-primary-light px-3 py-2 text-sm font-semibold text-primary hover:bg-primary hover:text-white"
              onclick={() => run(editing!)}
            >
              Run Sekarang
            </button>
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
