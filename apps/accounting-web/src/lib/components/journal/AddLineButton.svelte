<script lang="ts">
  interface Props {
    side: 'debit' | 'credit';
    onclick: () => void;
  }
  let { side, onclick }: Props = $props();
  const colorCls = 'border-border-soft bg-page-bg/70 text-[#1b84ff] hover:bg-page-bg hover:text-[#056ee9]';
</script>

<button
  type="button"
  {onclick}
  class="mt-2 flex w-full items-center justify-center gap-1.5 rounded-md border border-dashed px-4 py-2.5 text-sm font-normal transition-colors {colorCls}"
  data-testid="add-line-{side}"
>
  + Tambah baris {side === 'debit' ? 'debit' : 'kredit'}
</button>
