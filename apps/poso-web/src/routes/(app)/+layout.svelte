<script lang="ts">
  import { page } from '$app/stores';
  import { onMount } from 'svelte';
  import Icon from '$lib/components/Icon.svelte';
  import Sidebar from '$lib/components/Sidebar.svelte';
  import { posoContext } from '$lib/stores/context.svelte.js';
  import type { Snippet } from 'svelte';

  let { children }: { children?: Snippet } = $props();

  let entitySelectValue = $state('');
  let userInitials = $derived(
    posoContext.user.name
      .split(' ')
      .filter(Boolean)
      .slice(0, 2)
      .map((part) => part[0]?.toUpperCase())
      .join('') || 'AD'
  );

  $effect(() => {
    entitySelectValue = posoContext.activeEntity?.id ?? '';
  });

  onMount(() => {
    posoContext.refresh();
  });

  async function onEntityChange(event: Event) {
    const value = (event.currentTarget as HTMLSelectElement).value;
    if (!value) return;
    entitySelectValue = value;
    await posoContext.chooseEntity(value);
  }
</script>

<div class="min-h-screen md:flex">
  <Sidebar currentPath={$page.url.pathname} />

  <div class="min-w-0 flex-1">
    <header class="sticky top-0 z-20 border-b border-line bg-white/95 backdrop-blur">
      <div class="mx-auto flex h-16 max-w-[1440px] items-center gap-4 px-4 sm:px-6">
        <a href="/sales" class="flex items-center gap-3 md:hidden">
          <span class="grid size-9 place-items-center rounded-poso bg-blue font-black text-white">P</span>
          <span class="hidden font-bold tracking-tight text-ink sm:block">POSO</span>
        </a>

      <label class="relative hidden flex-1 md:block">
        <span class="sr-only">Cari</span>
        <input class="field h-11 max-w-xl pl-4 pr-20" placeholder="Cari menu, data, laporan..." />
        <span class="absolute right-14 top-1/2 -translate-y-1/2 text-muted">⌕</span>
        <span class="absolute right-3 top-1/2 -translate-y-1/2 rounded border border-line bg-soft px-1.5 py-0.5 text-xs font-semibold text-muted">⌘ K</span>
      </label>

      <label class="relative ml-auto hidden md:block">
        <span class="sr-only">Entitas aktif</span>
        <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-muted">
          <Icon name="building" size={16} stroke={2} />
        </span>
        <select
          class="h-11 min-w-56 appearance-none rounded-poso border border-line bg-white py-0 pl-10 pr-9 text-sm font-semibold text-ink shadow-sm outline-none hover:border-blue focus:border-blue focus:ring-4 focus:ring-blue/10"
          bind:value={entitySelectValue}
          onchange={onEntityChange}
          aria-label="Entitas aktif"
        >
          {#if posoContext.entities.length > 0}
            {#each posoContext.entities as entity (entity.id)}
              <option value={entity.id}>{entity.name}</option>
            {/each}
          {:else}
            <option value="">{posoContext.loading ? 'Memuat entitas...' : 'Belum ada entitas sinkron'}</option>
          {/if}
        </select>
        <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-muted">
          <Icon name="chevron-down" size={15} stroke={2} />
        </span>
      </label>
      <button class="relative grid size-11 place-items-center rounded-poso border border-line bg-white text-muted shadow-sm hover:border-blue hover:text-blue" aria-label="Notifikasi">
        <Icon name="bell" size={18} stroke={2} />
        <span class="absolute -right-1 -top-1 grid size-5 place-items-center rounded-full bg-red text-xs font-bold text-white">3</span>
      </button>
      <button class="flex items-center gap-3 rounded-poso px-2 py-1.5 hover:bg-soft">
        <span class="grid size-10 place-items-center rounded-full bg-amber-soft font-bold text-amber">{userInitials}</span>
        <span class="hidden text-left sm:block">
          <span class="block text-sm font-bold text-ink">{posoContext.user.name}</span>
          <span class="block text-xs text-muted">{posoContext.user.role}</span>
        </span>
        <span class="hidden text-muted sm:block"><Icon name="chevron-down" size={15} stroke={2} /></span>
      </button>
      </div>
    </header>

    <main class="mx-auto max-w-[1280px] px-4 py-6 sm:px-6">
      {@render children?.()}
    </main>
  </div>
</div>
