<script lang="ts">
  import { goto, invalidateAll } from '$app/navigation';
  import { page } from '$app/stores';
  import { auth } from '$lib/stores/auth.svelte.js';
  import { tenant } from '$lib/stores/tenant.svelte.js';
  import { period } from '$lib/stores/period.svelte.js';
  import { palette } from '$lib/stores/palette.svelte.js';
  import { ecosystem } from '$lib/stores/ecosystem.svelte.js';
  import type { EcosystemApp, EcosystemStatus } from '$lib/api/ecosystem.js';
  import WorkspaceTabs from './WorkspaceTabs.svelte';
  import { formatDate } from '$lib/utils/date.js';
  import { fakeDataApi } from '$lib/api/fake-data.js';

  interface NavItem {
    href?: string;
    label: string;
    icon?: string;
    match?: string[];
    children?: NavItem[];
  }
  interface NavGroup {
    title: string;
    items: NavItem[];
  }

  const groups: NavGroup[] = [
    {
      title: 'Jurnal',
      items: [
        { href: '/journals', label: 'Jurnal', icon: '✎', match: ['/journals'] },
        { href: '/template-jurnal', label: 'Journal Template', icon: '☰' },
        { href: '/jurnal-berulang', label: 'Jurnal Berulang', icon: '↻' },
        { href: '/auto-mapping', label: 'Auto Mapping', icon: '⇄' },
      ],
    },
    {
      title: 'Master',
      items: [
        { href: '/akun', label: 'Bagan Akun', icon: '⊞' },
        { href: '/periode', label: 'Periode', icon: '⌚' },
        { href: '/integrasi', label: 'Integrasi', icon: '⌘' },
        { href: '/settings', label: 'Setting', icon: '⚙' },
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
    {
      title: 'Fiskal',
      items: [{ href: '/fiskal/koreksi', label: 'Koreksi & Pajak Final', icon: 'F' }],
    },
    {
      title: 'Documentation',
      items: [{ href: '/documentation', label: 'Documentation', icon: '▤' }],
    },
  ];

  const MENU_STORAGE_KEY = 'akunta:accounting-sidebar-groups';

  const { children } = $props<{ children?: import('svelte').Snippet }>();

  function isActive(item: NavItem): boolean {
    const path = $page.url.pathname;
    if (item.href && path === item.href) return true;
    if ((item.match ?? []).some((m) => path.startsWith(m))) return true;
    if (item.children?.some((c) => isActive(c))) return true;
    return false;
  }

  // Keep the sidebar compact by default, with the journal group open on journal pages.
  let openParents = $state<Record<string, boolean>>({});
  let openGroups = $state<Record<string, boolean>>({
    Jurnal:
      $page.url.pathname.startsWith('/journals') ||
      $page.url.pathname === '/template-jurnal' ||
      $page.url.pathname === '/jurnal-berulang',
  });
  let menuStateRestored = $state(false);

  $effect(() => {
    const pathname = $page.url.pathname;
    if (!menuStateRestored && typeof localStorage !== 'undefined') {
      try {
        const stored = JSON.parse(localStorage.getItem(MENU_STORAGE_KEY) ?? '{}') as Record<
          string,
          boolean
        >;
        openGroups = { ...openGroups, ...stored };
      } catch {
        localStorage.removeItem(MENU_STORAGE_KEY);
      }
      menuStateRestored = true;
    }

    const activeGroup = groups.find((group) =>
      group.items.some(
        (item) => item.href && (pathname === item.href || pathname.startsWith(`${item.href}/`)),
      ),
    )?.title;
    if (activeGroup && !openGroups[activeGroup]) {
      openGroups[activeGroup] = true;
    }
  });

  function persistMenuState() {
    if (typeof localStorage !== 'undefined') {
      localStorage.setItem(MENU_STORAGE_KEY, JSON.stringify(openGroups));
    }
  }

  function toggleParent(key: string) {
    openParents[key] = !openParents[key];
  }

  function isParentOpen(item: NavItem): boolean {
    return openParents[item.label] === true;
  }

  function toggleGroup(key: string) {
    openGroups[key] = !openGroups[key];
    persistMenuState();
  }

  function isGroupOpen(key: string): boolean {
    return openGroups[key] === true;
  }

  const ecosystemOpen = $derived(isGroupOpen('Ekosistem'));
  const isInspector = $derived(
    auth.user?.roles.some((role) => role.toLowerCase() === 'inspector') ?? false,
  );
  const visibleGroups = $derived(
    isInspector
      ? groups.filter((group) => group.title === 'Fiskal')
      : groups.filter(
          (group) =>
            group.title !== 'Fiskal' ||
            tenant.available.find((item) => item.id === tenant.id)?.bookkeeping_mode ===
              'independent_books',
        ),
  );
  const visibleEntities = $derived(
    isInspector ? tenant.available.filter((item) => item.id === tenant.id) : tenant.available,
  );

  async function logout() {
    try {
      await auth.logout();
    } catch {
      // Continue to the logged-out screen; auth.logout() clears local state in
      // its own finally block even when the server request cannot complete.
    } finally {
      // Navigate even if the local logout request fails (for example, due to
      // an expired CSRF token). The store is cleared by auth.logout(), and the
      // logged-out page prevents an automatic Ecopa re-login.
      goto('/login?logged_out=1', { replaceState: true });
    }
  }

  async function stopImpersonation() {
    await fakeDataApi.stopImpersonation();
    await auth.refresh();
    goto('/settings');
  }

  let entityMenuOpen = $state(false);
  let periodMenuOpen = $state(false);
  let ecosystemRequested = $state(false);

  $effect(() => {
    // Refetch periods when active tenant changes.
    void tenant.id;
    if (tenant.id) period.refresh();
  });

  $effect(() => {
    // Fetch once per shell mount. An empty/error response must not create a
    // reactive retry loop; the refresh button remains available for retries.
    if (auth.user && !ecosystemRequested) {
      ecosystemRequested = true;
      void ecosystem.refresh();
    }
  });

  const ECO_ICON: Record<string, string> = {
    sales: '🛒',
    buy: '📦',
    inventory: '📦',
    payroll: '👥',
    invoice: '🧾',
    tax: '⚖',
    bank: '🏦',
    app: '◎',
  };

  const ECO_STATUS_CLASS: Record<EcosystemStatus, string> = {
    ok: 'ak-eco-dot--ok',
    warn: 'ak-eco-dot--warn',
    err: 'ak-eco-dot--err',
    syncing: 'ak-eco-dot--syncing',
    off: 'ak-eco-dot--off',
  };

  function openApp(app: EcosystemApp) {
    if (!app.url) return;
    window.open(app.url, '_blank', 'noopener,noreferrer');
  }

  // Dev preview: when Ecopa is not configured / user has no SSO link, render
  // a static preview list so the section is visible. Cleared as soon as real
  // data arrives. Toggle off via `?eco=hide` query param.
  const ECO_PREVIEW: EcosystemApp[] = [
    {
      slug: '_p_sales',
      label: 'App Penjualan',
      url: null,
      logo_url: null,
      app_role: null,
      icon_key: 'sales',
      status: 'ok',
      count: 142,
    },
    {
      slug: '_p_buy',
      label: 'App Pembelian',
      url: null,
      logo_url: null,
      app_role: null,
      icon_key: 'buy',
      status: 'ok',
      count: 38,
    },
    {
      slug: '_p_inv',
      label: 'App Inventory',
      url: null,
      logo_url: null,
      app_role: null,
      icon_key: 'inventory',
      status: 'ok',
      count: 11,
    },
    {
      slug: '_p_pay',
      label: 'App Payroll',
      url: null,
      logo_url: null,
      app_role: null,
      icon_key: 'payroll',
      status: 'warn',
      count: 1,
    },
    {
      slug: '_p_inv2',
      label: 'App Invoice',
      url: null,
      logo_url: null,
      app_role: null,
      icon_key: 'invoice',
      status: 'ok',
      count: 27,
    },
    {
      slug: '_p_tax',
      label: 'App e-Faktur',
      url: null,
      logo_url: null,
      app_role: null,
      icon_key: 'tax',
      status: 'syncing',
      count: null,
    },
  ];

  let showingPreview = $derived(
    ecosystem.apps.length === 0 && !ecosystem.loading && !ecosystem.error,
  );
  let displayedApps = $derived(showingPreview ? ECO_PREVIEW : ecosystem.apps);

  function pickPeriod(id: string) {
    period.switch(id);
    periodMenuOpen = false;
  }

  function pickEntity(id: string) {
    const selected = tenant.available.find((item) => item.id === id);
    if (selected?.is_active === false) {
      entityMenuOpen = false;
      goto('/settings');
      return;
    }
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
    return {
      destroy() {
        document.removeEventListener('mousedown', onDoc);
      },
    };
  }
</script>

<div class="flex min-h-screen bg-page-bg">
  <!-- Sidebar — matches Filament fi-sidebar slim 240px / Metronic Demo3 light -->
  <aside
    class="ak-sidebar hidden w-60 shrink-0 flex-col border-r border-border-default bg-sidebar-bg md:flex"
  >
    <a
      href="/dashboard"
      class="ak-sidebar-brand mt-5 mb-5 flex items-center gap-2.5 px-5 py-4 transition-colors"
      title="Ke Dashboard"
    >
      <span
        class="flex h-8 w-8 items-center justify-center rounded-md bg-primary text-white text-sm font-bold shadow-sm"
      >
        A
      </span>
      <div class="flex flex-col leading-tight">
        <strong class="text-xl font-bold tracking-tight text-text-strong">Akunta</strong>
        <span class="text-[0.65rem] font-medium uppercase tracking-wider text-text-muted"
          >Accounting</span
        >
      </div>
    </a>

    <nav class="flex-1 overflow-y-auto py-3">
      {#each visibleGroups as g (g.title)}
        {@const groupOpen = isGroupOpen(g.title)}
        <div class="mt-3 mb-6" class:ak-master-menu={g.title === 'Master'}>
          <button
            type="button"
            class="flex w-full items-center justify-between px-5 mb-1 text-left"
            onclick={() => toggleGroup(g.title)}
            aria-expanded={groupOpen}
          >
            <span class="ak-section-title">{g.title}</span>
            <span class="text-sm text-text-muted" aria-hidden="true">{groupOpen ? '▾' : '▸'}</span>
          </button>
          {#if groupOpen}
            <ul>
              {#each g.items as it (it.label)}
                <li>
                  {#if it.children?.length}
                    {@const open = isParentOpen(it)}
                    <div
                      class="ak-nav-item"
                      style="display: flex; align-items: stretch; gap: 0; padding: 0; background: transparent; color: var(--m-sidebar-text);"
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
                              class="ak-nav-item ak-nav-subitem"
                              style="background: transparent; font-weight: {childActive
                                ? '600'
                                : '400'};"
                            >
                              <span
                                class="ak-nav-icon"
                                style="font-size: 0.5rem; color: {childActive
                                  ? '#17C653'
                                  : 'var(--m-sidebar-muted)'};"
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
          {/if}
        </div>
      {/each}

      <div class="mt-3 mb-6">
        <div class="flex items-center justify-between px-5 mb-1">
          <button
            type="button"
            class="flex flex-1 items-center justify-between text-left"
            onclick={() => toggleGroup('Ekosistem')}
            aria-expanded={ecosystemOpen}
          >
            <span class="ak-section-title">Ekosistem</span>
            <span class="text-sm text-text-muted" aria-hidden="true"
              >{ecosystemOpen ? '▾' : '▸'}</span
            >
          </button>
          <button
            type="button"
            class="text-text-muted hover:text-primary text-xs"
            style="background: transparent; padding: 2px 4px; line-height: 1;"
            onclick={() => ecosystem.refresh()}
            aria-label="Refresh sinkronisasi"
            title="Refresh"
          >
            ↻
          </button>
        </div>
        {#if ecosystemOpen}
          <ul>
            {#if ecosystem.loading && displayedApps.length === 0}
              <li class="px-5 py-1.5 text-xs text-text-muted">Memuat…</li>
            {:else if displayedApps.length === 0}
              <li class="px-5 py-1.5 text-xs text-text-muted">
                {ecosystem.error ? 'Tidak terhubung' : 'Belum ada app terhubung'}
              </li>
            {:else}
              {#each displayedApps as app (app.slug)}
                <li>
                  <button
                    type="button"
                    class="ak-nav-item ak-eco-item"
                    style="background: transparent; width: 100%; text-align: left; cursor: {app.url
                      ? 'pointer'
                      : 'default'};"
                    onclick={() => openApp(app)}
                    title={app.url ?? app.label}
                  >
                    <span class="ak-nav-icon" aria-hidden="true"
                      >{ECO_ICON[app.icon_key] ?? ECO_ICON.app}</span
                    >
                    <span class="flex-1 truncate">{app.label}</span>
                    {#if app.count !== null}
                      <span class="ak-eco-count">{app.count}</span>
                    {/if}
                    <span class="ak-eco-dot {ECO_STATUS_CLASS[app.status]}" aria-label={app.status}
                    ></span>
                  </button>
                </li>
              {/each}
              {#if showingPreview}
                <li class="px-5 pt-1 text-[0.9rem] uppercase tracking-wider text-text-muted">
                  Preview · belum tersinkron
                </li>
              {/if}
            {/if}
          </ul>
        {/if}
      </div>
    </nav>

    <footer class="border-t border-border-soft px-3 py-3">
      {#if auth.user}
        <div class="flex items-center gap-3 px-2 py-1.5">
          <span
            class="flex h-9 w-9 items-center justify-center rounded-full bg-primary-light text-primary font-bold"
          >
            {auth.user.name.charAt(0).toUpperCase()}
          </span>
          <div class="min-w-0 flex-1">
            <strong class="block truncate text-sm font-semibold text-text-default"
              >{auth.user.name}</strong
            >
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
    <header
      class="sticky top-0 z-10 flex h-14 items-center justify-between gap-4 border-b border-border-default bg-topbar-bg px-6"
    >
      <div class="flex items-center gap-3">
        <button
          type="button"
          class="flex h-8 w-8 items-center justify-center rounded-md border border-border-default text-text-muted hover:bg-page-bg md:hidden"
          aria-label="Menu"
        >
          ☰
        </button>
        <button
          type="button"
          class="flex h-9 items-center gap-2 rounded-md border border-border-default bg-card-bg px-3 text-sm text-text-muted hover:border-primary hover:text-text-default"
          onclick={() => palette.show()}
          data-testid="palette-trigger"
          aria-label="Pencarian cepat"
        >
          <span aria-hidden="true">⌕</span>
          <span class="hidden sm:inline">Cari…</span>
          <kbd
            class="hidden sm:inline rounded border border-border-soft bg-page-bg px-1.5 py-0.5 text-[0.65rem] font-medium"
            >⌘K</kbd
          >
        </button>
      </div>
      <div class="flex items-center gap-2">
        {#if visibleEntities.length > 0}
          <div class="relative" use:handleClickOutside={() => (entityMenuOpen = false)}>
            <button
              type="button"
              class="flex h-9 items-center gap-2 rounded-md border border-border-default bg-card-bg px-3 text-sm font-medium text-text-default hover:border-primary"
              onclick={() => (entityMenuOpen = !entityMenuOpen)}
              data-testid="entity-switcher"
              title="Workspace hanya dapat diganti melalui Settings → Workspace"
              aria-haspopup="listbox"
              aria-expanded={entityMenuOpen}
            >
              <span
                class="flex h-5 w-5 items-center justify-center rounded bg-primary-light text-[0.625rem] font-bold text-primary"
              >
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
                <li
                  class="border-b border-border-soft px-3 py-2 text-[0.6875rem] font-semibold uppercase tracking-wider text-text-muted"
                >
                  Pilih Entitas ({visibleEntities.length})
                </li>
                {#each visibleEntities as t (t.id)}
                  <li>
                    <button
                      type="button"
                      class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-page-bg {tenant.id ===
                      t.id
                        ? 'bg-primary-light text-primary-active font-semibold'
                        : t.is_active === false
                          ? 'text-text-muted'
                          : 'text-text-default'}"
                      disabled
                      role="option"
                      aria-selected={tenant.id === t.id}
                      title={t.is_active === false
                        ? 'Workspace nonaktif. Buka Settings untuk mengaktifkannya.'
                        : undefined}
                    >
                      <span
                        class="flex h-6 w-6 items-center justify-center rounded bg-primary-light text-xs font-bold text-primary"
                      >
                        {t.name.charAt(0).toUpperCase()}
                      </span>
                      <span class="min-w-0 flex-1">
                        <span class="block truncate">{t.name}</span>
                        {#if t.slug}<span class="block truncate text-xs text-text-muted"
                            >{t.slug}</span
                          >{/if}
                        {#if t.is_active === false}
                          <span class="block text-xs font-medium text-warning"
                            >Nonaktif · buka Settings</span
                          >
                        {/if}
                      </span>
                      {#if tenant.id === t.id}<span class="text-primary">✓</span>{/if}
                    </button>
                  </li>
                {/each}
                <li class="border-t border-border-soft px-3 py-2 text-xs text-text-muted">
                  Switch to other workspace di
                  <a
                    href="/settings?section=workspace"
                    class="font-medium text-primary underline-offset-2 hover:underline">sini</a
                  >
                </li>
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
              <div
                class="absolute right-0 mt-1 w-72 overflow-hidden rounded-md border border-border-default bg-card-bg shadow-lg z-20 max-h-96 overflow-y-auto"
              >
                <div
                  class="border-b border-border-soft px-3 py-2 text-[0.6875rem] font-semibold uppercase tracking-wider text-text-muted"
                >
                  Pilih Periode ({period.available.length})
                </div>
                {#each period.byYear as group (group.year)}
                  <div
                    class="bg-page-bg px-3 py-1 text-[0.6875rem] font-semibold uppercase tracking-wider text-text-muted"
                  >
                    {group.year}
                  </div>
                  {#each group.periods as p (p.id)}
                    {@const isActive = period.activeId === p.id}
                    <button
                      type="button"
                      class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-page-bg {isActive
                        ? 'bg-primary-light/40 text-primary-active font-semibold'
                        : 'text-text-default'}"
                      onclick={() => pickPeriod(p.id)}
                      role="option"
                      aria-selected={isActive}
                    >
                      <span
                        class="ak-period-dot {isActive
                          ? 'ak-period-dot--active'
                          : 'ak-period-dot--idle'}"
                        aria-hidden="true"
                      ></span>
                      <span class="min-w-0 flex-1">
                        <span class="block truncate">{p.name}</span>
                        <span class="block truncate text-xs text-text-muted"
                          >{formatDate(p.start_date)} → {formatDate(p.end_date)}</span
                        >
                      </span>
                      {#if p.status !== 'open'}
                        <span class="text-[0.6rem] uppercase tracking-wider text-text-muted"
                          >{p.status}</span
                        >
                      {/if}
                    </button>
                  {/each}
                {/each}
              </div>
            {/if}
          </div>
        {/if}

        <button
          class="flex h-9 w-9 items-center justify-center rounded-md border border-border-default text-text-muted hover:bg-page-bg"
          aria-label="Notifikasi"
        >
          🔔
        </button>
        {#if auth.user}
          <span
            class="hidden lg:flex items-center gap-2 rounded-md border border-border-default px-2 py-1.5"
          >
            <span
              class="flex h-6 w-6 items-center justify-center rounded-full bg-primary-light text-primary text-xs font-bold"
            >
              {auth.user.name.charAt(0).toUpperCase()}
            </span>
            <span class="text-sm font-medium text-text-default">{auth.user.name}</span>
          </span>
        {/if}
      </div>
    </header>

    {#if auth.user?.is_impersonating}
      <div
        class="flex items-center justify-between gap-3 bg-warning px-6 py-2 text-sm font-semibold text-white"
      >
        <span>Anda sedang melihat aplikasi sebagai user fake.</span>
        <button
          type="button"
          class="rounded bg-white/20 px-3 py-1 text-xs hover:bg-white/30"
          onclick={stopImpersonation}>Kembali ke akun admin</button
        >
      </div>
    {/if}

    <WorkspaceTabs />

    <main class="min-w-0 flex-1">
      {@render children?.()}
    </main>
  </div>
</div>
