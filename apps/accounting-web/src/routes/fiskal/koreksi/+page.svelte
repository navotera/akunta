<script lang="ts">
  import { onMount } from 'svelte';
  import { goto } from '$app/navigation';
  import { formatRupiah } from '@akunta/ui';
  import { auth } from '$lib/stores/auth.svelte.js';
  import { accountApi, type Account } from '$lib/api/account.js';
  import { attachmentApi } from '$lib/api/attachment.js';
  import {
    FISCAL_ADJUSTMENT_ATTACHABLE_TYPE,
    fiscalAdjustmentApi,
    type FiscalAdjustment,
  } from '$lib/api/fiscal-adjustment.js';
  import { journalApi, type JournalSummary } from '$lib/api/journal.js';
  import { reportingApi, type FiscalReconciliationData } from '$lib/api/reporting.js';
  import DateInput from '$lib/components/ui/DateInput.svelte';
  import { formatDate } from '$lib/utils/date.js';

  const now = new Date();
  let periodStart = $state(`${now.getFullYear()}-01-01`);
  let periodEnd = $state(now.toISOString().slice(0, 10));
  let items = $state<FiscalAdjustment[]>([]);
  let accounts = $state<Account[]>([]);
  let journals = $state<JournalSummary[]>([]);
  let report = $state<FiscalReconciliationData | null>(null);
  let loading = $state(true);
  let saving = $state(false);
  let error = $state<string | null>(null);
  let formOpen = $state(false);
  let evidence = $state<File[]>([]);
  let canManage = $derived(
    auth.user?.is_admin ||
      auth.user?.roles.some((role) =>
        ['super_admin', 'admin', 'accountant', 'tax_officer'].includes(role),
      ),
  );
  let canApprove = $derived(
    auth.user?.is_admin ||
      auth.user?.roles.some((role) =>
        ['super_admin', 'admin', 'supervisor', 'tax_officer'].includes(role),
      ),
  );
  let form = $state({
    journal_id: '',
    account_id: '',
    date: now.toISOString().slice(0, 10),
    direction: 'positive' as 'positive' | 'negative',
    amount: '',
    reason: '',
    legal_basis: '',
  });

  async function load() {
    loading = true;
    error = null;
    try {
      [items, accounts, report] = await Promise.all([
        fiscalAdjustmentApi.list(periodStart, periodEnd),
        accountApi.list('', undefined, true, 'fiscal'),
        reportingApi.fiscalReconciliation(periodStart, periodEnd).then((response) => response.data),
      ]);
      const journalResult = await journalApi.list({
        per_page: 100,
        journal_mode: 'fiscal',
        status: 'posted',
      });
      journals = journalResult.data;
    } catch (caught) {
      error = caught instanceof Error ? caught.message : 'Data Fiskal gagal dimuat.';
    } finally {
      loading = false;
    }
  }

  onMount(async () => {
    if (!auth.user) {
      const user = await auth.refresh();
      if (!user) {
        await goto('/login', { replaceState: true });
        return;
      }
    }
    await load();
  });

  async function createAdjustment() {
    if (!form.account_id || !form.amount || !form.reason.trim()) return;
    saving = true;
    error = null;
    try {
      const created = await fiscalAdjustmentApi.create({
        ...form,
        journal_id: form.journal_id || null,
        legal_basis: form.legal_basis || null,
      });
      await Promise.all(
        evidence.map((file) =>
          attachmentApi.upload(FISCAL_ADJUSTMENT_ATTACHABLE_TYPE, created.id, file),
        ),
      );
      formOpen = false;
      evidence = [];
      form = {
        journal_id: '',
        account_id: '',
        date: form.date,
        direction: 'positive',
        amount: '',
        reason: '',
        legal_basis: '',
      };
      await load();
    } catch (caught) {
      error = caught instanceof Error ? caught.message : 'Koreksi Fiskal gagal disimpan.';
    } finally {
      saving = false;
    }
  }

  async function approve(id: string) {
    await fiscalAdjustmentApi.approve(id);
    await load();
  }

  async function remove(id: string) {
    if (!window.confirm('Hapus koreksi Fiskal draft ini?')) return;
    await fiscalAdjustmentApi.destroy(id);
    await load();
  }
</script>

<div class="px-6 py-6">
  <header class="mb-5 flex flex-wrap items-start justify-between gap-4">
    <div>
      <p class="text-xs font-semibold uppercase tracking-wider text-warning">Buku Fiskal</p>
      <h1 class="text-2xl font-bold">Koreksi dan Laporan Pajak Final</h1>
      <p class="mt-1 max-w-3xl text-sm text-text-muted">
        Jurnal Fiskal berdiri sendiri dari Intern. Koreksi di halaman ini tidak mengubah jurnal mana
        pun dan hanya koreksi disetujui yang masuk laporan final.
      </p>
    </div>
    {#if canManage}
      <button
        type="button"
        class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white"
        onclick={() => (formOpen = true)}>+ Koreksi Fiskal</button
      >
    {/if}
  </header>

  <div
    class="mb-5 flex flex-wrap items-end gap-3 rounded-lg border border-border-default bg-card-bg p-4"
  >
    <label class="text-sm"
      ><span class="mb-1 block font-medium">Mulai</span><DateInput
        value={periodStart}
        onChange={(value) => (periodStart = value)}
      /></label
    >
    <label class="text-sm"
      ><span class="mb-1 block font-medium">Sampai</span><DateInput
        value={periodEnd}
        onChange={(value) => (periodEnd = value)}
      /></label
    >
    <button
      type="button"
      class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white"
      onclick={load}
      disabled={loading}>{loading ? 'Memuat…' : 'Tampilkan'}</button
    >
  </div>

  {#if error}<div
      class="mb-4 rounded-md border border-danger bg-danger-light p-3 text-sm text-danger"
    >
      {error}
    </div>{/if}

  {#if report}
    <div class="mb-5 grid gap-3 md:grid-cols-4">
      <div class="ak-card p-4">
        <p class="text-xs text-text-muted">Laba Buku Fiskal</p>
        <p class="mt-1 font-mono text-lg font-bold">{formatRupiah(report.book_net_income)}</p>
      </div>
      <div class="ak-card p-4">
        <p class="text-xs text-text-muted">Koreksi Positif</p>
        <p class="mt-1 font-mono text-lg font-bold text-warning">
          {formatRupiah(report.positive_adjustments)}
        </p>
      </div>
      <div class="ak-card p-4">
        <p class="text-xs text-text-muted">Koreksi Negatif</p>
        <p class="mt-1 font-mono text-lg font-bold text-info">
          {formatRupiah(report.negative_adjustments)}
        </p>
      </div>
      <div class="ak-card p-4">
        <p class="text-xs text-text-muted">Laba Fiskal Final</p>
        <p class="mt-1 font-mono text-lg font-bold text-paid">
          {formatRupiah(report.final_net_income)}
        </p>
      </div>
    </div>

    <div class="mb-6 overflow-x-auto rounded-lg border border-border-default bg-card-bg">
      <table class="w-full text-sm">
        <thead class="bg-page-bg text-xs uppercase text-text-muted"
          ><tr
            ><th class="px-4 py-3 text-left">Akun</th><th class="px-4 py-3 text-right"
              >Buku Fiskal</th
            ><th class="px-4 py-3 text-right">Koreksi +</th><th class="px-4 py-3 text-right"
              >Koreksi −</th
            ><th class="px-4 py-3 text-right">Pajak Final</th></tr
          ></thead
        >
        <tbody
          >{#each report.rows as row (row.account_id)}<tr class="border-t border-border-soft"
              ><td class="px-4 py-3"
                ><span class="font-mono text-xs text-text-muted">{row.code}</span> {row.name}</td
              ><td class="px-4 py-3 text-right font-mono">{formatRupiah(row.book_amount)}</td><td
                class="px-4 py-3 text-right font-mono">{formatRupiah(row.positive_adjustment)}</td
              ><td class="px-4 py-3 text-right font-mono"
                >{formatRupiah(row.negative_adjustment)}</td
              ><td class="px-4 py-3 text-right font-mono font-semibold"
                >{formatRupiah(row.final_amount)}</td
              ></tr
            >{:else}<tr
              ><td colspan="5" class="px-4 py-10 text-center text-text-muted"
                >Belum ada jurnal atau koreksi Fiskal pada periode ini.</td
              ></tr
            >{/each}</tbody
        >
      </table>
    </div>
  {/if}

  <section class="overflow-x-auto rounded-lg border border-border-default bg-card-bg">
    <div class="border-b border-border-soft px-4 py-3">
      <h2 class="font-bold">Daftar Koreksi Fiskal</h2>
    </div>
    <table class="w-full text-sm">
      <thead class="bg-page-bg text-xs uppercase text-text-muted"
        ><tr
          ><th class="px-4 py-3 text-left">Tanggal</th><th class="px-4 py-3 text-left"
            >Akun/Jurnal</th
          ><th class="px-4 py-3 text-left">Alasan</th><th class="px-4 py-3 text-right">Nilai</th><th
            class="px-4 py-3 text-left">Status/Bukti</th
          ><th class="px-4 py-3"></th></tr
        ></thead
      ><tbody>
        {#each items as item (item.id)}
          <tr class="border-t border-border-soft"
            ><td class="px-4 py-3">{formatDate(item.date)}</td><td class="px-4 py-3"
              ><strong>{item.account_code} · {item.account_name}</strong><span
                class="block text-xs text-text-muted"
                >{item.journal_number ?? 'Tanpa jurnal sumber'}</span
              ></td
            ><td class="max-w-md px-4 py-3"
              ><span class="line-clamp-2">{item.reason}</span>{#if item.legal_basis}<span
                  class="mt-1 block text-xs text-text-muted">Dasar: {item.legal_basis}</span
                >{/if}</td
            ><td
              class="px-4 py-3 text-right font-mono {item.direction === 'positive'
                ? 'text-warning'
                : 'text-info'}"
              >{item.direction === 'positive' ? '+' : '−'} {formatRupiah(item.amount)}</td
            ><td class="px-4 py-3"
              ><span
                class="rounded-full px-2 py-1 text-xs font-semibold {item.status === 'approved'
                  ? 'bg-paid-light text-paid'
                  : 'bg-warning-light text-warning'}">{item.status}</span
              ><span class="ml-2 text-xs text-text-muted">{item.attachments_count} bukti</span></td
            ><td class="px-4 py-3 text-right"
              >{#if item.status === 'draft' && canApprove}<button
                  class="mr-2 text-xs font-semibold text-paid"
                  onclick={() => approve(item.id)}>Setujui</button
                >{/if}{#if item.status === 'draft' && canManage}<button
                  class="text-xs font-semibold text-danger"
                  onclick={() => remove(item.id)}>Hapus</button
                >{/if}</td
            ></tr
          >
        {:else}<tr
            ><td colspan="6" class="px-4 py-10 text-center text-text-muted"
              >Belum ada koreksi Fiskal.</td
            ></tr
          >{/each}
      </tbody>
    </table>
  </section>
</div>

{#if formOpen}
  <div
    class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
    role="presentation"
    onclick={(event) => event.currentTarget === event.target && (formOpen = false)}
  >
    <div
      class="w-full max-w-2xl rounded-lg bg-card-bg p-6 shadow-xl"
      role="dialog"
      aria-modal="true"
      aria-labelledby="fiscal-form-title"
      tabindex="-1"
    >
      <h2 id="fiscal-form-title" class="text-lg font-bold">Koreksi Fiskal Baru</h2>
      <p class="mt-1 text-sm text-text-muted">
        Koreksi hanya memengaruhi laporan pajak final; jurnal Fiskal dan Intern tidak diubah.
      </p>
      <div class="mt-5 grid gap-4 sm:grid-cols-2">
        <label class="text-sm"
          ><span class="mb-1 block font-medium">Tanggal</span><DateInput
            value={form.date}
            onChange={(value) => (form.date = value)}
          /></label
        >
        <label class="text-sm"
          ><span class="mb-1 block font-medium">Arah koreksi</span><select
            class="w-full rounded-md border border-border-default px-3 py-2"
            bind:value={form.direction}
            ><option value="positive">Positif — menambah laba fiskal</option><option
              value="negative">Negatif — mengurangi laba fiskal</option
            ></select
          ></label
        >
        <label class="text-sm sm:col-span-2"
          ><span class="mb-1 block font-medium">Akun Fiskal</span><select
            class="w-full rounded-md border border-border-default px-3 py-2"
            bind:value={form.account_id}
            ><option value="">Pilih akun</option
            >{#each accounts.filter( (account) => ['revenue', 'cogs', 'expense'].includes(account.type), ) as account (account.id)}<option
                value={account.id}>{account.code} · {account.name}</option
              >{/each}</select
          ></label
        >
        <label class="text-sm sm:col-span-2"
          ><span class="mb-1 block font-medium">Jurnal Fiskal sumber (opsional)</span><select
            class="w-full rounded-md border border-border-default px-3 py-2"
            bind:value={form.journal_id}
            ><option value="">Tanpa jurnal tertentu</option
            >{#each journals as journal (journal.id)}<option value={journal.id}
                >{journal.number} · {journal.memo}</option
              >{/each}</select
          ></label
        >
        <label class="text-sm"
          ><span class="mb-1 block font-medium">Nilai</span><input
            class="w-full rounded-md border border-border-default px-3 py-2"
            type="number"
            min="0.01"
            step="0.01"
            bind:value={form.amount}
          /></label
        >
        <label class="text-sm"
          ><span class="mb-1 block font-medium">Dasar hukum</span><input
            class="w-full rounded-md border border-border-default px-3 py-2"
            bind:value={form.legal_basis}
            placeholder="Pasal/peraturan atau kebijakan"
          /></label
        >
        <label class="text-sm sm:col-span-2"
          ><span class="mb-1 block font-medium">Alasan dan justifikasi</span><textarea
            class="min-h-24 w-full rounded-md border border-border-default px-3 py-2"
            bind:value={form.reason}
          ></textarea></label
        >
        <label class="text-sm sm:col-span-2"
          ><span class="mb-1 block font-medium">Bukti pendukung</span><input
            type="file"
            multiple
            onchange={(event) => (evidence = Array.from(event.currentTarget.files ?? []))}
          /><span class="mt-1 block text-xs text-text-muted"
            >{evidence.length} file dipilih. Maksimal 5 MB per file.</span
          ></label
        >
      </div>
      <div class="mt-6 flex justify-end gap-3">
        <button
          type="button"
          class="rounded-md border border-border-default px-4 py-2 text-sm"
          onclick={() => (formOpen = false)}>Batal</button
        ><button
          type="button"
          class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
          onclick={createAdjustment}
          disabled={saving || !form.account_id || !form.amount || !form.reason.trim()}
          >{saving ? 'Menyimpan…' : 'Simpan Draft'}</button
        >
      </div>
    </div>
  </div>
{/if}
