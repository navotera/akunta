<script lang="ts">
  import Icon from './Icon.svelte';
  import StatusPill from './StatusPill.svelte';
  import { formatRupiah, type TransactionRow } from '$lib/data/fixtures';

  let {
    rows,
    partyLabel,
    numberLabel
  }: {
    rows: TransactionRow[];
    partyLabel: string;
    numberLabel: string;
  } = $props();
</script>

<div class="overflow-hidden rounded-poso border border-line bg-white">
  <table class="w-full min-w-[860px] border-collapse bg-white text-sm">
    <thead class="bg-[#f8fafc] text-left text-[12px] font-bold uppercase tracking-[0.02em] text-muted">
      <tr>
        <th class="w-12 px-4 py-3"><input type="checkbox" class="size-4 rounded border-line" aria-label="Pilih semua" /></th>
        <th class="px-4 py-3">{numberLabel}</th>
        <th class="px-4 py-3">Tanggal</th>
        <th class="px-4 py-3">{partyLabel}</th>
        <th class="px-4 py-3">Jatuh Tempo</th>
        <th class="px-4 py-3 text-right">Total</th>
        <th class="px-4 py-3 text-center">Status</th>
        <th class="px-4 py-3 text-right">Aksi</th>
      </tr>
    </thead>
    <tbody class="divide-y divide-line">
      {#each rows as row (row.id)}
        <tr class="h-[55px] hover:bg-soft/70">
          <td class="px-4 py-3"><input type="checkbox" class="size-4 rounded border-line accent-blue" aria-label={`Pilih ${row.number}`} /></td>
          <td class="px-4 py-3 font-bold text-ink">{row.number}</td>
          <td class="px-4 py-3 text-muted">{row.date}</td>
          <td class="px-4 py-3 text-ink">{row.party}</td>
          <td class="px-4 py-3 text-muted">{row.due}</td>
          <td class="px-4 py-3 text-right tabular font-bold text-ink">{formatRupiah(row.total)}</td>
          <td class="px-4 py-3 text-center"><StatusPill status={row.status} /></td>
          <td class="px-4 py-3">
            <div class="flex justify-end gap-2">
              <button class="grid size-8 place-items-center rounded-poso border border-line text-muted hover:border-blue hover:text-blue" aria-label={`Lihat ${row.number}`}>
                <Icon name="eye" size={15} stroke={2} />
              </button>
              <button class="grid size-8 place-items-center rounded-poso border border-line text-muted hover:border-blue hover:text-blue" aria-label={`Menu ${row.number}`}>
                <Icon name="more-vertical" size={15} stroke={2.2} />
              </button>
            </div>
          </td>
        </tr>
      {/each}
    </tbody>
  </table>
</div>
