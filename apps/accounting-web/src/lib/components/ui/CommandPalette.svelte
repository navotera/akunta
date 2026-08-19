<script lang="ts">
  import { goto } from '$app/navigation';
  import { tick } from 'svelte';
  import { accountApi, type Account } from '$lib/api/account.js';
  import { templateApi, type JournalTemplateSummary } from '$lib/api/template.js';
  import { periodApi, type Period } from '$lib/api/period.js';
  import { formatDate } from '$lib/utils/date.js';

  interface Item {
    id: string;
    label: string;
    sub?: string;
    href: string;
    icon: string;
    group: string;
  }

  const PAGES: Item[] = [
    { id: 'p:dashboard', label: 'Dashboard', href: '/dashboard', icon: '⌂', group: 'Halaman' },
    { id: 'p:journals', label: 'Jurnal', href: '/journals', icon: '✎', group: 'Halaman' },
    { id: 'p:journal-new', label: 'Buat Jurnal', sub: 'Tambah jurnal baru', href: '/journals/new', icon: '+', group: 'Halaman' },
    { id: 'p:recurring', label: 'Jurnal Berulang', href: '/jurnal-berulang', icon: '↻', group: 'Halaman' },
    { id: 'p:akun', label: 'Bagan Akun', href: '/akun', icon: '⊞', group: 'Halaman' },
    { id: 'p:periode', label: 'Periode', href: '/periode', icon: '⌚', group: 'Halaman' },
    { id: 'p:template', label: 'Template Jurnal', href: '/template-jurnal', icon: '☰', group: 'Halaman' },
    { id: 'p:tb', label: 'Neraca Saldo', href: '/laporan/neraca-saldo', icon: '∑', group: 'Laporan' },
    { id: 'p:is', label: 'Laba Rugi', href: '/laporan/laba-rugi', icon: '↗', group: 'Laporan' },
    { id: 'p:bs', label: 'Neraca', href: '/laporan/neraca', icon: '⚖', group: 'Laporan' },
    { id: 'p:gl', label: 'Buku Besar', href: '/laporan/buku-besar', icon: '☐', group: 'Laporan' },
    { id: 'p:bp', label: 'Buku Pembantu', href: '/laporan/buku-pembantu', icon: '⌬', group: 'Laporan' },
  ];

  let { open = $bindable(false) } = $props<{ open?: boolean }>();

  let query = $state('');
  let cursor = $state(0);
  let dynamic = $state<Item[]>([]);
  let loading = $state(false);
  let inputEl: HTMLInputElement | undefined = $state();
  let listEl: HTMLUListElement | undefined = $state();

  let debounceTimer: ReturnType<typeof setTimeout> | undefined;
  let inflight = 0;

  $effect(() => {
    if (open) {
      query = '';
      cursor = 0;
      dynamic = [];
      tick().then(() => inputEl?.focus());
    }
  });

  function fuzzy(s: string, q: string): boolean {
    if (!q) return true;
    const a = s.toLowerCase();
    const b = q.toLowerCase();
    let i = 0;
    for (const ch of a) {
      if (ch === b[i]) i++;
      if (i === b.length) return true;
    }
    return false;
  }

  const filteredPages = $derived(PAGES.filter((p) => fuzzy(p.label, query) || (p.sub ? fuzzy(p.sub, query) : false)));

  $effect(() => {
    const q = query.trim();
    if (debounceTimer) clearTimeout(debounceTimer);
    if (q.length < 2) {
      dynamic = [];
      loading = false;
      return;
    }
    loading = true;
    const ticket = ++inflight;
    debounceTimer = setTimeout(async () => {
      try {
        const [accounts, templates, periods] = await Promise.all([
          accountApi.list(q).catch(() => [] as Account[]),
          templateApi.list(20).catch(() => [] as JournalTemplateSummary[]),
          periodApi.list().catch(() => [] as Period[]),
        ]);
        if (ticket !== inflight) return;
        const items: Item[] = [];
        for (const a of accounts.slice(0, 8)) {
          items.push({
            id: `a:${a.id}`,
            label: `${a.code} — ${a.name}`,
            sub: a.type,
            href: `/akun?focus=${encodeURIComponent(a.id)}`,
            icon: '⊞',
            group: 'Akun',
          });
        }
        for (const t of templates.filter((t) => fuzzy(`${t.code} ${t.name}`, q)).slice(0, 6)) {
          items.push({
            id: `t:${t.id}`,
            label: `${t.code} — ${t.name}`,
            sub: t.description ?? undefined,
            href: `/template-jurnal?focus=${encodeURIComponent(t.id)}`,
            icon: '☰',
            group: 'Template',
          });
        }
        for (const p of periods.filter((p) => fuzzy(p.name, q)).slice(0, 6)) {
          items.push({
            id: `pe:${p.id}`,
            label: p.name,
    sub: `${formatDate(p.start_date)} → ${formatDate(p.end_date)} · ${p.status}`,
            href: `/periode?focus=${encodeURIComponent(p.id)}`,
            icon: '⌚',
            group: 'Periode',
          });
        }
        dynamic = items;
      } finally {
        if (ticket === inflight) loading = false;
      }
    }, 200);
  });

  const all = $derived<Item[]>([...filteredPages, ...dynamic]);

  const grouped = $derived.by(() => {
    const map = new Map<string, Item[]>();
    for (const it of all) {
      if (!map.has(it.group)) map.set(it.group, []);
      map.get(it.group)!.push(it);
    }
    return [...map.entries()].map(([group, items]) => ({ group, items }));
  });

  $effect(() => {
    if (cursor >= all.length) cursor = 0;
  });

  function pick(item: Item) {
    open = false;
    goto(item.href);
  }

  function onKeydown(e: KeyboardEvent) {
    if (e.key === 'Escape') {
      e.preventDefault();
      open = false;
      return;
    }
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      cursor = all.length ? (cursor + 1) % all.length : 0;
      scrollIntoView();
      return;
    }
    if (e.key === 'ArrowUp') {
      e.preventDefault();
      cursor = all.length ? (cursor - 1 + all.length) % all.length : 0;
      scrollIntoView();
      return;
    }
    if (e.key === 'Enter') {
      e.preventDefault();
      const target = all[cursor];
      if (target) pick(target);
    }
  }

  function scrollIntoView() {
    tick().then(() => {
      const node = listEl?.querySelector<HTMLElement>(`[data-cursor-index="${cursor}"]`);
      node?.scrollIntoView({ block: 'nearest' });
    });
  }

  function indexOf(item: Item): number {
    return all.findIndex((it) => it.id === item.id);
  }
</script>

{#if open}
  <div
    class="fixed inset-0 z-[90] flex items-start justify-center bg-black/40 px-4 pt-[12vh]"
    role="presentation"
    onclick={() => (open = false)}
    onkeydown={onKeydown}
  >
    <div
      class="w-full max-w-xl overflow-hidden rounded-lg border border-border-default bg-card-bg shadow-2xl"
      role="dialog"
      tabindex="-1"
      aria-modal="true"
      aria-label="Pencarian cepat"
      onclick={(e) => e.stopPropagation()}
      onkeydown={(e) => e.stopPropagation()}
    >
      <div class="flex items-center gap-2 border-b border-border-default px-4 py-3">
        <span class="text-text-muted" aria-hidden="true">⌕</span>
        <input
          bind:this={inputEl}
          bind:value={query}
          onkeydown={onKeydown}
          type="text"
          placeholder="Cari halaman, akun, template, periode…"
          class="flex-1 bg-transparent text-sm text-text-strong placeholder:text-text-muted focus:outline-none"
          autocomplete="off"
          spellcheck="false"
          aria-label="Pencarian"
        />
        {#if loading}
          <span class="text-xs text-text-muted">…</span>
        {/if}
        <kbd class="rounded border border-border-soft bg-page-bg px-1.5 py-0.5 text-[0.65rem] font-medium text-text-muted">Esc</kbd>
      </div>

      <ul
        bind:this={listEl}
        class="max-h-[55vh] overflow-y-auto py-1"
        role="listbox"
      >
        {#if all.length === 0}
          <li class="px-4 py-6 text-center text-sm text-text-muted">
            {query.trim().length === 0 ? 'Mulai mengetik untuk mencari…' : 'Tidak ada hasil.'}
          </li>
        {/if}
        {#each grouped as g (g.group)}
          <li class="px-4 pt-2 pb-1 text-[0.65rem] font-semibold uppercase tracking-wider text-text-muted">
            {g.group}
          </li>
          {#each g.items as it (it.id)}
            {@const idx = indexOf(it)}
            {@const active = idx === cursor}
            <li>
              <button
                type="button"
                data-cursor-index={idx}
                class="flex w-full items-center gap-3 px-4 py-2 text-left text-sm {active ? 'bg-primary-light text-primary-active' : 'text-text-default hover:bg-page-bg'}"
                onclick={() => pick(it)}
                onmouseenter={() => (cursor = idx)}
                role="option"
                aria-selected={active}
              >
                <span class="flex h-6 w-6 items-center justify-center text-text-muted" aria-hidden="true">{it.icon}</span>
                <span class="min-w-0 flex-1">
                  <span class="block truncate font-medium">{it.label}</span>
                  {#if it.sub}
                    <span class="block truncate text-xs text-text-muted">{it.sub}</span>
                  {/if}
                </span>
                {#if active}<span class="text-xs text-text-muted">↵</span>{/if}
              </button>
            </li>
          {/each}
        {/each}
      </ul>

      <div class="flex items-center justify-between border-t border-border-default bg-page-bg/60 px-4 py-2 text-[0.7rem] text-text-muted">
        <span class="flex items-center gap-3">
          <span><kbd class="rounded border border-border-soft bg-card-bg px-1 py-0.5">↑</kbd><kbd class="ml-0.5 rounded border border-border-soft bg-card-bg px-1 py-0.5">↓</kbd> navigasi</span>
          <span><kbd class="rounded border border-border-soft bg-card-bg px-1 py-0.5">↵</kbd> buka</span>
        </span>
        <span><kbd class="rounded border border-border-soft bg-card-bg px-1 py-0.5">⌘</kbd>+<kbd class="rounded border border-border-soft bg-card-bg px-1 py-0.5">K</kbd></span>
      </div>
    </div>
  </div>
{/if}
