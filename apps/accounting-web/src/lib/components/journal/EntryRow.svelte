<script lang="ts">
  import { formatRupiah, parseRupiah } from '@akunta/ui';
  import type { AccountOption } from '$lib/api/account.js';

  interface Row {
    account_id: string;
    amount: string;
    memo: string | null;
  }

  interface Props {
    row: Row;
    index: number;
    accounts: AccountOption[];
    onChange: (next: Row) => void;
    onRemove: () => void;
  }

  let { row, index, accounts, onChange, onRemove }: Props = $props();

  let amountDisplay = $state(row.amount && Number(row.amount) > 0 ? formatRupiah(row.amount, { withSymbol: false }) : '');

  function setAccount(e: Event) {
    onChange({ ...row, account_id: (e.target as HTMLSelectElement).value });
  }
  function setMemo(e: Event) {
    onChange({ ...row, memo: (e.target as HTMLInputElement).value || null });
  }
  function setAmount(e: Event) {
    const raw = (e.target as HTMLInputElement).value;
    amountDisplay = raw;
    const parsed = parseRupiah(raw);
    onChange({ ...row, amount: parsed.toFixed(2) });
  }
  function blurAmount() {
    if (amountDisplay) {
      amountDisplay = formatRupiah(row.amount, { withSymbol: false });
    }
  }
</script>

<div class="grid grid-cols-[1.75rem_5fr_4fr_3fr_1.75rem] items-center gap-2 px-3 py-2 rounded-md border border-border-soft bg-card-bg hover:border-primary transition-colors">
  <div class="flex items-center justify-center w-7 h-7 rounded-full text-xs font-mono text-text-muted bg-page-bg">
    {index + 1}
  </div>

  <select
    class="w-full rounded-md border border-border-default px-2 py-1.5 text-sm focus:outline-none focus:border-primary"
    value={row.account_id}
    onchange={setAccount}
    data-testid="entry-account"
  >
    <option value="">Pilih akun…</option>
    {#each accounts as a (a.id)}
      <option value={a.id}>{a.code} — {a.name}</option>
    {/each}
  </select>

  <input
    type="text"
    class="w-full rounded-md border border-border-default px-2 py-1.5 text-sm focus:outline-none focus:border-primary"
    placeholder="Keterangan baris (opsional)"
    value={row.memo ?? ''}
    oninput={setMemo}
    data-testid="entry-memo"
  />

  <div class="relative">
    <span class="pointer-events-none absolute left-2 top-1/2 -translate-y-1/2 text-text-muted text-sm">Rp</span>
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

  <button
    type="button"
    class="flex items-center justify-center w-7 h-7 rounded text-text-muted hover:text-danger hover:bg-danger-light transition-colors"
    onclick={onRemove}
    aria-label="Hapus baris"
  >
    <svg width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6">
      <path d="M3 4h10M6 4V2.5h4V4M6 7v5M10 7v5M4.5 4l1 9.5h5l1-9.5" stroke-linecap="round" stroke-linejoin="round" />
    </svg>
  </button>
</div>
