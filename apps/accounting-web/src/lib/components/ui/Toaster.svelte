<script lang="ts">
  import { toast, type ToastKind } from '$lib/stores/toast.svelte.js';

  const KIND_STYLE: Record<ToastKind, { bg: string; bar: string; icon: string; text: string }> = {
    success: { bg: 'bg-white', bar: 'bg-success', icon: '✓', text: 'text-success' },
    error: { bg: 'bg-white', bar: 'bg-danger', icon: '✕', text: 'text-danger' },
    info: { bg: 'bg-white', bar: 'bg-primary', icon: 'ℹ', text: 'text-primary' },
    warning: { bg: 'bg-white', bar: 'bg-warning', icon: '!', text: 'text-warning' },
  };
</script>

<div
  class="pointer-events-none fixed top-4 right-4 z-[100] flex w-[22rem] max-w-[calc(100vw-2rem)] flex-col gap-2"
  role="region"
  aria-label="Notifikasi"
  aria-live="polite"
>
  {#each toast.items as t (t.id)}
    {@const s = KIND_STYLE[t.kind]}
    <div
      class="pointer-events-auto flex overflow-hidden rounded-md border border-border-default {s.bg} shadow-lg"
      role={t.kind === 'error' ? 'alert' : 'status'}
    >
      <span class="w-1 shrink-0 {s.bar}" aria-hidden="true"></span>
      <span
        class="flex h-9 w-9 shrink-0 items-center justify-center text-base font-bold {s.text}"
        aria-hidden="true"
      >
        {s.icon}
      </span>
      <div class="flex min-w-0 flex-1 flex-col gap-0.5 py-2 pr-2">
        {#if t.title}
          <strong class="truncate text-sm font-semibold text-text-strong">{t.title}</strong>
        {/if}
        <span class="text-sm text-text-default">{t.message}</span>
      </div>
      <button
        type="button"
        class="shrink-0 px-3 text-text-muted hover:text-text-strong"
        onclick={() => toast.dismiss(t.id)}
        aria-label="Tutup notifikasi"
      >
        ✕
      </button>
    </div>
  {/each}
</div>
