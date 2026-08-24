<script lang="ts">
  import { onMount, tick } from 'svelte';
  import { goto } from '$app/navigation';
  import { page } from '$app/stores';
  import {
    ensureWorkspaceTab,
    initializeWorkspace,
    persistWorkspace,
    workspace,
  } from '$lib/stores/workspace.svelte.js';

  function currentHref(): string {
    return `${$page.url.pathname}${$page.url.search}`;
  }

  let tabsScroll: HTMLDivElement;
  let trimmingTabs = false;

  function keepActiveTabFirst() {
    const activeIndex = workspace.tabs.findIndex((tab) => tab.href === currentHref());
    if (activeIndex <= 0) return;

    const activeTab = workspace.tabs[activeIndex];
    if (!activeTab) return;
    workspace.tabs = [
      activeTab,
      ...workspace.tabs.slice(0, activeIndex),
      ...workspace.tabs.slice(activeIndex + 1),
    ];
    persistWorkspace();
  }

  async function trimOverflowingTabs() {
    if (!tabsScroll || trimmingTabs) return;

    trimmingTabs = true;
    try {
      await tick();
      while (tabsScroll.scrollWidth > tabsScroll.clientWidth && workspace.tabs.length > 1) {
        const activeIndex = workspace.tabs.findIndex((tab) => tab.href === currentHref());
        const removableIndex = workspace.tabs.findIndex((_, index) => index !== activeIndex);
        workspace.tabs = workspace.tabs.filter((_, index) => index !== removableIndex);
        persistWorkspace();
        await tick();
      }
    } finally {
      trimmingTabs = false;
    }
  }

  onMount(() => {
    initializeWorkspace(currentHref());
    keepActiveTabFirst();

    const resizeObserver = new ResizeObserver(() => void trimOverflowingTabs());
    resizeObserver.observe(tabsScroll);
    void trimOverflowingTabs();

    return () => resizeObserver.disconnect();
  });

  $effect(() => {
    $page.url.pathname;
    $page.url.search;
    if (workspace.initialized) {
      ensureWorkspaceTab(currentHref());
      keepActiveTabFirst();
      void trimOverflowingTabs();
    }
  });

  function activate(href: string) {
    if (href !== currentHref()) void goto(href);
  }

  async function closeTab(href: string, event: MouseEvent) {
    event.stopPropagation();
    const index = workspace.tabs.findIndex((tab) => tab.href === href);
    if (index < 0) return;

    const wasActive = href === currentHref();
    const remainingTabs = workspace.tabs.filter((tab) => tab.href !== href);

    if (wasActive && remainingTabs.length > 0) {
      const nextTab = remainingTabs[Math.min(index, remainingTabs.length - 1)];
      if (nextTab) await goto(nextTab.href);
    }

    workspace.tabs = remainingTabs;
    if (workspace.tabs.length === 0) workspace.emptyAt = href;
    persistWorkspace();
  }

  function closeOtherTabs() {
    const active = workspace.tabs.find((tab) => tab.href === currentHref());
    if (!active) return;
    workspace.tabs = [active];
    persistWorkspace();
  }
</script>

<div class="ak-workspace-tabs" role="tablist" aria-label="Tab halaman yang terbuka">
  <div class="ak-workspace-tabs__scroll" bind:this={tabsScroll}>
    {#each workspace.tabs as tab (tab.href)}
      {@const active = tab.href === currentHref()}
      <div
        class="ak-workspace-tab {active ? 'is-active' : ''}"
        role="tab"
        aria-selected={active}
        tabindex="0"
        onclick={() => activate(tab.href)}
        onkeydown={(event) => {
          if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            activate(tab.href);
          }
        }}
      >
        <span class="ak-workspace-tab__icon" aria-hidden="true">{tab.icon}</span>
        <span class="ak-workspace-tab__label">{tab.label}</span>
        <button
          type="button"
          class="ak-workspace-tab__close"
          aria-label={`Tutup ${tab.label}`}
          onclick={(event) => closeTab(tab.href, event)}>×</button
        >
      </div>
    {/each}
  </div>
  <button
    type="button"
    class="ak-workspace-tabs__action"
    onclick={closeOtherTabs}
    title="Tutup tab lain">Tutup lainnya</button
  >
</div>
