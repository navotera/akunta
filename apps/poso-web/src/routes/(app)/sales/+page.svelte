<script lang="ts">
  import TransactionListPage from '$lib/components/TransactionListPage.svelte';
  import { salesRows } from '$lib/data/fixtures';
</script>

<TransactionListPage mode="sales" rows={salesRows} />

