<script lang="ts">
  export type BookMode = 'internal' | 'fiscal';
  export type BookToggleValue = BookMode | 'both';

  interface Props {
    value: BookToggleValue;
    disabled?: boolean;
    includeBoth?: boolean;
    onChange: (value: BookToggleValue) => void | Promise<void>;
  }

  let { value, disabled = false, includeBoth = false, onChange }: Props = $props();
</script>

<div
  class="flex items-center gap-1 rounded-full border border-border-default bg-card-bg p-1 text-sm shadow-xs"
  role="group"
  aria-label="Pilih buku laporan"
>
  <button
    type="button"
    class="rounded-full px-3 py-1 font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-60 {value ===
    'internal'
      ? 'bg-[#22c55e] text-white'
      : 'text-text-muted hover:text-text-default'}"
    onclick={() => onChange('internal')}
    {disabled}
    aria-pressed={value === 'internal'}>Intern</button
  >
  <button
    type="button"
    class="rounded-full px-3 py-1 font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-60 {value ===
    'fiscal'
      ? 'bg-[#facc15] text-[#5a4300]'
      : 'text-text-muted hover:text-text-default'}"
    onclick={() => onChange('fiscal')}
    {disabled}
    aria-pressed={value === 'fiscal'}>Fiskal</button
  >
  {#if includeBoth}
    <button
      type="button"
      class="rounded-full px-3 py-1 font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-60 {value ===
      'both'
        ? 'bg-gradient-to-r from-[#22c55e] to-[#facc15] text-white'
        : 'text-text-muted hover:text-text-default'}"
      onclick={() => onChange('both')}
      {disabled}
      aria-pressed={value === 'both'}>Intern &amp; Fiskal</button
    >
  {/if}
</div>
