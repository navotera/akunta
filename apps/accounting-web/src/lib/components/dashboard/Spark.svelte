<script lang="ts">
  interface Props {
    data: number[];
    w?: number;
    h?: number;
    color?: string;
    fill?: boolean;
    strokeW?: number;
  }

  let { data, w = 88, h = 32, color = 'var(--m-primary)', fill = true, strokeW = 1.6 }: Props = $props();

  const path = $derived.by(() => {
    if (data.length === 0) return '';
    const max = Math.max(...data);
    const min = Math.min(...data);
    const rng = max - min || 1;
    return data
      .map((v, i) => {
        const x = (i / (data.length - 1)) * w;
        const y = h - ((v - min) / rng) * (h - 4) - 2;
        return (i ? 'L' : 'M') + x.toFixed(1) + ',' + y.toFixed(1);
      })
      .join(' ');
  });

  const areaPath = $derived(path + ` L ${w},${h} L 0,${h} Z`);
  const gradId = `sp-${Math.random().toString(36).slice(2, 8)}`;
</script>

<svg width={w} height={h} viewBox={`0 0 ${w} ${h}`}>
  {#if fill}
    <defs>
      <linearGradient id={gradId} x1="0" x2="0" y1="0" y2="1">
        <stop offset="0%" stop-color={color} stop-opacity="0.18" />
        <stop offset="100%" stop-color={color} stop-opacity="0" />
      </linearGradient>
    </defs>
    <path d={areaPath} fill={`url(#${gradId})`} />
  {/if}
  <path d={path} fill="none" stroke={color} stroke-width={strokeW} stroke-linecap="round" stroke-linejoin="round" />
</svg>
