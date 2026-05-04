<script lang="ts">
  import { formatRupiah } from '@akunta/ui';

  interface Props {
    debits: Array<{ amount: string }>;
    credits: Array<{ amount: string }>;
  }
  let { debits, credits }: Props = $props();

  const sumD = $derived(debits.reduce((s, r) => s + Number(r.amount || 0), 0));
  const sumC = $derived(credits.reduce((s, r) => s + Number(r.amount || 0), 0));
  const diff = $derived(sumD - sumC);
  const balanced = $derived(sumD > 0 && Math.abs(diff) < 0.005);
</script>

{#if balanced}
  <span class="inline-flex items-center gap-2 rounded-full bg-paid-light px-3 py-1 text-xs font-bold text-paid" data-testid="balance-pill">
    <svg width="12" height="12" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 8.5l3 3 7-7" stroke-linecap="round" stroke-linejoin="round" /></svg>
    Jurnal Balance
  </span>
{:else}
  <span class="inline-flex items-center gap-2 rounded-full bg-page-bg px-3 py-1 text-xs font-bold text-text-muted" data-testid="balance-pill">
    <span class="w-2 h-2 rounded-full bg-text-muted opacity-60"></span>
    {sumD === 0 && sumC === 0 ? 'Belum diisi' : `Selisih ${formatRupiah(Math.abs(diff))}`}
  </span>
{/if}
