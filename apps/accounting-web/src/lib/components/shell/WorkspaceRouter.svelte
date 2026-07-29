<script lang="ts">
  import { onMount } from 'svelte';
  import { goto } from '$app/navigation';
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

  function openDashboard() {
    ensureWorkspaceTab('/dashboard');
    persistWorkspace();
    if (currentHref() !== '/dashboard') void goto('/dashboard');
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

{#if workspace.tabs.length === 0}
  <div class="ak-workspace-empty">
    <div class="ak-workspace-empty__card">
      <span class="ak-workspace-empty__icon" aria-hidden="true">⊞</span>
      <h1>Tidak ada tab terbuka</h1>
      <p>Pilih halaman dari menu di samping untuk membuka tab baru.</p>
      <button type="button" onclick={openDashboard}>Buka Dashboard</button>
    </div>
  </div>
{:else}
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
{/if}
