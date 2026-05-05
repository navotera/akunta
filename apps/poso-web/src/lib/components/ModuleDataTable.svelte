<script lang="ts">
  import Icon from './Icon.svelte';
  import { formatRupiah } from '$lib/data/fixtures';

  type Row = Record<string, unknown>;

  let {
    title,
    rows,
    columns,
    moneyColumns = []
  }: {
    title: string;
    rows: Row[];
    columns: string[];
    moneyColumns?: string[];
  } = $props();

  let search = $state('');
  let filteredRows = $derived(
    rows.filter((row) => {
      const needle = search.trim().toLowerCase();
      if (!needle) return true;

      return columns.some((column) => rowValue(row, column).toLowerCase().includes(needle));
    })
  );

  function rowValue(row: Row, key: string): string {
    const value = row[key];
    if (value === null || value === undefined || value === '') return '-';
    if (typeof value === 'number') return String(value);
    if (typeof value === 'boolean') return value ? 'Aktif' : 'Nonaktif';
    return String(value);
  }

  function money(value: unknown): string {
    return formatRupiah(Number(value ?? 0));
  }

  function statusClass(status: unknown): string {
    if (status === 'sent' || status === 'paid' || status === 'Aktif') return 'bg-green-soft text-green';
    if (status === 'failed') return 'bg-red-soft text-red';
    return 'bg-amber-soft text-amber';
  }
</script>

<section class="panel overflow-hidden rounded-poso">
  <div class="flex flex-col gap-3 border-b border-line px-5 py-4 md:flex-row md:items-center md:justify-between">
    <h2 class="text-base font-bold text-ink">{title}</h2>
    <label class="relative w-full md:max-w-xs">
      <span class="sr-only">Cari</span>
      <input class="field h-10 pr-10" bind:value={search} placeholder="Cari data..." />
      <span class="absolute right-3 top-1/2 -translate-y-1/2 text-muted"><Icon name="grid" size={15} /></span>
    </label>
  </div>

  <div class="overflow-x-auto">
    <table class="w-full min-w-[860px] text-sm">
      <thead class="bg-soft text-left text-[11px] font-bold uppercase tracking-[0.02em] text-muted">
        <tr>
          {#each columns as column}
            <th class="px-4 py-3">{column.replaceAll('_', ' ')}</th>
          {/each}
          <th class="w-12 px-4 py-3"></th>
        </tr>
      </thead>
      <tbody class="divide-y divide-line bg-white">
        {#if filteredRows.length === 0}
          <tr>
            <td class="px-4 py-8 text-center text-sm font-semibold text-muted" colspan={columns.length + 1}>
              {search ? 'Data tidak ditemukan' : 'Belum ada data'}
            </td>
          </tr>
        {:else}
          {#each filteredRows as row}
            <tr class="hover:bg-soft/70">
              {#each columns as column}
                <td class="max-w-[260px] truncate px-4 py-3 text-ink">
                  {#if column === 'status'}
                    <span class={`rounded-full px-2.5 py-1 text-xs font-bold ${statusClass(row[column])}`}>{rowValue(row, column)}</span>
                  {:else if moneyColumns.includes(column)}
                    <span class="font-semibold tabular">{money(row[column])}</span>
                  {:else}
                    {rowValue(row, column)}
                  {/if}
                </td>
              {/each}
              <td class="px-4 py-3">
                <button class="grid size-8 place-items-center rounded-poso border border-line text-muted hover:border-blue hover:text-blue" aria-label="Detail">
                  <Icon name="eye" size={15} />
                </button>
              </td>
            </tr>
          {/each}
        {/if}
      </tbody>
    </table>
  </div>

  <div class="border-t border-line px-5 py-3 text-xs font-semibold text-muted">
    Menampilkan {filteredRows.length} dari {rows.length} data
  </div>
</section>
