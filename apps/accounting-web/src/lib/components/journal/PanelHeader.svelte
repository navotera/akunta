<script lang="ts">
  import { formatRupiah } from '@akunta/ui';

  interface Props {
    side: 'debit' | 'credit';
    total: string | number;
  }

  let { side, total }: Props = $props();

  const isDebit = side === 'debit';
  const label = $derived(isDebit ? 'Debit' : 'Kredit');
  const subtitle = $derived(
    isDebit ? 'Bertambahnya aset / beban' : 'Bertambahnya kewajiban / pendapatan / ekuitas',
  );
</script>

<header class="grid grid-cols-[0.25rem_minmax(0,1fr)_auto] items-start gap-3 mb-3">
  <span
    class="block w-1 self-stretch min-h-[2.4rem] rounded-full {isDebit ? 'bg-paid' : 'bg-unpaid'}"
    aria-hidden="true"
  ></span>
  <div>
    <strong class="block text-base font-bold leading-tight {isDebit ? 'text-paid' : 'text-unpaid'}">
      {label}
    </strong>
    <span class="block text-xs text-text-muted leading-snug mt-0.5">{subtitle}</span>
  </div>
  <div class="font-mono tabnum text-base font-bold {isDebit ? 'text-paid' : 'text-unpaid'}">
    {formatRupiah(total)}
  </div>
</header>
