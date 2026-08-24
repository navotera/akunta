<script lang="ts">
  import { onMount } from 'svelte';
  import { goto } from '$app/navigation';
  import { auth } from '$lib/stores/auth.svelte.js';
  import { ecosystem } from '$lib/stores/ecosystem.svelte.js';
  import type { EcosystemApp, EcosystemStatus } from '$lib/api/ecosystem.js';
  import {
    webhookApi,
    type WebhookSubscription,
    type WebhookCreateInput,
    type WebhookDeliveryLog,
  } from '$lib/api/webhook.js';
  import { ApiError } from '$lib/api/client.js';

  const ICON: Record<string, string> = {
    sales: '🛒',
    buy: '📦',
    inventory: '📦',
    payroll: '👥',
    invoice: '🧾',
    tax: '⚖',
    bank: '🏦',
    app: '◎',
  };

  const WEBHOOK_EVENTS = ['journal.posted', 'journal.voided', 'journal.*', '*'];

  const WEBHOOK_EVENT_INFO: Record<string, string> = {
    'journal.posted': 'Dikirim saat jurnal berhasil diposting.',
    'journal.voided': 'Dikirim saat jurnal dibalik atau direversal.',
    'journal.*': 'Menerima semua event yang berkaitan dengan jurnal.',
    '*': 'Menerima semua event webhook yang tersedia di Akunta.',
  };

  const HEALTH_TONE: Record<EcosystemStatus, { bg: string; fg: string; label: string }> = {
    ok: { bg: 'bg-paid-light', fg: 'text-paid', label: 'Terhubung' },
    warn: { bg: 'bg-warning-light', fg: 'text-warning', label: 'Perlu perhatian' },
    err: { bg: 'bg-danger-light', fg: 'text-danger', label: 'Gagal' },
    syncing: { bg: 'bg-primary-light', fg: 'text-primary', label: 'Sinkronisasi' },
    off: { bg: 'bg-page-bg', fg: 'text-text-muted', label: 'Belum tersambung' },
  };

  // Ecopa Apps Management deep-link — base URL is exposed via the SPA-injected
  // window var; fall back to a known path on the configured Ecopa origin.
  const ecopaUrl: string =
    (typeof window !== 'undefined' && (window as { __ECOPA_URL__?: string }).__ECOPA_URL__) ||
    'https://home.opensynergic.com';
  const ecopaAppsUrl = `${ecopaUrl.replace(/\/$/, '')}/admin/websites`;

  let webhooks = $state<WebhookSubscription[]>([]);
  let webhooksLoading = $state(false);
  let webhooksError = $state<string | null>(null);
  let activeSection = $state<'webhooks' | 'logs' | 'docs'>('webhooks');
  let deliveryLogs = $state<WebhookDeliveryLog[]>([]);
  let logsLoading = $state(false);
  let logsError = $state<string | null>(null);
  let selectedWebhookId = $state<string | null>(null);
  let selectedWebhookLogs = $state<WebhookDeliveryLog[]>([]);
  let selectedLogsLoading = $state(false);
  let selectedLogsError = $state<string | null>(null);

  async function loadWebhooks() {
    webhooksLoading = true;
    webhooksError = null;
    try {
      webhooks = await webhookApi.list();
    } catch (e) {
      webhooksError = (e as Error).message;
    } finally {
      webhooksLoading = false;
    }
  }

  async function loadDeliveryLogs() {
    logsLoading = true;
    logsError = null;
    try {
      deliveryLogs = (await webhookApi.logs()).data;
    } catch (e) {
      logsError = (e as Error).message;
    } finally {
      logsLoading = false;
    }
  }

  async function selectSection(section: 'webhooks' | 'logs' | 'docs') {
    activeSection = section;
    if (section === 'logs') await loadDeliveryLogs();
  }

  async function openWebhookLogs(webhook: WebhookSubscription) {
    if (selectedWebhookId === webhook.id) {
      selectedWebhookId = null;
      return;
    }
    selectedWebhookId = webhook.id;
    selectedLogsLoading = true;
    selectedLogsError = null;
    try {
      selectedWebhookLogs = (await webhookApi.subscriptionLogs(webhook.id)).data;
    } catch (e) {
      selectedLogsError = (e as Error).message;
      selectedWebhookLogs = [];
    } finally {
      selectedLogsLoading = false;
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
    if (ecosystem.apps.length === 0) await ecosystem.refresh();
    await loadWebhooks();
  });

  // Add webhook modal state
  let showAddModal = $state(false);
  let addType = $state<'sister' | 'webhook' | null>(null);
  let webhookForm = $state<WebhookCreateInput>({
    event: 'journal.posted',
    description: '',
    is_active: true,
  });
  let webhookFormErrors = $state<Record<string, string[]> | null>(null);
  let saving = $state(false);
  let revealedWebhook = $state<{ id: string; url: string } | null>(null);

  function openAddChooser() {
    showAddModal = true;
    addType = 'webhook';
    webhookForm = { event: 'journal.posted', description: '', is_active: true };
    webhookFormErrors = null;
    revealedWebhook = null;
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
    saving = true;
    webhookFormErrors = null;
    try {
      const created = await webhookApi.create(webhookForm);
      revealedWebhook = { id: created.id, url: created.url };
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
    } catch (e) {
      webhooksError = (e as Error).message;
    }
  }

  async function deleteWebhook(w: WebhookSubscription) {
    if (!confirm(`Hapus webhook ${w.event} → ${w.url}?`)) return;
    try {
      await webhookApi.destroy(w.id);
      await loadWebhooks();
    } catch (e) {
      webhooksError = (e as Error).message;
    }
  }

  async function regenerateWebhookUrl(w: WebhookSubscription) {
    if (!confirm('Buat URL webhook baru? URL lama akan langsung tidak berlaku.')) return;
    try {
      const res = await webhookApi.regenerateUrl(w.id);
      revealedWebhook = { id: w.id, url: res.url };
      addType = 'webhook';
      showAddModal = true;
      await loadWebhooks();
    } catch (e) {
      webhooksError = (e as Error).message;
    }
  }

  async function copyWebhook(url: string) {
    await navigator.clipboard?.writeText(url);
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

  function fmtDate(iso: string | null): string {
    if (!iso) return '—';
    const date = new Date(iso);
    return Number.isNaN(date.getTime())
      ? iso
      : date.toLocaleString('id-ID', { dateStyle: 'medium', timeStyle: 'short' });
  }

  function logStatus(log: WebhookDeliveryLog): { label: string; tone: string } {
    if (log.status === 'success') return { label: 'Berhasil', tone: 'bg-paid-light text-paid' };
    if (log.status === 'pending')
      return { label: 'Menunggu', tone: 'bg-warning-light text-warning' };
    return {
      label: log.status === 'giving_up' ? 'Dihentikan' : 'Gagal',
      tone: 'bg-danger-light text-danger',
    };
  }

  let busy = $state<Record<string, boolean>>({});

  async function syncNow(app: EcosystemApp) {
    busy[app.slug] = true;
    try {
      await ecosystem.refresh();
    } finally {
      busy[app.slug] = false;
    }
  }

  function openExternal(app: EcosystemApp) {
    if (!app.url) return;
    window.open(app.url, '_blank', 'noopener,noreferrer');
  }
</script>

<div class="px-6 py-6">
  <header class="mb-5 flex items-center justify-between">
    <div>
      <div class="flex items-center gap-1 text-xs font-medium text-text-muted">
        <button
          type="button"
          class="hover:text-primary hover:underline"
          onclick={() => goto('/journals')}>Jurnal</button
        >
        <span aria-hidden="true">›</span>
        <button
          type="button"
          class="hover:text-primary hover:underline"
          onclick={() => goto('/auto-mapping')}>Auto Mapping</button
        >
        <span aria-hidden="true">›</span>
        <span class="text-text-default">Integrasi</span>
      </div>
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

  <div class="grid grid-cols-1 gap-5 lg:grid-cols-[210px_minmax(0,1fr)]">
    <aside class="h-fit rounded-lg border border-border-default bg-card-bg p-2 shadow-xs">
      <p
        class="px-3 pb-2 pt-2 text-[0.68rem] font-semibold uppercase tracking-wider text-text-muted"
      >
        Integrasi
      </p>
      <nav class="space-y-1" aria-label="Menu integrasi">
        <button
          type="button"
          class="flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm font-medium transition {activeSection ===
          'webhooks'
            ? 'bg-primary-light text-primary'
            : 'text-text-default hover:bg-page-bg'}"
          onclick={() => selectSection('webhooks')}
          aria-current={activeSection === 'webhooks' ? 'page' : undefined}
        >
          <span aria-hidden="true">↔</span> WebHook link
        </button>
        <button
          type="button"
          class="flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm font-medium transition {activeSection ===
          'logs'
            ? 'bg-primary-light text-primary'
            : 'text-text-default hover:bg-page-bg'}"
          onclick={() => selectSection('logs')}
          aria-current={activeSection === 'logs' ? 'page' : undefined}
        >
          <span aria-hidden="true">▤</span> Log history
        </button>
        <button
          type="button"
          class="flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-sm font-medium transition {activeSection ===
          'docs'
            ? 'bg-primary-light text-primary'
            : 'text-text-default hover:bg-page-bg'}"
          onclick={() => selectSection('docs')}
          aria-current={activeSection === 'docs' ? 'page' : undefined}
        >
          <span aria-hidden="true">?</span> Dokumentasi
        </button>
      </nav>
    </aside>

    <main class="min-w-0">
      {#if activeSection === 'webhooks'}
        {#if false}
          <!-- Legacy ecosystem summary and connected-apps section -->
          <div
            class="ak-integrasi-hero mb-4 rounded-lg border border-border-default bg-card-bg p-5 shadow-xs"
          >
            <div>
              <p class="text-[0.7rem] font-semibold uppercase tracking-wider text-text-muted">
                Ringkasan Integrasi
              </p>
              <p class="mt-1 text-lg font-semibold leading-tight">
                {stats().connected} dari {stats().total} koneksi aktif{#if stats().warn > 0}
                  · {stats().warn} perlu perhatian{/if}
              </p>
              <p class="mt-1 text-xs text-text-muted">
                Akunta menjadi single source of truth untuk laporan keuangan kamu.
              </p>
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
              <p class="ak-pie-mini__value {stats().warn > 0 ? 'text-warning' : ''}">
                {stats().warn}
              </p>
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
              Tidak bisa terhubung ke Ecopa. Cek konfigurasi <code>ECOPA_URL</code> +
              <code>ECOPA_API_TOKEN</code>.
            </div>
          {:else if apps.length === 0}
            <div
              class="rounded-md border border-border-default bg-card-bg p-8 text-center text-text-muted"
            >
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
                          <span class="ak-eco-dot ak-eco-dot--{app.status}" aria-hidden="true"
                          ></span>
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
                      aria-label={app.auto_posting !== false
                        ? 'Auto-posting aktif'
                        : 'Auto-posting nonaktif'}
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
        {/if}

        <!-- Webhook integrasi -->
        <section class="mt-8">
          <header class="mb-3 flex items-center justify-between">
            <div>
              <h2 class="text-base font-semibold">Webhook</h2>
              <p class="text-xs text-text-muted">
                Buat URL webhook untuk menerima payload dari aplikasi eksternal.
              </p>
            </div>
            <button
              type="button"
              class="rounded-md bg-primary px-3 py-1.5 text-sm font-semibold text-white hover:bg-primary-active"
              onclick={openAddChooser}
            >
              + New
            </button>
          </header>

          {#if webhooksLoading && webhooks.length === 0}
            <div class="text-text-muted text-sm">Memuat…</div>
          {:else if webhooksError}
            <div class="rounded-md border border-danger bg-danger-light p-3 text-sm text-danger">
              {webhooksError}
            </div>
          {:else if webhooks.length === 0}
            <div
              class="rounded-md border border-dashed border-border-default bg-card-bg p-6 text-center text-text-muted text-sm"
            >
              Belum ada webhook. Klik <strong>+ New</strong> untuk membuat webhook.
            </div>
          {:else}
            <div
              class="overflow-x-auto rounded-lg border border-border-default bg-card-bg shadow-xs"
            >
              <table class="w-full text-sm">
                <thead class="bg-page-bg text-xs uppercase tracking-wider text-text-muted">
                  <tr>
                    <th class="px-4 py-2 text-left">Event</th>
                    <th class="px-4 py-2 text-left">URL Tujuan</th>
                    <th class="px-4 py-2 text-left">Description</th>
                    <th class="px-4 py-2 text-left" title="Is Active">Status</th>
                    <th class="px-4 py-2 text-left">Last used</th>
                    <th class="px-4 py-2 text-right">Aksi</th>
                  </tr>
                </thead>
                <tbody>
                  {#each webhooks as w (w.id)}
                    <tr
                      class="cursor-pointer border-t border-border-soft hover:bg-page-bg"
                      class:bg-page-bg={selectedWebhookId === w.id}
                      onclick={() => openWebhookLogs(w)}
                      role="button"
                      tabindex="0"
                      aria-label={`Lihat log ${w.event}`}
                    >
                      <td class="px-4 py-2 font-mono text-xs">{w.event}</td>
                      <td class="px-4 py-2 truncate max-w-xs"
                        ><code class="text-xs">{w.url}</code></td
                      >
                      <td class="px-4 py-2 text-xs">{w.description ?? '—'}</td>
                      <td class="px-4 py-2">
                        <button
                          type="button"
                          class="ak-toggle ak-toggle--status"
                          class:ak-toggle--on={w.is_active}
                          aria-label={w.is_active ? 'Aktif' : 'Nonaktif'}
                          title={w.is_active ? 'Is Active: Aktif' : 'Is Active: Nonaktif'}
                          onclick={() => toggleActive(w)}
                        ></button>
                      </td>
                      <td class="px-4 py-2 text-xs text-text-muted">{fmtDate(w.last_used_at)}</td>
                      <td class="px-4 py-2 text-right">
                        <a
                          href={w.url}
                          target="_blank"
                          rel="noreferrer"
                          class="mr-3 text-xs text-primary hover:underline"
                          onclick={(event) => {
                            event.preventDefault();
                            copyWebhook(w.url);
                          }}>Copy webhook</a
                        >
                        <button
                          type="button"
                          class="text-xs text-primary hover:underline mr-3"
                          onclick={() => regenerateWebhookUrl(w)}>Buat URL baru</button
                        >
                        <button
                          type="button"
                          class="text-xs text-danger hover:underline"
                          onclick={() => deleteWebhook(w)}>Hapus</button
                        >
                      </td>
                    </tr>
                    {#if selectedWebhookId === w.id}
                      <tr class="border-t border-border-soft bg-page-bg">
                        <td colspan="6" class="px-5 py-4">
                          <div class="mb-2 flex items-center justify-between">
                            <p
                              class="text-xs font-semibold uppercase tracking-wider text-text-muted"
                            >
                              Log koneksi webhook
                            </p>
                            <span class="text-xs text-text-muted">Klik baris untuk menutup</span>
                          </div>
                          {#if selectedLogsLoading}
                            <p class="text-sm text-text-muted">Memuat log…</p>
                          {:else if selectedLogsError}
                            <p class="text-sm text-danger">{selectedLogsError}</p>
                          {:else if selectedWebhookLogs.length === 0}
                            <p class="text-sm text-text-muted">
                              Belum ada log koneksi untuk webhook ini.
                            </p>
                          {:else}
                            <div
                              class="overflow-x-auto rounded-md border border-border-default bg-card-bg"
                            >
                              <table class="w-full text-xs">
                                <thead
                                  class="bg-page-bg text-left uppercase tracking-wider text-text-muted"
                                >
                                  <tr
                                    ><th class="px-3 py-2">Waktu</th><th class="px-3 py-2">Event</th
                                    ><th class="px-3 py-2">Status</th><th class="px-3 py-2"
                                      >Response</th
                                    ></tr
                                  >
                                </thead>
                                <tbody>
                                  {#each selectedWebhookLogs as log (log.id)}
                                    {@const detailStatus = logStatus(log)}
                                    <tr class="border-t border-border-soft">
                                      <td class="px-3 py-2 text-text-muted"
                                        >{fmtDate(log.created_at)}</td
                                      >
                                      <td class="px-3 py-2 font-mono">{log.event}</td>
                                      <td class="px-3 py-2"
                                        ><span
                                          class="rounded-full px-2 py-1 font-semibold {detailStatus.tone}"
                                          >{detailStatus.label}</span
                                        ></td
                                      >
                                      <td class="px-3 py-2 font-mono">{log.response_code ?? '—'}</td
                                      >
                                    </tr>
                                  {/each}
                                </tbody>
                              </table>
                            </div>
                          {/if}
                        </td>
                      </tr>
                    {/if}
                  {/each}
                </tbody>
              </table>
            </div>
          {/if}
        </section>
      {:else if activeSection === 'logs'}
        <section class="rounded-lg border border-border-default bg-card-bg shadow-xs">
          <header
            class="flex flex-wrap items-start justify-between gap-3 border-b border-border-soft px-5 py-4"
          >
            <div>
              <h2 class="text-base font-semibold">Log history webhook</h2>
              <p class="mt-1 text-xs text-text-muted">
                Riwayat pengiriman dari setiap aplikasi yang menggunakan webhook. Log disimpan
                selama 12 bulan.
              </p>
            </div>
            <button
              type="button"
              class="rounded-md border border-border-default bg-card-bg px-3 py-1.5 text-xs font-medium text-text-default hover:border-primary disabled:opacity-50"
              onclick={loadDeliveryLogs}
              disabled={logsLoading}
            >
              {logsLoading ? 'Memuat…' : '↻ Refresh'}
            </button>
          </header>

          {#if logsLoading && deliveryLogs.length === 0}
            <div class="p-8 text-center text-sm text-text-muted">Memuat log history…</div>
          {:else if logsError}
            <div
              class="m-5 rounded-md border border-danger bg-danger-light p-3 text-sm text-danger"
            >
              {logsError}
            </div>
          {:else if deliveryLogs.length === 0}
            <div class="p-8 text-center text-sm text-text-muted">
              Belum ada log pengiriman webhook dalam 12 bulan terakhir.
            </div>
          {:else}
            <div class="overflow-x-auto">
              <table class="w-full text-sm">
                <thead
                  class="bg-page-bg text-left text-xs uppercase tracking-wider text-text-muted"
                >
                  <tr>
                    <th class="px-5 py-3">Waktu</th>
                    <th class="px-3 py-3">Description</th>
                    <th class="px-3 py-3">Event</th>
                    <th class="px-3 py-3">Status</th>
                    <th class="px-3 py-3">Response</th>
                    <th class="px-5 py-3 text-right">Percobaan</th>
                  </tr>
                </thead>
                <tbody>
                  {#each deliveryLogs as log (log.id)}
                    {@const status = logStatus(log)}
                    <tr class="border-t border-border-soft align-top">
                      <td class="whitespace-nowrap px-5 py-3 text-xs text-text-muted"
                        >{fmtDate(log.created_at)}</td
                      >
                      <td class="px-3 py-3 font-medium">{log.description || '—'}</td>
                      <td class="px-3 py-3">
                        <div class="font-mono text-xs">{log.event}</div>
                        {#if log.url}<div
                            class="mt-1 max-w-[220px] truncate text-[11px] text-text-muted"
                            title={log.url}
                          >
                            {log.url}
                          </div>{/if}
                        {#if log.error}<div
                            class="mt-1 max-w-[260px] text-xs text-danger"
                            title={log.error}
                          >
                            {log.error}
                          </div>{/if}
                      </td>
                      <td class="px-3 py-3"
                        ><span
                          class="rounded-full px-2 py-1 text-[11px] font-semibold {status.tone}"
                          >{status.label}</span
                        ></td
                      >
                      <td class="px-3 py-3 font-mono text-xs">{log.response_code ?? '—'}</td>
                      <td class="px-5 py-3 text-right text-xs">{log.attempts}</td>
                    </tr>
                  {/each}
                </tbody>
              </table>
            </div>
          {/if}
        </section>
      {:else}
        <section class="rounded-lg border border-border-default bg-card-bg p-5 shadow-xs">
          <h2 class="text-base font-semibold">Cara menggunakan webhook</h2>
          <p class="mt-1 text-sm text-text-muted">
            Kirim data JSON dari aplikasi lain ke Akunta dalam tiga langkah.
          </p>

          <div class="mt-5 grid gap-3 md:grid-cols-3">
            <div class="rounded-md border border-border-default p-4">
              <span
                class="mb-3 flex h-7 w-7 items-center justify-center rounded-full bg-primary-light text-xs font-bold text-primary"
                >1</span
              >
              <h3 class="font-semibold text-text-default">Buat webhook</h3>
              <p class="mt-1 text-sm leading-6 text-text-muted">
                Klik <strong>+ New</strong>, pilih event, lalu simpan.
              </p>
            </div>
            <div class="rounded-md border border-border-default p-4">
              <span
                class="mb-3 flex h-7 w-7 items-center justify-center rounded-full bg-primary-light text-xs font-bold text-primary"
                >2</span
              >
              <h3 class="font-semibold text-text-default">Salin URL</h3>
              <p class="mt-1 text-sm leading-6 text-text-muted">
                Gunakan URL yang muncul sebagai tujuan request dari aplikasi Anda.
              </p>
            </div>
            <div class="rounded-md border border-border-default p-4">
              <span
                class="mb-3 flex h-7 w-7 items-center justify-center rounded-full bg-primary-light text-xs font-bold text-primary"
                >3</span
              >
              <h3 class="font-semibold text-text-default">Kirim JSON</h3>
              <p class="mt-1 text-sm leading-6 text-text-muted">
                Kirim data dengan metode <code>POST</code> dan
                <code>Content-Type: application/json</code>.
              </p>
            </div>
          </div>

          <div class="mt-5">
            <h3 class="text-sm font-semibold text-text-default">Contoh request</h3>
            <div class="mt-2 overflow-x-auto rounded-md bg-page-bg p-4 font-mono text-xs leading-5">
              <pre>curl -X POST "https://akunta.example/api/webhooks/incoming/SECRET_ANDA" \
  -H "Content-Type: application/json" \
  -H "X-Akunta-Event: journal.posted" \
  -d '&#123;"reference":"INV-001","amount":125000&#125;'</pre>
            </div>
            <p class="mt-2 text-xs text-text-muted">
              Salin URL lengkap dari modal. Secret berada di bagian terakhir URL, bukan dikirim
              sebagai parameter atau header terpisah. Header <code>X-Akunta-Event</code> opsional.
            </p>
          </div>

          <div class="mt-5 grid gap-3 md:grid-cols-2">
            <div class="rounded-md border border-paid/30 bg-paid-light p-4 text-sm">
              <p class="font-semibold text-paid">Berhasil diterima</p>
              <p class="mt-1 text-text-muted">
                Akunta membalas status <code>202</code>. Hasil request dapat dilihat di menu
                <strong>Log history</strong>.
              </p>
            </div>
            <div class="rounded-md border border-warning/30 bg-warning-light p-4 text-sm">
              <p class="font-semibold text-warning">Jaga URL tetap rahasia</p>
              <p class="mt-1 text-text-muted">
                Bagian <code>/SECRET_ANDA</code> adalah kunci akses webhook. Jika URL bocor, gunakan
                <strong>Buat URL baru</strong>; URL lama langsung tidak berlaku.
              </p>
            </div>
          </div>
        </section>
      {/if}
    </main>
  </div>
</div>

<!-- Tambah integrasi: chooser modal -->
{#if showAddModal}
  <div
    class="fixed inset-0 z-30 flex items-center justify-center bg-black/30 p-4"
    onclick={() => (showAddModal = false)}
    role="presentation"
  >
    <div
      class="w-full max-w-2xl rounded-lg bg-card-bg p-6 shadow-lg"
      onclick={(e) => e.stopPropagation()}
      role="dialog"
      aria-modal="true"
    >
      {#if revealedWebhook}
        <h2 class="mb-2 text-lg font-bold">URL webhook siap ✓</h2>
        <p class="mb-2 text-sm text-text-muted">
          Salin URL lengkap ini dan gunakan sebagai endpoint POST di aplikasi pengirim.
        </p>
        <div class="flex items-center gap-2 rounded-md border border-border-default bg-page-bg p-3">
          <span class="min-w-0 flex-1 break-all font-mono text-xs">{revealedWebhook.url}</span>
          <button
            type="button"
            class="shrink-0 rounded-md p-1.5 text-text-muted hover:bg-card-bg hover:text-primary"
            onclick={() => copyWebhook(revealedWebhook!.url)}
            aria-label="Salin webhook URL"
            title="Salin webhook URL"
          >
            <svg
              xmlns="http://www.w3.org/2000/svg"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="2"
              class="h-4 w-4"
              aria-hidden="true"
            >
              <rect width="13" height="13" x="9" y="9" rx="2" ry="2" />
              <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" />
            </svg>
          </button>
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
              <span class="block text-xs text-text-muted"
                >POSO, Payroll, Inventory, dst — registrasi di Ecopa Apps Management. Akan dibuka di
                tab baru.</span
              >
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
              <span class="block text-xs text-text-muted"
                >Outbound delivery ke endpoint non-Ecopa saat event Akunta terjadi.</span
              >
            </span>
          </button>
        </div>
        <div class="mt-4 flex justify-end">
          <button
            type="button"
            class="rounded-md border border-border-default px-3 py-1.5 text-sm"
            onclick={() => (showAddModal = false)}>Batal</button
          >
        </div>
      {:else}
        <h2 class="mb-1 text-lg font-bold">Tambah WebHook link</h2>
        <p class="mb-4 text-sm text-text-muted">
          Pilih event yang boleh diterima melalui URL webhook yang dibuat Akunta.
        </p>
        <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
          <div>
            <p class="mb-2 text-xs font-semibold uppercase tracking-wider text-text-muted">
              Event list
            </p>
            <div class="flex flex-wrap gap-2">
              {#each WEBHOOK_EVENTS as event}
                <button
                  type="button"
                  class="rounded-full border px-3 py-1.5 font-mono text-xs font-semibold transition {webhookForm.event ===
                  event
                    ? 'border-primary bg-primary text-white'
                    : 'border-border-default bg-card-bg text-text-muted hover:border-primary hover:text-primary'}"
                  onclick={() => (webhookForm.event = event)}
                  aria-pressed={webhookForm.event === event}
                >
                  {event}
                </button>
              {/each}
            </div>
            {#if fieldErr('event')}<span class="mt-2 block text-xs text-danger"
                >{fieldErr('event')}</span
              >{/if}
          </div>
          <div class="rounded-md border border-border-default bg-page-bg p-4">
            <p class="mb-2 text-xs font-semibold uppercase tracking-wider text-text-muted">
              Informasi event
            </p>
            <p class="font-mono text-sm font-semibold text-text-default">{webhookForm.event}</p>
            <p class="mt-2 text-sm leading-6 text-text-muted">
              {WEBHOOK_EVENT_INFO[webhookForm.event] ?? 'Event webhook dari Akunta.'}
            </p>
          </div>
        </div>
        <div class="mt-5 space-y-3 text-sm">
          <label class="block">
            <span class="mb-1 block font-medium">Description (opsional)</span>
            <textarea
              class="w-full rounded-md border border-border-default px-2 py-1.5"
              rows="2"
              maxlength="500"
              bind:value={webhookForm.description}
              placeholder="Contoh: Webhook untuk aplikasi POSO"
            ></textarea>
          </label>
          <label class="flex items-center gap-2">
            <input type="checkbox" bind:checked={webhookForm.is_active} />
            <span class="text-sm">Aktif sekarang</span>
          </label>
          {#if fieldErr('_')}<div class="text-xs text-danger">{fieldErr('_')}</div>{/if}
        </div>
        <div class="mt-4 flex justify-end gap-2">
          <button
            type="button"
            class="rounded-md border border-border-default px-3 py-1.5 text-sm"
            onclick={() => (showAddModal = false)}
            disabled={saving}>Batal</button
          >
          <button
            type="button"
            class="rounded-md bg-primary px-3 py-1.5 text-sm font-semibold text-white hover:bg-primary-active disabled:opacity-50"
            onclick={submitWebhook}
            disabled={saving || !webhookForm.event}
          >
            {saving ? 'Menyimpan…' : 'Simpan webhook'}
          </button>
        </div>
      {/if}
    </div>
  </div>
{/if}
