<script lang="ts">
  import { goto } from '$app/navigation';
  import { onMount } from 'svelte';

  onMount(() => {
    goto('/sales', { replaceState: true });
  });
</script>

<div class="grid min-h-screen place-items-center text-sm text-muted">Membuka POSO...</div>

