<script lang="ts">
  import { onMount } from 'svelte';
  import { page } from '$app/stores';
  import { goto } from '$app/navigation';
  import { auth } from '$lib/stores/auth.svelte.js';
  import { isEcopaIntegrationEnabled, redirectToEcopaLogin } from '$lib/api/client.js';

  // Default flow: bounce to Ecopa OIDC. The legacy local form is reachable via
  // `?local=1` for environments where Ecopa is not configured (typically dev).
  let localMode = $state(false);
  let useLocalForm = $derived(localMode || $page.url.searchParams.get('local') === '1');
  let loggedOut = $derived($page.url.searchParams.get('logged_out') === '1');
  let ssoError = $derived($page.url.searchParams.get('sso_error'));
  let ssoErrorMessage = $derived(
    ssoError === 'state_mismatch'
      ? 'Sesi login kedaluwarsa atau tidak terbaca. Mulai login baru.'
      : ssoError === 'token_exchange'
        ? 'Ecopa gagal menyelesaikan login Akunta. Coba lagi atau hubungi admin.'
        : ssoError
          ? 'Login dengan Ecopa gagal. Coba lagi atau hubungi admin.'
          : null,
  );

  let email = $state('');
  let password = $state('');
  let remember = $state(false);
  let submitting = $state(false);
  let formError = $state<string | null>(null);

  onMount(() => {
    localMode = !isEcopaIntegrationEnabled();
    if (localMode && !loggedOut) {
      void loginLocal();
    } else if (!useLocalForm && !loggedOut && !ssoError) {
      redirectToEcopaLogin();
    }
  });

  async function loginLocal() {
    if (submitting) return;
    submitting = true;
    formError = null;
    try {
      await auth.localLogin();
      goto('/dashboard', { replaceState: true });
    } catch (err) {
      formError = err instanceof Error ? err.message : String(err);
    } finally {
      submitting = false;
    }
  }

  function startSsoLogin() {
    redirectToEcopaLogin();
  }

  async function onSubmit(e: SubmitEvent) {
    e.preventDefault();
    if (submitting) return;
    submitting = true;
    formError = null;
    try {
      await auth.login(email, password, remember);
      goto('/dashboard');
    } catch (err) {
      formError = err instanceof Error ? err.message : String(err);
    } finally {
      submitting = false;
    }
  }
</script>

{#if loggedOut}
  <div class="flex min-h-screen items-center justify-center px-4">
    <div class="w-full max-w-sm rounded-lg border border-border-default bg-card-bg p-6 text-center shadow-md">
      <h1 class="mb-1 text-xl font-bold">Anda telah logout</h1>
      <p class="mb-5 text-sm text-text-muted">Sesi Akunta sudah ditutup.</p>
      <button
        type="button"
        class="w-full rounded-md bg-primary px-4 py-2 font-semibold text-white hover:bg-primary-active"
        onclick={localMode ? loginLocal : startSsoLogin}
        data-testid={localMode ? 'local-login-button' : 'ecopa-login-button'}
      >
        {localMode ? 'Masuk kembali' : 'Masuk dengan Ecopa'}
      </button>
    </div>
  </div>
{:else if localMode}
  <div class="flex min-h-screen items-center justify-center px-4">
    <div class="w-full max-w-sm rounded-lg border border-border-default bg-card-bg p-6 text-center shadow-md">
      <h1 class="mb-1 text-xl font-bold">Akunta</h1>
      {#if submitting}
        <p class="text-sm text-text-muted">Menyiapkan akun lokal…</p>
      {:else if formError}
        <p class="mb-4 text-sm text-danger">{formError}</p>
        <button
          type="button"
          class="w-full rounded-md bg-primary px-4 py-2 font-semibold text-white hover:bg-primary-active"
          onclick={loginLocal}
        >
          Coba lagi
        </button>
      {/if}
    </div>
  </div>
{:else if !useLocalForm}
  {#if ssoErrorMessage}
    <div class="flex min-h-screen items-center justify-center px-4">
      <div class="w-full max-w-sm rounded-lg border border-border-default bg-card-bg p-6 text-center shadow-md">
        <h1 class="mb-1 text-xl font-bold">Login Akunta gagal</h1>
        <p class="mb-5 text-sm text-text-muted">{ssoErrorMessage}</p>
        <div
          class="mb-5 rounded-md border border-danger bg-danger-light px-3 py-2 text-left text-sm text-danger"
          role="alert"
          data-testid="sso-error"
        >
          Tidak ada data yang berubah. Silakan mulai ulang proses login.
        </div>
        <button
          type="button"
          class="w-full rounded-md bg-primary px-4 py-2 font-semibold text-white hover:bg-primary-active"
          onclick={startSsoLogin}
          data-testid="ecopa-login-button"
        >
          Coba lagi dengan Ecopa
        </button>
      </div>
    </div>
  {:else}
    <div class="flex min-h-screen items-center justify-center px-4 text-text-muted">
      Mengarahkan ke Ecopa…
    </div>
  {/if}
{:else}
  <div class="flex min-h-screen items-center justify-center px-4">
    <form
      class="w-full max-w-sm rounded-lg border border-border-default bg-card-bg p-6 shadow-md"
      onsubmit={onSubmit}
      data-testid="login-form"
    >
      <h1 class="mb-1 text-xl font-bold">Akunta</h1>
      <p class="mb-5 text-sm text-text-muted">Masuk ke aplikasi akuntansi (mode lokal).</p>

      {#if formError}
        <div
          class="mb-4 rounded-md border border-danger bg-danger-light px-3 py-2 text-sm text-danger"
          role="alert"
        >
          {formError}
        </div>
      {/if}

      <label class="mb-3 block text-sm">
        <span class="mb-1 block font-medium">Email</span>
        <input
          type="email"
          name="email"
          bind:value={email}
          required
          autocomplete="username"
          class="w-full rounded-md border border-border-default px-3 py-2 outline-none focus:border-primary"
          data-testid="login-email"
        />
      </label>

      <label class="mb-4 block text-sm">
        <span class="mb-1 block font-medium">Kata sandi</span>
        <input
          type="password"
          name="password"
          bind:value={password}
          required
          autocomplete="current-password"
          class="w-full rounded-md border border-border-default px-3 py-2 outline-none focus:border-primary"
          data-testid="login-password"
        />
      </label>

      <label class="mb-4 flex items-center gap-2 text-sm">
        <input type="checkbox" bind:checked={remember} />
        <span>Ingat saya</span>
      </label>

      <button
        type="submit"
        disabled={submitting}
        class="w-full rounded-md bg-primary px-4 py-2 font-semibold text-white hover:bg-primary-active disabled:opacity-50"
        data-testid="login-submit"
      >
        {submitting ? 'Memproses…' : 'Masuk'}
      </button>
    </form>
  </div>
{/if}
