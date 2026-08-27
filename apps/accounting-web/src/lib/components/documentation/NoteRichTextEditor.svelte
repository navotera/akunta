<script lang="ts">
  import { onDestroy, onMount } from 'svelte';
  import { markdownToHtml } from '$lib/utils/note-rich-text.js';

  interface Props {
    value?: string;
    editable?: boolean;
    onChange?: (value: string) => void;
  }

  let { value = '', editable = false, onChange = () => undefined }: Props = $props();
  let editorElement: HTMLDivElement;
  let commandRevision = $state(0);
  let empty = $state(true);

  function escapeHtml(text: string): string {
    return text
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  function normalizeContent(content: string): string {
    const trimmed = content.trim();
    if (!trimmed) return '<p><br></p>';
    if (/^<(p|h[1-6]|ul|ol|blockquote|pre|hr)(\s|>)/i.test(trimmed)) return trimmed;

    return trimmed
      .split(/\r?\n/)
      .map((line) => `<p>${escapeHtml(line) || '<br>'}</p>`)
      .join('');
  }

  function refreshState(): void {
    commandRevision += 1;
    empty = !editorElement?.textContent?.trim() && !editorElement?.querySelector('hr');
  }

  function demoteHeading(heading: HTMLHeadingElement): void {
    const paragraph = document.createElement('p');
    paragraph.innerHTML = heading.innerHTML;
    heading.replaceWith(paragraph);
  }

  function enforceSingleH1(): void {
    const headings = [...(editorElement?.querySelectorAll('h1') ?? [])];
    headings.slice(1).forEach((heading) => demoteHeading(heading));
  }

  function emitChange(): void {
    enforceSingleH1();
    refreshState();
    onChange(empty ? '' : editorElement.innerHTML);
  }

  function run(command: string, value?: string): void {
    if (!editable) return;
    document.execCommand(command, false, value);
    emitChange();
    editorElement.focus();
  }

  function runBlock(tag: 'h1' | 'h2' | 'h3' | 'blockquote'): void {
    if (tag === 'h1' && !isBlockActive('h1')) {
      const existingHeading = editorElement.querySelector('h1');
      if (existingHeading) demoteHeading(existingHeading);
    }
    run('formatBlock', isBlockActive(tag) ? 'p' : tag);
  }

  function runAlignment(alignment: 'left' | 'center' | 'right' | 'justify'): void {
    run(`justify${alignment[0].toUpperCase()}${alignment.slice(1)}`);
  }

  function addLink(): void {
    if (!editable) return;
    const url = window.prompt('URL tautan');
    if (url?.trim()) run('createLink', url.trim());
  }

  function isCommandActive(command: string): boolean {
    void commandRevision;
    return document.queryCommandState(command);
  }

  function isBlockActive(tag: string): boolean {
    void commandRevision;
    return document.queryCommandValue('formatBlock').toLowerCase() === tag;
  }

  function isCommandEnabled(command: string): boolean {
    void commandRevision;
    return document.queryCommandEnabled(command);
  }

  function preserveSelection(event: MouseEvent): void {
    event.preventDefault();
  }

  function clearActiveInlineFormatting(): void {
    for (const command of [
      'bold',
      'italic',
      'underline',
      'strikeThrough',
      'subscript',
      'superscript',
    ]) {
      if (document.queryCommandState(command)) document.execCommand(command, false);
    }
  }

  function hasRenderableContent(node: Node): boolean {
    return Boolean(
      node.textContent?.trim() ||
      (node instanceof Element && node.querySelector('img, hr, table, ul, ol, pre')),
    );
  }

  function placeCaretAfter(node: Node): void {
    const selection = window.getSelection();
    if (!selection) return;
    const range = document.createRange();
    range.setStartAfter(node);
    range.collapse(true);
    selection.removeAllRanges();
    selection.addRange(range);
  }

  function insertMarkdownHtml(html: string): void {
    const selection = window.getSelection();
    const activeRange = selection?.rangeCount ? selection.getRangeAt(0) : null;
    const template = document.createElement('template');
    template.innerHTML = html;
    const insertedNodes = [...template.content.childNodes];
    if (!insertedNodes.length) return;

    if (!activeRange || !editorElement.contains(activeRange.commonAncestorContainer)) {
      editorElement.append(template.content);
      placeCaretAfter(insertedNodes.at(-1)!);
      return;
    }

    activeRange.deleteContents();
    const marker = document.createElement('span');
    marker.setAttribute('data-markdown-paste-marker', '');
    activeRange.insertNode(marker);

    let rootBlock: Node = marker;
    while (rootBlock.parentNode && rootBlock.parentNode !== editorElement) {
      rootBlock = rootBlock.parentNode;
    }

    const splittable =
      rootBlock instanceof HTMLElement && /^(P|H[1-6]|BLOCKQUOTE|PRE)$/.test(rootBlock.tagName);
    const replacement = document.createDocumentFragment();

    if (splittable) {
      const beforeRange = document.createRange();
      beforeRange.selectNodeContents(rootBlock);
      beforeRange.setEndBefore(marker);
      const beforeBlock = rootBlock.cloneNode(false) as HTMLElement;
      beforeBlock.append(beforeRange.cloneContents());

      const afterRange = document.createRange();
      afterRange.selectNodeContents(rootBlock);
      afterRange.setStartAfter(marker);
      const afterBlock = rootBlock.cloneNode(false) as HTMLElement;
      afterBlock.append(afterRange.cloneContents());

      if (hasRenderableContent(beforeBlock)) replacement.append(beforeBlock);
      replacement.append(template.content);
      if (hasRenderableContent(afterBlock)) replacement.append(afterBlock);
      editorElement.replaceChild(replacement, rootBlock);
    } else if (rootBlock === marker) {
      editorElement.insertBefore(template.content, marker);
      marker.remove();
    } else {
      marker.remove();
      editorElement.insertBefore(template.content, rootBlock.nextSibling);
    }

    placeCaretAfter(insertedNodes.at(-1)!);
  }

  function handlePaste(event: ClipboardEvent): void {
    event.preventDefault();
    const markdown = event.clipboardData?.getData('text/plain') ?? '';
    clearActiveInlineFormatting();
    insertMarkdownHtml(markdownToHtml(markdown));
    emitChange();
    editorElement.focus();
  }

  function handleSelectionChange(): void {
    if (
      document.activeElement === editorElement ||
      editorElement?.contains(document.activeElement)
    ) {
      refreshState();
    }
  }

  onMount(() => {
    editorElement.innerHTML = normalizeContent(value);
    enforceSingleH1();
    refreshState();
    document.addEventListener('selectionchange', handleSelectionChange);
  });

  onDestroy(() => document.removeEventListener('selectionchange', handleSelectionChange));

  $effect(() => {
    if (!editorElement) return;
    const normalized = normalizeContent(value);
    if (editorElement.innerHTML !== normalized && document.activeElement !== editorElement) {
      editorElement.innerHTML = normalized;
      refreshState();
    }
  });
</script>

<div class:editing={editable} class="note-editor">
  {#if editable}
    <div
      class="sticky top-0 z-10 flex flex-wrap items-center gap-1 border-b border-border-soft bg-white p-2"
      role="toolbar"
      aria-label="Format catatan"
      tabindex="0"
      onmousedown={preserveSelection}
    >
      <button
        type="button"
        class:active={isBlockActive('h1')}
        title="Judul utama"
        aria-label="Judul utama"
        onclick={() => runBlock('h1')}>H1</button
      >
      <button
        type="button"
        class:active={isBlockActive('h2')}
        title="Judul besar"
        aria-label="Judul besar"
        onclick={() => runBlock('h2')}>H2</button
      >
      <button
        type="button"
        class:active={isBlockActive('h3')}
        title="Judul kecil"
        aria-label="Judul kecil"
        onclick={() => runBlock('h3')}>H3</button
      >
      <span class="divider" aria-hidden="true"></span>
      <button
        type="button"
        class:active={isCommandActive('bold')}
        title="Tebal"
        aria-label="Tebal"
        onclick={() => run('bold')}
      >
        <svg viewBox="0 0 24 24" aria-hidden="true"
          ><path d="M7 5h6a4 4 0 0 1 0 8H7zm0 8h7a4 4 0 0 1 0 8H7z" /></svg
        >
      </button>
      <button
        type="button"
        class:active={isCommandActive('italic')}
        title="Miring"
        aria-label="Miring"
        onclick={() => run('italic')}
      >
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 5h7M7 19h7M14 5 10 19" /></svg>
      </button>
      <button
        type="button"
        class:active={isCommandActive('underline')}
        title="Garis bawah"
        aria-label="Garis bawah"
        onclick={() => run('underline')}
      >
        <svg viewBox="0 0 24 24" aria-hidden="true"
          ><path d="M7 4v6a5 5 0 0 0 10 0V4M5 21h14" /></svg
        >
      </button>
      <button
        type="button"
        class:active={isCommandActive('strikeThrough')}
        title="Coret"
        aria-label="Coret"
        onclick={() => run('strikeThrough')}
      >
        <svg viewBox="0 0 24 24" aria-hidden="true"
          ><path
            d="M17 6.5A5 5 0 0 0 8 9c0 1.4 1.1 2.4 2.7 3M7 17.5A5.5 5.5 0 0 0 17 15M4 12h16"
          /></svg
        >
      </button>
      <button
        type="button"
        class:active={isCommandActive('subscript')}
        title="Subskrip"
        aria-label="Subskrip"
        onclick={() => run('subscript')}>X<sub>2</sub></button
      >
      <button
        type="button"
        class:active={isCommandActive('superscript')}
        title="Superskrip"
        aria-label="Superskrip"
        onclick={() => run('superscript')}>X<sup>2</sup></button
      >
      <span class="divider" aria-hidden="true"></span>
      <button
        type="button"
        class:active={isCommandActive('insertUnorderedList')}
        title="Daftar bullet"
        aria-label="Daftar bullet"
        onclick={() => run('insertUnorderedList')}
      >
        <svg viewBox="0 0 24 24" aria-hidden="true"
          ><path d="M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01" /></svg
        >
      </button>
      <button
        type="button"
        title="Code block"
        aria-label="Code block"
        onclick={() => run('formatBlock', isBlockActive('pre') ? 'p' : 'pre')}>Code</button
      >
      <button type="button" title="Tautan" aria-label="Tautan" onclick={addLink}>Link</button>
      <span class="divider" aria-hidden="true"></span>
      <button
        type="button"
        title="Rata kiri"
        aria-label="Rata kiri"
        onclick={() => runAlignment('left')}>≡</button
      >
      <button
        type="button"
        title="Rata tengah"
        aria-label="Rata tengah"
        onclick={() => runAlignment('center')}>≡</button
      >
      <button
        type="button"
        title="Rata kanan"
        aria-label="Rata kanan"
        onclick={() => runAlignment('right')}>≡</button
      >
      <button
        type="button"
        title="Rata penuh"
        aria-label="Rata penuh"
        onclick={() => runAlignment('justify')}>≡</button
      >
      <span class="divider" aria-hidden="true"></span>
      <button
        type="button"
        title="Kurangi indentasi"
        aria-label="Kurangi indentasi"
        onclick={() => run('outdent')}>←</button
      >
      <button
        type="button"
        title="Tambah indentasi"
        aria-label="Tambah indentasi"
        onclick={() => run('indent')}>→</button
      >
      <button
        type="button"
        title="Hapus format"
        aria-label="Hapus format"
        onclick={() => run('removeFormat')}>Tx</button
      >
      <span class="divider" aria-hidden="true"></span>
      <button
        type="button"
        class:active={isCommandActive('insertOrderedList')}
        title="Daftar angka"
        aria-label="Daftar angka"
        onclick={() => run('insertOrderedList')}
      >
        <svg viewBox="0 0 24 24" aria-hidden="true"
          ><path d="M10 6h10M10 12h10M10 18h10M4 5h2v3M4 11h2l-2 3h2M4 17h2v3H4" /></svg
        >
      </button>
      <button
        type="button"
        class:active={isBlockActive('blockquote')}
        title="Kutipan"
        aria-label="Kutipan"
        onclick={() => runBlock('blockquote')}
      >
        <svg viewBox="0 0 24 24" aria-hidden="true"
          ><path d="M5 7h5v5H6v5H4v-7a3 3 0 0 1 3-3m9 0h5v5h-4v5h-2v-7a3 3 0 0 1 3-3" /></svg
        >
      </button>
      <button
        type="button"
        title="Garis pemisah"
        aria-label="Garis pemisah"
        onclick={() => run('insertHorizontalRule')}
      >
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 12h16" /></svg>
      </button>
      <span class="divider" aria-hidden="true"></span>
      <button
        type="button"
        title="Urungkan"
        aria-label="Urungkan"
        disabled={!isCommandEnabled('undo')}
        onclick={() => run('undo')}
      >
        <svg viewBox="0 0 24 24" aria-hidden="true"
          ><path d="m9 7-5 5 5 5M5 12h8a6 6 0 0 1 6 6" /></svg
        >
      </button>
      <button
        type="button"
        title="Ulangi"
        aria-label="Ulangi"
        disabled={!isCommandEnabled('redo')}
        onclick={() => run('redo')}
      >
        <svg viewBox="0 0 24 24" aria-hidden="true"
          ><path d="m15 7 5 5-5 5m4-5h-8a6 6 0 0 0-6 6" /></svg
        >
      </button>
    </div>
  {/if}
  <div
    bind:this={editorElement}
    class="note-rich-editor-content"
    contenteditable={editable}
    role="textbox"
    aria-multiline="true"
    aria-label={editable ? 'Isi catatan' : 'Konten catatan'}
    tabindex={editable ? 0 : undefined}
    data-empty={empty}
    oninput={emitChange}
    onkeyup={refreshState}
    onmouseup={refreshState}
    onpaste={handlePaste}
  ></div>
</div>

<style>
  .note-editor.editing {
    overflow: hidden;
    border: 1px solid var(--border-default, #dbdfe9);
    border-radius: 0.75rem;
    background: #fff;
  }

  [role='toolbar'] button {
    display: grid;
    min-width: 2rem;
    height: 2rem;
    place-items: center;
    border-radius: 0.375rem;
    padding: 0 0.5rem;
    color: var(--text-muted, #78829d);
    font-size: 0.7rem;
    font-weight: 700;
    transition: 150ms ease;
  }

  [role='toolbar'] button:hover:not(:disabled),
  [role='toolbar'] button.active {
    background: var(--primary-light, #eef6ff);
    color: var(--primary, #1b84ff);
  }

  [role='toolbar'] button:disabled {
    cursor: not-allowed;
    opacity: 0.35;
  }

  [role='toolbar'] svg {
    width: 1rem;
    height: 1rem;
    fill: none;
    stroke: currentColor;
    stroke-linecap: round;
    stroke-linejoin: round;
    stroke-width: 2;
  }

  .divider {
    width: 1px;
    height: 1.25rem;
    margin: 0 0.125rem;
    background: var(--border-soft, #eff2f5);
  }

  .note-rich-editor-content {
    min-height: 3rem;
    overflow-x: auto;
    outline: none;
    background: #fff;
    color: var(--text-muted, #4b5675);
    font-size: 0.925rem;
    line-height: 1.8;
  }

  .editing .note-rich-editor-content {
    min-height: 16rem;
    padding: 1.25rem;
    color: var(--text-default, #252f4a);
  }

  .note-rich-editor-content :global(> * + *) {
    margin-top: 0.75rem;
  }

  .note-rich-editor-content :global(h1) {
    color: var(--text-default, #252f4a);
    font-size: 1.75rem;
    font-weight: 800;
    line-height: 1.25;
  }

  .note-rich-editor-content :global(h2) {
    color: var(--text-default, #252f4a);
    font-size: 1.35rem;
    font-weight: 700;
    line-height: 1.35;
  }

  .note-rich-editor-content :global(h3) {
    color: var(--text-default, #252f4a);
    font-size: 1.1rem;
    font-weight: 700;
    line-height: 1.4;
  }

  .note-rich-editor-content :global(h4),
  .note-rich-editor-content :global(h5),
  .note-rich-editor-content :global(h6) {
    color: var(--text-default, #252f4a);
    font-weight: 700;
    line-height: 1.45;
  }

  .note-rich-editor-content :global(h4) {
    font-size: 1rem;
  }

  .note-rich-editor-content :global(ul),
  .note-rich-editor-content :global(ol) {
    padding-left: 1.5rem;
  }

  .note-rich-editor-content :global(ul) {
    list-style: disc;
  }

  .note-rich-editor-content :global(ol) {
    list-style: decimal;
  }

  .note-rich-editor-content :global(blockquote) {
    border-left: 3px solid var(--primary, #1b84ff);
    padding-left: 1rem;
    color: var(--text-default, #252f4a);
    font-style: italic;
  }

  .note-rich-editor-content :global(a) {
    color: var(--primary, #1b84ff);
    text-decoration: underline;
    text-underline-offset: 2px;
  }

  .note-rich-editor-content :global(code) {
    border-radius: 0.25rem;
    background: #f1f3f6;
    padding: 0.1rem 0.3rem;
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 0.85em;
  }

  .note-rich-editor-content :global(pre) {
    overflow-x: auto;
    border-radius: 0.5rem;
    background: #182433;
    padding: 1rem;
    color: #e6edf3;
  }

  .note-rich-editor-content :global(pre code) {
    background: transparent;
    padding: 0;
    color: inherit;
  }

  .note-rich-editor-content :global(img) {
    max-width: 100%;
    height: auto;
    border-radius: 0.5rem;
  }

  .note-rich-editor-content :global(hr) {
    margin: 1.25rem 0;
    border: 0;
    border-top: 1px solid var(--border-default, #dbdfe9);
  }

  .note-rich-editor-content :global(table) {
    width: 100%;
    min-width: 36rem;
    border-collapse: collapse;
    font-size: 0.875rem;
  }

  .note-rich-editor-content :global(th),
  .note-rich-editor-content :global(td) {
    border: 1px solid var(--border-default, #dbdfe9);
    padding: 0.65rem 0.75rem;
    text-align: left;
    vertical-align: top;
  }

  .note-rich-editor-content :global(th) {
    background: #f9fafb;
    font-weight: 700;
  }

  .note-rich-editor-content :global(tbody tr:nth-child(even)) {
    background: #fafbfc;
  }

  .note-rich-editor-content[data-empty='true']::before {
    content: 'Mulai menulis catatan…';
    color: var(--text-muted, #99a1b7);
    pointer-events: none;
  }
</style>
