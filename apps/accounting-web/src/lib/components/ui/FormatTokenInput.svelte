<script lang="ts">
  interface Props {
    value: string;
    placeholder?: string;
    onChange: (value: string) => void;
  }

  type Part = {
    id: number;
    type: 'text' | 'token';
    value: string;
  };

  let { value, placeholder = '', onChange }: Props = $props();
  let nextId = 1;
  let editor = $state<HTMLDivElement>();
  let parts = $state<Part[]>([]);

  const tokens = [
    '{tahun}',
    '{tahun_full}',
    '{bulan}',
    '{numbering}',
    '{tipe_jurnal}',
    '{thn}',
    '{bln}',
    '{incremented_number}',
  ];
  const tokenPattern =
    /(\{tahun_full\}|\{tahun\}|\{bulan\}|\{numbering\}|\{tipe_jurnal\}|\{thn\}|\{bln\}|\{incremented_number\})/g;

  function createPart(type: Part['type'], partValue: string): Part {
    return { id: nextId++, type, value: partValue };
  }

  function parse(nextValue: string): Part[] {
    const parsed: Part[] = [];
    let offset = 0;

    for (const match of nextValue.matchAll(tokenPattern)) {
      const index = match.index ?? 0;
      parsed.push(createPart('text', nextValue.slice(offset, index)));
      parsed.push(createPart('token', match[0]));
      offset = index + match[0].length;
    }
    parsed.push(createPart('text', nextValue.slice(offset)));

    return parsed;
  }

  function serialize(nextParts = parts): string {
    return nextParts.map((part) => part.value).join('');
  }

  function normalize(nextParts: Part[]): Part[] {
    const normalized: Part[] = [];

    for (const part of nextParts) {
      const previous = normalized.at(-1);
      if (part.type === 'text' && previous?.type === 'text') {
        previous.value += part.value;
      } else {
        normalized.push(part);
      }
    }

    if (normalized[0]?.type !== 'text') normalized.unshift(createPart('text', ''));
    if (normalized.at(-1)?.type !== 'text') normalized.push(createPart('text', ''));

    return normalized;
  }

  function commit(nextParts: Part[]) {
    parts = normalize(nextParts);
    onChange(serialize(parts));
  }

  function updateText(id: number, nextValue: string) {
    const part = parts.find((item) => item.id === id);
    if (!part) return;
    part.value = nextValue;
    onChange(serialize());
  }

  function removeToken(id: number) {
    commit(parts.filter((part) => part.id !== id));
  }

  function focusTextPart(id: number, position?: number) {
    requestAnimationFrame(() => {
      const input = editor?.querySelector<HTMLInputElement>(`[data-part-id="${id}"]`);
      if (!input) return;
      input.focus();
      const caret = position ?? input.value.length;
      input.setSelectionRange(caret, caret);
    });
  }

  function insertToken(event: DragEvent, token: string) {
    const target = event.target;
    const element =
      target instanceof HTMLElement ? target.closest<HTMLElement>('[data-part-id]') : null;
    const targetId = Number(element?.dataset.partId);
    const targetIndex = parts.findIndex((part) => part.id === targetId);
    const nextParts = [...parts];

    if (
      targetIndex >= 0 &&
      parts[targetIndex].type === 'text' &&
      element instanceof HTMLInputElement
    ) {
      const caret = element.selectionStart ?? element.value.length;
      const textPart = parts[targetIndex];
      const before = createPart('text', textPart.value.slice(0, caret));
      const inserted = createPart('token', token);
      const after = createPart('text', textPart.value.slice(caret));
      nextParts.splice(targetIndex, 1, before, inserted, after);
      commit(nextParts);
      focusTextPart(after.id, 0);
      return;
    }

    if (targetIndex >= 0) {
      const rect = element?.getBoundingClientRect();
      const insertAfter = rect ? event.clientX >= rect.left + rect.width / 2 : true;
      const insertIndex = targetIndex + (insertAfter ? 1 : 0);
      const inserted = createPart('token', token);
      const trailing = createPart('text', '');
      nextParts.splice(insertIndex, 0, inserted, trailing);
      commit(nextParts);
      focusTextPart(trailing.id, 0);
      return;
    }

    const lastText = nextParts.at(-1);
    const inserted = createPart('token', token);
    const trailing = createPart('text', '');
    nextParts.push(inserted, trailing);
    commit(nextParts);
    focusTextPart(trailing.id, 0);
  }

  function handleDrop(event: DragEvent) {
    event.preventDefault();
    const token = event.dataTransfer?.getData('text/plain') ?? '';
    if (tokens.includes(token)) insertToken(event, token);
  }

  function focusEnd(event: MouseEvent) {
    if (event.target !== editor) return;
    const lastText = [...parts].reverse().find((part) => part.type === 'text');
    if (lastText) focusTextPart(lastText.id);
  }

  $effect(() => {
    if (value !== serialize()) parts = parse(value);
  });
</script>

<div
  bind:this={editor}
  class="format-token-editor w-full rounded-md border border-border-default bg-card-bg px-3 py-2 font-mono text-sm focus-within:border-primary"
  role="group"
  aria-label={placeholder}
  ondragover={(event) => event.preventDefault()}
  ondrop={handleDrop}
  onclick={focusEnd}
>
  {#each parts as part (part.id)}
    {#if part.type === 'token'}
      <span class="format-token" data-part-id={part.id}>
        <span>{part.value}</span>
        <button
          type="button"
          class="format-token-remove"
          aria-label={`Hapus ${part.value}`}
          onclick={(event) => {
            event.stopPropagation();
            removeToken(part.id);
          }}>×</button
        >
      </span>
    {:else}
      <input
        type="text"
        class="format-text-input"
        data-part-id={part.id}
        value={part.value}
        size={Math.max(part.value.length, 1)}
        aria-label="Bagian teks format"
        placeholder={parts.length === 1 ? placeholder : ''}
        oninput={(event) => updateText(part.id, event.currentTarget.value)}
      />
    {/if}
  {/each}
</div>

<style>
  .format-token-editor {
    display: flex;
    min-height: 2.5rem;
    align-items: center;
    overflow-x: auto;
    cursor: text;
    white-space: nowrap;
  }

  .format-text-input {
    min-width: 1ch;
    max-width: none;
    flex: 0 0 auto;
    border: 0;
    background: transparent;
    padding: 0;
    color: inherit;
    font: inherit;
    outline: none;
  }

  .format-text-input::placeholder {
    color: var(--color-text-muted, #99a1b7);
  }

  .format-token {
    display: inline-flex;
    flex: 0 0 auto;
    align-items: center;
    margin: 0 1px;
    border: 1px solid rgb(27 132 255 / 30%);
    border-radius: 0.25rem;
    background: rgb(27 132 255 / 12%);
    padding: 0 0.25rem;
    color: #1b84ff;
    user-select: none;
  }

  .format-token-remove {
    margin-left: 0.2rem;
    border: 0;
    background: transparent;
    padding: 0;
    color: #d9214f;
    cursor: pointer;
    font: inherit;
    font-size: 0.8rem;
    line-height: 1;
  }

  .format-token-remove:hover {
    color: #b7193e;
  }
</style>
