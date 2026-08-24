<script lang="ts">
  interface Props {
    income: number[];
    expense: number[];
    labels: string[];
    w?: number;
    h?: number;
  }

  let { income, expense, labels, w = 720, h = 220 }: Props = $props();

  const pad = { l: 44, r: 16, t: 16, b: 24 };
  const iw = $derived(w - pad.l - pad.r);
  const ih = $derived(h - pad.t - pad.b);

  const all = $derived([...income, ...expense]);
  const max = $derived(Math.max(...all, 1) * 1.15);
  const min = 0;

  function xAt(i: number): number {
    return pad.l + (i / Math.max(income.length - 1, 1)) * iw;
  }
  function yAt(v: number): number {
    return pad.t + ih - ((v - min) / (max - min)) * ih;
  }
  function mkPath(arr: number[]): string {
    return arr
      .map((v, i) => (i ? 'L' : 'M') + xAt(i).toFixed(1) + ',' + yAt(v).toFixed(1))
      .join(' ');
  }

  const grids = $derived([0, 0.25, 0.5, 0.75, 1].map((t) => min + t * (max - min)));
  const incomePath = $derived(mkPath(income));
  const expensePath = $derived(mkPath(expense));
  const incomeArea = $derived(
    incomePath + ` L ${xAt(income.length - 1)},${pad.t + ih} L ${pad.l},${pad.t + ih} Z`,
  );

  function fmtCompact(n: number): string {
    const a = Math.abs(n);
    if (a >= 1e9) return (n / 1e9).toFixed(1) + ' M';
    if (a >= 1e6) return (n / 1e6).toFixed(0) + ' jt';
    if (a >= 1e3) return (n / 1e3).toFixed(0) + ' rb';
    return n.toFixed(0);
  }
</script>

<svg width="100%" height={h} viewBox={`0 0 ${w} ${h}`} preserveAspectRatio="none">
  <defs>
    <linearGradient id="cfa" x1="0" x2="0" y1="0" y2="1">
      <stop offset="0%" stop-color="var(--m-primary)" stop-opacity="0.16" />
      <stop offset="100%" stop-color="var(--m-primary)" stop-opacity="0" />
    </linearGradient>
  </defs>

  {#each grids as g, i (i)}
    <line
      x1={pad.l}
      x2={w - pad.r}
      y1={yAt(g)}
      y2={yAt(g)}
      stroke="var(--m-border)"
      stroke-width="1"
      stroke-dasharray={i === 0 ? '0' : '2 4'}
    />
    <text
      x={pad.l - 8}
      y={yAt(g)}
      dy="3"
      font-size="10"
      text-anchor="end"
      fill="var(--m-text-muted)"
    >
      {fmtCompact(g)}
    </text>
  {/each}

  {#each labels as l, i (i)}
    <text x={xAt(i)} y={h - 6} font-size="10" text-anchor="middle" fill="var(--m-text-muted)"
      >{l}</text
    >
  {/each}

  <path d={incomeArea} fill="url(#cfa)" />
  <path
    d={incomePath}
    fill="none"
    stroke="var(--m-primary)"
    stroke-width="2"
    stroke-linejoin="round"
  />
  <path
    d={expensePath}
    fill="none"
    stroke="var(--m-text-muted)"
    stroke-width="1.6"
    stroke-dasharray="3 3"
    stroke-linejoin="round"
  />
  <circle
    cx={xAt(income.length - 1)}
    cy={yAt(income[income.length - 1] ?? 0)}
    r="3.5"
    fill="var(--m-primary)"
    stroke="white"
    stroke-width="2"
  />
</svg>
