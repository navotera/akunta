<script lang="ts">
  import { onMount, untrack } from 'svelte';
  import { goto } from '$app/navigation';
  import { page } from '$app/stores';
  import ReportTabs from '$lib/components/reporting/ReportTabs.svelte';
  import { auth } from '$lib/stores/auth.svelte.js';
  import { tenant } from '$lib/stores/tenant.svelte.js';
  import { canVisitWorkspacePage, workspaceHome } from '$lib/stores/permissions.svelte.js';
  import {
    ensureWorkspaceTab,
    consumeWorkspaceSwitch,
    getPersistedWorkspaceHref,
    getWorkspaceTab,
    initializeWorkspace,
    isWorkspaceHref,
    persistWorkspace,
    workspace,
    type WorkspaceScope,
  } from '$lib/stores/workspace.svelte.js';

  function currentHref(): string {
    return `${$page.url.pathname}${$page.url.search}`;
  }

  function openDashboard() {
    const href = workspaceHome();
    ensureWorkspaceTab(href);
    persistWorkspace(href);
    if (currentHref() !== href) void goto(href);
  }

  let restoringWorkspace = $state(true);
  let mounted = $state(false);
  let restoration = 0;
  let requestedScope = '';

  onMount(() => {
    mounted = true;
    if (!auth.user)
      void auth.refresh().then((user) => {
        if (mounted && !user) void goto('/login', { replaceState: true });
      });
    return () => {
      mounted = false;
      restoration += 1;
    };
  });

  async function restoreWorkspace(scope: WorkspaceScope) {
    const request = ++restoration;
    restoringWorkspace = true;
    const href = currentHref();
    const changedScope =
      workspace.scope &&
      (workspace.scope.userId !== scope.userId || workspace.scope.entityId !== scope.entityId);
    const persistedHref = getPersistedWorkspaceHref(scope);
    const switchedEntity = consumeWorkspaceSwitch(scope);
    const target =
      href === '/dashboard' || changedScope || switchedEntity
        ? persistedHref && canVisitWorkspacePage(persistedHref)
          ? persistedHref
          : workspaceHome()
        : href;
    initializeWorkspace(target, scope, canVisitWorkspacePage);
    try {
      if (target !== href) await goto(target, { replaceState: true });
    } finally {
      if (mounted && request === restoration) {
        restoringWorkspace = false;
        persistWorkspace(currentHref());
      }
    }
  }

  $effect(() => {
    const userId = auth.user?.id;
    const entityId = tenant.id;
    if (!mounted || !userId || workspace.switching) return;
    const scopeKey = JSON.stringify([userId, entityId]);
    if (requestedScope === scopeKey) return;
    requestedScope = scopeKey;
    untrack(() => void restoreWorkspace({ userId, entityId }));
  });

  $effect(() => {
    // Read the URL fields directly so Svelte tracks navigation as a dependency.
    $page.url.pathname;
    $page.url.search;
    const href = currentHref();
    if (
      !workspace.initialized ||
      workspace.switching ||
      restoringWorkspace ||
      !isWorkspaceHref(href) ||
      workspace.scope?.userId !== auth.user?.id ||
      workspace.scope?.entityId !== tenant.id
    )
      return;
    ensureWorkspaceTab(href);
    persistWorkspace(href);
  });
</script>

{#if !restoringWorkspace && $page.url.pathname.startsWith('/laporan')}
  <ReportTabs />
{/if}

{#if restoringWorkspace || workspace.switching}
  <div class="px-6 py-6 text-sm text-text-muted" role="status">Memuat halaman entity…</div>
{:else if workspace.tabs.length === 0}
  <div class="ak-workspace-empty">
    <div class="ak-workspace-empty__card">
      <span class="ak-workspace-empty__icon" aria-hidden="true">⊞</span>
      <h1>Tidak ada tab terbuka</h1>
      <p>Pilih halaman dari menu di samping untuk membuka tab baru.</p>
      <button type="button" onclick={openDashboard}>Buka Halaman Utama</button>
    </div>
  </div>
{:else}
  {@const activeTab = getWorkspaceTab(currentHref())}
  {#if activeTab}
    {@const Page = activeTab.component}
    {#key `${workspace.scope?.userId}:${workspace.scope?.entityId}:${activeTab.href}`}
      <div class="ak-workspace-page is-active" aria-hidden="false">
        <Page />
      </div>
    {/key}
  {/if}
{/if}
