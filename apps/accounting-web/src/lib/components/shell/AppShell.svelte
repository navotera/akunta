<script lang="ts">
  import { goto } from '$app/navigation';
  import { page } from '$app/stores';
  import { auth } from '$lib/stores/auth.svelte.js';

  interface NavItem {
    href: string;
    label: string;
    icon: string;
    match?: string[];
  }
  interface NavGroup { title: string; items: NavItem[]; }

  const groups: NavGroup[] = [
    {
      title: 'Operasional',
      items: [
        { href: '/dashboard', label: 'Dashboard', icon: '◉' },
        { href: '/journals', label: 'Jurnal', icon: '✎', match: ['/journals'] },
        { href: '/jurnal-berulang', label: 'Jurnal Berulang', icon: '↻' },
      ],
    },
    {
      title: 'Master',
      items: [
        { href: '/akun', label: 'Bagan Akun', icon: '⊞' },
        { href: '/partner', label: 'Partner', icon: '◴' },
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
      ],
    },
  ];

  const { children } = $props<{ children?: import('svelte').Snippet }>();

  function isActive(item: NavItem): boolean {
    const path = $page.url.pathname;
    if (path === item.href) return true;
    return (item.match ?? []).some((m) => path.startsWith(m));
  }

  async function logout() {
    await auth.logout();
    goto('/login', { replaceState: true });
  }
</script>

<div class="flex min-h-screen bg-page-bg">
  <aside class="hidden w-60 shrink-0 flex-col border-r border-border-default bg-card-bg md:flex">
    <header class="px-5 py-4 border-b border-border-soft">
      <strong class="block text-base font-bold tracking-tight">Akunta</strong>
      <span class="block text-xs text-text-muted">Accounting</span>
    </header>

    <nav class="flex-1 overflow-y-auto py-3">
      {#each groups as g (g.title)}
        <div class="mb-4">
          <p class="px-5 text-[10px] font-bold uppercase tracking-wider text-text-muted">{g.title}</p>
          <ul class="mt-1">
            {#each g.items as it (it.href)}
              <li>
                <a
                  href={it.href}
                  class="mx-2 flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors {isActive(it) ? 'bg-primary-light text-primary' : 'text-text-default hover:bg-page-bg'}"
                >
                  <span class="w-5 text-center text-base">{it.icon}</span>
                  <span>{it.label}</span>
                </a>
              </li>
            {/each}
          </ul>
        </div>
      {/each}
    </nav>

    <footer class="border-t border-border-soft px-3 py-3">
      {#if auth.user}
        <div class="flex items-center gap-3 px-2 py-1.5">
          <span class="flex h-8 w-8 items-center justify-center rounded-full bg-primary-light text-primary font-bold">
            {auth.user.name.charAt(0).toUpperCase()}
          </span>
          <div class="min-w-0 flex-1">
            <strong class="block truncate text-sm font-semibold">{auth.user.name}</strong>
            <span class="block truncate text-xs text-text-muted">{auth.user.email}</span>
          </div>
          <button
            type="button"
            class="text-text-muted hover:text-danger"
            onclick={logout}
            title="Keluar"
            data-testid="logout-button"
          >
            ⏻
          </button>
        </div>
      {/if}
    </footer>
  </aside>

  <main class="min-w-0 flex-1">
    {@render children?.()}
  </main>
</div>
