<script lang="ts">
  import { onMount } from 'svelte';
  import { goto } from '$app/navigation';
  import { auth } from '$lib/stores/auth.svelte.js';
  import { onboardingApi, type CoaTemplate, type OnboardingStatus } from '$lib/api/onboarding.js';
  import { periodApi } from '$lib/api/period.js';
  import { ApiError } from '$lib/api/client.js';
  import { installationOnboardingApi } from '$lib/api/installation-onboarding.js';
  import DateInput from '$lib/components/ui/DateInput.svelte';

  let status = $state<OnboardingStatus | null>(null);
  let templates = $state<CoaTemplate[]>([]);
  let selectedKey = $state<string | null>(null);
  let step = $state<0 | 1 | 2 | 3 | 4>(0);
  let bookkeepingMode = $state<'independent_books' | 'internal_only'>('independent_books');
  let busy = $state(false);
  let error = $state<string | null>(null);
  let entityName = $state('');
  let legalForm = $state('PT');

  let periodForm = $state({
    name: '',
    start_date: new Date(new Date().getFullYear(), new Date().getMonth(), 1)
      .toISOString()
      .slice(0, 10),
    end_date: new Date(new Date().getFullYear(), new Date().getMonth() + 1, 0)
      .toISOString()
      .slice(0, 10),
  });

  onMount(async () => {
    if (!auth.user) {
      const u = await auth.refresh();
      if (!u) {
        goto('/login', { replaceState: true });
        return;
      }
    }
    if (auth.user?.tenants.length === 0) {
      if (!auth.user.is_sso_admin) {
        error =
          'Entitas Akunta belum tersedia. Minta admin Ecopa menyelesaikan onboarding pertama.';
      }
      step = 0;
      return;
    }
    await loadEntityOnboarding();
  });

  async function loadEntityOnboarding() {
    [status, templates] = await Promise.all([onboardingApi.status(), onboardingApi.coaTemplates()]);
    selectedKey = templates[0]?.key ?? null;
    bookkeepingMode = status.bookkeeping_mode ?? 'independent_books';
    if (status.has_bookkeeping_mode) step = 2;
    if (status.has_bookkeeping_mode && status.has_accounts) step = 3;
    if (status.has_bookkeeping_mode && status.has_accounts && status.has_open_period) step = 4;
    periodForm.name = new Date().toLocaleString('id-ID', { month: 'long', year: 'numeric' });
  }

  async function createEntity() {
    busy = true;
    error = null;
    try {
      await installationOnboardingApi.createEntity({ name: entityName, legal_form: legalForm });
      await auth.refresh();
      await loadEntityOnboarding();
      step = 1;
    } catch (e) {
      error = e instanceof Error ? e.message : String(e);
    } finally {
      busy = false;
    }
  }

  async function saveBookkeepingMode() {
    busy = true;
    error = null;
    try {
      await onboardingApi.setBookkeepingMode(bookkeepingMode);
      status = await onboardingApi.status();
      step = 2;
    } catch (e) {
      error = (e as Error).message;
    } finally {
      busy = false;
    }
  }

  async function applyCoa() {
    if (!selectedKey) return;
    busy = true;
    error = null;
    try {
      await onboardingApi.applyCoa(selectedKey);
      status = await onboardingApi.status();
      step = 3;
    } catch (e) {
      error = (e as Error).message;
    } finally {
      busy = false;
    }
  }

  async function createPeriod() {
    busy = true;
    error = null;
    try {
      await periodApi.create(periodForm);
      status = await onboardingApi.status();
      step = 4;
    } catch (e) {
      error =
        e instanceof ApiError
          ? JSON.stringify((e.body as { errors?: unknown })?.errors ?? e.body)
          : (e as Error).message;
    } finally {
      busy = false;
    }
  }

  function finish() {
    goto('/dashboard');
  }
</script>

<div class="mx-auto max-w-3xl px-6 py-10">
  <header class="mb-6">
    <p class="text-xs font-medium text-text-muted">Setup</p>
    <h1 class="text-2xl font-bold">Onboarding</h1>
  </header>

  <ol class="mb-8 flex items-center gap-3 text-sm">
    {#each [{ n: 0, label: 'Entitas' }, { n: 1, label: 'Mode Buku' }, { n: 2, label: 'Pilih CoA' }, { n: 3, label: 'Buat Periode' }, { n: 4, label: 'Selesai' }] as s}
      <li
        class="flex items-center gap-2 {step >= s.n
          ? 'text-primary font-semibold'
          : 'text-text-muted'}"
      >
        <span
          class="flex h-6 w-6 items-center justify-center rounded-full text-xs {step >= s.n
            ? 'bg-primary text-white'
            : 'bg-page-bg'}"
        >
          {s.n}
        </span>
        <span>{s.label}</span>
        {#if s.n < 4}<span class="text-text-muted/50">→</span>{/if}
      </li>
    {/each}
  </ol>

  {#if error}
    <div class="mb-4 rounded-md border border-danger bg-danger-light p-3 text-sm text-danger">
      {error}
    </div>
  {/if}

  {#if step === 0 && auth.user?.is_sso_admin}
    <section
      class="rounded-lg border border-border-default bg-card-bg p-5"
      data-testid="entity-onboarding-step"
    >
      <h2 class="mb-2 text-lg font-bold">Buat Entitas Pertama</h2>
      <p class="mb-4 text-sm text-text-muted">
        Entitas ini menjadi ruang kerja awal untuk COA, periode, jurnal, dan laporan.
      </p>
      <div class="grid gap-3 text-sm sm:grid-cols-[1fr_8rem]">
        <label class="block">
          <span class="mb-1 block font-medium">Nama Entitas</span>
          <input
            class="w-full rounded-md border border-border-default px-3 py-2"
            bind:value={entityName}
            required
            placeholder="PT Contoh Indonesia"
            data-testid="entity-name"
          />
        </label>
        <label class="block">
          <span class="mb-1 block font-medium">Badan Usaha</span>
          <select
            class="w-full rounded-md border border-border-default px-3 py-2"
            bind:value={legalForm}
          >
            <option value="PT">PT</option>
            <option value="CV">CV</option>
            <option value="UD">UD</option>
            <option value="Yayasan">Yayasan</option>
          </select>
        </label>
      </div>
      <div class="mt-5 flex justify-end">
        <button
          type="button"
          class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
          onclick={createEntity}
          disabled={busy || entityName.trim().length === 0}
        >
          {busy ? 'Membuat…' : 'Buat Entitas & Lanjutkan'}
        </button>
      </div>
    </section>
  {/if}

  {#if step === 1}
    <section class="rounded-lg border border-border-default bg-card-bg p-5">
      <h2 class="mb-2 text-lg font-bold">Pilih Mode Pembukuan</h2>
      <p class="mb-4 text-sm text-text-muted">
        Pilihan berlaku per entitas dan menentukan apakah buku Fiskal tersedia.
      </p>
      <div class="grid gap-3 sm:grid-cols-2">
        <button
          type="button"
          class="rounded-md border p-4 text-left {bookkeepingMode === 'independent_books'
            ? 'border-primary bg-primary-light'
            : 'border-border-default'}"
          onclick={() => (bookkeepingMode = 'independent_books')}
        >
          <strong class="block text-sm">Intern dan Fiskal Independen</strong>
          <span class="mt-1 block text-xs text-text-muted"
            >Dua buku terpisah tanpa jurnal pasangan atau sinkronisasi. Koreksi hanya tersedia pada
            buku Fiskal.</span
          >
        </button>
        <button
          type="button"
          class="rounded-md border p-4 text-left {bookkeepingMode === 'internal_only'
            ? 'border-primary bg-primary-light'
            : 'border-border-default'}"
          onclick={() => (bookkeepingMode = 'internal_only')}
        >
          <strong class="block text-sm">Intern Saja</strong>
          <span class="mt-1 block text-xs text-text-muted"
            >Gunakan satu buku manajemen tanpa jurnal dan koreksi Fiskal.</span
          >
        </button>
      </div>
      <div class="mt-5 flex justify-end">
        <button
          type="button"
          class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
          onclick={saveBookkeepingMode}
          disabled={busy}
        >
          {busy ? 'Menyimpan…' : 'Lanjutkan'}
        </button>
      </div>
    </section>
  {/if}

  {#if step === 2}
    <section class="rounded-lg border border-border-default bg-card-bg p-5">
      <h2 class="mb-2 text-lg font-bold">Pilih Template Bagan Akun</h2>
      <p class="mb-4 text-sm text-text-muted">Akun pre-built sesuai industri. Bisa diedit nanti.</p>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        {#each templates as t (t.key)}
          <button
            type="button"
            class="rounded-md border p-3 text-left transition-colors {selectedKey === t.key
              ? 'border-primary bg-primary-light'
              : 'border-border-default hover:border-primary'}"
            onclick={() => (selectedKey = t.key)}
          >
            <strong class="block text-sm font-bold">{t.label}</strong>
            <span class="block text-xs text-text-muted mt-1">{t.description}</span>
          </button>
        {/each}
      </div>

      <div class="mt-5 flex justify-end">
        <button
          type="button"
          class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-active disabled:opacity-50"
          onclick={applyCoa}
          disabled={busy || !selectedKey}
        >
          {busy ? 'Memproses…' : 'Apply Template'}
        </button>
      </div>
    </section>
  {/if}

  {#if step === 3}
    <section class="rounded-lg border border-border-default bg-card-bg p-5">
      <h2 class="mb-2 text-lg font-bold">Buat Periode Akuntansi</h2>
      <p class="mb-4 text-sm text-text-muted">
        Periode pertama yang akan dipakai untuk posting jurnal.
      </p>
      <div class="space-y-3 text-sm">
        <label class="block">
          <span class="block font-medium mb-1">Nama Periode</span>
          <input
            class="w-full rounded-md border border-border-default px-2 py-1.5"
            bind:value={periodForm.name}
          />
        </label>
        <div class="grid grid-cols-2 gap-3">
          <label class="block">
            <span class="block font-medium mb-1">Mulai</span>
            <DateInput
              value={periodForm.start_date}
              onChange={(iso) => (periodForm.start_date = iso)}
            />
          </label>
          <label class="block">
            <span class="block font-medium mb-1">Selesai</span>
            <DateInput
              value={periodForm.end_date}
              onChange={(iso) => (periodForm.end_date = iso)}
            />
          </label>
        </div>
      </div>
      <div class="mt-5 flex justify-between">
        <button
          type="button"
          class="text-sm text-text-muted hover:text-text-default"
          onclick={() => (step = 2)}>← Kembali</button
        >
        <button
          type="button"
          class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-active disabled:opacity-50"
          onclick={createPeriod}
          disabled={busy}
        >
          {busy ? 'Menyimpan…' : 'Buat Periode'}
        </button>
      </div>
    </section>
  {/if}

  {#if step === 4}
    <section class="rounded-lg border border-paid bg-paid-light p-6 text-center">
      <span class="block text-3xl mb-2">✓</span>
      <h2 class="text-lg font-bold text-paid">Selesai</h2>
      <p class="mt-1 text-sm text-text-muted">
        {status?.account_count ?? 0} akun, {status?.period_count ?? 0} periode siap.
      </p>
      <button
        type="button"
        class="mt-4 rounded-md bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary-active"
        onclick={finish}
      >
        Ke Dashboard →
      </button>
    </section>
  {/if}
</div>
