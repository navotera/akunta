<script lang="ts">
  import { onMount } from 'svelte';
  import { goto, invalidateAll } from '$app/navigation';
  import { page } from '$app/stores';
  import { auth } from '$lib/stores/auth.svelte.js';
  import { tenant } from '$lib/stores/tenant.svelte.js';
  import { period } from '$lib/stores/period.svelte.js';

  interface NavItem {
    href?: string;
    label: string;
    icon?: string;
    match?: string[];
    children?: NavItem[];
  }
  interface NavGroup { title: string; items: NavItem[]; }

  const groups: NavGroup[] = [
    {
      title: 'Operasional',
      items: [
        {
          label: 'Jurnal',
          icon: '✎',
          match: ['/journals', '/jurnal-berulang'],
          href: '/journals',
          children: [
            { href: '/journals', label: 'List' },
            { href: '/journals/new', label: 'Buat Jurnal' },
            { href: '/jurnal-berulang', label: 'Jurnal Berulang' },
          ],
        },
      ],
    },
    {
      title: 'Master',
      items: [
        { href: '/akun', label: 'Bagan Akun', icon: '⊞' },
        { href: '/periode', label: 'Periode', icon: '⌚' },
        { href: '/template-jurnal', label: 'Template Jurnal', icon: '☰' },
      ],
    },
    {
      title: 'Laporan',
      items: [
        { href: '/laporan/neraca-saldo', label: 'Neraca Saldo', icon: '∑' },
        { href: '/laporan/laba-rugi', label: 'Laba Rugi', icon: '↗' },
        { href: '/laporan/neraca', label: 'Neraca', icon: '⚖' },
        { href: '/laporan/buku-besar', label: 'Buku Besar', icon: '☐' },
        { href: '/laporan/buku-pembantu', label: 'Buku Pembantu', icon: '⌬' },
      ],
    },
  ];

  const { children } = $props<{ children?: import('svelte').Snippet }>();

  function isActive(item: NavItem): boolean {
    const path = $page.url.pathname;
    if (item.href && path === item.href) return true;
    if ((item.match ?? []).some((m) => path.startsWith(m))) return true;
    if (item.children?.some((c) => isActive(c))) return true;
    return false;
  }

  let openParents = $state<Record<string, boolean>>({});

  function toggleParent(key: string) {
    openParents[key] = !openParents[key];
  }

  function isParentOpen(item: NavItem): boolean {
    return openParents[item.label] === true;
  }

  async function logout() {
    await auth.logout();
    goto('/login', { replaceState: true });
  }

  let entityMenuOpen = $state(false);
  let periodMenuOpen = $state(false);

  onMount(() => {
    if (tenant.id) period.refresh();
  });

  $effect(() => {
    // Refetch periods when active tenant changes.
    void tenant.id;
    if (tenant.id) period.refresh();
  });

  function pickPeriod(id: string) {
    period.switch(id);
    periodMenuOpen = false;
  }

  function pickEntity(id: string) {
    tenant.switch(id);
    entityMenuOpen = false;
    // Force a hard reload so every onMount() refetches with the new tenant.
    if (typeof window !== 'undefined') window.location.reload();
    else invalidateAll();
  }

  function handleClickOutside(node: HTMLElement, cb: () => void) {
    function onDoc(e: MouseEvent) {
      if (!node.contains(e.target as Node)) cb();
    }
    document.addEventListener('mousedown', onDoc);
    return { destroy() { document.removeEventListener('mousedown', onDoc); } };
  }
</script>

<div class="flex min-h-screen bg-page-bg">
  <!-- Sidebar — matches Filament fi-sidebar slim 240px / Metronic Demo3 light -->
  <aside class="hidden w-60 shrink-0 flex-col border-r border-border-default bg-sidebar-bg md:flex">
    <a
      href="/dashboard"
      class="flex items-center gap-2.5 px-5 py-4 border-b border-border-soft hover:bg-page-bg transition-colors"
      title="Ke Dashboard"
    >
      <span class="flex h-8 w-8 items-center justify-center rounded-md bg-primary text-white text-sm font-bold shadow-sm">
        A
      </span>
      <div class="flex flex-col leading-tight">
        <strong class="text-[0.95rem] font-bold tracking-tight text-text-strong">Akunta</strong>
        <span class="text-[0.65rem] font-medium uppercase tracking-wider text-text-muted">Accounting</span>
      </div>
    </a>

    <nav class="flex-1 overflow-y-auto py-3">
      {#each groups as g (g.title)}
        <div class="mb-4">
          <p class="px-5 mb-1 ak-section-title">{g.title}</p>
          <ul>
            {#each g.items as it (it.label)}
              <li>
                {#if it.children?.length}
                  {@const open = isParentOpen(it)}
                  <div
                    class="ak-nav-item"
                    style="display: flex; align-items: stretch; gap: 0; padding: 0; background: transparent; color: var(--m-text);"
                  >
                    {#if it.href}
                      <a
                        href={it.href}
                        class="flex flex-1 items-center gap-2"
                        style="padding: 0.5rem 0.75rem; color: inherit; text-decoration: none;"
                      >
                        <span class="ak-nav-icon">{it.icon ?? ''}</span>
                        <span>{it.label}</span>
                      </a>
                    {:else}
                      <button
                        type="button"
                        class="flex flex-1 items-center gap-2"
                        style="padding: 0.5rem 0.75rem; color: inherit; background: transparent;"
                        onclick={() => toggleParent(it.label)}
                      >
                        <span class="ak-nav-icon">{it.icon ?? ''}</span>
                        <span>{it.label}</span>
                      </button>
                    {/if}
                    <button
                      type="button"
                      class="text-text-muted hover:text-primary"
                      style="font-size: 1.5rem; line-height: 0.6; padding: 0.5rem 15px 0.5rem 0.4rem; background: transparent;"
                      onclick={() => toggleParent(it.label)}
                      aria-expanded={open}
                      aria-label={open ? 'Tutup' : 'Buka'}
                    >
                      {open ? '▾' : '▸'}
                    </button>
                  </div>
                  {#if open}
                    <ul class="mt-0.5">
                      {#each it.children as child (child.href ?? child.label)}
                        {@const childActive = isActive(child)}
                        <li>
                          <a
                            href={child.href}
                            class="ak-nav-item text-[0.8125rem]"
                            style="background: transparent; color: var(--m-text); font-weight: {childActive ? '600' : '500'};"
                          >
                            <span
                              class="ak-nav-icon"
                              style="font-size: 0.5rem; color: {childActive ? '#17C653' : 'var(--m-text-muted)'};"
                            >
                              {childActive ? '●' : '·'}
                            </span>
                            <span>{child.label}</span>
                          </a>
                        </li>
                      {/each}
                    </ul>
                  {/if}
                {:else}
                  <a href={it.href} class="ak-nav-item {isActive(it) ? 'is-active' : ''}">
                    <span class="ak-nav-icon">{it.icon ?? ''}</span>
                    <span>{it.label}</span>
                  </a>
                {/if}
              </li>
            {/each}
          </ul>
        </div>
      {/each}
    </nav>

    <footer class="border-t border-border-soft px-3 py-3">
      {#if auth.user}
        <div class="flex items-center gap-3 px-2 py-1.5">
          <span class="flex h-9 w-9 items-center justify-center rounded-full bg-primary-light text-primary font-bold">
            {auth.user.name.charAt(0).toUpperCase()}
          </span>
          <div class="min-w-0 flex-1">
            <strong class="block truncate text-sm font-semibold text-text-default">{auth.user.name}</strong>
            <span class="block truncate text-xs text-text-muted">{auth.user.email}</span>
          </div>
          <button
            type="button"
            class="text-text-muted hover:text-danger"
            onclick={logout}
            title="Keluar"
            data-testid="logout-button"
            aria-label="Keluar"
          >
            ⏻
          </button>
        </div>
      {/if}
    </footer>
  </aside>

  <!-- Main column with topbar -->
  <div class="flex min-w-0 flex-1 flex-col">
    <header class="sticky top-0 z-10 flex h-14 items-center justify-between gap-4 border-b border-border-default bg-topbar-bg px-6">
      <div class="flex items-center gap-3">
        <button
          type="button"
          class="flex h-8 w-8 items-center justify-center rounded-md border border-border-default text-text-muted hover:bg-page-bg md:hidden"
          aria-label="Menu"
        >
          ☰
        </button>
      </div>
      <div class="flex items-center gap-2">
        {#if tenant.available.length > 0}
          <div class="relative" use:handleClickOutside={() => (entityMenuOpen = false)}>
            <button
              type="button"
              class="flex h-9 items-center gap-2 rounded-md border border-border-default bg-card-bg px-3 text-sm font-medium text-text-default hover:border-primary"
              onclick={() => (entityMenuOpen = !entityMenuOpen)}
              data-testid="entity-switcher"
              aria-haspopup="listbox"
              aria-expanded={entityMenuOpen}
            >
              <span class="flex h-5 w-5 items-center justify-center rounded bg-primary-light text-[0.625rem] font-bold text-primary">
                {(tenant.name ?? '?').charAt(0).toUpperCase()}
              </span>
              <span class="max-w-[10rem] truncate">{tenant.name ?? 'Pilih entitas'}</span>
              <span class="text-text-muted text-xs">▾</span>
            </button>

            {#if entityMenuOpen}
              <ul
                class="absolute right-0 mt-1 w-64 overflow-hidden rounded-md border border-border-default bg-card-bg shadow-lg z-20"
                role="listbox"
              >
                <li class="border-b border-border-soft px-3 py-2 text-[0.6875rem] font-semibold uppercase tracking-wider text-text-muted">
                  Pilih Entitas ({tenant.available.length})
                </li>
                {#each tenant.available as t (t.id)}
                  <li>
                    <button
                      type="button"
                      class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-page-bg {tenant.id === t.id ? 'bg-primary-light text-primary-active font-semibold' : 'text-text-default'}"
                      onclick={() => pickEntity(t.id)}
                      role="option"
                      aria-selected={tenant.id === t.id}
                    >
                      <span class="flex h-6 w-6 items-center justify-center rounded bg-primary-light text-xs font-bold text-primary">
                        {t.name.charAt(0).toUpperCase()}
                      </span>
                      <span class="min-w-0 flex-1">
                        <span class="block truncate">{t.name}</span>
                        {#if t.slug}<span class="block truncate text-xs text-text-muted">{t.slug}</span>{/if}
                      </span>
                      {#if tenant.id === t.id}<span class="text-primary">✓</span>{/if}
                    </button>
                  </li>
                {/each}
              </ul>
            {/if}
          </div>
        {/if}

        {#if period.available.length > 0}
          <div class="relative" use:handleClickOutside={() => (periodMenuOpen = false)}>
            <button
              type="button"
              class="flex h-9 items-center gap-2 rounded-md border border-border-default bg-card-bg px-3 text-sm font-medium text-text-default hover:border-primary"
              onclick={() => (periodMenuOpen = !periodMenuOpen)}
              aria-haspopup="listbox"
              aria-expanded={periodMenuOpen}
              data-testid="period-switcher"
            >
              <span class="ak-period-dot ak-period-dot--active" aria-hidden="true"></span>
              <span class="max-w-[10rem] truncate">{period.active?.name ?? 'Pilih periode'}</span>
              <span class="text-text-muted text-xs">▾</span>
            </button>

            {#if periodMenuOpen}
              <div class="absolute right-0 mt-1 w-72 overflow-hidden rounded-md border border-border-default bg-card-bg shadow-lg z-20 max-h-96 overflow-y-auto">
                <div class="border-b border-border-soft px-3 py-2 text-[0.6875rem] font-semibold uppercase tracking-wider text-text-muted">
                  Pilih Periode ({period.available.length})
                </div>
                {#each period.byYear as group (group.year)}
                  <div class="bg-page-bg px-3 py-1 text-[0.6875rem] font-semibold uppercase tracking-wider text-text-muted">
                    {group.year}
                  </div>
                  {#each group.periods as p (p.id)}
                    {@const isActive = period.activeId === p.id}
                    <button
                      type="button"
                      class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-page-bg {isActive ? 'bg-primary-light/40 text-primary-active font-semibold' : 'text-text-default'}"
                      onclick={() => pickPeriod(p.id)}
                      role="option"
                      aria-selected={isActive}
                    >
                      <span class="ak-period-dot {isActive ? 'ak-period-dot--active' : 'ak-period-dot--idle'}" aria-hidden="true"></span>
                      <span class="min-w-0 flex-1">
                        <span class="block truncate">{p.name}</span>
                        <span class="block truncate text-xs text-text-muted">{p.start_date} → {p.end_date}</span>
                      </span>
                      {#if p.status !== 'open'}
                        <span class="text-[0.6rem] uppercase tracking-wider text-text-muted">{p.status}</span>
                      {/if}
                    </button>
                  {/each}
                {/each}
              </div>
            {/if}
          </div>
        {/if}

        <button class="flex h-9 w-9 items-center justify-center rounded-md border border-border-default text-text-muted hover:bg-page-bg" aria-label="Notifikasi">
          🔔
        </button>
        {#if auth.user}
          <span class="hidden lg:flex items-center gap-2 rounded-md border border-border-default px-2 py-1.5">
            <span class="flex h-6 w-6 items-center justify-center rounded-full bg-primary-light text-primary text-xs font-bold">
              {auth.user.name.charAt(0).toUpperCase()}
            </span>
            <span class="text-sm font-medium text-text-default">{auth.user.name}</span>
          </span>
        {/if}
      </div>
    </header>

    <main class="min-w-0 flex-1">
      {@render children?.()}
    </main>
  </div>
</div>
