<script lang="ts">
  import type { JournalAuditTrailItem } from '$lib/api/journal.js';

  interface Props {
    items?: JournalAuditTrailItem[];
    onRestore: (item: JournalAuditTrailItem) => void;
  }

  let { items = [], onRestore }: Props = $props();
  let activeId = $state<string | null>(null);
  let expanded = $state(false);

  function formatDate(value: string | null): string {
    if (!value) return '—';
    return new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' }).format(
      new Date(value),
    );
  }
</script>

<section
  class="rounded-lg border border-border-default bg-card-bg p-4 shadow-xs"
  data-testid="journal-audit-trail"
>
  <button
    type="button"
    class="flex w-full items-center gap-2 text-left"
    onclick={() => (expanded = !expanded)}
    aria-expanded={expanded}
    data-testid="journal-audit-toggle"
  >
    <span class="text-[#1b84ff]" aria-hidden="true">
      <img
        src="https://www.svgrepo.com/show/459104/restore.svg"
        alt=""
        width="16"
        height="16"
        class="h-4 w-4"
      />
    </span>
    <span class="text-sm font-bold">Audit Trail</span>
    <span class="rounded-full bg-page-bg px-2 py-0.5 text-[10px] font-semibold text-text-muted"
      >{items.length}</span
    >
    <span class="ml-auto text-xs text-text-muted" aria-hidden="true">{expanded ? '▲' : '▼'}</span>
  </button>

  {#if expanded}
    <p class="mt-1 text-xs text-text-muted">Klik perubahan untuk mengisi formulir dari snapshot.</p>
    {#if items.length === 0}
      <p class="mt-3 text-xs text-text-muted">Belum ada perubahan tersimpan.</p>
    {:else}
      <div class="mt-4">
        {#each items as item, index (item.id)}
          <button
            type="button"
            class="group w-full bg-transparent p-0 text-left"
            onclick={() => {
              activeId = item.id;
              onRestore(item);
            }}
            data-testid="journal-audit-item"
          >
            <span class="relative flex gap-3 pb-4 text-left">
              {#if index < items.length - 1}
                <span
                  class="absolute left-[0.4375rem] top-5 bottom-0 w-px bg-border-default"
                  aria-hidden="true"
                ></span>
              {/if}
              <span
                class="relative z-10 mt-0.5 h-3.5 w-3.5 shrink-0 rounded-full border-2 {activeId ===
                item.id
                  ? 'border-[#1b84ff] bg-[#1b84ff]'
                  : 'border-border-default bg-card-bg'}"
                aria-hidden="true"
              ></span>
              <span class="min-w-0 flex-1">
                <span class="block text-xs font-semibold">{formatDate(item.created_at)}</span>
                <span class="block text-xs text-text-muted">By {item.actor_name}</span>
                {#if item.action === 'journal.reject'}
                  <span class="mt-1 block text-xs font-semibold text-[#a16207]"
                    >Revision Requested</span
                  >
                  {#if item.review_note}
                    <span class="mt-0.5 block text-xs text-text-muted">{item.review_note}</span>
                  {/if}
                {:else if item.attachment_change}
                  <span class="mt-1 block text-xs font-medium text-warning"
                    >{item.attachment_change}</span
                  >
                {:else}
                  <span class="mt-1 block text-xs text-text-muted">Jurnal changed</span>
                {/if}
              </span>
              <span
                class="ml-auto shrink-0 self-center text-xs font-semibold text-[#1b84ff] {activeId ===
                item.id
                  ? 'opacity-100'
                  : 'opacity-0 group-hover:opacity-100'}">Restore</span
              >
            </span>
          </button>
        {/each}
      </div>
    {/if}
  {/if}
</section>
