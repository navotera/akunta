<script lang="ts">
  import type { JournalTemplateSummary } from '$lib/api/template.js';

  interface Props {
    templates: JournalTemplateSummary[];
    onPick?: (t: JournalTemplateSummary) => void;
  }
  let { templates, onPick }: Props = $props();
</script>

<section
  class="rounded-lg border border-border-default bg-card-bg p-4 shadow-xs"
  data-testid="template-sidebar"
>
  <header class="mb-3 flex items-start justify-between">
    <div>
      <strong class="block text-sm font-bold leading-tight text-text-default">
        <span class="text-primary mr-1">✦</span> Mulai dari template
      </strong>
      <span class="block text-xs text-text-muted leading-snug mt-1"
        >Isi otomatis akun debit & kredit</span
      >
    </div>
  </header>

  <div class="grid gap-2">
    {#if templates.length > 0}
      {#each templates as template (template.id)}
        <button
          type="button"
          class="grid grid-cols-[2rem_minmax(0,1fr)_auto] items-center gap-2 rounded-md border border-border-soft bg-card-bg p-2.5 text-left transition-colors hover:border-primary hover:bg-page-bg"
          onclick={() => onPick?.(template)}
          data-testid={`journal-template-${template.code}`}
        >
          <span
            class="flex h-8 w-8 items-center justify-center rounded-md bg-primary-light text-primary font-bold"
          >
            T
          </span>
          <span class="min-w-0">
            <strong class="block truncate text-sm font-bold text-text-default"
              >{template.name}</strong
            >
            <span class="flex items-center gap-2 truncate text-xs text-text-muted">
              <span
                class="rounded-full px-1.5 py-0.5 text-[10px] font-semibold {template.journal_mode ===
                'fiscal'
                  ? 'bg-warning-light text-warning'
                  : 'bg-primary-light text-primary'}"
                >{template.journal_mode === 'fiscal' ? 'Fiskal' : 'Intern'}</span
              >
              <span class="truncate">{template.description ?? `${template.lines_count} baris`}</span
              >
            </span>
          </span>
          <span class="text-text-muted/60">→</span>
        </button>
      {/each}
    {:else}
      <div class="rounded-md border border-dashed border-border-soft px-3 py-4 text-center">
        <p class="text-xs text-text-muted">Belum ada template untuk mode ini.</p>
        <a
          class="mt-2 inline-block text-xs font-semibold text-primary hover:text-primary-active"
          href="/template-jurnal">Buat template →</a
        >
      </div>
    {/if}
  </div>

  <a
    class="mt-3 block text-center text-xs font-semibold text-text-muted hover:text-primary"
    href="/template-jurnal"
  >
    Lihat semua template →
  </a>
</section>
