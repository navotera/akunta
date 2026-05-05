<script lang="ts">
  /**
   * Date picker that DISPLAYS the value as `dd Bulan YY` (Indonesian locale)
   * while keeping ISO `YYYY-MM-DD` for binding/transport. Click anywhere on
   * the chip → opens the native date picker.
   *
   * Keep `value` and `onChange` ISO strings to stay compatible with backend
   * `date_format:Y-m-d` validators.
   */
  interface Props {
    value: string;
    onChange: (iso: string) => void;
    placeholder?: string;
    disabled?: boolean;
    /** Optional pass-through className for outer wrapper. */
    class?: string;
    name?: string;
    /** Forward to input for E2E hooks. */
    testId?: string;
  }

  let {
    value,
    onChange,
    placeholder = 'Pilih tanggal',
    disabled = false,
    class: extraClass = '',
    name,
    testId,
  }: Props = $props();

  let inputEl: HTMLInputElement | undefined = $state();

  const display = $derived(format(value));

  function format(iso: string): string {
    if (!iso) return '';
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso);
    if (!m) return iso;
    const [, y, mm, d] = m;
    const date = new Date(Number(y), Number(mm) - 1, Number(d));
    if (Number.isNaN(date.getTime())) return iso;
    const day = String(date.getDate()).padStart(2, '0');
    const month = date.toLocaleString('id-ID', { month: 'long' });
    const year = String(date.getFullYear()).slice(-2);
    return `${day} ${month} ${year}`;
  }

  function open() {
    if (disabled) return;
    if (inputEl && typeof inputEl.showPicker === 'function') {
      try { inputEl.showPicker(); return; } catch {}
    }
    inputEl?.focus();
    inputEl?.click();
  }

  function onInput(e: Event) {
    onChange((e.target as HTMLInputElement).value);
  }
</script>

<div class="relative inline-flex w-full {extraClass}">
  <button
    type="button"
    class="w-full rounded-md border border-border-default bg-white px-2 py-1.5 text-left text-sm focus:outline-none focus:border-primary disabled:opacity-50 disabled:cursor-not-allowed
           {display ? 'text-text-default' : 'text-text-muted'}"
    onclick={open}
    {disabled}
  >
    {display || placeholder}
  </button>
  <input
    bind:this={inputEl}
    type="date"
    class="absolute inset-0 opacity-0 pointer-events-none"
    {value}
    {name}
    {disabled}
    data-testid={testId}
    oninput={onInput}
    tabindex="-1"
    aria-hidden="true"
  />
</div>
