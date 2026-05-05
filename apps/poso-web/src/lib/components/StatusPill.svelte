<script lang="ts">
  import { statusLabel, type TransactionRow } from '$lib/data/fixtures';

  let { status }: { status: TransactionRow['status'] } = $props();

  const classes = {
    paid: 'bg-green-soft text-green',
    partial: 'bg-amber-soft text-amber',
    unpaid: 'bg-red-soft text-red'
  };
</script>

<span class={`inline-flex min-w-24 justify-center rounded-md px-2.5 py-1 text-xs font-semibold ${classes[status]}`}>
  {statusLabel(status)}
</span>

