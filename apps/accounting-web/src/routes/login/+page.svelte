<script lang="ts">
  import { goto } from '$app/navigation';
  import { auth } from '$lib/stores/auth.svelte.js';

  let email = $state('');
  let password = $state('');
  let remember = $state(false);
  let submitting = $state(false);
  let formError = $state<string | null>(null);

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

<div class="flex min-h-screen items-center justify-center px-4">
  <form
    class="w-full max-w-sm rounded-lg border border-border-default bg-card-bg p-6 shadow-md"
    on:submit={onSubmit}
    data-testid="login-form"
  >
    <h1 class="mb-1 text-xl font-bold">Akunta</h1>
    <p class="mb-5 text-sm text-text-muted">Masuk ke aplikasi akuntansi.</p>

    {#if formError}
      <div class="mb-4 rounded-md border border-danger bg-danger-light px-3 py-2 text-sm text-danger" role="alert">
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
