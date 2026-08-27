<script lang="ts">
  import { sanitizeNoteContent } from '$lib/utils/note-rich-text.js';

  interface Props {
    value?: string | null;
  }

  let { value = '' }: Props = $props();
  const safeContent = $derived(sanitizeNoteContent(value ?? ''));
</script>

<!-- Content is reduced to a fixed tag allowlist with every attribute removed; see note-rich-text.test.ts. -->
<!-- eslint-disable-next-line svelte/no-at-html-tags -->
<div class="note-rich-text-view">{@html safeContent}</div>

<style>
  .note-rich-text-view {
    min-height: 3rem;
    overflow-x: auto;
    color: var(--text-muted, #4b5675);
    font-size: 0.925rem;
    line-height: 1.8;
  }

  .note-rich-text-view :global(> * + *) {
    margin-top: 0.75rem;
  }

  .note-rich-text-view :global(h1) {
    color: var(--text-default, #252f4a);
    font-size: 1.75rem;
    font-weight: 800;
    line-height: 1.25;
  }

  .note-rich-text-view :global(h2) {
    color: var(--text-default, #252f4a);
    font-size: 1.35rem;
    font-weight: 700;
    line-height: 1.35;
  }

  .note-rich-text-view :global(h3) {
    color: var(--text-default, #252f4a);
    font-size: 1.1rem;
    font-weight: 700;
    line-height: 1.4;
  }

  .note-rich-text-view :global(h4),
  .note-rich-text-view :global(h5),
  .note-rich-text-view :global(h6) {
    color: var(--text-default, #252f4a);
    font-weight: 700;
    line-height: 1.45;
  }

  .note-rich-text-view :global(h4) {
    font-size: 1rem;
  }

  .note-rich-text-view :global(ul),
  .note-rich-text-view :global(ol) {
    padding-left: 1.5rem;
  }

  .note-rich-text-view :global(ul) {
    list-style: disc;
  }

  .note-rich-text-view :global(ol) {
    list-style: decimal;
  }

  .note-rich-text-view :global(blockquote) {
    border-left: 3px solid var(--primary, #1b84ff);
    padding-left: 1rem;
    color: var(--text-default, #252f4a);
    font-style: italic;
  }

  .note-rich-text-view :global(a) {
    color: var(--primary, #1b84ff);
    text-decoration: underline;
    text-underline-offset: 2px;
  }

  .note-rich-text-view :global(code) {
    border-radius: 0.25rem;
    background: #f1f3f6;
    padding: 0.1rem 0.3rem;
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 0.85em;
  }

  .note-rich-text-view :global(pre) {
    overflow-x: auto;
    border-radius: 0.5rem;
    background: #182433;
    padding: 1rem;
    color: #e6edf3;
  }

  .note-rich-text-view :global(pre code) {
    background: transparent;
    padding: 0;
    color: inherit;
  }

  .note-rich-text-view :global(img) {
    max-width: 100%;
    height: auto;
    border-radius: 0.5rem;
  }

  .note-rich-text-view :global(hr) {
    margin: 1.25rem 0;
    border: 0;
    border-top: 1px solid var(--border-default, #dbdfe9);
  }

  .note-rich-text-view :global(table) {
    width: 100%;
    min-width: 36rem;
    border-collapse: collapse;
    font-size: 0.875rem;
  }

  .note-rich-text-view :global(th),
  .note-rich-text-view :global(td) {
    border: 1px solid var(--border-default, #dbdfe9);
    padding: 0.65rem 0.75rem;
    text-align: left;
    vertical-align: top;
  }

  .note-rich-text-view :global(th) {
    background: #f9fafb;
    color: var(--text-default, #252f4a);
    font-weight: 700;
  }

  .note-rich-text-view :global(tbody tr:nth-child(even)) {
    background: #fafbfc;
  }

  .note-rich-text-view :global(.empty-note) {
    color: var(--text-muted, #99a1b7);
  }
</style>
