<script lang="ts">
  import type { JournalTemplateSummary } from '$lib/api/template.js';

  interface Props {
    templates: JournalTemplateSummary[];
    onPick?: (t: JournalTemplateSummary) => void;
  }
  let { templates, onPick }: Props = $props();

  // Fallback quick-starts when no templates exist yet for the tenant.
  const quickStarts = [
    { code: 'qs-purchase', name: 'Pembelian Persediaan', description: 'Persediaan ↔ Utang/Kas', icon: '📦' },
    { code: 'qs-payroll', name: 'Pembayaran Gaji', description: 'Beban Gaji ↔ Bank', icon: '💰' },
    { code: 'qs-receivable', name: 'Pelunasan Piutang', description: 'Bank ↔ Piutang Usaha', icon: '💵' },
    { code: 'qs-rent', name: 'Pembayaran Sewa', description: 'Beban Sewa ↔ Bank', icon: '🏠' },
  ];

  const items = $derived.by(() => {
    if (templates.length > 0) {
      return templates.map((t) => ({
        code: t.code,
        name: t.name,
        description: t.description ?? `${t.lines_count} baris`,
        icon: 'T',
        template: t,
      }));
    }
    return quickStarts.map((qs) => ({ ...qs, template: undefined }));
  });
</script>

<section class="rounded-lg border border-border-default bg-card-bg p-4 shadow-xs" data-testid="template-sidebar">
  <header class="mb-3 flex items-start justify-between">
    <div>
      <strong class="block text-sm font-bold leading-tight text-text-default">
        <span class="text-primary mr-1">✦</span> Mulai dari template
      </strong>
      <span class="block text-xs text-text-muted leading-snug mt-1">Isi otomatis akun debit & kredit</span>
    </div>
  </header>

  <div class="grid gap-2">
    {#each items as it (it.code)}
      <button
        type="button"
        class="grid grid-cols-[2rem_minmax(0,1fr)_auto] items-center gap-2 rounded-md border border-border-soft bg-card-bg p-2.5 text-left transition-colors hover:border-primary hover:bg-page-bg"
        onclick={() => it.template && onPick?.(it.template)}
        disabled={!it.template}
      >
        <span class="flex h-8 w-8 items-center justify-center rounded-md bg-primary-light text-primary font-bold">
          {it.icon}
        </span>
        <span class="min-w-0">
          <strong class="block truncate text-sm font-bold text-text-default">{it.name}</strong>
          <span class="block truncate text-xs text-text-muted">{it.description}</span>
        </span>
        <span class="text-text-muted/60">→</span>
      </button>
    {/each}
  </div>

  <a class="mt-3 block text-center text-xs font-semibold text-text-muted hover:text-primary" href="/journal-templates">
    Lihat semua template →
  </a>
</section>
