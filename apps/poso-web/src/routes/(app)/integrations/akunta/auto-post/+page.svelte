<script lang="ts">
  import Icon from '$lib/components/Icon.svelte';

  // ─── Dummy data matching design (chat1 final pick: Variant C — Lookup Table) ───
  // Backend wiring deferred: settings.journal_template_mappings is too simple
  // for this pattern. Spec for new schema (description tokens + per-row meta
  // mapping + lookup tables keyed by POSO field) lives in this page until BE
  // catches up.

  type MetaType = 'string' | 'money' | 'number' | 'date' | 'time' | 'enum';
  interface MetaField { group: string; key: string; label: string; sample: string; type: MetaType; }
  interface Account { code: string; name: string; type: 'asset' | 'liability' | 'revenue' | 'contra-revenue' | 'expense'; }
  interface LookupRow { key: string; label: string; code: string; account: string; }
  interface Lookup { title: string; desc: string; sourceField: string; rows: LookupRow[]; }
  interface MappingRow {
    id: string;
    side: 'D' | 'K';
    dynamic: boolean;
    lookup?: 'payment' | 'service' | 'customer';
    code?: string;
    name?: string;
    label?: string;
    meta: string;
  }
  interface SampleTx {
    id: string; no: string; date: string; outlet: string; customer: string;
    method: string; methodLabel: string;
    resolvedCode: string; resolvedName: string;
    subtotal: number; tax: number; sc: number; total: number;
    scCode: string; scName: string;
  }
  type DescToken = { kind: 'text'; value: string } | { kind: 'token'; value: string };

  const SAMPLE_TXS: SampleTx[] = [
    { id: 'STR-2284', no: 'INV/2026/05/00284', date: '07 Mei · 14:32', outlet: 'Senopati', customer: 'Budi Santoso', method: 'bank_bca', methodLabel: 'Transfer BCA', resolvedCode: '1-1210', resolvedName: 'Kas Bank BCA', subtotal: 450000, tax: 45000, sc: 22500, total: 517500, scCode: '4-1900', scName: 'Pendapatan Service Charge' },
    { id: 'STR-2285', no: 'INV/2026/05/00285', date: '07 Mei · 14:48', outlet: 'Senopati', customer: 'Walk-in', method: 'cash', methodLabel: 'Tunai', resolvedCode: '1-1100', resolvedName: 'Kas Outlet', subtotal: 85000, tax: 8500, sc: 4250, total: 97750, scCode: '4-1900', scName: 'Pendapatan Service Charge' },
    { id: 'STR-2286', no: 'INV/2026/05/00286', date: '07 Mei · 15:02', outlet: 'Kemang', customer: 'Sari Dewi', method: 'qris', methodLabel: 'QRIS', resolvedCode: '1-1230', resolvedName: 'Kas QRIS Pending', subtotal: 120000, tax: 12000, sc: 6000, total: 138000, scCode: '4-1900', scName: 'Pendapatan Service Charge' },
    { id: 'STR-2287', no: 'INV/2026/05/00287', date: '07 Mei · 15:14', outlet: 'PIM', customer: 'PT Maju Jaya', method: 'bank_mandiri', methodLabel: 'Transfer Mandiri', resolvedCode: '1-1220', resolvedName: 'Kas Bank Mandiri', subtotal: 1250000, tax: 125000, sc: 62500, total: 1437500, scCode: '2-2400', scName: 'Utang Tip Karyawan' },
    { id: 'STR-2288', no: 'INV/2026/05/00288', date: '07 Mei · 15:21', outlet: 'BSD', customer: 'Andi Pratama', method: 'gopay', methodLabel: 'GoPay', resolvedCode: '1-1240', resolvedName: 'E-wallet GoPay', subtotal: 65000, tax: 6500, sc: 3250, total: 74750, scCode: '2-2400', scName: 'Utang Tip Karyawan' },
    { id: 'STR-2289', no: 'INV/2026/05/00289', date: '07 Mei · 15:33', outlet: 'Senopati', customer: 'Linda K.', method: 'credit_card', methodLabel: 'Kartu Kredit', resolvedCode: '1-1250', resolvedName: 'Kas Kartu Kredit Pending', subtotal: 320000, tax: 32000, sc: 16000, total: 368000, scCode: '4-1900', scName: 'Pendapatan Service Charge' },
  ];

  const META_FIELDS: MetaField[] = [
    { group: 'Identitas', key: 'transaction.id', label: 'ID Transaksi', sample: 'STR-2284', type: 'string' },
    { group: 'Identitas', key: 'transaction.no', label: 'No Struk', sample: 'INV/2026/05/00284', type: 'string' },
    { group: 'Identitas', key: 'transaction.date', label: 'Tanggal Transaksi', sample: '07 Mei 2026', type: 'date' },
    { group: 'Identitas', key: 'transaction.time', label: 'Jam Transaksi', sample: '14:32', type: 'time' },
    { group: 'Outlet', key: 'outlet.id', label: 'Outlet ID', sample: 'OUT-001', type: 'string' },
    { group: 'Outlet', key: 'outlet.name', label: 'Nama Outlet', sample: 'Senopati', type: 'string' },
    { group: 'Outlet', key: 'outlet.region', label: 'Region', sample: 'Jakarta Selatan', type: 'string' },
    { group: 'Kasir', key: 'cashier.id', label: 'Kasir ID', sample: 'USR-014', type: 'string' },
    { group: 'Kasir', key: 'cashier.name', label: 'Nama Kasir', sample: 'Andini', type: 'string' },
    { group: 'Kasir', key: 'cashier.shift', label: 'Shift', sample: 'Sore', type: 'string' },
    { group: 'Pelanggan', key: 'customer.id', label: 'Pelanggan ID', sample: 'CUST-1024', type: 'string' },
    { group: 'Pelanggan', key: 'customer.name', label: 'Nama Pelanggan', sample: 'Budi Santoso', type: 'string' },
    { group: 'Pelanggan', key: 'customer.type', label: 'Tipe Pelanggan', sample: 'member', type: 'enum' },
    { group: 'Pembayaran', key: 'payment.method', label: 'Metode Bayar', sample: 'bank_bca', type: 'enum' },
    { group: 'Pembayaran', key: 'payment.reference', label: 'No Referensi Bayar', sample: 'TRX19283746', type: 'string' },
    { group: 'Nominal', key: 'amount.subtotal', label: 'Subtotal', sample: 'Rp 450.000', type: 'money' },
    { group: 'Nominal', key: 'amount.tax', label: 'PPN', sample: 'Rp 45.000', type: 'money' },
    { group: 'Nominal', key: 'amount.service_charge', label: 'Service Charge', sample: 'Rp 22.500', type: 'money' },
    { group: 'Nominal', key: 'amount.discount', label: 'Diskon', sample: 'Rp 0', type: 'money' },
    { group: 'Nominal', key: 'amount.total', label: 'Total Bayar', sample: 'Rp 517.500', type: 'money' },
    { group: 'Item', key: 'items.count', label: 'Jumlah Item', sample: '4', type: 'number' },
    { group: 'Item', key: 'items.first_name', label: 'Nama Item Pertama', sample: 'Es Kopi Susu', type: 'string' },
  ];

  const ACCOUNTS: Account[] = [
    { code: '1-1100', name: 'Kas Outlet', type: 'asset' },
    { code: '1-1210', name: 'Kas Bank BCA', type: 'asset' },
    { code: '1-1220', name: 'Kas Bank Mandiri', type: 'asset' },
    { code: '1-1230', name: 'Kas QRIS Pending', type: 'asset' },
    { code: '1-1240', name: 'E-wallet GoPay', type: 'asset' },
    { code: '1-1241', name: 'E-wallet OVO', type: 'asset' },
    { code: '1-1250', name: 'Kas Kartu Kredit Pending', type: 'asset' },
    { code: '1-1300', name: 'Piutang Dagang', type: 'asset' },
    { code: '2-2100', name: 'PPN Keluaran', type: 'liability' },
    { code: '2-2200', name: 'PPh 23 Terutang', type: 'liability' },
    { code: '2-2400', name: 'Utang Tip Karyawan', type: 'liability' },
    { code: '4-1100', name: 'Pendapatan Penjualan', type: 'revenue' },
    { code: '4-1110', name: 'Pendapatan Member', type: 'revenue' },
    { code: '4-1120', name: 'Pendapatan Korporat', type: 'revenue' },
    { code: '4-1900', name: 'Pendapatan Service Charge', type: 'revenue' },
    { code: '5-1100', name: 'Diskon Penjualan', type: 'contra-revenue' },
    { code: '6-2100', name: 'Biaya MDR', type: 'expense' },
  ];

  const LOOKUPS: Record<string, Lookup> = {
    payment: {
      title: 'Metode Pembayaran → Akun Kas',
      desc: 'Resolve akun kas berdasarkan field POSO `payment.method`',
      sourceField: 'payment.method',
      rows: [
        { key: 'cash', label: 'Tunai', code: '1-1100', account: 'Kas Outlet' },
        { key: 'bank_bca', label: 'Transfer BCA', code: '1-1210', account: 'Kas Bank BCA' },
        { key: 'bank_mandiri', label: 'Transfer Mandiri', code: '1-1220', account: 'Kas Bank Mandiri' },
        { key: 'qris', label: 'QRIS', code: '1-1230', account: 'Kas QRIS Pending' },
        { key: 'gopay', label: 'GoPay', code: '1-1240', account: 'E-wallet GoPay' },
        { key: 'ovo', label: 'OVO', code: '1-1241', account: 'E-wallet OVO' },
        { key: 'credit_card', label: 'Kartu Kredit', code: '1-1250', account: 'Kas Kartu Kredit Pending' },
      ],
    },
    service: {
      title: 'Outlet → Akun Service Charge',
      desc: 'Resolve akun service charge berdasarkan field POSO `outlet.id`',
      sourceField: 'outlet.id',
      rows: [
        { key: 'OUT-001', label: 'Senopati', code: '4-1900', account: 'Pendapatan Service Charge' },
        { key: 'OUT-002', label: 'Kemang', code: '4-1900', account: 'Pendapatan Service Charge' },
        { key: 'OUT-003', label: 'PIM', code: '2-2400', account: 'Utang Tip Karyawan' },
        { key: 'OUT-004', label: 'BSD', code: '2-2400', account: 'Utang Tip Karyawan' },
      ],
    },
    customer: {
      title: 'Tipe Pelanggan → Akun Pendapatan',
      desc: 'Resolve akun pendapatan berdasarkan field POSO `customer.type`',
      sourceField: 'customer.type',
      rows: [
        { key: 'retail', label: 'Retail (Walk-in)', code: '4-1100', account: 'Pendapatan Penjualan' },
        { key: 'member', label: 'Member', code: '4-1110', account: 'Pendapatan Member' },
        { key: 'corporate', label: 'Korporat', code: '4-1120', account: 'Pendapatan Korporat' },
        { key: 'wholesale', label: 'Grosir / B2B', code: '4-1130', account: 'Pendapatan Wholesale' },
      ],
    },
  };

  const ROWS: MappingRow[] = [
    { id: 'd1', side: 'D', dynamic: true, lookup: 'payment', label: 'Akun Kas (per metode bayar)', meta: 'amount.total' },
    { id: 'k1', side: 'K', dynamic: false, code: '4-1100', name: 'Pendapatan Penjualan', meta: 'amount.subtotal' },
    { id: 'k2', side: 'K', dynamic: false, code: '2-2100', name: 'PPN Keluaran', meta: 'amount.tax' },
    { id: 'k3', side: 'K', dynamic: true, lookup: 'service', label: 'Akun Service Charge (per outlet)', meta: 'amount.service_charge' },
  ];

  interface PageTab { k: string; label: string; href?: string; }
  const TABS: PageTab[] = [
    { k: 'overview', label: 'Overview', href: '/integrations/akunta' },
    { k: 'autopost', label: 'Auto-Post Pipeline', href: '/integrations/akunta/auto-post' },
    { k: 'rules', label: 'Aturan Pajak' },
    { k: 'history', label: 'Riwayat Posting' },
    { k: 'mapping', label: 'Mapping Akun' },
    { k: 'config', label: 'Konfigurasi' },
  ];

  let activeLookup = $state<keyof typeof LOOKUPS>('payment');
  let rightTab = $state<'meta' | 'accounts' | 'lookup'>('meta');
  let pickerFor = $state<string | null>(null);
  let accountQuery = $state('');
  let metaQuery = $state('');
  let sampleTxId = $state('STR-2284');
  let txPickerOpen = $state(false);
  let txQuery = $state('');

  let descTokens = $state<DescToken[]>([
    { kind: 'text', value: 'Penjualan POSO ' },
    { kind: 'token', value: 'transaction.no' },
    { kind: 'text', value: ' · ' },
    { kind: 'token', value: 'outlet.name' },
    { kind: 'text', value: ' · ' },
    { kind: 'token', value: 'customer.name' },
  ]);

  let tx = $derived(SAMPLE_TXS.find((t) => t.id === sampleTxId) ?? SAMPLE_TXS[0]);
  let filteredTxs = $derived(
    SAMPLE_TXS.filter((t) => {
      if (!txQuery) return true;
      const q = txQuery.toLowerCase();
      return (
        t.id.toLowerCase().includes(q) ||
        t.no.toLowerCase().includes(q) ||
        t.customer.toLowerCase().includes(q) ||
        t.outlet.toLowerCase().includes(q)
      );
    }),
  );

  let metaGrouped = $derived(() => {
    const filtered = META_FIELDS.filter((f) => {
      if (!metaQuery) return true;
      const q = metaQuery.toLowerCase();
      return f.label.toLowerCase().includes(q) || f.key.toLowerCase().includes(q);
    });
    const groups = new Map<string, MetaField[]>();
    for (const f of filtered) {
      if (!groups.has(f.group)) groups.set(f.group, []);
      groups.get(f.group)!.push(f);
    }
    return Array.from(groups.entries());
  });

  let filteredAccounts = $derived(
    ACCOUNTS.filter((a) => {
      if (!accountQuery) return true;
      const q = accountQuery.toLowerCase();
      return a.name.toLowerCase().includes(q) || a.code.includes(q);
    }),
  );

  const TYPE_BADGE: Record<MetaType, string> = {
    string: 'bg-blue-soft text-blue',
    money: 'bg-green-soft text-green-700',
    number: 'bg-green-soft text-green-700',
    date: 'bg-amber-soft text-amber',
    time: 'bg-amber-soft text-amber',
    enum: 'bg-violet-soft text-violet-700',
  };

  const ACCOUNT_TYPE_LABEL: Record<Account['type'], { label: string; color: string }> = {
    asset: { label: 'Aset', color: 'bg-blue-soft text-blue' },
    liability: { label: 'Liabilitas', color: 'bg-red-soft text-red-600' },
    revenue: { label: 'Pendapatan', color: 'bg-green-soft text-green-700' },
    'contra-revenue': { label: 'Kontra-Pdpt', color: 'bg-amber-soft text-amber' },
    expense: { label: 'Beban', color: 'bg-violet-soft text-violet-700' },
  };

  function fmtIDR(n: number): string {
    return 'Rp ' + new Intl.NumberFormat('id-ID').format(n);
  }

  function removeToken(idx: number) {
    descTokens = descTokens.filter((_, i) => i !== idx);
  }

  function addToken(key: string) {
    descTokens = [...descTokens, { kind: 'token', value: key }];
  }

  function pickRow(rowId: string) {
    pickerFor = rowId;
    rightTab = 'accounts';
  }

  function pickLookup(lookupKey: keyof typeof LOOKUPS) {
    activeLookup = lookupKey;
    rightTab = 'lookup';
  }

  function chooseTx(id: string) {
    sampleTxId = id;
    txPickerOpen = false;
    txQuery = '';
  }

  function findMeta(key: string): MetaField | undefined {
    return META_FIELDS.find((m) => m.key === key);
  }
</script>

<div class="poso-autopost">
  <!-- Second-nav: breadcrumb + tab bar within page -->
  <nav class="poso-second-nav">
    <div class="flex items-center gap-2 text-xs text-muted">
      <Icon name="link" size={12} stroke={2} />
      <span>Integrasi</span>
      <Icon name="chevron-right" size={11} stroke={2} />
      <span class="poso-akunta-mark">A</span>
      <span class="font-semibold text-ink">akunta</span>
    </div>
    <ul class="flex gap-0">
      {#each TABS as t (t.k)}
        {#if t.href}
          <li>
            <a
              href={t.href}
              class="poso-tab"
              class:is-active={t.k === 'autopost'}
            >{t.label}</a>
          </li>
        {:else}
          <li>
            <button type="button" class="poso-tab" disabled>{t.label}</button>
          </li>
        {/if}
      {/each}
    </ul>
    <span class="poso-conn-pill">
      <span class="poso-conn-dot"></span>
      Akunta · Online
    </span>
  </nav>

  <!-- Page card -->
  <div class="poso-body">
    <article class="poso-page-card">
      <header class="poso-page-header">
        <div class="flex-1">
          <p class="text-xs text-muted">POSO · Auto-Post Pipeline · Penjualan</p>
          <h1 class="text-lg font-bold tracking-tight">Mapping Auto-Post · Lookup Table</h1>
        </div>
        <button type="button" class="btn-ghost"><Icon name="eye" size={12} stroke={2} /> Test resolve</button>
        <button type="button" class="btn-primary"><Icon name="plus" size={12} stroke={2.4} /> Simpan</button>
      </header>

      <div class="poso-page-content">
        <!-- Concept banner -->
        <div class="poso-banner">
          <span class="poso-banner-icon"><Icon name="link" size={18} stroke={2.2} /></span>
          <p class="flex-1 text-xs leading-relaxed text-muted">
            <strong class="text-ink">Pola Lookup Table + Token Description</strong> — tarik field meta dari panel kanan ke kolom value / deskripsi. Akun dipilih via search atau lookup table.
          </p>
          <button type="button" class="btn-ghost text-xs">ⓘ Pelajari</button>
        </div>

        <!-- Main grid -->
        <div class="poso-main-grid">
          <!-- LEFT -->
          <div class="flex flex-col gap-3">
            <!-- Description card -->
            <section class="poso-card">
              <header class="poso-card-header">
                <span aria-hidden="true">📝</span>
                <strong class="text-sm">Deskripsi Jurnal (Memo)</strong>
                <span class="text-[11px] text-muted">· template per transaksi</span>
                <span class="ml-auto text-[10px] text-muted">klik token untuk hapus · drag dari panel meta</span>
              </header>
              <div class="poso-card-body">
                <div class="poso-token-editor">
                  {#each descTokens as t, i (i)}
                    {#if t.kind === 'token'}
                      <span class="poso-token">
                        <span aria-hidden="true">#</span>{t.value}
                        <button
                          type="button"
                          class="poso-token-x"
                          onclick={() => removeToken(i)}
                          aria-label="Hapus token"
                        >✕</button>
                      </span>
                    {:else}
                      <span class="text-xs text-muted whitespace-pre">{t.value}</span>
                    {/if}
                  {/each}
                  <button type="button" class="poso-add-text">+ teks</button>
                </div>
                <div class="poso-token-preview">
                  <span class="poso-preview-label">Preview:</span>
                  {#each descTokens as t (t.kind + ':' + t.value)}
                    {#if t.kind === 'text'}
                      <span>{t.value}</span>
                    {:else}
                      {@const f = findMeta(t.value)}
                      <span class="poso-token-inline">{f ? f.sample : t.value}</span>
                    {/if}
                  {/each}
                </div>
              </div>
            </section>

            <!-- Mapping card -->
            <section class="poso-card">
              <header class="poso-card-header">
                <span aria-hidden="true">📒</span>
                <strong class="text-sm">Jurnal Mapping · Penjualan</strong>
                <span class="ml-auto text-[11px] text-muted">{ROWS.length} baris</span>
              </header>
              <div class="poso-card-body">
                <div class="poso-map-cols">
                  <div>Akun</div>
                  <div>Value (meta)</div>
                  <div></div>
                </div>

                <!-- DEBIT zone -->
                <div class="poso-zone poso-zone--D">
                  <span class="poso-zone-badge">D</span>
                  <span class="poso-zone-label">DEBIT</span>
                </div>
                {#each ROWS.filter((r) => r.side === 'D') as r (r.id)}
                  {@const meta = findMeta(r.meta)}
                  {@const lookup = r.dynamic && r.lookup ? LOOKUPS[r.lookup] : null}
                  <div class="poso-map-row">
                    {#if r.dynamic && r.lookup}
                      <button
                        type="button"
                        class="poso-acct-btn poso-acct-btn--dynamic"
                        class:is-active={activeLookup === r.lookup && rightTab === 'lookup'}
                        onclick={() => pickLookup(r.lookup!)}
                      >
                        <span aria-hidden="true">⚡</span>
                        <span class="flex-1 min-w-0 text-left">
                          <span class="block text-[11px] font-bold leading-tight text-orange-deep">{r.label}</span>
                          <span class="block text-[10px] leading-tight text-orange-deep/70 mt-0.5">via lookup · {lookup?.rows.length} rules</span>
                        </span>
                        <Icon name="chevron-right" size={11} stroke={2} />
                      </button>
                    {:else}
                      <button
                        type="button"
                        class="poso-acct-btn"
                        class:is-active={pickerFor === r.id}
                        onclick={() => pickRow(r.id)}
                      >
                        <span class="font-mono text-[10px] font-semibold text-muted shrink-0">{r.code}</span>
                        <span class="flex-1 truncate text-[11px] font-semibold">{r.name}</span>
                        <Icon name="search" size={10} stroke={2} />
                      </button>
                    {/if}
                    <div class="poso-meta-cell">
                      <span aria-hidden="true">#</span>
                      <span class="font-mono text-[11px] font-semibold text-ink">{r.meta}</span>
                      {#if meta}
                        <span class="ml-auto text-[10px] text-muted">{meta.sample}</span>
                      {/if}
                    </div>
                    <button type="button" class="poso-row-more" aria-label="Aksi">⋯</button>
                  </div>
                {/each}

                <!-- KREDIT zone -->
                <div class="mt-3"></div>
                <div class="poso-zone poso-zone--K">
                  <span class="poso-zone-badge">K</span>
                  <span class="poso-zone-label">KREDIT</span>
                </div>
                {#each ROWS.filter((r) => r.side === 'K') as r (r.id)}
                  {@const meta = findMeta(r.meta)}
                  {@const lookup = r.dynamic && r.lookup ? LOOKUPS[r.lookup] : null}
                  <div class="poso-map-row">
                    {#if r.dynamic && r.lookup}
                      <button
                        type="button"
                        class="poso-acct-btn poso-acct-btn--dynamic"
                        class:is-active={activeLookup === r.lookup && rightTab === 'lookup'}
                        onclick={() => pickLookup(r.lookup!)}
                      >
                        <span aria-hidden="true">⚡</span>
                        <span class="flex-1 min-w-0 text-left">
                          <span class="block text-[11px] font-bold leading-tight text-orange-deep">{r.label}</span>
                          <span class="block text-[10px] leading-tight text-orange-deep/70 mt-0.5">via lookup · {lookup?.rows.length} rules</span>
                        </span>
                        <Icon name="chevron-right" size={11} stroke={2} />
                      </button>
                    {:else}
                      <button
                        type="button"
                        class="poso-acct-btn"
                        class:is-active={pickerFor === r.id}
                        onclick={() => pickRow(r.id)}
                      >
                        <span class="font-mono text-[10px] font-semibold text-muted shrink-0">{r.code}</span>
                        <span class="flex-1 truncate text-[11px] font-semibold">{r.name}</span>
                        <Icon name="search" size={10} stroke={2} />
                      </button>
                    {/if}
                    <div class="poso-meta-cell">
                      <span aria-hidden="true">#</span>
                      <span class="font-mono text-[11px] font-semibold text-ink">{r.meta}</span>
                      {#if meta}
                        <span class="ml-auto text-[10px] text-muted">{meta.sample}</span>
                      {/if}
                    </div>
                    <button type="button" class="poso-row-more" aria-label="Aksi">⋯</button>
                  </div>
                {/each}

                <button type="button" class="poso-add-row">+ Tambah baris akun</button>
              </div>
            </section>
          </div>

          <!-- RIGHT: tabbed panel -->
          <aside class="poso-card poso-right-panel">
            <div class="poso-tab-strip">
              {#each [
                { k: 'meta', label: 'Meta Fields', count: META_FIELDS.length, icon: '#' },
                { k: 'accounts', label: 'Akun (CoA)', count: ACCOUNTS.length, icon: '📒' },
                { k: 'lookup', label: 'Lookup', count: Object.keys(LOOKUPS).length, icon: '🔗' },
              ] as t (t.k)}
                <button
                  type="button"
                  class="poso-tab-btn"
                  class:is-active={rightTab === t.k}
                  onclick={() => (rightTab = t.k as typeof rightTab)}
                >
                  <span aria-hidden="true">{t.icon}</span>
                  {t.label}
                  <span class="poso-tab-count">{t.count}</span>
                </button>
              {/each}
            </div>

            {#if rightTab === 'meta'}
              <div class="poso-search-bar">
                <Icon name="search" size={12} stroke={2} />
                <input class="poso-search-input" placeholder="Cari meta field…" bind:value={metaQuery} />
              </div>
              <p class="poso-search-hint">Klik <strong>+</strong> untuk tambah ke deskripsi · drag ke kolom <em>Value</em> di mapping</p>
              <div class="poso-scroll">
                {#each metaGrouped() as [g, items] (g)}
                  <div class="poso-group-header">{g} <span class="opacity-60">· {items.length}</span></div>
                  {#each items as f (f.key)}
                    <div class="poso-meta-item">
                      <span aria-hidden="true">#</span>
                      <div class="flex-1 min-w-0">
                        <div class="text-[11px] font-semibold text-ink">{f.label}</div>
                        <div class="font-mono text-[10px] text-muted mt-0.5">{f.key} <span class="opacity-70">= "{f.sample}"</span></div>
                      </div>
                      <span class="poso-type-badge {TYPE_BADGE[f.type]}">{f.type}</span>
                      <button
                        type="button"
                        class="poso-add-token"
                        onclick={() => addToken(f.key)}
                        title="Tambah ke deskripsi"
                      >+</button>
                    </div>
                  {/each}
                {/each}
              </div>
            {:else if rightTab === 'accounts'}
              <div class="poso-search-bar">
                <Icon name="search" size={12} stroke={2} />
                <input class="poso-search-input" placeholder="Cari akun (nama atau kode)…" bind:value={accountQuery} />
              </div>
              {#if pickerFor}
                <div class="poso-picker-banner">
                  <span aria-hidden="true">⚡</span>
                  <span>Pilih akun untuk row <strong>{pickerFor}</strong></span>
                  <button
                    type="button"
                    class="ml-auto poso-banner-x"
                    onclick={() => (pickerFor = null)}
                    aria-label="Batal"
                  >✕</button>
                </div>
              {/if}
              <p class="poso-search-hint">{filteredAccounts.length} dari {ACCOUNTS.length} akun · klik untuk assign ke row aktif</p>
              <div class="poso-scroll">
                {#each filteredAccounts as a (a.code)}
                  {@const t = ACCOUNT_TYPE_LABEL[a.type]}
                  <button type="button" class="poso-acct-row">
                    <span class="font-mono text-[11px] font-bold text-muted w-12 shrink-0">{a.code}</span>
                    <span class="flex-1 text-[12px] font-semibold">{a.name}</span>
                    <span class="poso-type-badge {t.color}">{t.label}</span>
                  </button>
                {/each}
              </div>
              <footer class="poso-panel-footer">
                <button type="button" class="btn-ghost w-full text-xs">+ Buat akun baru</button>
              </footer>
            {:else}
              <!-- Lookup -->
              <div class="poso-lookup-tabs">
                {#each Object.entries(LOOKUPS) as [k, lk] (k)}
                  <button
                    type="button"
                    class="poso-lookup-pill"
                    class:is-active={activeLookup === k}
                    onclick={() => (activeLookup = k as keyof typeof LOOKUPS)}
                  >{lk.title.split(' → ')[0]}</button>
                {/each}
              </div>
              {@const lk = LOOKUPS[activeLookup]}
              <div class="poso-lookup-meta">
                <span class="poso-lookup-icon">⚡</span>
                <div class="flex-1">
                  <div class="text-[12px] font-bold text-orange-deep">{lk.title}</div>
                  <div class="text-[10px] text-orange-deep/80 mt-0.5">
                    field <span class="poso-key-chip">{lk.sourceField}</span>
                  </div>
                </div>
              </div>
              <div class="poso-lookup-cols">
                <div>Value POSO</div>
                <div></div>
                <div>Akun akunta</div>
                <div></div>
              </div>
              <div class="poso-scroll">
                {#each lk.rows as r (r.key)}
                  <div class="poso-lookup-row">
                    <div>
                      <div class="text-[11px] font-semibold">{r.label}</div>
                      <div class="font-mono text-[10px] text-muted mt-0.5">{r.key}</div>
                    </div>
                    <Icon name="chevron-right" size={11} stroke={2} />
                    <div>
                      <div class="text-[11px] font-semibold">{r.account}</div>
                      <div class="font-mono text-[10px] text-muted mt-0.5">{r.code}</div>
                    </div>
                    <button type="button" class="poso-row-more" aria-label="Aksi">⋯</button>
                  </div>
                {/each}
              </div>
              <footer class="poso-panel-footer flex gap-2">
                <button type="button" class="btn-ghost flex-1 text-xs">+ Tambah rule</button>
                <button type="button" class="btn-ghost text-xs"><Icon name="upload" size={11} stroke={2} /> Import</button>
              </footer>
            {/if}
          </aside>
        </div>

        <!-- Resolver preview -->
        <section class="poso-card mt-3">
          <header class="poso-card-header">
            <span aria-hidden="true" class="text-orange-accent">⚡</span>
            <strong class="text-sm">Resolver Preview</strong>
            <span class="text-[11px] text-muted">· transaksi POSO masuk → jurnal yang dihasilkan</span>
            <span class="flex-1"></span>

            <div class="relative">
              <button
                type="button"
                class="poso-tx-picker-btn"
                class:is-open={txPickerOpen}
                onclick={() => (txPickerOpen = !txPickerOpen)}
              >
                <Icon name="search" size={11} stroke={2} />
                <span class="text-muted">Pilih transaksi:</span>
                <span class="font-mono font-bold">{tx.id}</span>
                <span class="text-muted">·</span>
                <span class="font-semibold">{tx.customer}</span>
                <Icon name="chevron-down" size={10} stroke={2} />
              </button>
              {#if txPickerOpen}
                <div class="poso-tx-dropdown" role="listbox">
                  <div class="poso-tx-dropdown-search">
                    <div class="relative">
                      <Icon name="search" size={11} stroke={2} />
                      <input
                        class="poso-search-input"
                        autofocus
                        placeholder="Cari ID, no struk, pelanggan, outlet…"
                        bind:value={txQuery}
                      />
                    </div>
                    <div class="text-[10px] text-muted mt-1">{filteredTxs.length} dari {SAMPLE_TXS.length} transaksi</div>
                  </div>
                  <div class="poso-scroll">
                    {#each filteredTxs as t (t.id)}
                      <button
                        type="button"
                        class="poso-tx-option"
                        class:is-active={t.id === sampleTxId}
                        onclick={() => chooseTx(t.id)}
                      >
                        <div>
                          <div class="font-mono text-[11px] font-bold">{t.id}</div>
                          <div class="text-[10px] text-muted mt-0.5">{t.date}</div>
                        </div>
                        <div class="min-w-0">
                          <div class="text-[12px] font-semibold truncate">{t.customer}</div>
                          <div class="text-[10px] text-muted mt-0.5">{t.outlet} · <span class="font-mono">{t.method}</span></div>
                        </div>
                        <span class="font-mono tabular-nums text-[11px] font-bold text-muted">{fmtIDR(t.total)}</span>
                      </button>
                    {/each}
                  </div>
                </div>
              {/if}
            </div>
          </header>
          <div class="poso-resolver-grid">
            <div class="poso-resolver-cell">
              <div class="poso-section-label">Sample Transaksi POSO · {tx.id}</div>
              <dl class="poso-resolver-dl">
                <dt>transaction.no:</dt><dd class="font-mono">{tx.no}</dd>
                <dt>outlet.name:</dt><dd class="font-mono">{tx.outlet}</dd>
                <dt>customer.name:</dt><dd class="font-mono">{tx.customer}</dd>
                <dt>payment.method:</dt><dd class="font-mono text-orange-accent font-bold">{tx.method}</dd>
                <dt>amount.subtotal:</dt><dd class="font-mono">{fmtIDR(tx.subtotal)}</dd>
                <dt>amount.tax:</dt><dd class="font-mono">{fmtIDR(tx.tax)}</dd>
                <dt>amount.service_charge:</dt><dd class="font-mono">{fmtIDR(tx.sc)}</dd>
                <dt>amount.total:</dt><dd class="font-mono font-bold">{fmtIDR(tx.total)}</dd>
              </dl>
            </div>
            <div class="poso-resolver-cell">
              <div class="poso-section-label">Hasil Resolve · Jurnal akunta</div>
              <p class="text-[11px] text-muted mb-2">Memo: <em>Penjualan POSO {tx.no} · {tx.outlet} · {tx.customer}</em></p>
              <table class="poso-jurnal-table">
                <thead>
                  <tr>
                    <th>Akun</th>
                    <th class="text-right">Debit</th>
                    <th class="text-right">Kredit</th>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <td><span class="font-mono text-muted mr-2">{tx.resolvedCode}</span>{tx.resolvedName} <span class="poso-resolved-tag">resolved</span></td>
                    <td class="text-right tabular-nums">{fmtIDR(tx.total)}</td>
                    <td class="text-muted">—</td>
                  </tr>
                  <tr>
                    <td><span class="font-mono text-muted mr-2">4-1100</span>Pendapatan Penjualan</td>
                    <td class="text-muted">—</td>
                    <td class="text-right tabular-nums">{fmtIDR(tx.subtotal)}</td>
                  </tr>
                  <tr>
                    <td><span class="font-mono text-muted mr-2">2-2100</span>PPN Keluaran</td>
                    <td class="text-muted">—</td>
                    <td class="text-right tabular-nums">{fmtIDR(tx.tax)}</td>
                  </tr>
                  <tr>
                    <td><span class="font-mono text-muted mr-2">{tx.scCode}</span>{tx.scName} <span class="poso-resolved-tag">resolved</span></td>
                    <td class="text-muted">—</td>
                    <td class="text-right tabular-nums">{fmtIDR(tx.sc)}</td>
                  </tr>
                  <tr class="poso-jurnal-total">
                    <td>Balanced ✓</td>
                    <td class="text-right tabular-nums">{fmtIDR(tx.total)}</td>
                    <td class="text-right tabular-nums">{fmtIDR(tx.total)}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </section>
      </div>
    </article>
  </div>
</div>

<style>
  /* ─── tokens ─── */
  :global(.text-orange-accent) { color: #ea580c; }
  :global(.text-orange-deep) { color: #9a3412; }
  :global(.text-violet-700) { color: #6d28d9; }
  :global(.text-green-700) { color: #166534; }
  :global(.text-red-600) { color: #dc2626; }

  .poso-autopost {
    margin: -24px -24px 0;
    background: #f1f5f9;
    min-height: calc(100vh - 64px);
    display: flex;
    flex-direction: column;
  }

  /* Second nav */
  .poso-second-nav {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 0 22px;
    background: white;
    border-bottom: 1px solid #e2e8f0;
  }
  .poso-second-nav > .flex {
    padding: 8px 0;
  }
  .poso-akunta-mark {
    display: inline-grid;
    place-items: center;
    width: 16px;
    height: 16px;
    border-radius: 4px;
    background: #2563EB;
    color: white;
    font-size: 9px;
    font-weight: 800;
  }
  .poso-tab {
    display: inline-block;
    padding: 12px 14px;
    font-size: 12px;
    font-weight: 500;
    color: #64748b;
    text-decoration: none;
    border-bottom: 2px solid transparent;
    margin-bottom: -1px;
    background: transparent;
    border-top: 0;
    border-left: 0;
    border-right: 0;
    cursor: pointer;
  }
  .poso-tab:hover:not(:disabled) { color: #0f172a; }
  .poso-tab.is-active {
    color: #ea580c;
    font-weight: 700;
    border-bottom-color: #ea580c;
  }
  .poso-tab:disabled {
    opacity: 0.45;
    cursor: not-allowed;
  }
  .poso-conn-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin-left: auto;
    padding: 4px 10px;
    font-size: 10.5px;
    font-weight: 600;
    color: #166534;
    background: #DCFCE7;
    border-radius: 999px;
  }
  .poso-conn-dot {
    width: 6px;
    height: 6px;
    border-radius: 999px;
    background: #16A34A;
  }

  /* Body + page card */
  .poso-body {
    flex: 1;
    padding: 20px;
    background: #f1f5f9;
  }
  .poso-page-card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
    overflow: hidden;
  }
  .poso-page-header {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 16px 20px 12px;
    border-bottom: 1px solid #e2e8f0;
  }
  .poso-page-content {
    padding: 14px 20px 20px;
  }

  /* Banner */
  .poso-banner {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px;
    margin-bottom: 14px;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    background: linear-gradient(90deg, #fff7ed 0%, #ffffff 60%);
  }
  .poso-banner-icon {
    display: grid;
    place-items: center;
    width: 40px;
    height: 40px;
    border-radius: 10px;
    background: #ea580c;
    color: white;
    flex-shrink: 0;
  }

  /* Buttons (page-local) */
  .btn-primary {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 8px;
    background: #2563EB;
    color: white;
    font-size: 12px;
    font-weight: 600;
    border: 0;
    cursor: pointer;
  }
  .btn-primary:hover { background: #1d4ed8; }
  .btn-ghost {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 10px;
    border-radius: 6px;
    background: transparent;
    border: 1px solid #e2e8f0;
    color: #475569;
    font-size: 11.5px;
    font-weight: 500;
    cursor: pointer;
  }
  .btn-ghost:hover { background: #f8fafc; }

  /* Main grid */
  .poso-main-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr);
    gap: 14px;
  }
  @media (max-width: 1100px) { .poso-main-grid { grid-template-columns: 1fr; } }

  /* Card */
  .poso-card {
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    overflow: hidden;
  }
  .poso-card-header {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 14px;
    border-bottom: 1px solid #e2e8f0;
  }
  .poso-card-body { padding: 12px 14px; }

  /* Token editor + preview */
  .poso-token-editor {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 4px;
    padding: 10px 12px;
    border-radius: 7px;
    min-height: 38px;
    border: 1.5px dashed #cbd5e1;
    background: #f8fafc;
  }
  .poso-token {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 3px 7px;
    border-radius: 5px;
    background: #fff7ed;
    border: 1px solid #fdba74;
    color: #9a3412;
    font-size: 11px;
    font-weight: 600;
    font-family: 'JetBrains Mono', ui-monospace, monospace;
  }
  .poso-token-x {
    background: rgba(154, 52, 18, 0.1);
    border: 0;
    border-radius: 3px;
    width: 14px;
    height: 14px;
    display: grid;
    place-items: center;
    cursor: pointer;
    color: #9a3412;
    font-size: 9px;
    line-height: 1;
  }
  .poso-add-text {
    margin-left: 4px;
    padding: 3px 8px;
    border: 1px dashed #cbd5e1;
    border-radius: 5px;
    background: transparent;
    cursor: pointer;
    font: 500 10.5px/1.2 inherit;
    color: #64748b;
  }
  .poso-token-preview {
    margin-top: 8px;
    padding: 8px 12px;
    border-radius: 6px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    font-size: 11px;
    color: #475569;
  }
  .poso-preview-label {
    font-size: 9.5px;
    font-weight: 700;
    letter-spacing: 0.08em;
    color: #94a3b8;
    text-transform: uppercase;
    margin-right: 8px;
  }
  .poso-token-inline {
    background: #fff7ed;
    color: #9a3412;
    padding: 1px 4px;
    border-radius: 3px;
    font-weight: 600;
  }

  /* Mapping */
  .poso-map-cols {
    display: grid;
    grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr) 24px;
    gap: 10px;
    padding: 0 4px 6px;
    font-size: 9.5px;
    font-weight: 700;
    letter-spacing: 0.08em;
    color: #94a3b8;
    text-transform: uppercase;
  }
  .poso-zone {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 5px 8px;
    margin-bottom: 6px;
    border-radius: 6px;
  }
  .poso-zone--D { background: #dbeafe; color: #1d4ed8; }
  .poso-zone--K { background: #dcfce7; color: #166534; }
  .poso-zone-badge {
    width: 16px;
    height: 16px;
    border-radius: 4px;
    background: currentColor;
    font-size: 9.5px;
    font-weight: 800;
    text-align: center;
    line-height: 16px;
  }
  .poso-zone--D .poso-zone-badge { color: #dbeafe; }
  .poso-zone--K .poso-zone-badge { color: #dcfce7; }
  .poso-zone-label { font-size: 10.5px; font-weight: 700; letter-spacing: 0.08em; }

  .poso-map-row {
    display: grid;
    grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr) 24px;
    gap: 10px;
    padding: 7px 0;
    border-top: 1px solid #e2e8f0;
    align-items: center;
  }
  .poso-acct-btn {
    display: flex;
    align-items: center;
    gap: 7px;
    padding: 6px 9px;
    border-radius: 6px;
    border: 1px solid #e2e8f0;
    background: white;
    cursor: pointer;
    text-align: left;
    width: 100%;
    font-family: inherit;
  }
  .poso-acct-btn.is-active { border-color: #ea580c; background: #fff7ed; }
  .poso-acct-btn--dynamic {
    border: 1.5px solid #fdba74;
    background: rgba(254, 215, 170, 0.25);
  }
  .poso-acct-btn--dynamic.is-active { border-color: #ea580c; background: #fff7ed; }
  .poso-meta-cell {
    display: flex;
    align-items: center;
    gap: 6px;
    padding: 5px 9px;
    border-radius: 6px;
    border: 1px dashed #cbd5e1;
    background: #f8fafc;
    cursor: grab;
  }
  .poso-row-more {
    border: 0;
    background: none;
    cursor: pointer;
    color: #94a3b8;
    padding: 0;
    line-height: 1;
  }
  .poso-add-row {
    margin-top: 10px;
    padding: 8px 12px;
    border: 1px dashed #cbd5e1;
    border-radius: 7px;
    background: transparent;
    cursor: pointer;
    font-family: inherit;
    font-size: 11.5px;
    color: #64748b;
    width: 100%;
  }

  /* Right panel */
  .poso-right-panel {
    display: flex;
    flex-direction: column;
    align-self: start;
    max-height: 720px;
  }
  .poso-tab-strip {
    display: flex;
    border-bottom: 1px solid #e2e8f0;
    background: #f8fafc;
  }
  .poso-tab-btn {
    flex: 1;
    padding: 10px 8px;
    border: 0;
    background: transparent;
    color: #64748b;
    font: 500 11px/1.2 inherit;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    border-bottom: 2px solid transparent;
    margin-bottom: -1px;
  }
  .poso-tab-btn.is-active {
    background: white;
    color: #ea580c;
    font-weight: 700;
    border-bottom-color: #ea580c;
  }
  .poso-tab-count { font-size: 9.5px; opacity: 0.6; font-weight: 600; }
  .poso-search-bar {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 12px;
    border-bottom: 1px solid #e2e8f0;
    background: white;
  }
  .poso-search-input {
    flex: 1;
    border: 1px solid #e2e8f0;
    background: #f8fafc;
    padding: 7px 9px;
    border-radius: 6px;
    font: 11.5px/1 inherit;
    color: #0f172a;
    outline: none;
  }
  .poso-search-hint { padding: 6px 12px; font-size: 10px; color: #94a3b8; margin: 0; }
  .poso-scroll { flex: 1; overflow: auto; min-height: 0; }
  .poso-group-header {
    padding: 6px 12px;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    font-size: 9.5px;
    font-weight: 700;
    letter-spacing: 0.08em;
    color: #94a3b8;
    text-transform: uppercase;
    position: sticky;
    top: 0;
    z-index: 1;
  }
  .poso-meta-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 7px 12px;
    border-bottom: 1px solid #e2e8f0;
    cursor: grab;
  }
  .poso-type-badge {
    display: inline-block;
    font-size: 8.5px;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    padding: 2px 5px;
    border-radius: 3px;
  }
  .poso-add-token {
    border: 1px solid #fdba74;
    background: #fff7ed;
    color: #9a3412;
    width: 22px;
    height: 22px;
    border-radius: 5px;
    cursor: pointer;
    display: grid;
    place-items: center;
    font-size: 12px;
    font-weight: 700;
    line-height: 1;
  }
  .poso-acct-row {
    width: 100%;
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 12px;
    border: 0;
    border-bottom: 1px solid #e2e8f0;
    background: transparent;
    cursor: pointer;
    text-align: left;
    font-family: inherit;
  }
  .poso-acct-row:hover { background: #f8fafc; }
  .poso-panel-footer {
    padding: 8px 12px;
    border-top: 1px solid #e2e8f0;
    background: #f8fafc;
  }
  .poso-picker-banner {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 8px 12px 0;
    padding: 5px 8px;
    background: #fff7ed;
    border-radius: 5px;
    font-size: 10.5px;
    color: #9a3412;
  }
  .poso-banner-x {
    border: 0;
    background: none;
    cursor: pointer;
    color: #9a3412;
    font-size: 10px;
    line-height: 1;
  }
  .poso-lookup-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    padding: 8px 10px;
    border-bottom: 1px solid #e2e8f0;
    background: white;
  }
  .poso-lookup-pill {
    padding: 4px 9px;
    border-radius: 999px;
    font: 600 10.5px/1.2 inherit;
    border: 1px solid #e2e8f0;
    background: white;
    color: #475569;
    cursor: pointer;
  }
  .poso-lookup-pill.is-active { border-color: #ea580c; background: #fff7ed; color: #ea580c; }
  .poso-lookup-meta {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 10px 14px;
    background: #fff7ed;
    border-bottom: 1px solid #e2e8f0;
  }
  .poso-lookup-icon {
    width: 24px;
    height: 24px;
    border-radius: 6px;
    background: #ea580c;
    display: grid;
    place-items: center;
    color: white;
    font-size: 11px;
  }
  .poso-key-chip {
    background: rgba(154, 52, 18, 0.1);
    padding: 1px 4px;
    border-radius: 3px;
    font-family: 'JetBrains Mono', ui-monospace, monospace;
  }
  .poso-lookup-cols {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 14px minmax(0, 1.3fr) 24px;
    padding: 6px 14px;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    font-size: 9.5px;
    font-weight: 700;
    letter-spacing: 0.06em;
    color: #94a3b8;
    text-transform: uppercase;
  }
  .poso-lookup-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 14px minmax(0, 1.3fr) 24px;
    padding: 8px 14px;
    border-bottom: 1px solid #e2e8f0;
    align-items: center;
    font-size: 11px;
  }

  /* Resolver */
  .poso-tx-picker-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 5px 9px;
    border-radius: 6px;
    font-family: inherit;
    border: 1px solid #e2e8f0;
    background: white;
    cursor: pointer;
    font-size: 11px;
    color: #0f172a;
  }
  .poso-tx-picker-btn.is-open { border-color: #ea580c; background: #fff7ed; }
  .poso-tx-dropdown {
    position: absolute;
    right: 0;
    top: calc(100% + 4px);
    z-index: 10;
    width: 360px;
    max-height: 360px;
    background: white;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
    display: flex;
    flex-direction: column;
  }
  .poso-tx-dropdown-search {
    padding: 8px 10px;
    border-bottom: 1px solid #e2e8f0;
    background: #f8fafc;
  }
  .poso-tx-option {
    width: 100%;
    display: grid;
    grid-template-columns: auto 1fr auto;
    gap: 8px;
    padding: 8px 10px;
    border: 0;
    border-bottom: 1px solid #e2e8f0;
    background: transparent;
    cursor: pointer;
    font-family: inherit;
    text-align: left;
    align-items: center;
  }
  .poso-tx-option:hover { background: #f8fafc; }
  .poso-tx-option.is-active { background: #fff7ed; }
  .poso-resolver-grid {
    display: grid;
    grid-template-columns: 1fr 1.2fr;
    gap: 0;
  }
  @media (max-width: 900px) { .poso-resolver-grid { grid-template-columns: 1fr; } }
  .poso-resolver-cell { padding: 14px; }
  .poso-resolver-cell + .poso-resolver-cell { border-left: 1px solid #e2e8f0; }
  @media (max-width: 900px) {
    .poso-resolver-cell + .poso-resolver-cell { border-left: 0; border-top: 1px solid #e2e8f0; }
  }
  .poso-section-label {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.08em;
    color: #94a3b8;
    text-transform: uppercase;
    margin-bottom: 8px;
  }
  .poso-resolver-dl {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 5px 14px;
    font-size: 11px;
    margin: 0;
  }
  .poso-resolver-dl dt { color: #94a3b8; margin: 0; }
  .poso-resolver-dl dd { margin: 0; }
  .poso-jurnal-table {
    width: 100%;
    font-size: 11px;
    border-collapse: collapse;
  }
  .poso-jurnal-table th {
    padding: 4px 0;
    font-weight: 600;
    color: #94a3b8;
    font-size: 10px;
    text-align: left;
  }
  .poso-jurnal-table td { padding: 4px 0; }
  .poso-jurnal-total {
    border-top: 1px solid #e2e8f0;
    font-weight: 700;
  }
  .poso-jurnal-total td { padding: 5px 0; }
  .poso-resolved-tag {
    background: #fff7ed;
    color: #ea580c;
    font-size: 9px;
    padding: 1px 4px;
    border-radius: 3px;
    margin-left: 4px;
    font-weight: 700;
  }
</style>
