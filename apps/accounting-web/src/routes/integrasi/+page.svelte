<script lang="ts">
  import { onMount } from 'svelte';
  import { goto } from '$app/navigation';
  import { auth } from '$lib/stores/auth.svelte.js';
  import { ecosystem } from '$lib/stores/ecosystem.svelte.js';
  import type { EcosystemApp, EcosystemStatus } from '$lib/api/ecosystem.js';
  import { webhookApi, type WebhookSubscription, type WebhookCreateInput } from '$lib/api/webhook.js';
  import { ApiError } from '$lib/api/client.js';

  const ICON: Record<string, string> = {
    sales: '🛒', buy: '📦', inventory: '📦', payroll: '👥',
    invoice: '🧾', tax: '⚖', bank: '🏦', app: '◎',
  };

  const HEALTH_TONE: Record<EcosystemStatus, { bg: string; fg: string; label: string }> = {
    ok:      { bg: 'bg-paid-light',     fg: 'text-paid',     label: 'Terhubung' },
    warn:    { bg: 'bg-warning-light',  fg: 'text-warning',  label: 'Perlu perhatian' },
    err:     { bg: 'bg-danger-light',   fg: 'text-danger',   label: 'Gagal' },
    syncing: { bg: 'bg-primary-light',  fg: 'text-primary',  label: 'Sinkronisasi' },
    off:     { bg: 'bg-page-bg',        fg: 'text-text-muted', label: 'Belum tersambung' },
  };

  // Ecopa Apps Management deep-link — base URL is exposed via the SPA-injected
  // window var; fall back to a known path on the configured Ecopa origin.
  const ecopaUrl: string = (typeof window !== 'undefined' && (window as { __ECOPA_URL__?: string }).__ECOPA_URL__) || 'https://home.opensynergic.com';
  const ecopaAppsUrl = `${ecopaUrl.replace(/\/$/, '')}/admin/websites`;

  let webhooks = $state<WebhookSubscription[]>([]);
  let webhooksLoading = $state(false);
  let webhooksError = $state<string | null>(null);

  async function loadWebhooks() {
    webhooksLoading = true; webhooksError = null;
    try { webhooks = await webhookApi.list(); }
    catch (e) { webhooksError = e instanceof ApiError ? `Server ${e.status}` : (e as Error).message; }
    finally { webhooksLoading = false; }
  }

  onMount(async () => {
    if (!auth.user) {
      const u = await auth.refresh();
      if (!u) { goto('/login', { replaceState: true }); return; }
    }
    if (ecosystem.apps.length === 0) await ecosystem.refresh();
    await loadWebhooks();
  });

  // Add webhook modal state
  let showAddModal = $state(false);
  let addType = $state<'sister' | 'webhook' | null>(null);
  let webhookForm = $state<WebhookCreateInput>({ event: 'journal.posted', url: '', is_active: true });
  let webhookFormErrors = $state<Record<string, string[]> | null>(null);
  let saving = $state(false);
  let revealedSecret = $state<{ id: string; secret: string } | null>(null);

  function openAddChooser() {
    showAddModal = true;
    addType = null;
    webhookForm = { event: 'journal.posted', url: '', is_active: true };
    webhookFormErrors = null;
    revealedSecret = null;
  }

  function pickType(t: 'sister' | 'webhook') {
    if (t === 'sister') {
      window.open(ecopaAppsUrl, '_blank', 'noopener,noreferrer');
      showAddModal = false;
      return;
    }
    addType = 'webhook';
  }

  async function submitWebhook() {
    saving = true; webhookFormErrors = null;
    try {
      const created = await webhookApi.create(webhookForm);
      revealedSecret = { id: created.id, secret: created.secret };
      await loadWebhooks();
    } catch (e) {
      if (e instanceof ApiError && e.status === 422 && typeof e.body === 'object' && e.body) {
        const body = e.body as { errors?: Record<string, string[]> };
        webhookFormErrors = body.errors ?? null;
      } else {
        webhookFormErrors = { _: [(e as Error).message] };
      }
    } finally {
      saving = false;
    }
  }

  async function toggleActive(w: WebhookSubscription) {
    try {
      await webhookApi.update(w.id, { is_active: !w.is_active });
      await loadWebhooks();
    } catch (e) { webhooksError = (e as Error).message; }
  }

  async function deleteWebhook(w: WebhookSubscription) {
    if (!confirm(`Hapus webhook ${w.event} → ${w.url}?`)) return;
    try {
      await webhookApi.destroy(w.id);
      await loadWebhooks();
    } catch (e) { webhooksError = (e as Error).message; }
  }

  async function rotateSecret(w: WebhookSubscription) {
    if (!confirm('Rotate secret? Receiver yang lama akan reject delivery sampai secret baru di-update di mereka.')) return;
    try {
      const res = await webhookApi.rotateSecret(w.id);
      revealedSecret = { id: w.id, secret: res.secret };
      await loadWebhooks();
    } catch (e) { webhooksError = (e as Error).message; }
  }

  function copySecret() {
    if (!revealedSecret) return;
    navigator.clipboard?.writeText(revealedSecret.secret);
  }

  function fieldErr(name: string): string | null {
    return webhookFormErrors?.[name]?.[0] ?? null;
  }

  let apps = $derived(ecosystem.apps);

  let stats = $derived(() => {
    const total = apps.length;
    const connected = apps.filter((a) => a.connected !== false).length;
    const warn = apps.filter((a) => a.status === 'warn' || a.status === 'err').length;
    const todayTotal = apps.reduce((s, a) => s + (a.today_count ?? 0), 0);
    const autoCount = apps.filter((a) => a.auto_posting !== false).length;
    const autoPct = total > 0 ? Math.round((autoCount / total) * 100) : 0;
    return { total, connected, warn, todayTotal, autoPct };
  });

  function fmtRelative(iso: string | null | undefined): string {
    if (!iso) return '—';
    const t = new Date(iso).getTime();
    if (Number.isNaN(t)) return iso;
    const diff = Date.now() - t;
    const min = Math.round(diff / 60000);
    if (min < 1) return 'baru saja';
    if (min < 60) return `${min} menit lalu`;
    const hr = Math.round(min / 60);
    if (hr < 24) return `${hr} jam lalu`;
    const d = Math.round(hr / 24);
    return `${d} hari lalu`;
  }

  function fmtVolume(a: EcosystemApp): string {
    const t = a.today_count;
    const m = a.month_count;
    if (t === null && m === null) return '—';
    const parts: string[] = [];
    if (t !== null && t !== undefined) parts.push(`${t} hari ini`);
    if (m !== null && m !== undefined) parts.push(`${m} bulan ini`);
    return parts.join(' · ');
  }

  let busy = $state<Record<string, boolean>>({});

  async function syncNow(app: EcosystemApp) {
    busy[app.slug] = true;
    try { await ecosystem.refresh(); }
    finally { busy[app.slug] = false; }
  }

  function openExternal(app: EcosystemApp) {
    if (!app.url) return;
    window.open(app.url, '_blank', 'noopener,noreferrer');
  }
</script>

<div class="px-6 py-6">
  <header class="mb-5 flex items-center justify-between">
    <div>
      <p class="text-xs font-medium text-text-muted">Master / Integrasi</p>
      <h1 class="text-2xl font-bold">Integrasi Ekosistem</h1>
    </div>
    <div class="flex items-center gap-2">
      <button
        type="button"
        class="rounded-md border border-border-default bg-card-bg px-3 py-1.5 text-sm font-medium text-text-default hover:border-primary"
        onclick={() => ecosystem.refresh()}
        disabled={ecosystem.loading}
      >
        {ecosystem.loading ? 'Memuat…' : '↻ Refresh semua'}
      </button>
    </div>
  </header>

  <!-- Hero stats -->
  <div class="ak-integrasi-hero mb-4 rounded-lg border border-border-default bg-card-bg p-5 shadow-xs">
    <div>
      <p class="text-[0.7rem] font-semibold uppercase tracking-wider text-text-muted">Ringkasan Integrasi</p>
      <p class="mt-1 text-lg font-semibold leading-tight">
        {stats().connected} dari {stats().total} koneksi aktif{#if stats().warn > 0} · {stats().warn} perlu perhatian{/if}
      </p>
      <p class="mt-1 text-xs text-text-muted">Akunta menjadi single source of truth untuk laporan keuangan kamu.</p>
    </div>
    <div class="ak-pie-mini">
      <p class="ak-pie-mini__label">Otomatis posting</p>
      <p class="ak-pie-mini__value">{stats().autoPct}%</p>
    </div>
    <div class="ak-pie-mini">
      <p class="ak-pie-mini__label">Tx hari ini</p>
      <p class="ak-pie-mini__value">{stats().todayTotal || '—'}</p>
    </div>
    <div class="ak-pie-mini">
      <p class="ak-pie-mini__label">Selisih open</p>
      <p class="ak-pie-mini__value {stats().warn > 0 ? 'text-warning' : ''}">{stats().warn}</p>
    </div>
  </div>

  <!-- Section header -->
  <div class="mb-3 flex items-center justify-between">
    <h2 class="text-base font-semibold">Aplikasi Terhubung</h2>
    <button
      type="button"
      class="rounded-md bg-primary px-3 py-1.5 text-sm font-semibold text-white hover:bg-primary-active"
      onclick={openAddChooser}
    >
      + Tambah integrasi
    </button>
  </div>

  {#if ecosystem.loading && apps.length === 0}
    <div class="text-text-muted">Memuat…</div>
  {:else if ecosystem.error && apps.length === 0}
    <div class="rounded-md border border-danger bg-danger-light p-3 text-sm text-danger">
      Tidak bisa terhubung ke Ecopa. Cek konfigurasi <code>ECOPA_URL</code> + <code>ECOPA_API_TOKEN</code>.
    </div>
  {:else if apps.length === 0}
    <div class="rounded-md border border-border-default bg-card-bg p-8 text-center text-text-muted">
      Belum ada aplikasi yang terhubung.
    </div>
  {:else}
    <div class="grid grid-cols-1 gap-3 lg:grid-cols-2">
      {#each apps as app (app.slug)}
        {@const tone = HEALTH_TONE[app.status]}
        <article class="ak-integrasi-card">
          <header class="flex items-center gap-3">
            <span class="ak-integrasi-icon {tone.bg} {tone.fg}" aria-hidden="true">
              {ICON[app.icon_key] ?? ICON.app}
            </span>
            <div class="min-w-0 flex-1">
              <div class="flex flex-wrap items-center gap-2">
                <strong class="text-sm font-semibold text-text-default">{app.label}</strong>
                <span class="ak-integrasi-pill">
                  <span class="ak-eco-dot ak-eco-dot--{app.status}" aria-hidden="true"></span>
                  {app.connected === false ? 'Belum tersambung' : tone.label}
                </span>
              </div>
              {#if app.desc}
                <p class="mt-0.5 truncate text-xs text-text-muted">{app.desc}</p>
              {/if}
            </div>
            <button
              type="button"
              class="rounded-md p-1 text-text-muted hover:bg-page-bg"
              aria-label="Lebih banyak"
              onclick={() => openExternal(app)}
              title={app.url ?? 'Buka aplikasi'}
            >
              ⋯
            </button>
          </header>

          <dl class="ak-integrasi-meta">
            <div>
              <dt>Last sync</dt>
              <dd>{fmtRelative(app.last_sync_at)}</dd>
            </div>
            <div>
              <dt>Volume</dt>
              <dd>{fmtVolume(app)}</dd>
            </div>
          </dl>

          {#if app.note}
            <p class="ak-integrasi-note">⚡ {app.note}</p>
          {/if}

          <footer class="ak-integrasi-footer">
            <span
              class="ak-toggle"
              class:ak-toggle--on={app.auto_posting !== false}
              role="img"
              aria-label={app.auto_posting !== false ? 'Auto-posting aktif' : 'Auto-posting nonaktif'}
            ></span>
            <span class="flex-1 text-xs text-text-default">Auto-posting ke jurnal</span>
            {#if app.connected === false}
              <button
                type="button"
                class="rounded-md bg-primary px-3 py-1 text-xs font-semibold text-white hover:bg-primary-active"
                onclick={() => openExternal(app)}
              >
                🔗 Hubungkan
              </button>
            {:else}
              <button
                type="button"
                class="rounded-md border border-border-default bg-card-bg px-3 py-1 text-xs font-medium text-text-default hover:border-primary disabled:opacity-50"
                onclick={() => syncNow(app)}
                disabled={busy[app.slug]}
              >
                ↻ {busy[app.slug] ? 'Sync…' : 'Sync sekarang'}
              </button>
            {/if}
          </footer>
        </article>
      {/each}
    </div>
  {/if}

  <!-- Webhook custom integrasi -->
  <section class="mt-8">
    <header class="mb-3 flex items-center justify-between">
      <div>
        <h2 class="text-base font-semibold">Webhook Custom</h2>
        <p class="text-xs text-text-muted">Outbound delivery ke endpoint non-Ecopa saat event terjadi (mis. <code>journal.posted</code>).</p>
      </div>
    </header>

    {#if webhooksLoading && webhooks.length === 0}
      <div class="text-text-muted text-sm">Memuat…</div>
    {:else if webhooksError}
      <div class="rounded-md border border-danger bg-danger-light p-3 text-sm text-danger">{webhooksError}</div>
    {:else if webhooks.length === 0}
      <div class="rounded-md border border-dashed border-border-default bg-card-bg p-6 text-center text-text-muted text-sm">
        Belum ada webhook. Tekan <strong>+ Tambah integrasi</strong> di atas untuk konfigurasi.
      </div>
    {:else}
      <div class="overflow-x-auto rounded-lg border border-border-default bg-card-bg shadow-xs">
        <table class="w-full text-sm">
          <thead class="bg-page-bg text-xs uppercase tracking-wider text-text-muted">
            <tr>
              <th class="px-4 py-2 text-left">Event</th>
              <th class="px-4 py-2 text-left">URL Tujuan</th>
              <th class="px-4 py-2 text-left">App Code</th>
              <th class="px-4 py-2 text-left">Status</th>
              <th class="px-4 py-2 text-right">Aksi</th>
            </tr>
          </thead>
          <tbody>
            {#each webhooks as w (w.id)}
              <tr class="border-t border-border-soft">
                <td class="px-4 py-2 font-mono text-xs">{w.event}</td>
                <td class="px-4 py-2 truncate max-w-xs"><code class="text-xs">{w.url}</code></td>
                <td class="px-4 py-2 text-xs">{w.app_code ?? '—'}</td>
                <td class="px-4 py-2">
                  <button
                    type="button"
                    class="ak-toggle"
                    class:ak-toggle--on={w.is_active}
                    aria-label={w.is_active ? 'Aktif' : 'Nonaktif'}
                    onclick={() => toggleActive(w)}
                  ></button>
                </td>
                <td class="px-4 py-2 text-right">
                  <button type="button" class="text-xs text-primary hover:underline mr-3" onclick={() => rotateSecret(w)}>Rotate secret</button>
                  <button type="button" class="text-xs text-danger hover:underline" onclick={() => deleteWebhook(w)}>Hapus</button>
                </td>
              </tr>
            {/each}
          </tbody>
        </table>
      </div>
    {/if}
  </section>
</div>

<!-- Tambah integrasi: chooser modal -->
{#if showAddModal}
  <div
    class="fixed inset-0 z-30 flex items-center justify-center bg-black/30 p-4"
    onclick={() => (showAddModal = false)}
    role="presentation"
  >
    <div
      class="w-full max-w-lg rounded-lg bg-card-bg p-6 shadow-lg"
      onclick={(e) => e.stopPropagation()}
      role="dialog"
      aria-modal="true"
    >
      {#if revealedSecret}
        <h2 class="mb-2 text-lg font-bold">Webhook dibuat ✓</h2>
        <p class="mb-3 text-sm text-text-muted">Simpan secret berikut sekarang — tidak akan ditampilkan lagi.</p>
        <div class="rounded-md bg-page-bg p-3 font-mono text-xs break-all border border-border-default">{revealedSecret.secret}</div>
        <div class="mt-4 flex justify-end gap-2">
          <button type="button" class="rounded-md border border-border-default px-3 py-1.5 text-sm" onclick={copySecret}>Salin</button>
          <button type="button" class="rounded-md bg-primary px-3 py-1.5 text-sm font-semibold text-white hover:bg-primary-active" onclick={() => (showAddModal = false)}>Selesai</button>
        </div>
      {:else if addType === null}
        <h2 class="mb-1 text-lg font-bold">Tambah integrasi</h2>
        <p class="mb-4 text-sm text-text-muted">Pilih jenis integrasi yang ingin ditambahkan.</p>
        <div class="grid grid-cols-1 gap-2">
          <button
            type="button"
            class="flex items-start gap-3 rounded-md border border-border-default p-3 text-left hover:border-primary"
            onclick={() => pickType('sister')}
          >
            <span class="text-xl">🏢</span>
            <span class="flex-1">
              <span class="block text-sm font-semibold">Sister app via Ecopa ↗</span>
              <span class="block text-xs text-text-muted">POSO, Payroll, Inventory, dst — registrasi di Ecopa Apps Management. Akan dibuka di tab baru.</span>
            </span>
          </button>
          <button
            type="button"
            class="flex items-start gap-3 rounded-md border border-border-default p-3 text-left hover:border-primary"
            onclick={() => pickType('webhook')}
          >
            <span class="text-xl">🪝</span>
            <span class="flex-1">
              <span class="block text-sm font-semibold">Webhook custom</span>
              <span class="block text-xs text-text-muted">Outbound delivery ke endpoint non-Ecopa saat event Akunta terjadi.</span>
            </span>
          </button>
        </div>
        <div class="mt-4 flex justify-end">
          <button type="button" class="rounded-md border border-border-default px-3 py-1.5 text-sm" onclick={() => (showAddModal = false)}>Batal</button>
        </div>
      {:else}
        <h2 class="mb-1 text-lg font-bold">Webhook custom baru</h2>
        <p class="mb-4 text-sm text-text-muted">Akunta akan POST ke URL ini setiap event yang cocok terjadi. Signature header <code>X-Akunta-Signature</code>.</p>
        <div class="space-y-3 text-sm">
          <label class="block">
            <span class="block font-medium mb-1">Event <span class="text-danger">*</span></span>
            <input
              class="w-full rounded-md border border-border-default px-2 py-1.5 font-mono text-xs"
              bind:value={webhookForm.event}
              placeholder="journal.posted"
            />
            <span class="block text-xs text-text-muted mt-1">Pakai glob: <code>journal.*</code> match semua event journal. <code>*</code> match semua.</span>
            {#if fieldErr('event')}<span class="text-xs text-danger">{fieldErr('event')}</span>{/if}
          </label>
          <label class="block">
            <span class="block font-medium mb-1">URL tujuan <span class="text-danger">*</span></span>
            <input
              class="w-full rounded-md border border-border-default px-2 py-1.5"
              bind:value={webhookForm.url}
              placeholder="https://example.com/akunta-webhook"
            />
            {#if fieldErr('url')}<span class="text-xs text-danger">{fieldErr('url')}</span>{/if}
          </label>
          <label class="block">
            <span class="block font-medium mb-1">App code (opsional)</span>
            <input
              class="w-full rounded-md border border-border-default px-2 py-1.5 font-mono text-xs"
              bind:value={webhookForm.app_code}
              placeholder="poso, payroll, dst"
            />
          </label>
          <label class="flex items-center gap-2">
            <input type="checkbox" bind:checked={webhookForm.is_active} />
            <span class="text-sm">Aktif sekarang</span>
          </label>
          {#if fieldErr('_')}<div class="text-xs text-danger">{fieldErr('_')}</div>{/if}
        </div>
        <div class="mt-4 flex justify-end gap-2">
          <button type="button" class="rounded-md border border-border-default px-3 py-1.5 text-sm" onclick={() => (showAddModal = false)} disabled={saving}>Batal</button>
          <button
            type="button"
            class="rounded-md bg-primary px-3 py-1.5 text-sm font-semibold text-white hover:bg-primary-active disabled:opacity-50"
            onclick={submitWebhook}
            disabled={saving || !webhookForm.event || !webhookForm.url}
          >
            {saving ? 'Menyimpan…' : 'Simpan webhook'}
          </button>
        </div>
      {/if}
    </div>
  </div>
{/if}
