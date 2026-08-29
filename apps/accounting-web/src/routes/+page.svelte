<script lang="ts">
  import { onMount } from 'svelte';
  import { goto } from '$app/navigation';
  import IntegrationSetupWizard from '$lib/components/shell/IntegrationSetupWizard.svelte';
  import { ecopaIntegrationApi } from '$lib/api/ecopa-integration.js';
  import { auth } from '$lib/stores/auth.svelte.js';

  let setupRequired = $state<boolean | null>(null);
  let integrationError = $state<string | null>(null);

  onMount(async () => {
    try {
      const integration = await ecopaIntegrationApi.publicStatus();
      setupRequired = !integration.configured;
      if (!setupRequired) await continueToApplication(integration.integration_status === 'off');
    } catch (caught) {
      integrationError =
        caught instanceof Error ? caught.message : 'Status integrasi tidak dapat diperiksa.';
    }
  });

  async function continueToApplication(local = false) {
    const user = await auth.refresh();
    await goto(user ? '/dashboard' : local ? '/login?local=1' : '/login', {
      replaceState: true,
    });
  }
</script>

{#if setupRequired === null && !integrationError}
  <div
    class="fixed inset-0 z-50 flex min-h-screen items-center justify-center bg-slate-950/40 px-4"
    role="dialog"
    aria-modal="true"
    aria-labelledby="integration-loading-title"
    data-testid="integration-loading"
  >
    <div
      class="w-full max-w-sm rounded-xl border border-border-default bg-card-bg p-6 text-center shadow-xl"
    >
      <div
        class="mx-auto mb-4 h-8 w-8 animate-spin rounded-full border-4 border-border-default border-t-primary"
        aria-hidden="true"
      ></div>
      <h1 id="integration-loading-title" class="font-semibold text-text-strong">
        Memeriksa integrasi
      </h1>
      <p class="mt-2 text-sm text-text-muted">Mohon tunggu sebentar…</p>
    </div>
  </div>
{:else if integrationError}
  <div class="flex min-h-screen items-center justify-center px-4">
    <div
      class="w-full max-w-sm rounded-xl border border-border-default bg-card-bg p-6 text-center shadow-md"
    >
      <h1 class="font-semibold text-text-strong">Integrasi belum dapat diperiksa</h1>
      <p class="mt-2 text-sm text-text-muted">{integrationError}</p>
      <button
        type="button"
        class="mt-5 w-full rounded-md bg-primary px-4 py-2 font-semibold text-white hover:bg-primary-active"
        onclick={() => window.location.reload()}
      >
        Coba lagi
      </button>
    </div>
  </div>
{:else if setupRequired}
  <IntegrationSetupWizard onActivated={() => continueToApplication()} />
{/if}
