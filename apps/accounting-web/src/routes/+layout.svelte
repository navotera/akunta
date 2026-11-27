<script lang="ts">
  import '../app.css';
  import { onMount } from 'svelte';
  import { page } from '$app/stores';
  import { goto } from '$app/navigation';
  import { auth } from '$lib/stores/auth.svelte.js';
  import AppShell from '$lib/components/shell/AppShell.svelte';
  import Toaster from '$lib/components/ui/Toaster.svelte';
  import CommandPalette from '$lib/components/ui/CommandPalette.svelte';
  import WorkspaceRouter from '$lib/components/shell/WorkspaceRouter.svelte';
  import { palette } from '$lib/stores/palette.svelte.js';
  import { branding } from '$lib/stores/branding.svelte.js';
  import { MEMBER_MESSAGES } from '$lib/api/organization-onboarding.js';
  import ZeroValueStyler from '$lib/components/ui/ZeroValueStyler.svelte';

  let { children } = $props();

  // Application name and logo (public: the login page shows them too).
  onMount(() => void branding.load());

  // Routes that render without the app shell (auth, landing).
  const bareRoutes = ['/login', '/', '/onboarding'];

  // Entry flows (stage 7): an organisation owner without a (finished) workspace goes to the
  // wizard; a member sees whom to contact and never the wizard.
  const entryState = $derived(auth.user?.organization?.state ?? null);
  const memberMessage = $derived(
    entryState === 'workspace_missing' || entryState === 'no_role'
      ? MEMBER_MESSAGES[entryState]
      : null,
  );

  $effect(() => {
    if (
      (entryState === 'needs_workspace' || entryState === 'needs_setup') &&
      $page.url.pathname !== '/onboarding'
    ) {
      void goto('/onboarding', { replaceState: true });
    }
  });

  function hasNoWorkspaceAccess(): boolean {
    return Boolean(auth.user && !auth.user.is_sso_admin && auth.user.tenants.length === 0);
  }

  function onKeydown(e: KeyboardEvent) {
    if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
      e.preventDefault();
      palette.toggle();
    }
  }
</script>

<svelte:window on:keydown={onKeydown} />

{#if bareRoutes.includes($page.url.pathname)}
  <main class="min-h-screen">
    {@render children?.()}
  </main>
{:else if memberMessage || hasNoWorkspaceAccess()}
  <main class="flex min-h-screen items-center justify-center bg-page-bg px-6 py-12">
    <section class="w-full max-w-lg text-center" aria-labelledby="no-workspace-title">
      <div
        class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-warning-light text-warning"
        aria-hidden="true"
      >
        <svg
          viewBox="0 0 24 24"
          class="h-7 w-7"
          fill="none"
          stroke="currentColor"
          stroke-width="1.8"
        >
          <path
            d="M12 8v4m0 4h.01M10.3 3.9 2.8 17a2 2 0 0 0 1.74 3h14.92A2 2 0 0 0 21.2 17L13.7 3.9a2 2 0 0 0-3.4 0Z"
            stroke-linecap="round"
            stroke-linejoin="round"
          />
        </svg>
      </div>
      <h1 id="no-workspace-title" class="mt-6 text-2xl font-semibold text-text-primary">
        Anda belum terhubung ke workspace
      </h1>
      <p
        class="mx-auto mt-3 max-w-md text-sm leading-6 text-text-muted"
        data-testid="no-workspace-message"
      >
        {memberMessage ?? MEMBER_MESSAGES.no_role}
      </p>
    </section>
  </main>
{:else}
  <AppShell>
    <WorkspaceRouter />
  </AppShell>
  <CommandPalette bind:open={palette.open} />
{/if}

<Toaster />
<ZeroValueStyler />
