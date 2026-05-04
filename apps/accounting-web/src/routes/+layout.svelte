<script lang="ts">
  import '../app.css';
  import { page } from '$app/stores';
  import AppShell from '$lib/components/shell/AppShell.svelte';

  let { children } = $props();

  // Routes that render without the app shell (auth, landing).
  const bareRoutes = ['/login', '/'];
</script>

{#if bareRoutes.includes($page.url.pathname)}
  <main class="min-h-screen">
    {@render children?.()}
  </main>
{:else}
  <AppShell>
    {@render children?.()}
  </AppShell>
{/if}
