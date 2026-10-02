<script lang="ts">
  import { onMount } from 'svelte';
  import { schedulerApi, type SchedulerHealth } from '$lib/api/scheduler.js';
  import { auth } from '$lib/stores/auth.svelte.js';
  import { tenant } from '$lib/stores/tenant.svelte.js';

  let schedulerHealth = $state<SchedulerHealth | null>(null);
  let schedulerLoading = $state(false);
  let loadedFor = $state<string | null>(null);
  let poll: number | null = null;

  const canManageCron = $derived(
    Boolean(
      auth.user?.is_sso_admin ||
      auth.user?.tenants.find((item) => item.id === tenant.id)?.can_manage_cron,
    ),
  );

  async function loadSchedulerHealth() {
    if (!canManageCron || !tenant.id || schedulerLoading) return;

    schedulerLoading = true;
    try {
      schedulerHealth = await schedulerApi.status(tenant.id);
    } catch {
      // The endpoint is permission-protected. Do not expose an alert when the
      // current session cannot read scheduler health or the request is offline.
      schedulerHealth = null;
    } finally {
      schedulerLoading = false;
    }
  }

  $effect(() => {
    const userId = auth.user?.id;
    const entityId = tenant.id;
    const accessKey = userId && entityId ? `${userId}:${entityId}` : null;

    if (!accessKey || !canManageCron) {
      schedulerHealth = null;
      loadedFor = null;
      return;
    }

    if (loadedFor !== accessKey) {
      loadedFor = accessKey;
      void loadSchedulerHealth();
    }
  });

  onMount(() => {
    poll = window.setInterval(() => void loadSchedulerHealth(), 60_000);

    return () => {
      if (poll !== null) window.clearInterval(poll);
    };
  });
</script>

{#if schedulerHealth && !schedulerHealth.scheduler.healthy}
  <div
    class="flex flex-wrap items-center justify-between gap-3 border-b border-danger/30 bg-danger-light px-6 py-2.5 text-sm text-danger"
    role="alert"
    data-testid="scheduler-alert"
  >
    <span>
      <strong>Scheduler tidak terdeteksi.</strong>
      Cron server belum menjalankan scheduler dalam {schedulerHealth.scheduler.age_seconds ?? '—'} detik
      terakhir. Pastikan cron OS menjalankan
      <code class="font-semibold">php artisan schedule:run</code>
      setiap menit.
    </span>
    <a
      href="/settings?section=cron"
      class="shrink-0 font-semibold underline underline-offset-2 hover:text-danger/80"
      data-testid="scheduler-alert-detail">Detail</a
    >
  </div>
{/if}
