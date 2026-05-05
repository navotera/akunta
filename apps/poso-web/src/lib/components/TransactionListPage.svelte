<script lang="ts">
  import Icon from './Icon.svelte';
  import KpiCard from './KpiCard.svelte';
  import QuickIllustration from './QuickIllustration.svelte';
  import TransactionTable from './TransactionTable.svelte';
  import { formatRupiah, type TransactionRow } from '$lib/data/fixtures';

  type Mode = 'sales' | 'purchases';

  let {
    mode,
    rows
  }: {
    mode: Mode;
    rows: TransactionRow[];
  } = $props();

  let isSales = $derived(mode === 'sales');
  let title = $derived(isSales ? 'Penjualan' : 'Pembelian');
  let subtitle = $derived(isSales
    ? 'Kelola transaksi penjualan dan tagihan pelanggan.'
    : 'Kelola transaksi pembelian dan tagihan pemasok.');
  let newHref = $derived(isSales ? '/sales/new' : '/purchases/new');
  let numberLabel = $derived(isSales ? 'No. Invoice' : 'No. Tagihan');
  let partyLabel = $derived(isSales ? 'Pelanggan' : 'Pemasok');
  let quickItems = $derived(isSales
    ? [
        ['Invoice', 'Tagihan untuk pelanggan'],
        ['Penawaran', 'Buat penawaran harga'],
        ['Pesanan Penjualan', 'Buat pesanan dari pelanggan'],
        ['Pembayaran Diterima', 'Catat pembayaran dari pelanggan'],
        ['Retur Penjualan', 'Catat retur dari pelanggan']
      ]
    : [
        ['Tagihan', 'Tagihan dari pemasok'],
        ['Pesanan Pembelian', 'Buat pesanan ke pemasok'],
        ['Pembayaran Keluar', 'Catat pembayaran ke pemasok'],
        ['Retur Pembelian', 'Catat retur ke pemasok'],
        ['Penerimaan Barang', 'Catat barang diterima']
      ]);
  let tabs = $derived(isSales
    ? ['Semua', 'Invoice', 'Penawaran', 'Pesanan Penjualan', 'Pembayaran Diterima', 'Retur Penjualan']
    : ['Semua', 'Tagihan', 'Pesanan Pembelian', 'Pembayaran Keluar', 'Retur Pembelian']);

  let total = $derived(rows.reduce((sum, row) => sum + row.total, 0));
  let paid = $derived(rows.filter((row) => row.status === 'paid').reduce((sum, row) => sum + row.total, 0));
  let unpaid = $derived(rows.filter((row) => row.status === 'unpaid').reduce((sum, row) => sum + row.total, 0));
</script>

<section class="space-y-5">
  <header class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
    <div>
      <h1 class="text-2xl font-bold text-ink">{title}</h1>
      <p class="mt-1 text-sm text-muted">{subtitle}</p>
    </div>
    <div class="flex flex-wrap gap-3">
      <button class="inline-flex h-11 items-center gap-2 rounded-poso border border-line bg-white px-4 text-sm font-semibold text-blue shadow-sm hover:border-blue">
        <Icon name="upload" size={17} stroke={2.1} />
        Impor
      </button>
      <a class="inline-flex h-11 items-center gap-2 rounded-poso bg-blue px-4 text-sm font-semibold text-white shadow-sm hover:bg-blue/90" href={newHref}>
        <Icon name="plus" size={17} stroke={2.2} />
        Buat {title}
      </a>
    </div>
  </header>

  <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
    <KpiCard label={`Total ${title} (Mei 2024)`} value={formatRupiah(total)} caption="↑ 12% dari Apr 2024" icon="document" />
    <KpiCard label={isSales ? 'Terbayar' : 'Sudah Dibayar'} value={formatRupiah(paid)} caption="78.8% dari total" tone="green" icon={isSales ? 'cart' : 'wallet'} />
    <KpiCard label={isSales ? 'Belum Terbayar' : 'Belum Dibayar'} value={formatRupiah(unpaid)} caption="21.2% dari total" tone="amber" icon="clock" />
    <KpiCard label={`Total ${isSales ? 'Invoice' : 'Tagihan'}`} value="32" caption="8 draft" tone="violet" icon="file-text" />
  </div>

  <section class="panel rounded-poso">
    <div class="flex gap-2 overflow-x-auto border-b border-line px-5">
      {#each tabs as tab, index}
        <button class={`h-14 whitespace-nowrap border-b-2 px-1 text-sm font-semibold ${index === 1 ? 'border-blue text-blue' : 'border-transparent text-muted hover:text-ink'}`}>
          {tab}
        </button>
      {/each}
    </div>

    <div class="flex flex-col gap-3 p-5 lg:flex-row lg:items-center lg:justify-between">
      <div class="grid gap-3 md:grid-cols-[minmax(240px,1fr)_170px_170px]">
        <label class="relative">
          <span class="sr-only">Cari</span>
          <input class="field pr-10" placeholder={`Cari nomor ${isSales ? 'invoice' : 'tagihan'} / ${partyLabel.toLowerCase()}...`} />
          <span class="absolute right-3 top-1/2 -translate-y-1/2 text-muted"><Icon name="search" size={16} stroke={2} /></span>
        </label>
        <button class="field flex items-center justify-between text-left text-muted">
          Tanggal: Semua
          <Icon name="calendar" size={16} stroke={1.9} />
        </button>
        <button class="field flex items-center justify-between text-left text-muted">
          Status: Semua
          <Icon name="chevron-down" size={16} stroke={2} />
        </button>
      </div>
      <div class="flex gap-3">
        <button class="inline-flex h-11 items-center gap-2 rounded-poso border border-line bg-white px-4 text-sm font-semibold text-muted hover:border-blue hover:text-blue">
          <Icon name="filter" size={16} stroke={2} />
          Filter
        </button>
        <button class="grid size-11 place-items-center rounded-poso border border-line bg-white text-muted hover:border-blue hover:text-blue" aria-label="Pengaturan tabel">
          <Icon name="gear" size={16} stroke={2} />
        </button>
      </div>
    </div>

    <div class="px-5 pb-4">
      <TransactionTable {rows} {partyLabel} {numberLabel} />
    </div>

    <div class="flex flex-col gap-3 border-t border-line px-5 py-4 text-sm text-muted md:flex-row md:items-center md:justify-between">
      <p>Menampilkan 1 - 5 dari 32 data</p>
      <div class="flex items-center gap-2">
        <button class="grid size-8 place-items-center rounded-poso border border-line"><Icon name="chevron-left" size={15} /></button>
        <button class="grid size-8 place-items-center rounded-poso bg-blue font-semibold text-white">1</button>
        <button class="grid size-8 place-items-center rounded-poso border border-line">2</button>
        <button class="grid size-8 place-items-center rounded-poso border border-line">3</button>
        <button class="grid size-8 place-items-center rounded-poso border border-line"><Icon name="chevron-right" size={15} /></button>
      </div>
    </div>
  </section>

  <section class="panel overflow-hidden rounded-poso">
    <div class="grid gap-6 p-6 md:grid-cols-[minmax(0,1fr)_220px] xl:grid-cols-[1fr_260px]">
      <div>
        <h2 class="text-base font-bold text-ink">Buat transaksi {title.toLowerCase()} dengan cepat</h2>
        <p class="mt-1 text-sm text-muted">Pilih jenis transaksi yang ingin Anda buat.</p>
        <div class="mt-8 grid gap-5 md:grid-cols-3 xl:grid-cols-5">
          {#each quickItems as item}
            <a class="group flex items-start gap-3" href={newHref}>
              <span class="grid size-10 shrink-0 place-items-center rounded-poso bg-blue-soft text-blue group-hover:bg-blue group-hover:text-white">
                <Icon name="document" size={18} stroke={1.9} />
              </span>
              <span>
                <span class="block text-sm font-bold text-ink">{item[0]}</span>
                <span class="mt-1 block text-sm text-muted">{item[1]}</span>
              </span>
            </a>
          {/each}
        </div>
      </div>
      <div class="hidden items-end justify-end md:flex">
        <QuickIllustration mode={mode} />
      </div>
    </div>
  </section>
</section>
