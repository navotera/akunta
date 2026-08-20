<script lang="ts">
  import { formatRupiah } from '@akunta/ui';
  import type { JournalSummary } from '$lib/api/journal.js';
  import { formatDate } from '$lib/utils/date.js';

  interface Props {
    recent: JournalSummary | null;
  }
  let { recent }: Props = $props();

</script>

<section class="rounded-lg border border-border-default bg-card-bg p-4 shadow-xs" data-testid="recent-journal">
  <header class="mb-3">
    <strong class="block text-sm font-bold leading-tight text-text-default">Jurnal Terakhir</strong>
    <span class="block text-xs text-text-muted leading-snug mt-1">Klik untuk duplikat sebagai dasar</span>
  </header>

  {#if recent}
    <a
      href={`/journals/${recent.id}`}
      class="block rounded-md border border-border-soft p-3 hover:border-primary hover:bg-page-bg transition-colors"
    >
      <div class="flex items-baseline justify-between text-xs text-text-muted font-mono tabnum mb-1">
        <span>{recent.number}</span>
        <span>{formatDate(recent.date)}</span>
      </div>
      <strong class="block truncate text-sm font-bold text-text-default">{recent.memo ?? 'Tanpa keterangan'}</strong>
      <small class="mt-1 block text-xs text-text-muted font-mono tabnum">{formatRupiah(recent.total)}</small>
    </a>
  {:else}
    <div class="rounded-md border border-dashed border-border-default p-3 text-xs text-text-muted">
      Belum ada jurnal terakhir.
    </div>
  {/if}
</section>
