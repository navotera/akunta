<script lang="ts">
  interface Props {
    title: string;
    breadcrumb: string;
    subtitle?: string | null;
    actions?: import('svelte').Snippet;
    toolbar?: import('svelte').Snippet;
    children?: import('svelte').Snippet;
  }

  let { title, breadcrumb, subtitle = null, actions, toolbar, children }: Props = $props();
</script>

<div class="px-6 py-6">
  <header class="mb-4">
    <p class="text-xs font-medium text-text-muted">{breadcrumb}</p>
    <div class="mt-1 flex items-baseline justify-between gap-4">
      <div>
        <h1 class="text-2xl font-bold leading-tight text-text-default">{title}</h1>
        {#if subtitle}
          <p class="mt-0.5 text-sm text-text-muted">{subtitle}</p>
        {/if}
      </div>
      {#if actions}
        <div class="flex items-center gap-2">{@render actions()}</div>
      {/if}
    </div>
  </header>

  {#if toolbar}
    <section class="mb-4 flex flex-wrap items-end gap-3 rounded-lg border border-border-default bg-card-bg p-3">
      {@render toolbar()}
    </section>
  {/if}

  <section class="rounded-lg border border-border-default bg-card-bg shadow-xs">
    {@render children?.()}
  </section>
</div>
