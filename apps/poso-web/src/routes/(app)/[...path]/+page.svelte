<script lang="ts">
  import { page } from '$app/stores';
  import Icon from '$lib/components/Icon.svelte';
  import ModuleDataTable from '$lib/components/ModuleDataTable.svelte';
  import { ApiError } from '$lib/api/client';
  import {
    createCustomer,
    createProduct,
    createSupplier,
    getModuleData,
    isModuleKey,
    saveJournalTemplateMapping,
    type ModuleKey
  } from '$lib/api/modules';
  import { formatRupiah } from '$lib/data/fixtures';
  import { posoContext } from '$lib/stores/context.svelte.js';

  type IconName =
    | 'book-open'
    | 'grid'
    | 'layers'
    | 'link'
    | 'package'
    | 'settings'
    | 'shield'
    | 'tag'
    | 'truck'
    | 'users'
    | 'wallet';

  type ModuleMeta = {
    title: string;
    description: string;
    icon: IconName;
    action?: string;
  };

  type Row = Record<string, unknown>;
  type Kpi = { label: string; value: unknown; format?: 'money' | 'number' };
  type DashboardData = {
    cards?: Kpi[];
    recent_transactions?: Row[];
    sync?: Record<string, number>;
  };
  type JournalTemplateOption = {
    id: string;
    code: string;
    name: string;
    matches_document_type?: boolean;
  };
  type JournalTemplateMapping = {
    transaction_type: string;
    label: string;
    description: string;
    journal_template: { id: string; code: string | null; name: string | null } | null;
    is_required: boolean;
    auto_queue_webhook: boolean;
    is_active: boolean;
  };
  type SettingsData = {
    entity_id?: string | null;
    accounting_templates?: JournalTemplateOption[];
    journal_template_mappings?: JournalTemplateMapping[];
    document_numbering?: Row[];
    taxes?: Row[];
  };

  const modules: Record<ModuleKey, ModuleMeta> = {
    dashboard: {
      title: 'Dashboard',
      description: 'Ringkasan penjualan, pembelian, dan sinkronisasi Akunta.',
      icon: 'grid'
    },
    customers: {
      title: 'Manajemen Pelanggan',
      description: 'Profil pelanggan, kontak, alamat, termin, dan riwayat transaksi.',
      icon: 'users',
      action: 'Tambah Pelanggan'
    },
    suppliers: {
      title: 'Manajemen Pemasok',
      description: 'Profil pemasok, kontak PIC, termin pembelian, dan alamat.',
      icon: 'truck',
      action: 'Tambah Pemasok'
    },
    products: {
      title: 'Produk & Jasa',
      description: 'Katalog produk, jasa, SKU, satuan, pajak, dan harga dasar.',
      icon: 'package',
      action: 'Tambah Produk'
    },
    'price-lists': {
      title: 'Daftar Harga',
      description: 'Harga jual, harga beli, margin, dan pajak per produk.',
      icon: 'tag'
    },
    inventory: {
      title: 'Stok & Gudang',
      description: 'Stok tersedia, titik pemesanan ulang, dan lokasi gudang.',
      icon: 'layers'
    },
    payments: {
      title: 'Pembayaran',
      description: 'Tagihan masuk dan keluar yang perlu ditindaklanjuti.',
      icon: 'wallet'
    },
    reports: {
      title: 'Laporan',
      description: 'Ringkasan operasional penjualan, pembelian, piutang, dan hutang.',
      icon: 'book-open'
    },
    'integrations/akunta': {
      title: 'Integrasi Akunta',
      description: 'Outbox webhook, status retry, dan template jurnal yang dikirim.',
      icon: 'link'
    },
    users: {
      title: 'Manajemen User',
      description: 'User POSO yang tersambung ke main tier Ecopa.',
      icon: 'users'
    },
    roles: {
      title: 'Role & Akses',
      description: 'Role dan batas akses operasional per fungsi.',
      icon: 'shield'
    },
    settings: {
      title: 'Setting POSO',
      description: 'Penomoran dokumen, pajak, dan mapping template jurnal.',
      icon: 'settings'
    },
    'audit-log': {
      title: 'Audit Log',
      description: 'Riwayat aktivitas penting dan perubahan data operasional.',
      icon: 'grid'
    }
  };

  let path = $derived(($page.params.path ?? 'dashboard') as string);
  let moduleKey = $derived<ModuleKey>(isModuleKey(path) ? path : 'dashboard');
  let meta = $derived(modules[moduleKey]);
  let data = $state<unknown>(null);
  let rows = $state<Row[]>([]);
  let loading = $state(false);
  let error = $state<string | null>(null);
  let loadedKey = $state('');
  let saving = $state(false);
  let savingMapping = $state<string | null>(null);
  let notice = $state<string | null>(null);
  let formOpen = $state(false);
  let form = $state({
    code: '',
    sku: '',
    name: '',
    email: '',
    phone: '',
    address: '',
    type: 'goods',
    unit: 'Pcs',
    sales_price: '0',
    purchase_price: '0',
    tax_rate: '11'
  });
  let mappingDrafts = $state<Record<string, {
    journal_template_id: string;
    is_required: boolean;
    auto_queue_webhook: boolean;
    is_active: boolean;
  }>>({});

  $effect(() => {
    if (loadedKey === moduleKey) return;
    loadedKey = moduleKey;
    void load();
  });

  async function load() {
    loading = true;
    error = null;
    notice = null;

    try {
      data = await getModuleData(moduleKey, {
        accounting_entity_id: moduleKey === 'settings' ? posoContext.activeEntity?.id : undefined
      });
      rows = extractRows(moduleKey, data);
      if (moduleKey === 'settings') seedMappingDrafts(data as SettingsData);
    } catch (caught) {
      error = errorMessage(caught);
      data = null;
      rows = [];
    } finally {
      loading = false;
    }
  }

  async function saveMapping(mapping: JournalTemplateMapping) {
    const draft = mappingDrafts[mapping.transaction_type];
    if (!draft?.journal_template_id) {
      error = 'Template jurnal wajib dipilih.';
      return;
    }

    savingMapping = mapping.transaction_type;
    error = null;
    notice = null;

    try {
      await saveJournalTemplateMapping({
        accounting_entity_id: settingsPayload().entity_id ?? posoContext.activeEntity?.id ?? null,
        transaction_type: mapping.transaction_type,
        journal_template_id: draft.journal_template_id,
        is_required: draft.is_required,
        auto_queue_webhook: draft.auto_queue_webhook,
        is_active: draft.is_active
      });

      notice = `${mapping.label} tersimpan.`;
      await load();
    } catch (caught) {
      error = errorMessage(caught);
    } finally {
      savingMapping = null;
    }
  }

  async function submitMaster() {
    if (!form.name.trim()) {
      error = 'Nama wajib diisi.';
      return;
    }

    saving = true;
    error = null;

    try {
      if (moduleKey === 'customers') {
        await createCustomer({
          code: form.code || null,
          name: form.name,
          email: form.email || null,
          phone: form.phone || null,
          address: form.address || null
        });
      } else if (moduleKey === 'suppliers') {
        await createSupplier({
          code: form.code || null,
          name: form.name,
          email: form.email || null,
          phone: form.phone || null,
          address: form.address || null
        });
      } else if (moduleKey === 'products') {
        await createProduct({
          sku: form.sku || null,
          name: form.name,
          type: form.type,
          unit: form.unit,
          sales_price: Number(form.sales_price || 0),
          purchase_price: Number(form.purchase_price || 0),
          tax_rate: Number(form.tax_rate || 0),
          is_active: true
        });
      }

      notice = `${form.name} tersimpan.`;
      resetForm();
      formOpen = false;
      await load();
    } catch (caught) {
      error = errorMessage(caught);
    } finally {
      saving = false;
    }
  }

  function resetForm() {
    form = {
      code: '',
      sku: '',
      name: '',
      email: '',
      phone: '',
      address: '',
      type: 'goods',
      unit: 'Pcs',
      sales_price: '0',
      purchase_price: '0',
      tax_rate: '11'
    };
  }

  function extractRows(key: ModuleKey, value: unknown): Row[] {
    if (Array.isArray(value)) return value as Row[];
    if (key === 'dashboard') return ((value as DashboardData | null)?.recent_transactions ?? []) as Row[];
    return [];
  }

  function dashboardCards(): Kpi[] {
    return ((data as DashboardData | null)?.cards ?? []) as Kpi[];
  }

  function dashboardSync(): Record<string, number> {
    return ((data as DashboardData | null)?.sync ?? {}) as Record<string, number>;
  }

  function reportData(): Record<string, number> {
    return (data ?? {}) as Record<string, number>;
  }

  function settingsData(): Record<string, unknown> {
    return (data ?? {}) as Record<string, unknown>;
  }

  function settingsPayload(): SettingsData {
    return (data ?? {}) as SettingsData;
  }

  function seedMappingDrafts(value: SettingsData) {
    const next: typeof mappingDrafts = {};
    for (const mapping of value.journal_template_mappings ?? []) {
      next[mapping.transaction_type] = {
        journal_template_id: mapping.journal_template?.id ?? '',
        is_required: mapping.is_required,
        auto_queue_webhook: mapping.auto_queue_webhook,
        is_active: mapping.is_active
      };
    }
    mappingDrafts = next;
  }

  function templateOptions(): JournalTemplateOption[] {
    return settingsPayload().accounting_templates ?? [];
  }

  function selectedTemplate(templateId: string): JournalTemplateOption | undefined {
    return templateOptions().find((template) => template.id === templateId);
  }

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

  function formatKpi(card: Kpi): string {
    if (card.format === 'money') return money(card.value);
    return new Intl.NumberFormat('id-ID').format(Number(card.value ?? 0));
  }

  function statusClass(status: unknown): string {
    if (status === 'sent' || status === 'paid' || status === 'Aktif') return 'bg-green-soft text-green';
    if (status === 'failed') return 'bg-red-soft text-red';
    return 'bg-amber-soft text-amber';
  }

  function errorMessage(caught: unknown): string {
    if (caught instanceof ApiError) {
      const body = caught.body as { errors?: { message?: string }[]; message?: string };
      return body.errors?.[0]?.message ?? body.message ?? 'Data modul belum bisa dimuat.';
    }

    return caught instanceof Error ? caught.message : 'Data modul belum bisa dimuat.';
  }
</script>

<section class="space-y-5">
  <header class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between">
    <div class="flex items-start gap-4">
      <div class="grid size-12 shrink-0 place-items-center rounded-poso bg-blue-soft text-blue">
        <Icon name={meta.icon} size={22} stroke={1.9} />
      </div>
      <div>
        <h1 class="text-2xl font-extrabold tracking-tight text-ink">{meta.title}</h1>
        <p class="mt-1 text-sm text-muted">{meta.description}</p>
      </div>
    </div>

    <div class="flex flex-wrap gap-3">
      <button class="h-11 rounded-poso border border-line bg-white px-4 text-sm font-semibold text-muted shadow-sm hover:border-blue hover:text-blue" onclick={load}>
        Refresh
      </button>
      {#if meta.action}
        <button class="h-11 rounded-poso bg-blue px-4 text-sm font-semibold text-white shadow-sm hover:bg-blue/90" onclick={() => (formOpen = !formOpen)}>
          {meta.action}
        </button>
      {/if}
    </div>
  </header>

  {#if notice}
    <div class="rounded-poso border border-green/20 bg-green-soft px-4 py-3 text-sm font-semibold text-green">{notice}</div>
  {/if}

  {#if error}
    <div class="rounded-poso border border-red/20 bg-red-soft px-4 py-3 text-sm font-semibold text-red">{error}</div>
  {/if}

  {#if formOpen}
    <section class="panel rounded-poso p-5">
      <div class="grid gap-4 lg:grid-cols-4">
        {#if moduleKey === 'products'}
          <label class="block">
            <span class="mb-2 block text-sm font-semibold text-ink">SKU</span>
            <input class="field" bind:value={form.sku} placeholder="SKU-001" />
          </label>
          <label class="block lg:col-span-2">
            <span class="mb-2 block text-sm font-semibold text-ink">Nama Produk/Jasa</span>
            <input class="field" bind:value={form.name} placeholder="Nama produk atau jasa" />
          </label>
          <label class="block">
            <span class="mb-2 block text-sm font-semibold text-ink">Tipe</span>
            <select class="field" bind:value={form.type}>
              <option value="goods">Barang</option>
              <option value="service">Jasa</option>
            </select>
          </label>
          <label class="block">
            <span class="mb-2 block text-sm font-semibold text-ink">Satuan</span>
            <input class="field" bind:value={form.unit} />
          </label>
          <label class="block">
            <span class="mb-2 block text-sm font-semibold text-ink">Harga Jual</span>
            <input class="field tabular" type="number" bind:value={form.sales_price} />
          </label>
          <label class="block">
            <span class="mb-2 block text-sm font-semibold text-ink">Harga Beli</span>
            <input class="field tabular" type="number" bind:value={form.purchase_price} />
          </label>
          <label class="block">
            <span class="mb-2 block text-sm font-semibold text-ink">Pajak %</span>
            <input class="field tabular" type="number" bind:value={form.tax_rate} />
          </label>
        {:else}
          <label class="block">
            <span class="mb-2 block text-sm font-semibold text-ink">Kode</span>
            <input class="field" bind:value={form.code} placeholder="AUTO" />
          </label>
          <label class="block lg:col-span-2">
            <span class="mb-2 block text-sm font-semibold text-ink">Nama</span>
            <input class="field" bind:value={form.name} placeholder={moduleKey === 'customers' ? 'Nama pelanggan' : 'Nama pemasok'} />
          </label>
          <label class="block">
            <span class="mb-2 block text-sm font-semibold text-ink">Telepon</span>
            <input class="field" bind:value={form.phone} placeholder="+62" />
          </label>
          <label class="block lg:col-span-2">
            <span class="mb-2 block text-sm font-semibold text-ink">Email</span>
            <input class="field" type="email" bind:value={form.email} placeholder="nama@domain.com" />
          </label>
          <label class="block lg:col-span-2">
            <span class="mb-2 block text-sm font-semibold text-ink">Alamat</span>
            <input class="field" bind:value={form.address} placeholder="Alamat" />
          </label>
        {/if}
      </div>
      <div class="mt-4 flex justify-end gap-3">
        <button class="h-10 rounded-poso border border-line bg-white px-4 text-sm font-semibold text-muted hover:border-blue hover:text-blue" onclick={() => (formOpen = false)}>Batal</button>
        <button class="h-10 rounded-poso bg-blue px-4 text-sm font-semibold text-white hover:bg-blue/90 disabled:opacity-60" disabled={saving} onclick={submitMaster}>
          {saving ? 'Menyimpan...' : 'Simpan'}
        </button>
      </div>
    </section>
  {/if}

  {#if loading}
    <div class="panel rounded-poso p-8 text-sm font-semibold text-muted">Memuat data...</div>
  {:else if moduleKey === 'dashboard'}
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
      {#each dashboardCards() as card}
        <section class="panel rounded-poso p-5">
          <div class="text-sm font-semibold text-muted">{card.label}</div>
          <div class="mt-3 text-2xl font-extrabold tabular text-ink">{formatKpi(card)}</div>
        </section>
      {/each}
    </div>

    <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_340px]">
      <ModuleDataTable title="Transaksi Terbaru" {rows} columns={['type', 'number', 'party', 'date', 'amount', 'status']} moneyColumns={['amount']} />
      <section class="panel rounded-poso p-5">
        <h2 class="text-base font-bold text-ink">Status Webhook Akunta</h2>
        <div class="mt-5 space-y-3">
          {#each Object.entries(dashboardSync()) as [key, value]}
            <div class="flex items-center justify-between rounded-poso border border-line px-3 py-3">
              <span class="text-sm font-semibold capitalize text-muted">{key}</span>
              <span class={`rounded-full px-2.5 py-1 text-xs font-bold ${statusClass(key)}`}>{value}</span>
            </div>
          {/each}
        </div>
      </section>
    </div>
  {:else if moduleKey === 'reports'}
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
      {#each Object.entries(reportData()) as [key, value]}
        <section class="panel rounded-poso p-5">
          <div class="text-sm font-semibold capitalize text-muted">{key.replaceAll('_', ' ')}</div>
          <div class="mt-3 text-2xl font-extrabold tabular text-ink">{money(value)}</div>
        </section>
      {/each}
    </div>
    <section class="panel rounded-poso p-5">
      <h2 class="text-base font-bold text-ink">Laporan Siap Ekspor</h2>
      <div class="mt-4 grid gap-3 md:grid-cols-3">
        <button class="rounded-poso border border-line bg-white px-4 py-4 text-left text-sm font-bold text-ink hover:border-blue">Rekap Penjualan</button>
        <button class="rounded-poso border border-line bg-white px-4 py-4 text-left text-sm font-bold text-ink hover:border-blue">Rekap Pembelian</button>
        <button class="rounded-poso border border-line bg-white px-4 py-4 text-left text-sm font-bold text-ink hover:border-blue">Piutang & Hutang</button>
      </div>
    </section>
  {:else if moduleKey === 'settings'}
    <section class="panel rounded-poso p-5">
      <div class="flex flex-col gap-3 border-b border-line pb-5 md:flex-row md:items-start md:justify-between">
        <div>
          <h2 class="text-base font-bold text-ink">Mapping Template Jurnal</h2>
          <p class="mt-1 text-sm text-muted">{posoContext.activeEntity?.name ?? 'Entitas aktif'} · Akunta COA</p>
        </div>
        <span class="inline-flex w-fit rounded-full bg-blue-soft px-3 py-1 text-xs font-bold text-blue">Default transaksi</span>
      </div>

      <div class="mt-5 grid gap-4 xl:grid-cols-2">
        {#each (settingsPayload().journal_template_mappings ?? []) as mapping}
          <article class="rounded-poso border border-line bg-white p-4 shadow-sm">
            <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
              <div>
                <h3 class="text-sm font-extrabold text-ink">{mapping.label}</h3>
                <p class="mt-1 text-xs leading-5 text-muted">{mapping.description}</p>
              </div>
              <span class={`w-fit rounded-full px-2.5 py-1 text-[10px] font-bold uppercase ${mappingDrafts[mapping.transaction_type]?.is_active ? 'bg-green-soft text-green' : 'bg-amber-soft text-amber'}`}>
                {mappingDrafts[mapping.transaction_type]?.is_active ? 'Aktif' : 'Draft'}
              </span>
            </div>

            {#each [mappingDrafts[mapping.transaction_type]] as draft}
            {#if draft}
              <label class="mt-4 block">
                <span class="mb-2 block text-sm font-semibold text-ink">Template Akunta</span>
                <select class="field" bind:value={draft.journal_template_id}>
                  <option value="">Pilih template jurnal</option>
                  {#each templateOptions() as template (template.id)}
                    <option value={template.id}>{template.code} — {template.name}</option>
                  {/each}
                </select>
              </label>

              {#if selectedTemplate(draft.journal_template_id)}
                {@const chosen = selectedTemplate(draft.journal_template_id)}
                <div class="mt-3 rounded-poso bg-soft px-3 py-2 text-xs font-semibold text-muted">
                  {chosen?.code} · {chosen?.name}
                </div>
              {/if}

              <div class="mt-4 grid gap-3 md:grid-cols-3">
                <label class="flex items-center gap-2 rounded-poso border border-line px-3 py-2 text-sm font-semibold text-ink">
                  <input class="accent-blue" type="checkbox" bind:checked={draft.is_required} />
                  Wajib
                </label>
                <label class="flex items-center gap-2 rounded-poso border border-line px-3 py-2 text-sm font-semibold text-ink">
                  <input class="accent-blue" type="checkbox" bind:checked={draft.auto_queue_webhook} />
                  Webhook
                </label>
                <label class="flex items-center gap-2 rounded-poso border border-line px-3 py-2 text-sm font-semibold text-ink">
                  <input class="accent-blue" type="checkbox" bind:checked={draft.is_active} />
                  Aktif
                </label>
              </div>

              <div class="mt-4 flex justify-end">
                <button
                  class="h-10 rounded-poso bg-blue px-4 text-sm font-semibold text-white hover:bg-blue/90 disabled:opacity-60"
                  disabled={savingMapping === mapping.transaction_type || !draft.journal_template_id}
                  onclick={() => saveMapping(mapping)}
                >
                  {savingMapping === mapping.transaction_type ? 'Menyimpan...' : 'Simpan Mapping'}
                </button>
              </div>
            {/if}
            {/each}
          </article>
        {/each}
      </div>
    </section>

    <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_360px]">
      <section class="panel rounded-poso p-5">
        <h2 class="text-base font-bold text-ink">Penomoran Dokumen</h2>
        <div class="mt-4 divide-y divide-line rounded-poso border border-line bg-white">
          {#each ((settingsData().document_numbering as Row[] | undefined) ?? []) as row}
            <div class="grid gap-3 px-4 py-3 text-sm md:grid-cols-3">
              <span class="font-bold text-ink">{rowValue(row, 'type')}</span>
              <span class="text-muted">Prefix: {rowValue(row, 'prefix')}</span>
              <span class="font-semibold tabular text-blue">{rowValue(row, 'sample')}</span>
            </div>
          {/each}
        </div>
      </section>

      <section class="panel rounded-poso p-5">
        <h2 class="text-base font-bold text-ink">Pajak</h2>
        <div class="mt-4 space-y-3">
          {#each ((settingsData().taxes as Row[] | undefined) ?? []) as tax}
            <div class="flex items-center justify-between rounded-poso border border-line px-3 py-3">
              <span class="text-sm font-bold text-ink">{rowValue(tax, 'name')}</span>
              <span class="rounded-full bg-blue-soft px-2.5 py-1 text-xs font-bold text-blue">{rowValue(tax, 'rate')}%</span>
            </div>
          {/each}
        </div>
      </section>
    </div>

    <ModuleDataTable title="Template Jurnal Akunta" rows={((settingsData().accounting_templates as Row[] | undefined) ?? [])} columns={['code', 'name', 'journal_type', 'matches_document_type']} />
  {:else if moduleKey === 'customers'}
    <ModuleDataTable title="Daftar Pelanggan" {rows} columns={['code', 'name', 'email', 'phone', 'transactions_count', 'total']} moneyColumns={['total']} />
  {:else if moduleKey === 'suppliers'}
    <ModuleDataTable title="Daftar Pemasok" {rows} columns={['code', 'name', 'email', 'phone', 'transactions_count', 'total']} moneyColumns={['total']} />
  {:else if moduleKey === 'products'}
    <ModuleDataTable title="Katalog Produk & Jasa" {rows} columns={['sku', 'name', 'type', 'unit', 'sales_price', 'purchase_price', 'tax_rate', 'is_active']} moneyColumns={['sales_price', 'purchase_price']} />
  {:else if moduleKey === 'price-lists'}
    <ModuleDataTable title="Daftar Harga" {rows} columns={['sku', 'name', 'sales_price', 'purchase_price', 'margin', 'tax_rate']} moneyColumns={['sales_price', 'purchase_price', 'margin']} />
  {:else if moduleKey === 'inventory'}
    <ModuleDataTable title="Stok & Gudang" {rows} columns={['sku', 'name', 'warehouse', 'stock_on_hand', 'reorder_point', 'unit']} />
  {:else if moduleKey === 'payments'}
    <ModuleDataTable title="Antrian Pembayaran" {rows} columns={['type', 'number', 'party', 'due_at', 'amount', 'status']} moneyColumns={['amount']} />
  {:else if moduleKey === 'integrations/akunta'}
    <ModuleDataTable title="Outbox Webhook Akunta" {rows} columns={['event_type', 'template', 'status', 'attempts', 'available_at', 'sent_at', 'last_error']} />
  {:else if moduleKey === 'users'}
    <ModuleDataTable title="User POSO" {rows} columns={['name', 'email', 'status', 'created_at']} />
  {:else if moduleKey === 'roles'}
    <ModuleDataTable title="Role & Permission" {rows} columns={['code', 'name', 'is_preset', 'created_at']} />
  {:else if moduleKey === 'audit-log'}
    <ModuleDataTable title="Audit Log" {rows} columns={['event', 'auditable_type', 'auditable_id', 'user_id', 'created_at']} />
  {/if}
</section>
