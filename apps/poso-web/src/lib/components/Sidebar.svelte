<script lang="ts">
  import Icon from './Icon.svelte';

  type IconName =
    | 'book-open'
    | 'cart'
    | 'grid'
    | 'home'
    | 'layers'
    | 'link'
    | 'package'
    | 'receipt'
    | 'settings'
    | 'shield'
    | 'tag'
    | 'truck'
    | 'users'
    | 'wallet';

  type SidebarItem = {
    href: string;
    label: string;
    icon: IconName;
    badge?: string;
  };

  type SidebarSection = {
    label: string;
    items: SidebarItem[];
  };

  let { currentPath = '/sales' }: { currentPath?: string } = $props();

  const sections: SidebarSection[] = [
    {
      label: 'Utama',
      items: [
        { href: '/dashboard', label: 'Dashboard', icon: 'home' },
        { href: '/sales', label: 'Penjualan', icon: 'receipt' },
        { href: '/purchases', label: 'Pembelian', icon: 'cart' }
      ]
    },
    {
      label: 'Master Data',
      items: [
        { href: '/customers', label: 'Pelanggan', icon: 'users' },
        { href: '/suppliers', label: 'Pemasok', icon: 'truck' },
        { href: '/products', label: 'Produk & Jasa', icon: 'package' },
        { href: '/price-lists', label: 'Daftar Harga', icon: 'tag' },
        { href: '/inventory', label: 'Stok & Gudang', icon: 'layers' }
      ]
    },
    {
      label: 'Keuangan',
      items: [
        { href: '/payments', label: 'Pembayaran', icon: 'wallet' },
        { href: '/reports', label: 'Laporan', icon: 'book-open' },
        { href: '/integrations/akunta', label: 'Integrasi Akunta', icon: 'link', badge: 'Sync' }
      ]
    },
    {
      label: 'Administrasi',
      items: [
        { href: '/users', label: 'Manajemen User', icon: 'users' },
        { href: '/roles', label: 'Role & Akses', icon: 'shield' },
        { href: '/settings', label: 'Setting POSO', icon: 'settings' },
        { href: '/audit-log', label: 'Audit Log', icon: 'grid' }
      ]
    }
  ];

  function isActive(href: string): boolean {
    if (href === '/dashboard') return currentPath === href;
    return currentPath === href || currentPath.startsWith(`${href}/`);
  }
</script>

<aside class="hidden min-h-screen w-[264px] shrink-0 border-r border-line bg-white md:block">
  <div class="sticky top-0 flex h-screen flex-col">
    <div class="flex h-16 items-center gap-3 border-b border-line px-5">
      <a href="/sales" class="flex items-center gap-3">
        <span class="grid size-9 place-items-center rounded-poso bg-blue text-sm font-black text-white shadow-sm">P</span>
        <span>
          <span class="block text-base font-extrabold tracking-tight text-ink">POSO</span>
          <span class="block text-[11px] font-semibold uppercase tracking-[0.12em] text-muted">Sales & Purchase</span>
        </span>
      </a>
    </div>

    <nav class="flex-1 overflow-y-auto px-3 py-4">
      {#each sections as section}
        <div class="mb-5">
          <div class="px-3 pb-2 text-[11px] font-bold uppercase tracking-[0.12em] text-muted/80">
            {section.label}
          </div>
          <div class="space-y-1">
            {#each section.items as item}
              <a
                href={item.href}
                class={`group flex h-10 items-center gap-3 rounded-poso px-3 text-sm font-semibold transition ${
                  isActive(item.href)
                    ? 'bg-blue text-white shadow-sm'
                    : 'text-muted hover:bg-soft hover:text-ink'
                }`}
              >
                <span class={`grid size-7 place-items-center rounded-md ${isActive(item.href) ? 'bg-white/15 text-white' : 'bg-soft text-muted group-hover:text-blue'}`}>
                  <Icon name={item.icon} size={16} stroke={2} />
                </span>
                <span class="min-w-0 flex-1 truncate">{item.label}</span>
                {#if item.badge}
                  <span class={`rounded-full px-2 py-0.5 text-[10px] font-bold ${isActive(item.href) ? 'bg-white/20 text-white' : 'bg-blue-soft text-blue'}`}>
                    {item.badge}
                  </span>
                {/if}
              </a>
            {/each}
          </div>
        </div>
      {/each}
    </nav>

    <div class="border-t border-line p-4">
      <div class="rounded-poso border border-blue/15 bg-blue-soft/70 p-3">
        <div class="text-xs font-bold text-blue">Akunta sync</div>
        <div class="mt-1 text-xs leading-5 text-muted">POSO mengirim event penjualan dan pembelian ke Akunta untuk double entry.</div>
      </div>
    </div>
  </div>
</aside>
