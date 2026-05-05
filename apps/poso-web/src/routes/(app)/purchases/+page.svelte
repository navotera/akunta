<script lang="ts">
  import TransactionListPage from '$lib/components/TransactionListPage.svelte';
  import { purchaseRows } from '$lib/data/fixtures';
</script>

<TransactionListPage mode="purchases" rows={purchaseRows} />

