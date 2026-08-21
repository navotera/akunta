<script lang="ts">
  import { formatRupiah, parseRupiah } from '@akunta/ui';
  import type { AccountOption } from '$lib/api/account.js';
  import AccountCombobox from '$lib/components/ui/AccountCombobox.svelte';

  interface Row {
    account_id: string;
    amount: string;
    memo: string | null;
  }

  interface Props {
    row: Row;
    side: 'debit' | 'credit';
    index: number;
    accounts: AccountOption[];
    onChange: (next: Row) => void;
    onRemove: () => void;
  }

  let { row, side, index, accounts, onChange, onRemove }: Props = $props();

  let amountDisplay = $state(
    row.amount && Number(row.amount) > 0 ? formatRupiah(row.amount, { withSymbol: false }) : '',
  );
  let syncedAmount = $state(row.amount);
  let showMemo = $state(false);
  const hasMemo = $derived(Boolean(row.memo?.trim()));

  $effect(() => {
    const nextAmount = row.amount;
    if (nextAmount === syncedAmount) return;

    syncedAmount = nextAmount;
    amountDisplay =
      nextAmount && Number(nextAmount) > 0 ? formatRupiah(nextAmount, { withSymbol: false }) : '';
  });

  function pickAccount(id: string) {
    onChange({ ...row, account_id: id });
  }
  function setMemo(e: Event) {
    onChange({ ...row, memo: (e.target as HTMLInputElement).value || null });
  }
  function setAmount(e: Event) {
    const raw = (e.target as HTMLInputElement).value;
    amountDisplay = raw;
    const parsed = parseRupiah(raw);
    syncedAmount = parsed.toFixed(2);
    onChange({ ...row, amount: parsed.toFixed(2) });
  }
  function blurAmount() {
    if (amountDisplay) {
      amountDisplay = formatRupiah(row.amount, { withSymbol: false });
    }
  }
</script>

<div
  class="grid grid-cols-[1.75rem_minmax(0,5fr)_minmax(7rem,3fr)_auto_auto] items-center gap-2 px-3 py-2 rounded-md border border-border-soft bg-card-bg hover:border-primary transition-colors {showMemo
    ? 'grid-cols-[1.75rem_minmax(0,5fr)_minmax(7rem,3fr)_minmax(0,4fr)_auto_auto]'
    : ''}"
>
  <div
    class="flex items-center justify-center w-7 h-7 rounded-full text-xs font-mono text-text-muted bg-page-bg"
  >
    {index + 1}
  </div>

  <AccountCombobox
    {accounts}
    value={row.account_id}
    testId={`entry-account-${side}-${index}`}
    onSelect={pickAccount}
  />

  <div class="relative">
    <span
      class="pointer-events-none absolute left-2 top-1/2 -translate-y-1/2 text-text-muted text-sm"
      >Rp</span
    >
    <input
      type="text"
      inputmode="decimal"
      class="w-full rounded-md border border-border-default pl-8 pr-2 py-1.5 text-right text-sm font-mono tabnum focus:outline-none focus:border-primary"
      placeholder="0"
      value={amountDisplay}
      oninput={setAmount}
      onblur={blurAmount}
      data-testid="entry-amount"
    />
  </div>

  {#if showMemo}
    <input
      type="text"
      class="w-full rounded-md border border-border-default px-2 py-1.5 text-sm focus:outline-none focus:border-primary"
      placeholder="Catatan (opsional)"
      value={row.memo ?? ''}
      oninput={setMemo}
      data-testid="entry-memo"
    />
  {/if}

  <button
    type="button"
    class="flex h-7 w-7 items-center justify-center rounded transition-colors {hasMemo
      ? 'text-[#eab308] hover:bg-warning-light hover:text-[#ca8a04]'
      : 'text-text-muted hover:bg-primary-light hover:text-primary'}"
    onclick={() => (showMemo = !showMemo)}
    aria-label={showMemo ? 'Sembunyikan catatan' : 'Tampilkan catatan'}
    aria-pressed={showMemo}
    title={showMemo ? 'Sembunyikan catatan' : 'Tampilkan catatan'}
    data-testid="toggle-entry-memo"
  >
    <svg
      width="16"
      height="16"
      viewBox="0 0 16 16"
      fill="none"
      stroke="currentColor"
      stroke-width="1.4"
      aria-hidden="true"
    >
      <path d="M3.5 2.5h6l3 3v8h-9v-11Z" stroke-linejoin="round" />
      <path d="M9.5 2.8v3h2.7M5.5 8h5M5.5 10.5h5" stroke-linecap="round" />
    </svg>
  </button>

  <button
    type="button"
    class="flex items-center justify-center w-7 h-7 rounded text-text-muted hover:text-danger hover:bg-danger-light transition-colors"
    onclick={onRemove}
    aria-label="Hapus baris"
  >
    <svg
      width="16"
      height="16"
      viewBox="0 0 16 16"
      fill="none"
      stroke="currentColor"
      stroke-width="1.6"
    >
      <path
        d="M3 4h10M6 4V2.5h4V4M6 7v5M10 7v5M4.5 4l1 9.5h5l1-9.5"
        stroke-linecap="round"
        stroke-linejoin="round"
      />
    </svg>
  </button>
</div>
