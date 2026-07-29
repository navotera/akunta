<script lang="ts">
  import { onMount } from 'svelte';
  import { page } from '$app/stores';
  import ReportTabs from '$lib/components/reporting/ReportTabs.svelte';
  import {
    ensureWorkspaceTab,
    initializeWorkspace,
    persistWorkspace,
    workspace,
  } from '$lib/stores/workspace.svelte.js';

  function currentHref(): string {
    return `${$page.url.pathname}${$page.url.search}`;
  }

  onMount(() => initializeWorkspace(currentHref()));

  $effect(() => {
    const href = currentHref();
    if (!workspace.initialized) return;
    ensureWorkspaceTab(href);
    persistWorkspace();
  });
</script>

{#if $page.url.pathname.startsWith('/laporan')}
  <ReportTabs />
{/if}

{#each workspace.tabs as tab (tab.href)}
  {@const Page = tab.component}
  <div
    class="ak-workspace-page"
    class:is-active={tab.href === currentHref()}
    aria-hidden={tab.href !== currentHref()}
  >
    <Page />
  </div>
{/each}
