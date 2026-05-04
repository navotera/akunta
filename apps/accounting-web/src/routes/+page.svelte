<script lang="ts">
  import { onMount } from 'svelte';
  import { goto } from '$app/navigation';
  import { auth } from '$lib/stores/auth.svelte.js';

  onMount(async () => {
    const u = await auth.refresh();
    goto(u ? '/dashboard' : '/login', { replaceState: true });
  });
</script>

<div class="flex min-h-screen items-center justify-center text-text-muted">
  Memuat…
</div>
