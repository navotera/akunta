<script lang="ts">
  import '../app.css';
  import { page } from '$app/stores';
  import AppShell from '$lib/components/shell/AppShell.svelte';
  import Toaster from '$lib/components/ui/Toaster.svelte';
  import CommandPalette from '$lib/components/ui/CommandPalette.svelte';
  import WorkspaceRouter from '$lib/components/shell/WorkspaceRouter.svelte';
  import { palette } from '$lib/stores/palette.svelte.js';

  let { children } = $props();

  // Routes that render without the app shell (auth, landing).
  const bareRoutes = ['/login', '/'];

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
{:else}
  <AppShell>
    <WorkspaceRouter />
  </AppShell>
  <CommandPalette bind:open={palette.open} />
{/if}

<Toaster />
