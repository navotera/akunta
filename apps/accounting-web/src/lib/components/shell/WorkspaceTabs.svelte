<script lang="ts">
  import { goto } from '$app/navigation';
  import { page } from '$app/stores';
  import { persistWorkspace, workspace } from '$lib/stores/workspace.svelte.js';

  function currentHref(): string {
    return `${$page.url.pathname}${$page.url.search}`;
  }

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
  <div class="ak-workspace-tabs__scroll">
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
