<script lang="ts">
  import { onMount, tick } from 'svelte';
  import {
    documentationArticles,
    documentationCategories,
    searchableArticleText,
    type DocumentationArticle,
    type DocumentationCategoryId,
    type DocumentationSection,
  } from '$lib/documentation/content.js';
  import { documentationNoteApi, type DocumentationNote } from '$lib/api/documentation-note.js';
  import { tenant } from '$lib/stores/tenant.svelte.js';
  import { auth } from '$lib/stores/auth.svelte.js';
  import { toast, toastApiError } from '$lib/stores/toast.svelte.js';
  import NoteRichTextEditor from '$lib/components/documentation/NoteRichTextEditor.svelte';
  import NoteRichTextView from '$lib/components/documentation/NoteRichTextView.svelte';

  let activeTab = $state<'tutorial' | 'notes'>('tutorial');
  let activeArticleId = $state('overview');
  let query = $state('');
  let notesQuery = $state('');
  let navigationOpen = $state(false);
  let notes = $state<DocumentationNote[]>([]);
  let notesLoading = $state(false);
  let notesLoadedForTenant = $state<string | null>(null);
  let notesError = $state<string | null>(null);
  let canManageNotes = $state(false);
  let activeNoteId = $state<string | null>(null);
  let editorOpen = $state(false);
  let editingNoteId = $state<string | null>(null);
  let editorParentId = $state<string | null>(null);
  let noteDescription = $state('');
  let noteEditorError = $state<string | null>(null);
  let noteSaving = $state(false);

  const activeArticle = $derived(
    documentationArticles.find((article) => article.id === activeArticleId) ??
      documentationArticles[0],
  );
  const normalizedQuery = $derived(query.trim().toLocaleLowerCase('id-ID'));
  const searchResults = $derived(
    normalizedQuery
      ? documentationArticles.filter((article) =>
          searchableArticleText(article).includes(normalizedQuery),
        )
      : [],
  );
  const allNotes = $derived(notes.flatMap((note) => [note, ...note.children]));
  const activeNote = $derived(allNotes.find((note) => note.id === activeNoteId) ?? null);
  const normalizedNotesQuery = $derived(notesQuery.trim().toLocaleLowerCase('id-ID'));
  const noteSuggestions = $derived(
    normalizedNotesQuery.length >= 2
      ? allNotes
          .map((note) => ({ note, score: noteMatchScore(note, normalizedNotesQuery) }))
          .filter(({ score }) => score > 0)
          .sort((a, b) => b.score - a.score)
          .slice(0, 6)
          .map(({ note }) => note)
      : [],
  );
  const filteredNotes = $derived(
    normalizedNotesQuery
      ? notes.flatMap((note) => {
          const noteMatches = noteSearchText(note).includes(normalizedNotesQuery);
          const matchingChildren = note.children.filter((child) =>
            noteSearchText(child).includes(normalizedNotesQuery),
          );

          if (!noteMatches && !matchingChildren.length) return [];

          return [{ ...note, children: noteMatches ? note.children : matchingChildren }];
        })
      : notes,
  );

  const quickStarts = ['first-setup', 'create-journal', 'reports'];

  function articlesForCategory(category: DocumentationCategoryId) {
    return documentationArticles.filter((article) => article.category === category);
  }

  function categoryLabel(categoryId: DocumentationCategoryId): string {
    return (
      documentationCategories.find((category) => category.id === categoryId)?.label ?? categoryId
    );
  }

  function selectArticle(id: string) {
    activeArticleId = id;
    query = '';
    navigationOpen = false;
    requestAnimationFrame(() =>
      document
        .querySelector('#documentation-article')
        ?.scrollIntoView({ behavior: 'smooth', block: 'start' }),
    );
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

  function noteExcerpt(content: string | null): string {
    return (
      content
        ?.replace(/<[^>]+>/g, ' ')
        .replace(/&nbsp;/g, ' ')
        .replace(/&amp;/g, '&')
        .replace(/\s+/g, ' ')
        .trim() || 'Belum ada isi catatan.'
    );
  }

  function noteSearchText(note: DocumentationNote): string {
    return `${note.title} ${noteExcerpt(note.description)}`.toLocaleLowerCase('id-ID');
  }

  function noteMatchScore(note: DocumentationNote, search: string): number {
    const title = note.title.toLocaleLowerCase('id-ID');
    const content = noteExcerpt(note.description).toLocaleLowerCase('id-ID');
    const terms = search.split(/\s+/).filter(Boolean);
    let score = 0;

    if (title.includes(search)) score += 100;
    if (content.includes(search)) score += 60;
    for (const term of terms) {
      if (title.includes(term)) score += 30;
      else if (content.includes(term)) score += 15;
    }

    return score;
  }

  function switchTab(tab: 'tutorial' | 'notes') {
    activeTab = tab;
    navigationOpen = false;
    if (tab === 'notes') void ensureNotesContext();
  }

  async function ensureNotesContext() {
    if (!tenant.id) await auth.refresh();
    await loadNotes();
  }

  async function loadNotes(force = false) {
    const entityId = tenant.id;
    if (!entityId || notesLoading || (!force && notesLoadedForTenant === entityId)) return;

    notesLoading = true;
    notesError = null;
    try {
      const result = await documentationNoteApi.list(entityId);
      notes = result.notes.map((note) => applyContentTitle(note));
      canManageNotes = result.canManage;
      notesLoadedForTenant = entityId;
      const loadedNotes = notes.flatMap((note) => [note, ...note.children]);
      if (!loadedNotes.some((note) => note.id === activeNoteId)) {
        activeNoteId = notes[0]?.id ?? null;
      }
    } catch (error) {
      notesError = error instanceof Error ? error.message : 'Catatan tidak dapat dimuat.';
    } finally {
      notesLoading = false;
    }
  }

  function selectNote(id: string) {
    activeNoteId = id;
    closeEditor();
    navigationOpen = false;
  }

  function selectNoteSuggestion(id: string) {
    notesQuery = '';
    selectNote(id);
  }

  function openCreateNote(parentId: string | null = null) {
    editingNoteId = null;
    editorParentId = parentId;
    noteDescription = '';
    noteEditorError = null;
    editorOpen = true;
  }

  async function openEditNote(note: DocumentationNote) {
    editingNoteId = note.id;
    editorParentId = note.parent_id;
    noteDescription = note.description ?? '';
    noteEditorError = null;
    editorOpen = true;

    await tick();
    requestAnimationFrame(() => {
      document.querySelector<HTMLElement>('[role="textbox"][aria-label="Isi catatan"]')?.focus();
    });
  }

  function closeEditor() {
    editorOpen = false;
    editingNoteId = null;
    editorParentId = null;
    noteEditorError = null;
  }

  function updateNoteDescription(value: string) {
    noteDescription = value;
    noteEditorError = null;
  }

  function noteSaveErrorMessage(error: unknown): string {
    if (error && typeof error === 'object') {
      const body = (error as { body?: unknown }).body;
      if (body && typeof body === 'object') {
        const message = (body as { message?: unknown }).message;
        if (typeof message === 'string' && message.trim()) return message;
      }
      const message = (error as { message?: unknown }).message;
      if (typeof message === 'string' && message.trim()) return message;
    }

    return 'Catatan tidak dapat disimpan. Periksa isi catatan lalu coba lagi.';
  }

  function titleFromContent(content: string): string {
    const heading = new DOMParser()
      .parseFromString(content, 'text/html')
      .querySelector('h1')
      ?.textContent?.replace(/\s+/g, ' ')
      .trim();

    return heading?.slice(0, 160) ?? '';
  }

  function applyContentTitle(note: DocumentationNote): DocumentationNote {
    return {
      ...note,
      title: titleFromContent(note.description ?? '') || note.title,
      children: note.children.map((child) => applyContentTitle(child)),
    };
  }

  async function saveNote() {
    if (noteSaving) return;

    const description = noteDescription.trim();
    const title = titleFromContent(description);
    if (!title) {
      noteEditorError = 'Tambahkan judul H1 di awal konten catatan.';
      return;
    }

    noteSaving = true;
    try {
      if (editingNoteId) {
        await documentationNoteApi.update(
          editingNoteId,
          { title, description: description || null },
          tenant.id,
        );
        activeNoteId = editingNoteId;
        toast.success('Catatan berhasil diperbarui.');
      } else {
        const created = await documentationNoteApi.create(
          {
            parent_id: editorParentId,
            title,
            description: description || null,
          },
          tenant.id,
        );
        activeNoteId = created.id;
        toast.success(
          editorParentId ? 'Submenu berhasil ditambahkan.' : 'Menu berhasil ditambahkan.',
        );
      }
      closeEditor();
      await loadNotes(true);
    } catch (error) {
      noteEditorError = noteSaveErrorMessage(error);
    } finally {
      noteSaving = false;
    }
  }

  async function deleteNote(note: DocumentationNote) {
    const childWarning = note.children.length
      ? ' Seluruh submenu di dalamnya juga akan dihapus.'
      : '';
    if (!window.confirm(`Hapus “${note.title}”?${childWarning}`)) return;

    try {
      await documentationNoteApi.destroy(note.id, tenant.id);
      if (activeNoteId === note.id || note.children.some((child) => child.id === activeNoteId)) {
        activeNoteId = null;
      }
      await loadNotes(true);
      toast.success('Catatan berhasil dihapus.');
    } catch (error) {
      toastApiError(error, 'Catatan tidak dapat dihapus.');
    }
  }

  onMount(() => {
    if (!tenant.id) void auth.refresh();
    if (activeTab === 'notes') void ensureNotesContext();
  });

  $effect(() => {
    const entityId = tenant.id;
    if (!entityId || activeTab !== 'notes' || notesLoadedForTenant === entityId) return;
    notes = [];
    activeNoteId = null;
    closeEditor();
    void loadNotes();
  });
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
          <div
            class="mb-2 inline-flex items-center gap-2 rounded-full bg-primary-light px-3 py-1 text-xs font-semibold text-primary"
          >
            <span aria-hidden="true">▤</span>
            Pusat Bantuan Akunta
          </div>
          <h1 class="text-2xl font-bold tracking-tight text-text-default sm:text-3xl">
            Panduan Pengguna
          </h1>
          <p class="mt-2 max-w-2xl text-sm leading-6 text-text-muted">
            Pelajari workflow Akunta dari setup awal sampai laporan. Panduan ditulis untuk pengguna
            awam dan dapat diikuti langkah demi langkah.
          </p>
        </div>

        {#if activeTab === 'tutorial'}
          <label class="relative block w-full xl:max-w-xl">
            <span class="sr-only">Cari panduan</span>
            <svg
              class="pointer-events-none absolute left-4 top-1/2 h-5 w-5 -translate-y-1/2 text-text-muted"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="2"
              aria-hidden="true"
            >
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
              <span class="absolute right-4 top-1/2 -translate-y-1/2 text-xs text-text-muted"
                >{searchResults.length} hasil</span
              >
            {/if}
          </label>
        {:else}
          <div
            class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-border-soft bg-page-bg px-4 py-3 text-sm text-text-muted"
          >
            <span>
              Catatan internal untuk <span class="font-semibold text-text-default"
                >{tenant.name ?? 'entitas aktif'}</span
              >
            </span>
            {#if !canManageNotes && !notesLoading}
              <span
                class="rounded-full bg-card-bg px-3 py-1.5 text-xs font-semibold text-text-muted"
                >Mode baca</span
              >
            {/if}
          </div>
        {/if}
      </div>

      <div class="mt-5 flex flex-wrap gap-2" role="tablist" aria-label="Jenis panduan">
        <button
          type="button"
          role="tab"
          aria-selected={activeTab === 'tutorial'}
          class="inline-flex items-center gap-2 rounded-full border px-4 py-2 text-sm font-semibold transition {activeTab ===
          'tutorial'
            ? 'border-primary bg-primary text-white shadow-sm'
            : 'border-border-default bg-card-bg text-text-muted hover:border-primary/40 hover:text-primary'}"
          onclick={() => switchTab('tutorial')}
        >
          <span aria-hidden="true">▶</span> Tutorial
        </button>
        <button
          type="button"
          role="tab"
          aria-selected={activeTab === 'notes'}
          class="inline-flex items-center gap-2 rounded-full border px-4 py-2 text-sm font-semibold transition {activeTab ===
          'notes'
            ? 'border-primary bg-primary text-white shadow-sm'
            : 'border-border-default bg-card-bg text-text-muted hover:border-primary/40 hover:text-primary'}"
          onclick={() => switchTab('notes')}
        >
          <span aria-hidden="true">✎</span> Catatan
          {#if notes.length}
            <span
              class="rounded-full px-2 py-0.5 text-[0.65rem] {activeTab === 'notes'
                ? 'bg-white/20 text-white'
                : 'bg-primary-light text-primary'}">{allNotes.length}</span
            >
          {/if}
        </button>
        {#if activeTab === 'notes'}
          <label class="relative ml-auto block w-full sm:w-80">
            <span class="sr-only">Cari isi catatan</span>
            <svg
              class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-text-muted"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="2"
              aria-hidden="true"
            >
              <circle cx="11" cy="11" r="7" />
              <path d="m20 20-3.5-3.5" />
            </svg>
            <input
              type="search"
              class="h-10 w-full rounded-lg border border-border-default bg-card-bg pl-10 pr-4 text-sm shadow-xs outline-none transition focus:border-primary focus:ring-2 focus:ring-primary/10"
              placeholder="Cari isi catatan..."
              aria-label="Cari isi catatan"
              bind:value={notesQuery}
            />
            {#if noteSuggestions.length}
              <div
                class="absolute left-0 right-0 top-full z-20 mt-2 overflow-hidden rounded-lg border border-border-default bg-card-bg p-1 shadow-lg"
                data-testid="note-search-suggestions"
                role="listbox"
                aria-label="Saran catatan"
              >
                <p
                  class="px-3 py-2 text-[0.65rem] font-semibold uppercase tracking-wider text-text-muted"
                >
                  Saran catatan
                </p>
                {#each noteSuggestions as suggestion (suggestion.id)}
                  <button
                    type="button"
                    class="block w-full rounded-md px-3 py-2 text-left transition hover:bg-page-bg"
                    role="option"
                    aria-selected={suggestion.id === activeNoteId}
                    onclick={() => selectNoteSuggestion(suggestion.id)}
                  >
                    <span class="block truncate text-sm font-semibold text-text-default"
                      >{suggestion.title}</span
                    >
                    <span class="mt-0.5 block truncate text-xs text-text-muted"
                      >{noteExcerpt(suggestion.description)}</span
                    >
                  </button>
                {/each}
              </div>
            {/if}
            {#if normalizedNotesQuery}
              <span class="absolute right-3.5 top-1/2 -translate-y-1/2 text-xs text-text-muted"
                >{filteredNotes.reduce((count, note) => count + 1 + note.children.length, 0)} hasil</span
              >
            {/if}
          </label>
        {/if}
      </div>

      {#if activeTab === 'tutorial'}
        <div class="mt-4 grid gap-3 sm:grid-cols-3">
          {#each quickStarts as id (id)}
            {@const item = articleById(id)}
            {#if item}
              <button
                type="button"
                class="group flex items-center gap-3 rounded-lg border border-border-soft bg-card-bg px-4 py-3 text-left transition hover:border-primary/35 hover:bg-primary-light/40"
                onclick={() => selectArticle(item.id)}
              >
                <span
                  class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-primary-light font-bold text-primary"
                  >{item.icon}</span
                >
                <span class="min-w-0">
                  <span
                    class="block truncate text-sm font-semibold text-text-default group-hover:text-primary"
                    >{item.title}</span
                  >
                  <span class="mt-0.5 block truncate text-xs text-text-muted">{item.summary}</span>
                </span>
                <span class="ml-auto text-text-muted group-hover:text-primary" aria-hidden="true"
                  >→</span
                >
              </button>
            {/if}
          {/each}
        </div>
      {/if}
    </div>
  </header>

  <div class="mx-auto max-w-[96rem] px-5 py-6 lg:px-8">
    <button
      type="button"
      class="mb-4 w-full items-center justify-between rounded-lg border border-border-default bg-card-bg px-4 py-3 text-sm font-semibold {activeTab ===
      'tutorial'
        ? 'flex lg:hidden'
        : 'hidden'}"
      onclick={() => (navigationOpen = !navigationOpen)}
      aria-expanded={navigationOpen}
    >
      Daftar Panduan
      <span class="text-text-muted" aria-hidden="true">{navigationOpen ? '−' : '+'}</span>
    </button>

    <div
      class="items-start gap-6 lg:grid-cols-[19rem_minmax(0,1fr)] {activeTab === 'tutorial'
        ? 'grid'
        : 'hidden'}"
    >
      <aside
        class="{navigationOpen
          ? 'block'
          : 'hidden'} max-h-[calc(100vh-8rem)] overflow-y-auto rounded-lg border border-border-default bg-card-bg p-3 lg:sticky lg:top-4 lg:block"
      >
        <button
          type="button"
          class="mb-3 flex w-full items-center gap-3 rounded-md px-3 py-2.5 text-left {activeArticleId ===
            'overview' && !normalizedQuery
            ? 'bg-primary-light text-primary'
            : 'hover:bg-page-bg'}"
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
              <h2 class="text-[0.7rem] font-bold uppercase tracking-wider text-text-muted">
                {category.label}
              </h2>
            </div>
            <div class="space-y-0.5">
              {#each articlesForCategory(category.id) as article (article.id)}
                {#if article.id !== 'overview'}
                  <button
                    type="button"
                    class="w-full rounded-md px-3 py-2 text-left text-sm transition {activeArticleId ===
                      article.id && !normalizedQuery
                      ? 'bg-primary-light font-semibold text-primary'
                      : 'text-text-default hover:bg-page-bg'}"
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
              <p class="text-xs font-semibold uppercase tracking-wider text-primary">
                Hasil Pencarian
              </p>
              <h2 class="mt-2 text-2xl font-bold">“{query.trim()}”</h2>
              <p class="mt-2 text-sm text-text-muted">
                Pilih artikel yang paling sesuai dengan masalah atau pekerjaan Anda.
              </p>
            </div>

            <div class="mt-5 space-y-3">
              {#each searchResults as result (result.id)}
                <button
                  type="button"
                  class="group flex w-full items-start gap-4 rounded-lg border border-border-soft p-4 text-left transition hover:border-primary/40 hover:bg-primary-light/30"
                  onclick={() => selectArticle(result.id)}
                >
                  <span
                    class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-page-bg font-semibold text-primary"
                    >{result.icon}</span
                  >
                  <span class="min-w-0 flex-1">
                    <span class="text-xs font-semibold uppercase tracking-wider text-text-muted"
                      >{categoryLabel(result.category)}</span
                    >
                    <span
                      class="mt-1 block text-base font-bold text-text-default group-hover:text-primary"
                      >{result.title}</span
                    >
                    <span class="mt-1 block text-sm leading-6 text-text-muted"
                      >{result.summary}</span
                    >
                  </span>
                  <span class="pt-2 text-text-muted group-hover:text-primary" aria-hidden="true"
                    >→</span
                  >
                </button>
              {:else}
                <div
                  class="rounded-lg border border-dashed border-border-default px-5 py-12 text-center"
                >
                  <div
                    class="mx-auto grid h-12 w-12 place-items-center rounded-full bg-page-bg text-xl text-text-muted"
                  >
                    ?
                  </div>
                  <h3 class="mt-3 font-bold">Panduan tidak ditemukan</h3>
                  <p class="mt-1 text-sm text-text-muted">
                    Coba kata yang lebih singkat seperti “jurnal”, “periode”, “fiskal”, atau
                    “laporan”.
                  </p>
                  <button
                    type="button"
                    class="mt-4 text-sm font-semibold text-primary hover:underline"
                    onclick={() => (query = '')}>Hapus pencarian</button
                  >
                </div>
              {/each}
            </div>
          </section>
        {:else if activeArticle}
          <article class="overflow-hidden rounded-lg border border-border-default bg-card-bg">
            <header class="border-b border-border-soft px-5 py-6 sm:px-8 sm:py-8">
              <nav
                class="flex flex-wrap items-center gap-2 text-xs text-text-muted"
                aria-label="Breadcrumb"
              >
                <button
                  type="button"
                  class="hover:text-primary"
                  onclick={() => selectArticle('overview')}>Panduan</button
                >
                <span aria-hidden="true">/</span>
                <span>{categoryLabel(activeArticle.category)}</span>
              </nav>
              <div class="mt-4 flex items-start gap-4">
                <span
                  class="grid h-12 w-12 shrink-0 place-items-center rounded-xl bg-primary-light text-lg font-bold text-primary"
                  >{activeArticle.icon}</span
                >
                <div class="min-w-0">
                  <h2 class="text-2xl font-bold tracking-tight text-text-default sm:text-3xl">
                    {activeArticle.title}
                  </h2>
                  <p class="mt-2 max-w-3xl text-sm leading-6 text-text-muted sm:text-base">
                    {activeArticle.summary}
                  </p>
                </div>
              </div>
              <div class="mt-5 flex flex-wrap gap-2 text-xs">
                <span class="rounded-full bg-page-bg px-3 py-1.5 text-text-muted"
                  >◷ {activeArticle.minutes} menit</span
                >
                <span class="rounded-full bg-page-bg px-3 py-1.5 text-text-muted"
                  >Untuk: {activeArticle.audience}</span
                >
                <span class="rounded-full bg-[#e8fff1] px-3 py-1.5 text-[#166534]"
                  >Panduan langkah demi langkah</span
                >
              </div>
            </header>

            <div class="px-5 py-6 sm:px-8 sm:py-8">
              {#if activeArticle.outcomes?.length}
                <section class="rounded-lg border border-[#17c653]/25 bg-[#e8fff1]/60 p-5">
                  <h3 class="font-bold text-text-default">
                    Setelah membaca panduan ini, Anda dapat:
                  </h3>
                  <ul class="mt-3 space-y-2 text-sm leading-6 text-text-muted">
                    {#each activeArticle.outcomes as outcome (outcome)}
                      <li class="flex gap-2">
                        <span class="mt-0.5 text-[#17c653]">✓</span><span>{outcome}</span>
                      </li>
                    {/each}
                  </ul>
                </section>
              {/if}

              {#if activeArticle.prerequisites?.length}
                <section class="mt-6 rounded-lg border border-[#f6c000]/35 bg-[#fff8dd]/70 p-5">
                  <h3 class="font-bold text-text-default">Sebelum mulai</h3>
                  <ul class="mt-3 list-disc space-y-1.5 pl-5 text-sm leading-6 text-text-muted">
                    {#each activeArticle.prerequisites as prerequisite (prerequisite)}<li>
                        {prerequisite}
                      </li>{/each}
                  </ul>
                </section>
              {/if}

              {#if activeArticle.steps?.length}
                <section class="mt-8">
                  <div class="mb-5">
                    <p class="text-xs font-bold uppercase tracking-wider text-primary">Tutorial</p>
                    <h3 class="mt-1 text-xl font-bold">Ikuti langkah berikut</h3>
                  </div>
                  <ol
                    class="relative space-y-5 before:absolute before:bottom-5 before:left-5 before:top-5 before:w-px before:bg-border-default"
                  >
                    {#each activeArticle.steps as step, index (step.title)}
                      <li class="relative grid grid-cols-[2.5rem_minmax(0,1fr)] gap-4">
                        <span
                          class="z-10 grid h-10 w-10 place-items-center rounded-full border border-primary/25 bg-card-bg text-sm font-bold text-primary"
                          >{index + 1}</span
                        >
                        <div class="rounded-lg border border-border-soft bg-page-bg/40 p-4">
                          <h4 class="font-bold text-text-default">{step.title}</h4>
                          <p class="mt-1.5 text-sm leading-6 text-text-muted">
                            {step.description}
                          </p>
                          {#if step.bullets?.length}
                            <ul
                              class="mt-3 list-disc space-y-1 pl-5 text-sm leading-6 text-text-muted"
                            >
                              {#each step.bullets as bullet (bullet)}<li>{bullet}</li>{/each}
                            </ul>
                          {/if}
                          {#if step.href}
                            <a
                              class="mt-3 inline-flex items-center gap-1 text-sm font-semibold text-primary hover:underline"
                              href={step.href}
                              >{step.actionLabel ?? 'Buka fitur'}
                              <span aria-hidden="true">→</span></a
                            >
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
                    <p class="text-xs font-bold uppercase tracking-wider text-primary">
                      Penjelasan
                    </p>
                    <h3 class="mt-1 text-xl font-bold">Hal penting yang perlu dipahami</h3>
                  </div>
                  {#each activeArticle.sections as section (section.title)}
                    <section class="rounded-lg border p-5 {sectionTone(section)}">
                      <h4 class="font-bold text-text-default">{section.title}</h4>
                      {#each section.paragraphs ?? [] as paragraph (paragraph)}
                        <p class="mt-2 text-sm leading-6 text-text-muted">{paragraph}</p>
                      {/each}
                      {#if section.bullets?.length}
                        <ul
                          class="mt-3 list-disc space-y-1.5 pl-5 text-sm leading-6 text-text-muted"
                        >
                          {#each section.bullets as bullet (bullet)}<li>{bullet}</li>{/each}
                        </ul>
                      {/if}
                    </section>
                  {/each}
                </section>
              {/if}

              {#if activeArticle.id === 'auto-mapping'}
                <a
                  href="/documentation/auto-mapping"
                  class="mt-6 flex items-center justify-between rounded-lg border border-primary/25 bg-primary-light/40 p-5 text-left transition hover:border-primary"
                >
                  <span>
                    <span class="block font-bold text-text-default"
                      >Dokumentasi teknis Auto Mapping</span
                    >
                    <span class="mt-1 block text-sm text-text-muted"
                      >Endpoint API, contoh JSON, token, dan pattern matching untuk tim integrasi.</span
                    >
                  </span>
                  <span class="text-primary" aria-hidden="true">→</span>
                </a>
              {/if}

              {#if activeArticle.faq?.length}
                <section class="mt-8">
                  <p class="text-xs font-bold uppercase tracking-wider text-primary">FAQ</p>
                  <h3 class="mt-1 text-xl font-bold">Pertanyaan yang sering muncul</h3>
                  <div
                    class="mt-4 divide-y divide-border-soft overflow-hidden rounded-lg border border-border-soft"
                  >
                    {#each activeArticle.faq as item (item.question)}
                      <details class="group bg-card-bg px-5 py-4 open:bg-page-bg/40">
                        <summary
                          class="flex cursor-pointer list-none items-center justify-between gap-4 font-semibold text-text-default"
                        >
                          {item.question}
                          <span class="text-lg font-normal text-text-muted group-open:hidden"
                            >+</span
                          >
                          <span class="hidden text-lg font-normal text-text-muted group-open:inline"
                            >−</span
                          >
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
                        <button
                          type="button"
                          class="group rounded-lg border border-border-soft p-4 text-left transition hover:border-primary/40 hover:bg-primary-light/30"
                          onclick={() => selectArticle(related.id)}
                        >
                          <span
                            class="text-xs font-semibold uppercase tracking-wider text-text-muted"
                            >{categoryLabel(related.category)}</span
                          >
                          <span class="mt-1 block text-sm font-bold group-hover:text-primary"
                            >{related.title}</span
                          >
                          <span class="mt-1 block text-xs leading-5 text-text-muted"
                            >{related.summary}</span
                          >
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
    {#if activeTab === 'notes'}
      <button
        type="button"
        class="mb-4 flex w-full items-center justify-between rounded-lg border border-border-default bg-card-bg px-4 py-3 text-sm font-semibold lg:hidden"
        onclick={() => (navigationOpen = !navigationOpen)}
        aria-expanded={navigationOpen}
      >
        Daftar Catatan
        <span class="text-text-muted" aria-hidden="true">{navigationOpen ? '−' : '+'}</span>
      </button>

      <div class="grid items-start gap-6 lg:grid-cols-[19rem_minmax(0,1fr)]">
        <aside
          class="{navigationOpen
            ? 'block'
            : 'hidden'} max-h-[calc(100vh-8rem)] overflow-y-auto rounded-lg border border-border-default bg-card-bg p-3 lg:sticky lg:top-4 lg:block"
        >
          <div class="flex items-center justify-between gap-3 px-2 pb-3">
            <div>
              <p class="text-[0.7rem] font-bold uppercase tracking-wider text-text-muted">
                Catatan
              </p>
              <p class="mt-0.5 text-xs text-text-muted">Menu dan submenu internal</p>
            </div>
            {#if canManageNotes}
              <button
                type="button"
                class="grid h-8 w-8 shrink-0 place-items-center rounded-md bg-primary-light text-lg font-semibold leading-none text-primary transition hover:bg-primary hover:text-white"
                title="Tambah menu"
                aria-label="Tambah menu"
                onclick={() => openCreateNote()}>+</button
              >
            {/if}
          </div>

          {#if notesLoading && !notes.length}
            <div class="space-y-2 p-2" aria-label="Memuat catatan">
              {#each Array(4) as _}
                <div class="h-9 animate-pulse rounded-md bg-page-bg"></div>
              {/each}
            </div>
          {:else if notesError}
            <div class="rounded-md border border-danger/20 bg-danger/5 p-3 text-xs text-danger">
              <p>{notesError}</p>
              <button
                class="mt-2 font-semibold underline"
                type="button"
                onclick={() => loadNotes(true)}>Coba lagi</button
              >
            </div>
          {:else if filteredNotes.length}
            <nav class="space-y-2" aria-label="Navigasi catatan">
              {#each filteredNotes as note (note.id)}
                <section class="rounded-md border border-border-soft p-1.5">
                  <div class="flex items-center gap-1">
                    <button
                      type="button"
                      class="min-w-0 flex-1 rounded-md px-2 py-2 text-left text-sm font-semibold transition {activeNoteId ===
                      note.id
                        ? 'bg-primary-light text-primary'
                        : 'text-text-default hover:bg-page-bg'}"
                      onclick={() => selectNote(note.id)}
                    >
                      <span class="block truncate">{note.title}</span>
                    </button>
                    {#if canManageNotes}
                      <button
                        type="button"
                        class="shrink-0 rounded-md px-2 py-1.5 text-[0.65rem] font-semibold text-primary hover:bg-primary-light"
                        title="Tambah submenu"
                        aria-label={`Tambah submenu pada ${note.title}`}
                        onclick={() => openCreateNote(note.id)}>+ Submenu</button
                      >
                    {/if}
                  </div>
                  {#if note.children.length}
                    <div class="mt-1 space-y-0.5 border-l border-border-default pl-3">
                      {#each note.children as child (child.id)}
                        <button
                          type="button"
                          class="w-full rounded-md px-2 py-1.5 text-left text-sm transition {activeNoteId ===
                          child.id
                            ? 'bg-primary-light font-semibold text-primary'
                            : 'text-text-muted hover:bg-page-bg hover:text-text-default'}"
                          onclick={() => selectNote(child.id)}
                        >
                          {child.title}
                        </button>
                      {/each}
                    </div>
                  {/if}
                </section>
              {/each}
            </nav>
          {:else if normalizedNotesQuery}
            <div class="rounded-md border border-dashed border-border-default p-5 text-center">
              <p class="text-sm font-semibold text-text-default">Catatan tidak ditemukan</p>
              <p class="mt-1 text-xs leading-5 text-text-muted">
                Coba kata kunci lain untuk mencari judul atau isi catatan.
              </p>
            </div>
          {:else}
            <div class="rounded-md border border-dashed border-border-default p-5 text-center">
              <p class="text-sm font-semibold text-text-default">Belum ada catatan</p>
              <p class="mt-1 text-xs leading-5 text-text-muted">
                {canManageNotes
                  ? 'Tambahkan menu pertama untuk mulai menulis.'
                  : 'Admin belum menambahkan catatan untuk entitas ini.'}
              </p>
            </div>
          {/if}
        </aside>

        <main class="min-w-0">
          {#if !canManageNotes && !notesLoading && !notesError}
            <section
              class="mb-4 rounded-lg border border-border-soft bg-page-bg px-4 py-3 text-xs leading-5 text-text-muted"
            >
              Anda berada dalam mode baca. Penambahan menu dan submenu memerlukan role admin atau
              permission <code class="rounded bg-card-bg px-1.5 py-0.5">workspace.manage</code> pada entitas
              aktif.
            </section>
          {/if}

          {#if editorOpen && canManageNotes && !editingNoteId}
            <form
              class="rounded-lg border border-primary/25 bg-white p-5 shadow-sm sm:p-7"
              onsubmit={(event) => {
                event.preventDefault();
                void saveNote();
              }}
            >
              <NoteRichTextEditor
                value={noteDescription}
                editable
                onChange={updateNoteDescription}
              />
              <div class="mt-4 flex flex-wrap items-center justify-end gap-2">
                {#if noteEditorError}
                  <div
                    class="mr-auto flex items-center gap-2 rounded-md border border-[#eab308] bg-[rgb(251_247_221)] px-3 py-2 text-xs font-medium text-[#111827]"
                    role="alert"
                  >
                    <svg
                      class="h-4 w-4 shrink-0"
                      viewBox="0 0 24 24"
                      fill="none"
                      stroke="currentColor"
                      stroke-width="2"
                      aria-hidden="true"
                      ><path
                        d="M12 9v4m0 4h.01M10.3 4.8 2.7 18a2 2 0 0 0 1.7 3h15.2a2 2 0 0 0 1.7-3L13.7 4.8a2 2 0 0 0-3.4 0Z"
                      /></svg
                    >
                    <span>{noteEditorError}</span>
                  </div>
                {/if}
                <button
                  type="button"
                  class="rounded-lg border border-border-default px-4 py-2.5 text-sm font-semibold text-text-muted hover:bg-page-bg"
                  onclick={closeEditor}>Batal</button
                >
                <button
                  type="submit"
                  class="rounded-lg bg-primary px-4 py-2.5 text-sm font-semibold text-white shadow-sm disabled:cursor-not-allowed disabled:opacity-50"
                  disabled={noteSaving}>{noteSaving ? 'Menyimpan…' : 'Simpan Catatan'}</button
                >
              </div>
            </form>
          {:else if activeNote}
            <article class="overflow-hidden rounded-lg border border-border-default bg-card-bg">
              <div class="px-5 py-6 sm:px-8 sm:py-8">
                {#if canManageNotes}
                  <div class="mb-4 flex min-h-9 flex-wrap items-center justify-end gap-2">
                    {#if editorOpen && editingNoteId === activeNote.id}
                      {#if noteEditorError}
                        <div
                          class="mr-auto flex items-center gap-2 rounded-md border border-[#eab308] bg-[rgb(251_247_221)] px-3 py-2 text-xs font-medium text-[#111827]"
                          role="alert"
                        >
                          <svg
                            class="h-4 w-4 shrink-0"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                            aria-hidden="true"
                            ><path
                              d="M12 9v4m0 4h.01M10.3 4.8 2.7 18a2 2 0 0 0 1.7 3h15.2a2 2 0 0 0 1.7-3L13.7 4.8a2 2 0 0 0-3.4 0Z"
                            /></svg
                          >
                          <span>{noteEditorError}</span>
                        </div>
                      {/if}
                      <button
                        type="button"
                        class="rounded-lg border border-border-default px-3 py-2 text-xs font-semibold text-text-muted hover:bg-page-bg"
                        onclick={closeEditor}>Batal</button
                      >
                      <button
                        type="button"
                        class="rounded-lg bg-primary px-3 py-2 text-xs font-semibold text-white shadow-sm disabled:cursor-not-allowed disabled:opacity-50"
                        disabled={noteSaving}
                        onclick={() => void saveNote()}
                      >
                        {noteSaving ? 'Menyimpan…' : 'Simpan konten'}
                      </button>
                    {:else}
                      <button
                        type="button"
                        class="grid h-10 w-10 place-items-center rounded-full text-text-muted transition hover:bg-primary-light hover:text-primary"
                        title="Edit konten"
                        aria-label="Edit konten"
                        onclick={() => void openEditNote(activeNote)}
                      >
                        <svg
                          class="h-4 w-4"
                          viewBox="0 0 24 24"
                          fill="none"
                          stroke="currentColor"
                          stroke-width="2"
                          aria-hidden="true"
                          ><path d="m4 20 4.5-1 10-10a2.1 2.1 0 0 1 3-3l-10 10z" /><path
                            d="m14 7 3 3"
                          /></svg
                        >
                      </button>
                      <button
                        type="button"
                        class="grid h-10 w-10 place-items-center rounded-full border border-danger/20 text-danger transition hover:bg-danger/5"
                        title="Hapus catatan"
                        aria-label="Hapus catatan"
                        onclick={() => deleteNote(activeNote)}
                      >
                        <svg
                          class="h-4 w-4"
                          viewBox="0 0 24 24"
                          fill="none"
                          stroke="currentColor"
                          stroke-width="2"
                          aria-hidden="true"
                          ><path d="M4 7h16M9 7V4h6v3M7 7l1 13h8l1-13M10 11v5M14 11v5" /></svg
                        >
                      </button>
                    {/if}
                  </div>
                {/if}

                {#if editorOpen && editingNoteId === activeNote.id}
                  {#key `${activeNote.id}:edit`}
                    <NoteRichTextEditor
                      value={noteDescription}
                      editable
                      onChange={updateNoteDescription}
                    />
                  {/key}
                {:else}
                  <NoteRichTextView value={activeNote.description} />
                {/if}

                {#if !activeNote.parent_id && activeNote.children.length}
                  <section class="mt-10 border-t border-border-soft pt-7">
                    <p class="text-xs font-bold uppercase tracking-wider text-primary">Submenu</p>
                    <h3 class="mt-1 text-base font-normal text-text-muted">Catatan terkait</h3>
                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                      {#each activeNote.children as child (child.id)}
                        <button
                          type="button"
                          class="group rounded-lg border border-border-soft p-4 text-left transition hover:border-primary/40 hover:bg-primary-light/30"
                          onclick={() => selectNote(child.id)}
                        >
                          <span class="text-sm font-bold text-text-default group-hover:text-primary"
                            >{child.title}</span
                          >
                          <span class="mt-1 line-clamp-2 block text-xs leading-5 text-text-muted"
                            >{noteExcerpt(child.description)}</span
                          >
                        </button>
                      {/each}
                    </div>
                  </section>
                {/if}
              </div>
            </article>
          {:else if !notesLoading}
            <section
              class="rounded-lg border border-dashed border-border-default bg-card-bg px-6 py-16 text-center"
            >
              <div
                class="mx-auto grid h-14 w-14 place-items-center rounded-full bg-primary-light text-xl text-primary"
              >
                ✎
              </div>
              <h2 class="mt-4 text-xl font-bold text-text-default">
                {notes.length ? 'Pilih catatan' : 'Belum ada catatan pengguna'}
              </h2>
              <p class="mx-auto mt-2 max-w-md text-sm leading-6 text-text-muted">
                {notes.length
                  ? 'Pilih menu atau submenu di sebelah kiri untuk membaca deskripsi tambahannya.'
                  : canManageNotes
                    ? 'Buat menu pertama, lalu isi deskripsi tambahan yang dibutuhkan pengguna.'
                    : 'Admin dapat menambahkan catatan khusus untuk entitas ini.'}
              </p>
            </section>
          {/if}
        </main>
      </div>
    {/if}
  </div>
</div>
