<script lang="ts">
  import Icon from './Icon.svelte';

  let {
    label,
    value,
    caption,
    tone = 'blue',
    icon = 'document'
  }: {
    label: string;
    value: string;
    caption: string;
    tone?: 'blue' | 'green' | 'amber' | 'violet';
    icon?: 'cart' | 'clock' | 'document' | 'file-text' | 'wallet';
  } = $props();

  const tones = {
    blue: 'bg-blue-soft text-blue',
    green: 'bg-green-soft text-green',
    amber: 'bg-amber-soft text-amber',
    violet: 'bg-violet-soft text-violet'
  };
</script>

<article class="panel rounded-poso p-5 shadow-sm">
  <div class="flex items-center gap-4">
    <div class={`grid size-12 shrink-0 place-items-center rounded-full ${tones[tone]}`}>
      <Icon name={icon} size={22} stroke={1.9} />
    </div>
    <div class="min-w-0">
      <p class="truncate text-[13px] font-medium text-muted">{label}</p>
      <p class="mt-1 truncate text-xl font-extrabold tracking-tight text-ink">{value}</p>
      <p class="mt-1 truncate text-[13px] text-muted">{caption}</p>
    </div>
  </div>
</article>
