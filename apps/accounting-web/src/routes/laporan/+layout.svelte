<script lang="ts">
  import ReportTabs from '$lib/components/reporting/ReportTabs.svelte';

  let { children } = $props();
</script>

<ReportTabs />
{@render children?.()}
