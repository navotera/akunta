<script lang="ts">
  import { accountApi, type Account, type AccountJournalMode } from '$lib/api/account.js';

  interface Props {
    tenantSlug?: string | null;
    value: string;
    placeholder?: string;
    onSelect: (id: string) => void;
    journalMode?: AccountJournalMode;
    testId?: string;
  }

  let {
    tenantSlug,
    value,
    placeholder = 'Cari akun dari COA…',
    onSelect,
    journalMode = 'internal',
    testId,
  }: Props = $props();

  let open = $state(false);
  let query = $state('');
  let accounts = $state<Account[]>([]);
  let selectedAccount = $state<Account | null>(null);
  let loading = $state(false);
  let highlight = $state(0);
  let triggerEl = $state<HTMLButtonElement>();
  let inputEl = $state<HTMLInputElement>();
  let listEl = $state<HTMLDivElement>();
  let menuStyle = $state('');
  let searchTimer: number | undefined;
  let searchRequest = 0;

  const display = $derived(
    selectedAccount ? `${selectedAccount.code} — ${selectedAccount.name}` : '',
  );

  $effect(() => {
    if (!value) {
      selectedAccount = null;
      return;
    }
    if (selectedAccount?.id === value) return;
    accountApi
      .show(value, tenantSlug)
      .then((account) => (selectedAccount = account))
      .catch(() => (selectedAccount = null));
  });

  $effect(() => {
    if (!open) return;
    const reposition = () => updatePosition();
    window.addEventListener('resize', reposition);
    window.addEventListener('scroll', reposition, true);
    return () => {
      window.removeEventListener('resize', reposition);
      window.removeEventListener('scroll', reposition, true);
    };
  });

  async function search(searchTerm: string) {
    const requestId = ++searchRequest;
    loading = true;
    try {
      const term = searchTerm.trim().toLowerCase();
      // Only postable COA accounts can be used as journal lines.
      let result = await accountApi.list(searchTerm.trim(), tenantSlug, true);

      // Keep the combobox useful with older API instances that do not apply
      // the search query consistently: fetch the COA and filter it locally.
      if (term && result.length === 0) {
        const allAccounts = await accountApi.list('', tenantSlug, true);
        result = allAccounts.filter((account) =>
          `${account.code} ${account.name}`.toLowerCase().includes(term),
        );
      }

      if (requestId !== searchRequest) return;
      accounts = result;
      highlight = 0;
    } catch {
      if (requestId === searchRequest) accounts = [];
    } finally {
      loading = false;
    }
  }

  function updatePosition() {
    const rect = triggerEl?.getBoundingClientRect();
    if (!rect) return;
    menuStyle = `top:${rect.bottom + 4}px;left:${rect.left}px;width:${rect.width}px;`;
  }

  function openList() {
    open = true;
    query = '';
    updatePosition();
    queueMicrotask(() => {
      inputEl?.focus();
      search('');
    });
  }

  function closeList() {
    open = false;
    query = '';
    if (searchTimer) window.clearTimeout(searchTimer);
  }

  function onQueryInput(event: Event) {
    query = (event.currentTarget as HTMLInputElement).value;
    if (searchTimer) window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => search(query), 250);
  }

  function pick(account: Account) {
    if (!isSelectable(account)) return;
    selectedAccount = account;
    onSelect(account.id);
    closeList();
  }

  function isSelectable(account: Account) {
    return (
      account.availability === 'both' ||
      account.availability === (journalMode === 'fiscal' ? 'fiskal' : 'intern')
    );
  }

  function onKey(event: KeyboardEvent) {
    if (!open) return;
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      highlight = Math.min(highlight + 1, accounts.length - 1);
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      highlight = Math.max(highlight - 1, 0);
    } else if (event.key === 'Enter') {
      event.preventDefault();
      if (accounts[highlight]) pick(accounts[highlight]);
    } else if (event.key === 'Escape') {
      event.preventDefault();
      closeList();
    }
  }

  function clickOutside(node: HTMLElement) {
    function onDocument(event: MouseEvent) {
      if (!node.contains(event.target as Node)) closeList();
    }
    document.addEventListener('mousedown', onDocument);
    return { destroy: () => document.removeEventListener('mousedown', onDocument) };
  }
</script>

<div class="relative w-full" use:clickOutside>
  <button
    bind:this={triggerEl}
    type="button"
    class="h-9 min-h-9 w-full rounded-md border border-[#dbdfe9] bg-white px-2 text-left text-[12px] outline-none focus:border-[#1b84ff] focus:ring-4 focus:ring-[#1b84ff]/10 {selectedAccount
      ? 'text-[#252f4a]'
      : 'text-[#78829d]'}"
    onclick={openList}
    data-testid={testId}
  >
    <span class="block truncate">{display || placeholder}</span>
  </button>

  {#if open}
    <div
      bind:this={listEl}
      class="fixed z-[100] max-h-72 overflow-y-auto rounded-md border border-[#dbdfe9] bg-white shadow-xl"
      style={menuStyle}
      role="listbox"
    >
      <div class="sticky top-0 border-b border-[#e5e7eb] bg-white p-2">
        <input
          bind:this={inputEl}
          class="h-8 w-full rounded-md border border-[#dbdfe9] px-2 text-[12px] outline-none focus:border-[#1b84ff]"
          placeholder="Cari kode atau nama akun…"
          bind:value={query}
          oninput={onQueryInput}
          onkeydown={onKey}
        />
      </div>
      {#if loading}
        <div class="px-3 py-3 text-center text-[12px] text-[#78829d]">Mencari akun…</div>
      {:else if accounts.length === 0}
        <div class="px-3 py-3 text-center text-[12px] text-[#78829d]">Akun tidak ditemukan.</div>
      {:else}
        {#each accounts as account, index (account.id)}
          <button
            type="button"
            class="flex w-full items-center gap-2 px-3 py-2 text-left text-[12px] {isSelectable(
              account,
            )
              ? 'hover:bg-[#eff6ff]'
              : 'cursor-not-allowed opacity-50'} {index === highlight ? 'bg-[#eff6ff]' : ''}"
            onclick={() => pick(account)}
            onmouseenter={() => (highlight = index)}
            disabled={!isSelectable(account)}
            role="option"
            aria-selected={account.id === value}
            aria-disabled={!isSelectable(account)}
          >
            {#if account.is_fake}
              <span
                class="h-1.5 w-1.5 shrink-0 rounded-full bg-[#99a1b7]"
                title="Akun hasil import fake data"
                aria-label="Akun fake"
              ></span>
            {:else}
              <span class="h-1.5 w-1.5 shrink-0" aria-hidden="true"></span>
            {/if}
            <span class="w-20 shrink-0 font-mono text-[11px] text-[#78829d]">{account.code}</span>
            <span class="truncate text-[#252f4a]">{account.name}</span>
            {#if account.availability !== 'both'}
              <span
                class="ml-auto shrink-0 rounded-full px-1.5 py-0.5 text-[10px] font-semibold {account.availability ===
                'fiskal'
                  ? 'bg-[#fff1f2] text-[#f8285a]'
                  : 'bg-[#eff6ff] text-[#1b84ff]'}"
                >{account.availability === 'fiskal' ? 'Fiskal' : 'Intern'}</span
              >
            {/if}
          </button>
        {/each}
      {/if}
    </div>
  {/if}
</div>
