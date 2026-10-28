<script lang="ts">
  export interface ComboOption {
    id: string;
    label: string;
    /** Optional secondary text rendered muted on the right (e.g. category, hint). */
    sublabel?: string | null;
    /** Optional leading code/badge rendered monospace on the left. */
    code?: string | null;
    /** Optional muted tag rendered far right (e.g. "PARENT", "GLOBAL"). */
    tag?: string | null;
    /** Optional value rendered at the far right of the option row. */
    trailing?: string | number | null;
    /** Accounting journal scope shown as a badge on account options. */
    availability?: 'intern' | 'fiskal' | 'both' | null;
    /** True when the option still belongs to the removable demo dataset. */
    isFake?: boolean;
    isInactive?: boolean;
    disabled?: boolean;
  }

  interface Props {
    options: ComboOption[];
    value: string;
    placeholder?: string;
    emptyText?: string;
    selectedLabel?: string;
    maxResults?: number;
    onSelect: (id: string) => void;
    /** Test-id forwarded to the trigger button for E2E hooks. */
    testId?: string;
  }

  let {
    options,
    value,
    placeholder = 'Cari…',
    emptyText = 'Tidak ditemukan.',
    selectedLabel = '',
    maxResults = 50,
    onSelect,
    testId,
  }: Props = $props();

  let open = $state(false);
  let query = $state('');
  let highlight = $state(0);
  let inputEl: HTMLInputElement | undefined = $state();
  let listEl: HTMLDivElement | undefined = $state();
  let menuStyle = $state('');

  function updatePosition() {
    const rect = inputEl?.getBoundingClientRect();
    if (!rect) return;
    const width = Math.min(Math.max(rect.width, 320), window.innerWidth - 32);
    const left = Math.max(16, Math.min(rect.left, window.innerWidth - width - 16));
    const below = window.innerHeight - rect.bottom - 12;
    const above = rect.top - 12;
    const showAbove = below < Math.min(200, above);
    const height = Math.min(288, Math.max(80, showAbove ? above : below));
    menuStyle = `left:${left}px;width:${width}px;max-height:${height}px;${showAbove ? `bottom:${window.innerHeight - rect.top + 4}px` : `top:${rect.bottom + 4}px`};`;
  }

  $effect(() => {
    if (!open) return;
    window.addEventListener('resize', updatePosition);
    window.addEventListener('scroll', updatePosition, true);
    return () => {
      window.removeEventListener('resize', updatePosition);
      window.removeEventListener('scroll', updatePosition, true);
    };
  });

  const selected = $derived(options.find((o) => o.id === value) ?? null);
  const display = $derived(
    selected
      ? selected.code
        ? `${selected.code} — ${selected.label}`
        : selected.label
      : value
        ? selectedLabel
        : '',
  );

  const filtered = $derived.by(() => {
    const q = query.trim().toLowerCase();
    const pool = q
      ? options.filter((o) => {
          const haystack = `${o.code ?? ''} ${o.label} ${o.sublabel ?? ''}`.toLowerCase();
          return haystack.includes(q);
        })
      : options;
    return pool.slice(0, maxResults);
  });

  function openList() {
    open = true;
    query = '';
    highlight = Math.max(
      0,
      filtered.findIndex((o) => o.id === value),
    );
    queueMicrotask(() => {
      inputEl?.focus();
      updatePosition();
    });
  }

  function closeList() {
    open = false;
    query = '';
  }

  function pick(o: ComboOption) {
    if (o.disabled) return;
    onSelect(o.id);
    closeList();
  }

  function onKey(e: KeyboardEvent) {
    if (!open) return;
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      highlight = Math.min(highlight + 1, filtered.length - 1);
      scrollIntoView();
    } else if (e.key === 'ArrowUp') {
      e.preventDefault();
      highlight = Math.max(highlight - 1, 0);
      scrollIntoView();
    } else if (e.key === 'Enter') {
      e.preventDefault();
      const target = filtered[highlight];
      if (target) pick(target);
    } else if (e.key === 'Escape') {
      e.preventDefault();
      closeList();
    }
  }

  function scrollIntoView() {
    queueMicrotask(() => {
      const node = listEl?.querySelector(`[data-idx="${highlight}"]`) as HTMLElement | null;
      node?.scrollIntoView({ block: 'nearest' });
    });
  }

  function clickOutside(node: HTMLElement, cb: () => void) {
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

<div class="relative w-full" use:clickOutside={closeList}>
  {#if !open}
    <button
      type="button"
      class="ak-form-control w-full rounded-md border border-border-default px-2 py-1.5 text-sm text-left bg-white focus:outline-none focus:border-primary {selected &&
      !selected.isInactive
        ? 'text-text-default'
        : 'text-text-muted'}"
      onclick={openList}
      data-testid={testId}
    >
      {display || placeholder}
      {#if selected?.isInactive}
        <span class="ml-2 text-xs">Tidak aktif</span>
      {/if}
    </button>
  {:else}
    <input
      bind:this={inputEl}
      type="text"
      class="w-full rounded-md border border-primary px-2 py-1.5 text-sm focus:outline-none"
      {placeholder}
      bind:value={query}
      onkeydown={onKey}
      oninput={() => (highlight = 0)}
    />
    <div
      bind:this={listEl}
      class="fixed z-[100] overflow-y-auto rounded-md border border-border-default bg-card-bg shadow-lg"
      style={menuStyle}
      role="listbox"
    >
      {#each filtered as o, i (o.id)}
        <button
          type="button"
          data-idx={i}
          class="flex w-full items-center gap-2 px-2.5 py-1.5 text-left text-sm hover:bg-page-bg
                 {o.isInactive
            ? 'bg-page-bg text-text-muted'
            : i === highlight
              ? 'bg-primary-light'
              : ''}
                 {o.isInactive
            ? ''
            : o.id === value
              ? 'font-semibold text-primary-active'
              : 'text-text-default'}
                 {o.disabled
            ? o.isInactive
              ? 'cursor-not-allowed'
              : 'opacity-50 cursor-not-allowed'
            : ''}"
          onmouseenter={() => (highlight = i)}
          onclick={() => pick(o)}
          disabled={o.disabled}
          aria-disabled={o.disabled}
          title={o.isInactive ? 'Aktifkan akun melalui Bagan Akun sebelum digunakan.' : undefined}
          role="option"
          aria-selected={o.id === value}
        >
          {#if o.isFake}
            <span
              class="h-1.5 w-1.5 shrink-0 rounded-full bg-text-muted/60"
              title="Akun hasil import fake data"
              aria-label="Akun fake"
            ></span>
          {:else}
            <span class="h-1.5 w-1.5 shrink-0" aria-hidden="true"></span>
          {/if}
          {#if o.code}
            <span class="font-mono text-xs text-text-muted w-14 shrink-0">{o.code}</span>
          {/if}
          <span class="min-w-0 flex-1">
            <span class="block truncate">{o.label}</span>
            {#if o.isInactive}
              <span class="block text-xs text-text-muted">Tidak aktif</span>
            {/if}
          </span>
          {#if o.sublabel}
            <span class="text-xs text-text-muted truncate">{o.sublabel}</span>
          {/if}
          {#if o.availability}
            <span
              class="ml-auto inline-flex shrink-0 items-center rounded-full px-1.5 py-0.5 text-[10px] font-semibold {o.isInactive
                ? 'bg-page-bg text-text-muted'
                : o.availability === 'fiskal'
                  ? 'bg-[#fff0b8] text-[#8a5a00]'
                  : o.availability === 'intern'
                    ? 'bg-paid-light text-paid'
                    : 'bg-gradient-to-r from-[#22c55e] to-[#facc15] text-white'}"
              >{o.availability === 'fiskal'
                ? 'Fiskal'
                : o.availability === 'intern'
                  ? 'Intern'
                  : 'Intern & Fiskal'}</span
            >
          {/if}
          {#if o.tag}
            <span class="ml-auto text-[0.6rem] uppercase tracking-wider text-text-muted"
              >{o.tag}</span
            >
          {/if}
          {#if o.trailing !== undefined && o.trailing !== null}
            <span class="ml-auto shrink-0 pl-3 text-xs tabular-nums text-text-muted"
              >{o.trailing}</span
            >
          {/if}
        </button>
      {:else}
        <div class="px-2.5 py-3 text-center text-xs text-text-muted">{emptyText}</div>
      {/each}
    </div>
  {/if}
</div>
