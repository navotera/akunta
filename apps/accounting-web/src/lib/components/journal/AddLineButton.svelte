<script lang="ts">
  interface Props {
    side: 'debit' | 'credit';
    onclick: () => void;
  }
  let { side, onclick }: Props = $props();
  const colorCls = side === 'debit'
    ? 'border-paid text-paid bg-paid-light/40 hover:bg-paid-light'
    : 'border-unpaid text-unpaid bg-unpaid-light/40 hover:bg-unpaid-light';
</script>

<button
  type="button"
  {onclick}
  class="mt-2 flex w-full items-center justify-center gap-1.5 rounded-md border border-dashed px-4 py-2.5 text-sm font-semibold transition-colors {colorCls}"
  data-testid="add-line-{side}"
>
  + Tambah baris {side === 'debit' ? 'debit' : 'kredit'}
</button>
