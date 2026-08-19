<script lang="ts">
  import AutoMappingDocumentation from './auto-mapping/+page.svelte';

  type DocumentationSection = 'auto-mapping';
  let activeSection = $state<DocumentationSection>('auto-mapping');
  const sections = [{ id: 'auto-mapping' as const, label: 'Auto Mapping', description: 'Mapping transaksi JSON ke jurnal' }];
</script>

<div class="ak-settings-page flex min-h-full gap-6 px-6 py-6">
  <aside class="w-64 shrink-0">
    <h1 class="text-xl font-bold">Documentation</h1>
    <p class="mt-1 text-sm text-text-muted">Panduan penggunaan fitur Akunta.</p>
    <nav class="mt-6 space-y-1" aria-label="Documentation sections">
      {#each sections as section (section.id)}
        <button type="button" class="flex w-full items-start gap-3 rounded-md px-3 py-3 text-left transition-colors {activeSection === section.id ? 'bg-primary/10 text-primary' : 'text-text-default hover:bg-page-bg'}" onclick={() => (activeSection = section.id)}>
          <span class="mt-0.5 text-base">▤</span>
          <span><span class="block text-sm font-semibold">{section.label}</span><span class="mt-0.5 block text-xs text-text-muted">{section.description}</span></span>
        </button>
      {/each}
    </nav>
  </aside>
  <section class="min-w-0 flex-1 rounded-lg border border-border-soft bg-card-bg">
    {#if activeSection === 'auto-mapping'}
      <AutoMappingDocumentation />
    {/if}
  </section>
</div>
