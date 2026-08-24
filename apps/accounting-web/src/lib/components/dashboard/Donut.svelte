<script lang="ts">
  interface Slice {
    value: number;
    color: string;
  }

  interface Props {
    data: Slice[];
    size?: number;
    thickness?: number;
  }

  let { data, size = 148, thickness = 18 }: Props = $props();

  const total = $derived(data.reduce((s, d) => s + d.value, 0));
  const r = $derived((size - thickness) / 2);
  const c = $derived(2 * Math.PI * r);

  const slices = $derived.by(() => {
    let off = 0;
    const out: Array<{ color: string; len: number; offset: number }> = [];
    for (const d of data) {
      const len = total > 0 ? (d.value / total) * c : 0;
      out.push({ color: d.color, len, offset: off });
      off += len;
    }
    return out;
  });
</script>

<svg width={size} height={size} viewBox={`0 0 ${size} ${size}`}>
  <circle
    cx={size / 2}
    cy={size / 2}
    {r}
    fill="none"
    stroke="var(--m-bg-page)"
    stroke-width={thickness}
  />
  {#each slices as s, i (i)}
    <circle
      cx={size / 2}
      cy={size / 2}
      {r}
      fill="none"
      stroke={s.color}
      stroke-width={thickness}
      stroke-dasharray={`${s.len} ${c - s.len}`}
      stroke-dashoffset={-s.offset}
      transform={`rotate(-90 ${size / 2} ${size / 2})`}
    />
  {/each}
</svg>
