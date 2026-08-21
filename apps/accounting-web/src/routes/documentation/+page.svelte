<script lang="ts">
  import {
    documentationArticles,
    documentationCategories,
    searchableArticleText,
    type DocumentationArticle,
    type DocumentationCategoryId,
    type DocumentationSection,
  } from '$lib/documentation/content.js';

  let activeArticleId = $state('overview');
  let query = $state('');
  let navigationOpen = $state(false);

  const activeArticle = $derived(
    documentationArticles.find((article) => article.id === activeArticleId) ?? documentationArticles[0],
  );
  const normalizedQuery = $derived(query.trim().toLocaleLowerCase('id-ID'));
  const searchResults = $derived(
    normalizedQuery
      ? documentationArticles.filter((article) => searchableArticleText(article).includes(normalizedQuery))
      : [],
  );

  const quickStarts = ['first-setup', 'create-journal', 'reports'];

  function articlesForCategory(category: DocumentationCategoryId) {
    return documentationArticles.filter((article) => article.category === category);
  }

  function categoryLabel(categoryId: DocumentationCategoryId): string {
    return documentationCategories.find((category) => category.id === categoryId)?.label ?? categoryId;
  }

  function selectArticle(id: string) {
    activeArticleId = id;
    query = '';
    navigationOpen = false;
    requestAnimationFrame(() => document.querySelector('#documentation-article')?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
  }

  function sectionTone(section: DocumentationSection): string {
    if (section.tone === 'warning') return 'border-[#f6c000]/35 bg-[#fff8dd]/70';
    if (section.tone === 'success') return 'border-[#17c653]/25 bg-[#e8fff1]/70';
    if (section.tone === 'info') return 'border-[#1b84ff]/25 bg-[#eff6ff]/70';
    return 'border-border-soft bg-card-bg';
  }

  function articleById(id: string): DocumentationArticle | undefined {
    return documentationArticles.find((article) => article.id === id);
  }
</script>

<svelte:head>
  <title>Panduan Pengguna — Akunta</title>
  <meta
    name="description"
    content="Panduan lengkap penggunaan Akunta untuk pengguna baru, operator, supervisor, accountant, dan tax officer."
  />
</svelte:head>

<div class="min-h-full bg-page-bg">
  <header class="border-b border-border-default bg-card-bg">
    <div class="mx-auto max-w-[96rem] px-5 py-7 lg:px-8">
      <div class="flex flex-col gap-5 xl:flex-row xl:items-end xl:justify-between">
        <div>
          <div class="mb-2 inline-flex items-center gap-2 rounded-full bg-primary-light px-3 py-1 text-xs font-semibold text-primary">
            <span aria-hidden="true">▤</span>
            Pusat Bantuan Akunta
          </div>
          <h1 class="text-2xl font-bold tracking-tight text-text-default sm:text-3xl">Panduan Pengguna</h1>
          <p class="mt-2 max-w-2xl text-sm leading-6 text-text-muted">
            Pelajari workflow Akunta dari setup awal sampai laporan. Panduan ditulis untuk pengguna awam dan dapat diikuti langkah demi langkah.
          </p>
        </div>

        <label class="relative block w-full xl:max-w-xl">
          <span class="sr-only">Cari panduan</span>
          <svg class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <circle cx="11" cy="11" r="7" />
            <path d="m20 20-3.5-3.5" />
          </svg>
          <input
            type="search"
            class="h-12 w-full rounded-lg border border-border-default bg-page-bg pl-12 pr-4 text-sm shadow-xs outline-none transition focus:border-primary focus:bg-card-bg focus:ring-2 focus:ring-primary/10"
            placeholder="Cari: membuat jurnal, periode, laporan kosong…"
            bind:value={query}
          />
          {#if normalizedQuery}
            <span class="absolute right-4 top-1/2 -translate-y-1/2 text-xs text-text-muted">{searchResults.length} hasil</span>
          {/if}
        </label>
      </div>

      <div class="mt-5 grid gap-3 sm:grid-cols-3">
        {#each quickStarts as id (id)}
          {@const item = articleById(id)}
          {#if item}
            <button
              type="button"
              class="group flex items-center gap-3 rounded-lg border border-border-soft bg-card-bg px-4 py-3 text-left transition hover:border-primary/35 hover:bg-primary-light/40"
              onclick={() => selectArticle(item.id)}
            >
              <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-primary-light font-bold text-primary">{item.icon}</span>
              <span class="min-w-0">
                <span class="block truncate text-sm font-semibold text-text-default group-hover:text-primary">{item.title}</span>
                <span class="mt-0.5 block truncate text-xs text-text-muted">{item.summary}</span>
              </span>
              <span class="ml-auto text-text-muted group-hover:text-primary" aria-hidden="true">→</span>
            </button>
          {/if}
        {/each}
      </div>
    </div>
  </header>

  <div class="mx-auto max-w-[96rem] px-5 py-6 lg:px-8">
    <button
      type="button"
      class="mb-4 flex w-full items-center justify-between rounded-lg border border-border-default bg-card-bg px-4 py-3 text-sm font-semibold lg:hidden"
      onclick={() => (navigationOpen = !navigationOpen)}
      aria-expanded={navigationOpen}
    >
      Daftar Panduan
      <span class="text-text-muted" aria-hidden="true">{navigationOpen ? '−' : '+'}</span>
    </button>

    <div class="grid items-start gap-6 lg:grid-cols-[19rem_minmax(0,1fr)]">
      <aside class="{navigationOpen ? 'block' : 'hidden'} max-h-[calc(100vh-8rem)] overflow-y-auto rounded-lg border border-border-default bg-card-bg p-3 lg:sticky lg:top-4 lg:block">
        <button
          type="button"
          class="mb-3 flex w-full items-center gap-3 rounded-md px-3 py-2.5 text-left {activeArticleId === 'overview' && !normalizedQuery ? 'bg-primary-light text-primary' : 'hover:bg-page-bg'}"
          onclick={() => selectArticle('overview')}
        >
          <span class="grid h-8 w-8 place-items-center rounded-md bg-page-bg text-sm">⌂</span>
          <span>
            <span class="block text-sm font-semibold">Beranda Panduan</span>
            <span class="text-xs text-text-muted">Mulai dari sini</span>
          </span>
        </button>

        {#each documentationCategories as category (category.id)}
          <section class="border-t border-border-soft py-3 first:border-t-0">
            <div class="mb-1 flex items-center gap-2 px-3">
              <span class="text-xs text-text-muted" aria-hidden="true">{category.icon}</span>
              <h2 class="text-[0.7rem] font-bold uppercase tracking-wider text-text-muted">{category.label}</h2>
            </div>
            <div class="space-y-0.5">
              {#each articlesForCategory(category.id) as article (article.id)}
                {#if article.id !== 'overview'}
                  <button
                    type="button"
                    class="w-full rounded-md px-3 py-2 text-left text-sm transition {activeArticleId === article.id && !normalizedQuery ? 'bg-primary-light font-semibold text-primary' : 'text-text-default hover:bg-page-bg'}"
                    onclick={() => selectArticle(article.id)}
                  >
                    {article.title}
                  </button>
                {/if}
              {/each}
            </div>
          </section>
        {/each}
      </aside>

      <main id="documentation-article" class="min-w-0 scroll-mt-4">
        {#if normalizedQuery}
          <section class="rounded-lg border border-border-default bg-card-bg p-5 sm:p-7">
            <div class="border-b border-border-soft pb-5">
              <p class="text-xs font-semibold uppercase tracking-wider text-primary">Hasil Pencarian</p>
              <h2 class="mt-2 text-2xl font-bold">“{query.trim()}”</h2>
              <p class="mt-2 text-sm text-text-muted">Pilih artikel yang paling sesuai dengan masalah atau pekerjaan Anda.</p>
            </div>

            <div class="mt-5 space-y-3">
              {#each searchResults as result (result.id)}
                <button
                  type="button"
                  class="group flex w-full items-start gap-4 rounded-lg border border-border-soft p-4 text-left transition hover:border-primary/40 hover:bg-primary-light/30"
                  onclick={() => selectArticle(result.id)}
                >
                  <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-page-bg font-semibold text-primary">{result.icon}</span>
                  <span class="min-w-0 flex-1">
                    <span class="text-xs font-semibold uppercase tracking-wider text-text-muted">{categoryLabel(result.category)}</span>
                    <span class="mt-1 block text-base font-bold text-text-default group-hover:text-primary">{result.title}</span>
                    <span class="mt-1 block text-sm leading-6 text-text-muted">{result.summary}</span>
                  </span>
                  <span class="pt-2 text-text-muted group-hover:text-primary" aria-hidden="true">→</span>
                </button>
              {:else}
                <div class="rounded-lg border border-dashed border-border-default px-5 py-12 text-center">
                  <div class="mx-auto grid h-12 w-12 place-items-center rounded-full bg-page-bg text-xl text-text-muted">?</div>
                  <h3 class="mt-3 font-bold">Panduan tidak ditemukan</h3>
                  <p class="mt-1 text-sm text-text-muted">Coba kata yang lebih singkat seperti “jurnal”, “periode”, “fiskal”, atau “laporan”.</p>
                  <button type="button" class="mt-4 text-sm font-semibold text-primary hover:underline" onclick={() => (query = '')}>Hapus pencarian</button>
                </div>
              {/each}
            </div>
          </section>
        {:else if activeArticle}
          <article class="overflow-hidden rounded-lg border border-border-default bg-card-bg">
            <header class="border-b border-border-soft px-5 py-6 sm:px-8 sm:py-8">
              <nav class="flex flex-wrap items-center gap-2 text-xs text-text-muted" aria-label="Breadcrumb">
                <button type="button" class="hover:text-primary" onclick={() => selectArticle('overview')}>Panduan</button>
                <span aria-hidden="true">/</span>
                <span>{categoryLabel(activeArticle.category)}</span>
              </nav>
              <div class="mt-4 flex items-start gap-4">
                <span class="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-primary-light text-lg font-bold text-primary">{activeArticle.icon}</span>
                <div class="min-w-0">
                  <h2 class="text-2xl font-bold tracking-tight text-text-default sm:text-3xl">{activeArticle.title}</h2>
                  <p class="mt-2 max-w-3xl text-sm leading-6 text-text-muted sm:text-base">{activeArticle.summary}</p>
                </div>
              </div>
              <div class="mt-5 flex flex-wrap gap-2 text-xs">
                <span class="rounded-full bg-page-bg px-3 py-1.5 text-text-muted">◷ {activeArticle.minutes} menit</span>
                <span class="rounded-full bg-page-bg px-3 py-1.5 text-text-muted">Untuk: {activeArticle.audience}</span>
                <span class="rounded-full bg-[#e8fff1] px-3 py-1.5 text-[#166534]">Panduan langkah demi langkah</span>
              </div>
            </header>

            <div class="px-5 py-6 sm:px-8 sm:py-8">
              {#if activeArticle.outcomes?.length}
                <section class="rounded-lg border border-[#17c653]/25 bg-[#e8fff1]/60 p-5">
                  <h3 class="font-bold text-text-default">Setelah membaca panduan ini, Anda dapat:</h3>
                  <ul class="mt-3 space-y-2 text-sm leading-6 text-text-muted">
                    {#each activeArticle.outcomes as outcome (outcome)}
                      <li class="flex gap-2"><span class="mt-0.5 text-[#17c653]">✓</span><span>{outcome}</span></li>
                    {/each}
                  </ul>
                </section>
              {/if}

              {#if activeArticle.prerequisites?.length}
                <section class="mt-6 rounded-lg border border-[#f6c000]/35 bg-[#fff8dd]/70 p-5">
                  <h3 class="font-bold text-text-default">Sebelum mulai</h3>
                  <ul class="mt-3 list-disc space-y-1.5 pl-5 text-sm leading-6 text-text-muted">
                    {#each activeArticle.prerequisites as prerequisite (prerequisite)}<li>{prerequisite}</li>{/each}
                  </ul>
                </section>
              {/if}

              {#if activeArticle.steps?.length}
                <section class="mt-8">
                  <div class="mb-5">
                    <p class="text-xs font-bold uppercase tracking-wider text-primary">Tutorial</p>
                    <h3 class="mt-1 text-xl font-bold">Ikuti langkah berikut</h3>
                  </div>
                  <ol class="relative space-y-5 before:absolute before:bottom-5 before:left-5 before:top-5 before:w-px before:bg-border-default">
                    {#each activeArticle.steps as step, index (step.title)}
                      <li class="relative grid grid-cols-[2.5rem_minmax(0,1fr)] gap-4">
                        <span class="z-10 grid h-10 w-10 place-items-center rounded-full border border-primary/25 bg-card-bg text-sm font-bold text-primary">{index + 1}</span>
                        <div class="rounded-lg border border-border-soft bg-page-bg/40 p-4">
                          <h4 class="font-bold text-text-default">{step.title}</h4>
                          <p class="mt-1.5 text-sm leading-6 text-text-muted">{step.description}</p>
                          {#if step.bullets?.length}
                            <ul class="mt-3 list-disc space-y-1 pl-5 text-sm leading-6 text-text-muted">
                              {#each step.bullets as bullet (bullet)}<li>{bullet}</li>{/each}
                            </ul>
                          {/if}
                          {#if step.href}
                            <a class="mt-3 inline-flex items-center gap-1 text-sm font-semibold text-primary hover:underline" href={step.href}>{step.actionLabel ?? 'Buka fitur'} <span aria-hidden="true">→</span></a>
                          {/if}
                        </div>
                      </li>
                    {/each}
                  </ol>
                </section>
              {/if}

              {#if activeArticle.sections?.length}
                <section class="mt-8 space-y-4">
                  <div class="mb-5">
                    <p class="text-xs font-bold uppercase tracking-wider text-primary">Penjelasan</p>
                    <h3 class="mt-1 text-xl font-bold">Hal penting yang perlu dipahami</h3>
                  </div>
                  {#each activeArticle.sections as section (section.title)}
                    <section class="rounded-lg border p-5 {sectionTone(section)}">
                      <h4 class="font-bold text-text-default">{section.title}</h4>
                      {#each section.paragraphs ?? [] as paragraph (paragraph)}
                        <p class="mt-2 text-sm leading-6 text-text-muted">{paragraph}</p>
                      {/each}
                      {#if section.bullets?.length}
                        <ul class="mt-3 list-disc space-y-1.5 pl-5 text-sm leading-6 text-text-muted">
                          {#each section.bullets as bullet (bullet)}<li>{bullet}</li>{/each}
                        </ul>
                      {/if}
                    </section>
                  {/each}
                </section>
              {/if}

              {#if activeArticle.id === 'auto-mapping'}
                <a href="/documentation/auto-mapping" class="mt-6 flex items-center justify-between rounded-lg border border-primary/25 bg-primary-light/40 p-5 text-left transition hover:border-primary">
                  <span>
                    <span class="block font-bold text-text-default">Dokumentasi teknis Auto Mapping</span>
                    <span class="mt-1 block text-sm text-text-muted">Endpoint API, contoh JSON, token, dan pattern matching untuk tim integrasi.</span>
                  </span>
                  <span class="text-primary" aria-hidden="true">→</span>
                </a>
              {/if}

              {#if activeArticle.faq?.length}
                <section class="mt-8">
                  <p class="text-xs font-bold uppercase tracking-wider text-primary">FAQ</p>
                  <h3 class="mt-1 text-xl font-bold">Pertanyaan yang sering muncul</h3>
                  <div class="mt-4 divide-y divide-border-soft overflow-hidden rounded-lg border border-border-soft">
                    {#each activeArticle.faq as item (item.question)}
                      <details class="group bg-card-bg px-5 py-4 open:bg-page-bg/40">
                        <summary class="flex cursor-pointer list-none items-center justify-between gap-4 font-semibold text-text-default">
                          {item.question}
                          <span class="text-lg font-normal text-text-muted group-open:hidden">+</span>
                          <span class="hidden text-lg font-normal text-text-muted group-open:inline">−</span>
                        </summary>
                        <p class="mt-3 pr-8 text-sm leading-6 text-text-muted">{item.answer}</p>
                      </details>
                    {/each}
                  </div>
                </section>
              {/if}

              {#if activeArticle.related?.length}
                <section class="mt-8 border-t border-border-soft pt-6">
                  <h3 class="font-bold">Lanjutkan membaca</h3>
                  <div class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    {#each activeArticle.related as relatedId (relatedId)}
                      {@const related = articleById(relatedId)}
                      {#if related}
                        <button type="button" class="group rounded-lg border border-border-soft p-4 text-left transition hover:border-primary/40 hover:bg-primary-light/30" onclick={() => selectArticle(related.id)}>
                          <span class="text-xs font-semibold uppercase tracking-wider text-text-muted">{categoryLabel(related.category)}</span>
                          <span class="mt-1 block text-sm font-bold group-hover:text-primary">{related.title}</span>
                          <span class="mt-1 block text-xs leading-5 text-text-muted">{related.summary}</span>
                        </button>
                      {/if}
                    {/each}
                  </div>
                </section>
              {/if}
            </div>
          </article>
        {/if}
      </main>
    </div>
  </div>
</div>
