<script lang="ts">
  import { onMount } from 'svelte';
  import {
    platformUsageApi,
    type OrganizationUsage,
    type PlatformUsage,
    type UsageWebhookOutcome,
  } from '$lib/api/platform-usage.js';
  import { formatDateTime } from '$lib/utils/date.js';

  // Platform Owner only (the parent gates it and the endpoint answers 403
  // otherwise). Shows metadata and counts per organisation - never transaction
  // content: the backend does not send any.
  let usage = $state<PlatformUsage | null>(null);
  let loading = $state(false);
  let error = $state<string | null>(null);

  const outcomeLabels: Record<UsageWebhookOutcome, string> = {
    processed: 'Diproses',
    already_processed: 'Duplikat aman',
    retryable: 'Perlu retry',
    rejected: 'Ditolak',
    unauthorized: 'Signature gagal',
    error: 'Error server',
  };

  async function load() {
    loading = true;
    error = null;
    try {
      usage = await platformUsageApi.show();
    } catch (caught) {
      error = caught instanceof Error ? caught.message : String(caught);
    } finally {
      loading = false;
    }
  }

  function journalTotal(organization: OrganizationUsage, book: 'internal' | 'fiscal'): number {
    return organization.journals.reduce((sum, month) => sum + month[book], 0);
  }

  function webhookSummary(stats: OrganizationUsage['webhooks']): string {
    const parts = (Object.keys(outcomeLabels) as UsageWebhookOutcome[])
      .filter((outcome) => (stats.by_outcome[outcome] ?? 0) > 0)
      .map((outcome) => `${outcomeLabels[outcome]} ${stats.by_outcome[outcome]}`);

    return parts.length > 0 ? parts.join(' · ') : 'Tidak ada';
  }

  function when(value: string | null): string {
    return value ? formatDateTime(value) : 'Belum ada';
  }

  onMount(() => {
    void load();
  });
</script>

<div
  class="mt-5 overflow-hidden rounded-lg border border-border-default"
  data-testid="platform-usage"
>
  <div
    class="flex flex-wrap items-center justify-between gap-3 border-b border-border-default px-4 py-3"
  >
    <div>
      <h3 class="text-sm font-semibold text-text-default">Penggunaan per organisasi</h3>
      <p class="mt-1 text-xs text-text-muted">
        Hanya metadata dan jumlah ({usage?.window_days ?? 30} hari terakhir untuk webhook dan pengaturan).
        Isi transaksi, nama akun, dan data pribadi tidak ditampilkan.
      </p>
    </div>
    <button
      type="button"
      class="rounded-md border border-border-default px-3 py-2 text-xs font-semibold hover:border-primary hover:text-primary"
      onclick={load}
      disabled={loading}
    >
      {loading ? 'Memuat…' : 'Muat ulang penggunaan'}
    </button>
  </div>

  {#if error}
    <p class="m-4 rounded-md border border-danger bg-danger-light p-3 text-sm text-danger">
      {error}
    </p>
  {:else if !usage}
    <p class="px-4 py-5 text-sm text-text-muted">Memuat penggunaan…</p>
  {:else}
    <div class="grid gap-3 border-b border-border-soft px-4 py-3 text-xs md:grid-cols-3">
      <p>
        <span class="font-semibold text-text-default">Antrean</span>
        <span class="text-text-muted">
          · menunggu {usage.installation.queue.pending_jobs ?? '—'} · gagal
          {usage.installation.queue.failed_jobs ?? '—'} ({usage.installation.queue
            .failed_jobs_in_window ?? '—'} dalam {usage.window_days} hari)
        </span>
      </p>
      <p>
        <span class="font-semibold text-text-default">Cron</span>
        <span class="text-text-muted">
          · gagal {usage.installation.cron.failed_runs_in_window ?? '—'} kali; terakhir
          {when(usage.installation.cron.last_failed_at)}
        </span>
      </p>
      <p>
        <span class="font-semibold text-text-default">Webhook Ecopa</span>
        <span class="text-text-muted">
          · {usage.installation.webhooks.total} diterima, {usage.installation.webhooks.unattributed}
          tanpa organisasi
        </span>
      </p>
    </div>
    <div class="overflow-x-auto">
      <table class="ak-table min-w-[960px]">
        <thead>
          <tr>
            <th>Organisasi</th>
            <th>Login terakhir</th>
            <th class="text-right">User aktif</th>
            <th class="text-right">Workspace</th>
            <th class="text-right">Jurnal ({usage.journal_months.length} bln)</th>
            <th>Webhook</th>
            <th>Aktivitas pengaturan</th>
          </tr>
        </thead>
        <tbody>
          {#each usage.organizations as organization (organization.tenant_id)}
            <tr>
              <td>
                <p class="text-sm font-semibold text-text-default">{organization.name}</p>
                <p class="mt-1 text-[11px] text-text-muted">
                  {organization.linked
                    ? `Ecopa ${organization.ecopa_organization_id}`
                    : 'Belum tertaut ke organisasi Ecopa'}
                </p>
              </td>
              <td class="whitespace-nowrap text-xs">{when(organization.last_login_at)}</td>
              <td class="text-right font-mono text-xs">{organization.active_users}</td>
              <td class="text-right text-xs">
                {organization.workspaces.active} aktif
                {#if organization.workspaces.archived > 0}
                  <span class="text-text-muted">· {organization.workspaces.archived} arsip</span>
                {/if}
              </td>
              <td class="text-right text-xs">
                Intern {journalTotal(organization, 'internal')} · Fiskal
                {journalTotal(organization, 'fiscal')}
              </td>
              <td class="text-xs">
                {webhookSummary(organization.webhooks)}
                {#if organization.webhooks.last_received_at}
                  <p class="mt-1 text-[11px] text-text-muted">
                    Terakhir {when(organization.webhooks.last_received_at)}
                  </p>
                {/if}
              </td>
              <td class="text-xs">
                {#each organization.setting_activity as activity (activity.action)}
                  <p>
                    <code class="font-semibold text-primary">{activity.action}</code>
                    <span class="text-text-muted">×{activity.count}</span>
                  </p>
                {:else}
                  <span class="text-text-muted">Tidak ada</span>
                {/each}
              </td>
            </tr>
          {:else}
            <tr>
              <td colspan="7" class="py-8 text-center text-text-muted">Belum ada organisasi.</td>
            </tr>
          {/each}
        </tbody>
      </table>
    </div>
  {/if}
</div>
