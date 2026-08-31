<script lang="ts">
  import { onMount } from 'svelte';
  import { goto } from '$app/navigation';
  import { page } from '$app/stores';
  import ReportTabs from '$lib/components/reporting/ReportTabs.svelte';
  import {
    ensureWorkspaceTab,
    getPersistedWorkspaceHref,
    getWorkspaceTab,
    initializeWorkspace,
    isWorkspaceHref,
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

  let restoringWorkspace = $state(false);

  onMount(() => {
    const href = currentHref();
    if (!isWorkspaceHref(href)) return;

    initializeWorkspace(href);
    const persistedHref = getPersistedWorkspaceHref();
    if (
      href === '/dashboard' &&
      persistedHref &&
      persistedHref !== href &&
      workspace.tabs.some((tab) => tab.href === persistedHref)
    ) {
      restoringWorkspace = true;
      void goto(persistedHref, { replaceState: true }).finally(() => {
        restoringWorkspace = false;
        persistWorkspace(currentHref());
      });
      return;
    }
    persistWorkspace(href);
  });

  $effect(() => {
    // Read the URL fields directly so Svelte tracks navigation as a dependency.
    $page.url.pathname;
    $page.url.search;
    const href = currentHref();
    if (!workspace.initialized || restoringWorkspace || !isWorkspaceHref(href)) return;
    ensureWorkspaceTab(href);
    persistWorkspace(href);
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
  {@const activeTab = getWorkspaceTab(currentHref())}
  {#if activeTab}
    {@const Page = activeTab.component}
    <div class="ak-workspace-page is-active" aria-hidden="false">
      <Page />
    </div>
  {/if}
{/if}
