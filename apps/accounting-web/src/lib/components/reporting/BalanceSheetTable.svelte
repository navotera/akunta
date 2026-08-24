<script lang="ts">
  import type { BalanceSheetData, BalanceSheetSection } from '$lib/api/reporting.js';
  import { formatRupiah } from '@akunta/ui';
  import { sourceFromName } from './sourceFromName.js';

  interface Props {
    report: BalanceSheetData;
    fiscal?: BalanceSheetData;
    primaryBook?: 'internal' | 'fiscal';
  }

  interface MergedRow {
    id: string;
    code: string;
    name: string;
    internalBalance: string;
    fiscalBalance: string;
  }

  let { report, fiscal, primaryBook = 'internal' }: Props = $props();

  const internHeader = 'bg-gradient-to-r from-[#f0fdf4] to-[#dcfce7] text-[#166534]';
  const fiscalHeader = 'bg-gradient-to-r from-[#fffbeb] to-[#fef9c3] text-[#854d0e]';

  function mergedRows(internal: BalanceSheetSection, fiscalSection?: BalanceSheetSection) {
    const rows = new Map<string, MergedRow>();

    for (const row of internal.lines) {
      rows.set(row.id, {
        id: row.id,
        code: row.code,
        name: row.name,
        internalBalance: row.balance,
        fiscalBalance: '0.00',
      });
    }

    for (const row of fiscalSection?.lines ?? []) {
      const existing = rows.get(row.id);
      if (existing) {
        existing.fiscalBalance = row.balance;
      } else {
        rows.set(row.id, {
          id: row.id,
          code: row.code,
          name: row.name,
          internalBalance: '0.00',
          fiscalBalance: row.balance,
        });
      }
    }

    return [...rows.values()].sort((a, b) => a.code.localeCompare(b.code));
  }

  function total(section: BalanceSheetSection | undefined) {
    return section?.total ?? '0.00';
  }
</script>

<section class="min-w-0 rounded-md border border-border-soft bg-card-bg">
  <header
    class="flex flex-wrap items-center justify-between gap-2 border-b border-border-soft bg-page-bg px-3 py-2 text-xs font-bold uppercase tracking-wider text-text-muted"
  >
    <span>Neraca</span>
  </header>
  <div class="grid grid-cols-1 gap-5 p-5 xl:grid-cols-2">
    <section class="min-w-0 overflow-hidden rounded-md border border-border-soft">
      <header
        class="bg-page-bg px-3 py-2 text-xs font-bold uppercase tracking-wider text-text-muted"
      >
        Aset
      </header>
      <div class="overflow-x-auto">
        <table class="w-full min-w-[38rem] text-sm">
          <thead class="border-t border-border-soft bg-page-bg/60 text-xs text-text-muted">
            <tr>
              <th class="w-20 px-3 py-2 text-left font-semibold">Kode</th>
              <th class="px-3 py-2 text-left font-semibold">Nama Akun</th>
              <th
                class="px-3 py-2 text-center font-semibold {primaryBook === 'fiscal'
                  ? fiscalHeader
                  : internHeader}">{primaryBook === 'fiscal' ? 'Fiskal' : 'Intern'}</th
              >
              {#if fiscal}<th class="px-3 py-2 text-center font-semibold {fiscalHeader}">Fiskal</th
                >{/if}
            </tr>
          </thead>
          <tbody>
            {#each mergedRows(report.assets, fiscal?.assets) as row (row.id)}
              <tr class="border-t border-border-soft">
                <td class="w-20 px-3 py-2 font-mono">{row.code}</td>
                <td class="px-3 py-2">
                  <span>{row.name}</span>
                  <span class="ak-source-pill ml-2">{sourceFromName(row.name)}</span>
                </td>
                <td class="px-3 py-2 text-right font-mono tabnum"
                  >{formatRupiah(row.internalBalance)}</td
                >
                {#if fiscal}
                  <td class="px-3 py-2 text-right font-mono tabnum"
                    >{formatRupiah(row.fiscalBalance)}</td
                  >
                {/if}
              </tr>
            {/each}
            <tr class="border-t-2 border-border-default bg-page-bg">
              <td class="px-3 py-2 font-semibold" colspan="2">Total Aset</td>
              <td class="px-3 py-2 text-right font-mono tabnum font-bold"
                >{formatRupiah(total(report.assets))}</td
              >
              {#if fiscal}
                <td class="px-3 py-2 text-right font-mono tabnum font-bold"
                  >{formatRupiah(total(fiscal.assets))}</td
                >
              {/if}
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <section class="min-w-0 overflow-hidden rounded-md border border-border-soft">
      <header
        class="bg-page-bg px-3 py-2 text-xs font-bold uppercase tracking-wider text-text-muted"
      >
        Liabilitas + Ekuitas
      </header>
      <div class="overflow-x-auto">
        <table class="w-full min-w-[38rem] text-sm">
          <thead class="border-t border-border-soft bg-page-bg/60 text-xs text-text-muted">
            <tr>
              <th class="w-20 px-3 py-2 text-left font-semibold">Kode</th>
              <th class="px-3 py-2 text-left font-semibold">Nama Akun</th>
              <th
                class="px-3 py-2 text-center font-semibold {primaryBook === 'fiscal'
                  ? fiscalHeader
                  : internHeader}">{primaryBook === 'fiscal' ? 'Fiskal' : 'Intern'}</th
              >
              {#if fiscal}<th class="px-3 py-2 text-center font-semibold {fiscalHeader}">Fiskal</th
                >{/if}
            </tr>
          </thead>
          <tbody>
            <tr class="bg-page-bg/60">
              <td colspan={fiscal ? 4 : 3} class="px-3 py-1.5 text-xs font-semibold text-text-muted"
                >Liabilitas</td
              >
            </tr>
            {#each mergedRows(report.liabilities, fiscal?.liabilities) as row (row.id)}
              <tr class="border-t border-border-soft">
                <td class="w-20 px-3 py-2 font-mono">{row.code}</td>
                <td class="px-3 py-2">
                  <span>{row.name}</span>
                  <span class="ak-source-pill ml-2">{sourceFromName(row.name)}</span>
                </td>
                <td class="px-3 py-2 text-right font-mono tabnum"
                  >{formatRupiah(row.internalBalance)}</td
                >
                {#if fiscal}
                  <td class="px-3 py-2 text-right font-mono tabnum"
                    >{formatRupiah(row.fiscalBalance)}</td
                  >
                {/if}
              </tr>
            {/each}
            <tr class="border-t border-border-default">
              <td class="px-3 py-1.5 font-semibold" colspan="2">Total Liabilitas</td>
              <td class="px-3 py-1.5 text-right font-mono tabnum font-semibold"
                >{formatRupiah(total(report.liabilities))}</td
              >
              {#if fiscal}
                <td class="px-3 py-1.5 text-right font-mono tabnum font-semibold"
                  >{formatRupiah(total(fiscal.liabilities))}</td
                >
              {/if}
            </tr>

            <tr class="bg-page-bg/60">
              <td colspan={fiscal ? 4 : 3} class="px-3 py-1.5 text-xs font-semibold text-text-muted"
                >Ekuitas</td
              >
            </tr>
            {#each mergedRows(report.equity, fiscal?.equity) as row (row.id)}
              <tr class="border-t border-border-soft">
                <td class="w-20 px-3 py-2 font-mono">{row.code}</td>
                <td class="px-3 py-2">
                  <span>{row.name}</span>
                  <span class="ak-source-pill ml-2">{sourceFromName(row.name)}</span>
                </td>
                <td class="px-3 py-2 text-right font-mono tabnum"
                  >{formatRupiah(row.internalBalance)}</td
                >
                {#if fiscal}
                  <td class="px-3 py-2 text-right font-mono tabnum"
                    >{formatRupiah(row.fiscalBalance)}</td
                  >
                {/if}
              </tr>
            {/each}
            <tr class="border-t border-border-soft italic text-text-muted">
              <td class="w-20 px-3 py-2 font-mono">-</td>
              <td class="px-3 py-2">Laba Bersih YTD (auto)</td>
              <td class="px-3 py-2 text-right font-mono tabnum"
                >{formatRupiah(report.equity.net_income_ytd ?? '0.00')}</td
              >
              {#if fiscal}
                <td class="px-3 py-2 text-right font-mono tabnum"
                  >{formatRupiah(fiscal.equity.net_income_ytd ?? '0.00')}</td
                >
              {/if}
            </tr>
            <tr class="border-t border-border-default">
              <td class="px-3 py-1.5 font-semibold" colspan="2">Total Ekuitas</td>
              <td class="px-3 py-1.5 text-right font-mono tabnum font-semibold"
                >{formatRupiah(total(report.equity))}</td
              >
              {#if fiscal}
                <td class="px-3 py-1.5 text-right font-mono tabnum font-semibold"
                  >{formatRupiah(total(fiscal.equity))}</td
                >
              {/if}
            </tr>
            <tr class="border-t-2 border-border-default bg-page-bg">
              <td class="px-3 py-2 font-bold" colspan="2">Total Liabilitas + Ekuitas</td>
              <td class="px-3 py-2 text-right font-mono tabnum font-bold">
                {formatRupiah(
                  (Number(report.liabilities.total) + Number(report.equity.total)).toFixed(2),
                )}
              </td>
              {#if fiscal}
                <td class="px-3 py-2 text-right font-mono tabnum font-bold">
                  {formatRupiah(
                    (Number(fiscal.liabilities.total) + Number(fiscal.equity.total)).toFixed(2),
                  )}
                </td>
              {/if}
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</section>
