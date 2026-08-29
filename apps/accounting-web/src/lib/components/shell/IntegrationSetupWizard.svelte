<script lang="ts">
  import { onMount } from 'svelte';
  import { ecopaIntegrationApi, type EcopaIntegrationStatus } from '$lib/api/ecopa-integration.js';

  let { onActivated } = $props<{ onActivated?: () => void }>();

  let status = $state<EcopaIntegrationStatus | null>(null);
  let loading = $state(true);
  let saving = $state(false);
  let error = $state<string | null>(null);
  let ecopaUrl = $state('');
  let registrationToken = $state('');

  onMount(() => {
    void loadStatus();
    const timer = window.setInterval(() => {
      if (status?.registration_status === 'pending') void loadStatus(false);
    }, 5000);

    return () => window.clearInterval(timer);
  });

  async function loadStatus(showLoader = true) {
    if (showLoader) loading = true;
    try {
      const next = await ecopaIntegrationApi.publicStatus();
      status = next;
      if (!ecopaUrl) ecopaUrl = next.ecopa_url;
      if (next.configured && next.integration_status === 'on') onActivated?.();
    } catch (caught) {
      error = caught instanceof Error ? caught.message : String(caught);
    } finally {
      loading = false;
    }
  }

  async function submit(event: SubmitEvent) {
    event.preventDefault();
    saving = true;
    error = null;
    try {
      status = await ecopaIntegrationApi.requestRegistration({
        ecopa_url: ecopaUrl.trim(),
        registration_token: registrationToken,
      });
      registrationToken = '';
    } catch (caught) {
      error = caught instanceof Error ? caught.message : String(caught);
    } finally {
      saving = false;
    }
  }
</script>

<div class="flex min-h-screen items-center justify-center bg-page-bg px-5 py-10">
  <section
    class="w-full max-w-xl rounded-xl border border-border-default bg-card-bg p-7 shadow-sm"
    data-testid="ecopa-setup-wizard"
  >
    <div class="mb-6 flex items-center gap-3">
      <span
        class="flex h-10 w-10 items-center justify-center rounded-lg bg-primary text-lg font-bold text-white"
        >A</span
      >
      <div>
        <h1 class="text-xl font-bold text-text-strong">Hubungkan Akunta ke Ecopa</h1>
        <p class="text-sm text-text-muted">Konfigurasi pertama sebelum login.</p>
      </div>
    </div>

    {#if loading}
      <p class="py-8 text-center text-sm text-text-muted">Memeriksa status integrasi…</p>
    {:else if status?.registration_status === 'pending'}
      <div class="text-center" data-testid="ecopa-registration-pending">
        <span
          class="inline-flex rounded-full bg-warning-light px-3 py-1 text-xs font-semibold text-warning"
        >
          Menunggu persetujuan Ecopa
        </span>
        <h2 class="mt-4 text-xl font-bold text-text-strong">Request registrasi sudah diterima</h2>
        <p class="mt-2 text-sm leading-6 text-text-muted">
          Admin Ecopa perlu menyetujui request
          <strong>{status.registration_request_id ?? 'pending'}</strong>. Halaman ini akan memeriksa
          status Akunta secara otomatis.
        </p>
        <div class="mt-5 rounded-lg border border-border-default bg-page-bg p-4 text-left text-sm">
          <p class="font-semibold text-text-strong">Webhook standar</p>
          <code class="mt-1 block break-all text-text-muted">{status.webhook_url}</code>
          <p class="mt-3 text-text-muted">
            Setelah callback approval tervalidasi, wizard ini hilang dan login Ecopa dimulai.
          </p>
        </div>
        <button
          type="button"
          class="mt-5 rounded-md border border-border-default px-4 py-2 text-sm font-medium hover:border-primary"
          onclick={() => loadStatus()}
        >
          Periksa sekarang
        </button>
      </div>
    {:else}
      {#if status?.registration_status === 'rejected'}
        <div
          class="mb-5 rounded-md border border-danger bg-danger-light px-4 py-3 text-sm text-danger"
          role="alert"
        >
          {status.registration_message ??
            'Request ditolak Ecopa. Gunakan token registrasi baru untuk mencoba lagi.'}
        </div>
      {/if}

      <p class="mb-5 text-sm leading-6 text-text-muted">
        Masukkan alamat Ecopa dan Registration Token dari dashboard Ecopa. Nama aplikasi, slug <code
          >accounting</code
        >, base URL, callback SSO, dan webhook ditentukan otomatis oleh Akunta.
      </p>

      <form class="space-y-4" onsubmit={submit}>
        <label class="block text-sm font-medium">
          Ecopa URL
          <input
            class="mt-1 w-full rounded-md border border-border-default px-3 py-2 outline-none focus:border-primary"
            type="url"
            bind:value={ecopaUrl}
            placeholder="https://ecopa.example.com"
            required
            data-testid="ecopa-url"
          />
        </label>
        <label class="block text-sm font-medium">
          Registration Token
          <input
            class="mt-1 w-full rounded-md border border-border-default px-3 py-2 outline-none focus:border-primary"
            type="password"
            bind:value={registrationToken}
            autocomplete="off"
            minlength="8"
            required
            data-testid="ecopa-registration-token"
          />
          <span class="mt-1 block text-xs font-normal text-text-muted">
            Token dikirim ke backend Akunta dan tidak disimpan di browser.
          </span>
        </label>
        <button
          class="w-full rounded-md bg-primary px-4 py-2.5 font-semibold text-white hover:bg-primary-active disabled:opacity-60"
          disabled={saving}
          data-testid="ecopa-registration-submit"
        >
          {saving ? 'Mengirim request…' : 'Daftarkan Akunta'}
        </button>
      </form>
    {/if}

    {#if error}
      <div
        class="mt-5 rounded-md border border-danger bg-danger-light px-4 py-3 text-sm text-danger"
        role="alert"
      >
        {error}
      </div>
    {/if}
  </section>
</div>
