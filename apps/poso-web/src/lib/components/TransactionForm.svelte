<script lang="ts">
  import { api, ApiError } from '$lib/api/client';
  import { getJournalTemplateMappings, getJournalTemplates, type JournalTemplate } from '$lib/api/accounting';
  import { formatRupiah } from '$lib/data/fixtures';
  import { posoContext } from '$lib/stores/context.svelte.js';

  type Mode = 'sales' | 'purchases';
  type DocumentType = 'sales_invoice' | 'purchase_bill';
  type Row = {
    id: number;
    product: string;
    description: string;
    quantity: number;
    unit: string;
    price: number;
    discount: number;
    tax: number;
  };

  let { mode }: { mode: Mode } = $props();

  let isSales = $derived(mode === 'sales');
  let title = $derived(isSales ? 'Buat Penjualan' : 'Buat Pembelian');
  let root = $derived(isSales ? '/sales' : '/purchases');
  let partyLabel = $derived(isSales ? 'Pelanggan' : 'Pemasok');
  let contactPlaceholder = $derived(isSales ? 'Pilih kontak (opsional)' : 'Pilih PIC pemasok (opsional)');
  let numberLabel = $derived(isSales ? 'No. Invoice' : 'No. Tagihan');
  let primaryAction = $derived(isSales ? 'Simpan & Terbitkan' : 'Simpan & Kirim');
  let documentType = $derived<DocumentType>(isSales ? 'sales_invoice' : 'purchase_bill');

  function initialNumber() {
    return mode === 'sales' ? 'INV/2024/05/0010' : 'BILL/2024/05/0013';
  }

  let rows = $state<Row[]>([
    { id: 1, product: '', description: '', quantity: 1, unit: 'Pcs', price: 0, discount: 0, tax: 11 },
    { id: 2, product: '', description: '', quantity: 1, unit: 'Pcs', price: 0, discount: 0, tax: 11 }
  ]);

  let party = $state('');
  let contact = $state('');
  let address = $state('');
  let number = $state(initialNumber());
  let issuedAt = $state('2024-05-23');
  let dueAt = $state('2024-06-06');
  let terms = $state('');
  let paymentTerms = $state('Net 14');
  let paymentMethod = $state('Transfer Bank');
  let journalTemplates = $state<JournalTemplate[]>([]);
  let selectedTemplateId = $state('');
  let templatesLoading = $state(false);
  let templateError = $state<string | null>(null);
  let templateLoadKey = $state('');
  let saving = $state(false);
  let saveMessage = $state<string | null>(null);
  let saveError = $state<string | null>(null);

  let subtotal = $derived(rows.reduce((sum, row) => sum + row.quantity * row.price, 0));
  let discountTotal = $derived(rows.reduce((sum, row) => sum + row.quantity * row.price * (row.discount / 100), 0));
  let dpp = $derived(subtotal - discountTotal);
  let taxTotal = $derived(rows.reduce((sum, row) => {
    const base = row.quantity * row.price * (1 - row.discount / 100);
    return sum + base * (row.tax / 100);
  }, 0));
  let grandTotal = $derived(dpp + taxTotal);
  let selectedTemplate = $derived(journalTemplates.find((template) => template.id === selectedTemplateId) ?? null);
  let canPublish = $derived(Boolean(selectedTemplateId) && !saving);

  $effect(() => {
    const entityId = posoContext.activeEntity?.id ?? '';
    const nextKey = `${documentType}:${entityId}`;

    if (!entityId || nextKey === templateLoadKey) return;

    templateLoadKey = nextKey;
    void loadJournalTemplates(entityId);
  });

  async function loadJournalTemplates(entityId: string) {
    templatesLoading = true;
    templateError = null;

    try {
      const [templates, mappings] = await Promise.all([
        getJournalTemplates(documentType, entityId),
        getJournalTemplateMappings(entityId)
      ]);
      const mappedTemplateId = mappings.find((mapping) => mapping.transaction_type === documentType && mapping.is_active)
        ?.journal_template?.id;

      journalTemplates = templates;
      selectedTemplateId =
        templates.find((template) => template.id === mappedTemplateId)?.id ??
        templates.find((template) => template.matches_document_type)?.id ??
        templates[0]?.id ??
        '';
    } catch (error) {
      templateError = error instanceof Error ? error.message : 'Template jurnal Akunta belum bisa dimuat.';
      journalTemplates = [];
      selectedTemplateId = '';
    } finally {
      templatesLoading = false;
    }
  }

  function addRow() {
    const id = Math.max(...rows.map((row) => row.id)) + 1;
    rows = [...rows, { id, product: '', description: '', quantity: 1, unit: 'Pcs', price: 0, discount: 0, tax: 11 }];
  }

  function removeRow(id: number) {
    rows = rows.length === 1 ? rows : rows.filter((row) => row.id !== id);
  }

  function clearRows() {
    rows = [{ id: 1, product: '', description: '', quantity: 1, unit: 'Pcs', price: 0, discount: 0, tax: 11 }];
  }

  async function save(status: 'draft' | 'published') {
    saveMessage = null;
    saveError = null;

    if (!party.trim()) {
      saveError = `${partyLabel} wajib diisi.`;
      return;
    }

    if (status === 'published' && !selectedTemplateId) {
      saveError = 'Template jurnal Akunta wajib dipilih sebelum transaksi diterbitkan.';
      return;
    }

    const validRows = rows.filter((row) => row.product.trim() || row.price > 0);
    if (validRows.length === 0) {
      saveError = 'Minimal satu item transaksi wajib diisi.';
      return;
    }

    saving = true;

    try {
      const payload = {
        [isSales ? 'customer' : 'supplier']: {
          name: party,
          address
        },
        number: number || null,
        issued_at: issuedAt,
        due_at: dueAt || null,
        status,
        payment_status: 'unpaid',
        payment_terms: paymentTerms,
        payment_method: paymentMethod,
        terms,
        accounting_entity_id: posoContext.activeEntity?.id ?? null,
        journal_template_id: selectedTemplateId || null,
        items: validRows.map((row) => ({
          name: row.product,
          description: row.description || null,
          quantity: row.quantity,
          unit: row.unit,
          unit_price: row.price,
          discount_rate: row.discount,
          tax_rate: row.tax
        }))
      };

      const endpoint = isSales ? '/api/v1/sales/invoices' : '/api/v1/purchases/bills';
      const response = await api<{ data: { number: string }; meta?: { akunta_event_id?: string | null; akunta_event_status?: string | null } }>(endpoint, {
        method: 'POST',
        body: JSON.stringify(payload)
      });

      saveMessage =
        status === 'published'
          ? `${response.data.number} diterbitkan. Event Akunta: ${response.meta?.akunta_event_status ?? 'pending'}.`
          : `${response.data.number} disimpan sebagai draft.`;
    } catch (error) {
      saveError = errorMessage(error);
    } finally {
      saving = false;
    }
  }

  function errorMessage(error: unknown): string {
    if (error instanceof ApiError) {
      const body = error.body as { errors?: { message?: string }[]; message?: string };
      return body.errors?.[0]?.message ?? body.message ?? 'Transaksi belum berhasil disimpan.';
    }

    return error instanceof Error ? error.message : 'Transaksi belum berhasil disimpan.';
  }
</script>

<section class="space-y-5">
  <header class="flex flex-col gap-4 border-b border-line pb-5 lg:flex-row lg:items-end lg:justify-between">
    <div class="flex items-start gap-4">
      <a class="mt-8 grid size-9 place-items-center rounded-poso border border-line bg-white text-muted hover:border-blue hover:text-blue" href={root} aria-label="Kembali">‹</a>
      <div>
        <div class="mb-2 flex items-center gap-2 text-sm text-muted">
          <a href={root} class="text-blue">{isSales ? 'Penjualan' : 'Pembelian'}</a>
          <span>›</span>
          <span>{title}</span>
        </div>
        <h1 class="text-2xl font-bold text-ink">{title}</h1>
        <p class="mt-2 text-sm text-muted">{isSales ? 'Buat invoice penjualan untuk pelanggan.' : 'Catat tagihan pembelian dari pemasok.'}</p>
      </div>
    </div>
    <div class="flex gap-3">
      <button class="h-11 rounded-poso border border-line bg-white px-5 text-sm font-semibold text-blue shadow-sm hover:border-blue disabled:opacity-50" disabled={saving} onclick={() => save('draft')}>
        {saving ? 'Menyimpan...' : 'Simpan Draft'}
      </button>
      <button class="h-11 rounded-poso bg-blue px-5 text-sm font-semibold text-white shadow-sm hover:bg-blue/90 disabled:bg-muted disabled:shadow-none" disabled={!canPublish} onclick={() => save('published')}>
        {saving ? 'Menyimpan...' : primaryAction}
      </button>
    </div>
  </header>

  {#if saveMessage}
    <div class="rounded-poso border border-green/20 bg-green-soft px-4 py-3 text-sm font-semibold text-green">{saveMessage}</div>
  {/if}

  {#if saveError}
    <div class="rounded-poso border border-red/20 bg-red-soft px-4 py-3 text-sm font-semibold text-red">{saveError}</div>
  {/if}

  <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_360px]">
    <div class="space-y-5">
      <section class="panel rounded-poso p-5">
        <h2 class="mb-5 text-base font-bold text-ink">Informasi {partyLabel}</h2>
        <div class="grid gap-5 lg:grid-cols-2">
          <div class="space-y-4">
            <label class="block">
              <span class="mb-2 block text-sm font-semibold text-ink">{partyLabel} <span class="text-red">*</span></span>
              <div class="flex gap-3">
                <input class="field" bind:value={party} placeholder={`Pilih ${partyLabel.toLowerCase()}...`} />
                <button class="grid size-11 shrink-0 place-items-center rounded-poso border border-line bg-white text-xl text-blue hover:border-blue" aria-label={`Tambah ${partyLabel.toLowerCase()} baru`}>+</button>
              </div>
            </label>
            <button class="text-sm font-semibold text-blue">+ Tambah {partyLabel.toLowerCase()} baru</button>
            <label class="block">
              <span class="mb-2 block text-sm font-semibold text-ink">Alamat</span>
              <textarea class="field min-h-28 resize-y" bind:value={address} placeholder={`Alamat akan terisi otomatis berdasarkan ${partyLabel.toLowerCase()}`}></textarea>
            </label>
          </div>

          <div class="space-y-4">
            <label class="block">
              <span class="mb-2 block text-sm font-semibold text-ink">Kontak</span>
              <input class="field" bind:value={contact} placeholder={contactPlaceholder} />
            </label>
            <label class="block">
              <span class="mb-2 block text-sm font-semibold text-ink">{numberLabel} <span class="text-red">*</span></span>
              <input class="field" bind:value={number} />
            </label>
            <div class="grid gap-4 md:grid-cols-2">
              <label class="block">
                <span class="mb-2 block text-sm font-semibold text-ink">Tanggal <span class="text-red">*</span></span>
                <input class="field" type="date" bind:value={issuedAt} />
              </label>
              <label class="block">
                <span class="mb-2 block text-sm font-semibold text-ink">Jatuh Tempo <span class="text-red">*</span></span>
                <input class="field" type="date" bind:value={dueAt} />
              </label>
            </div>
          </div>
        </div>
      </section>

      <section class="panel rounded-poso p-5">
        <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <h2 class="text-base font-bold text-ink">Detail Item</h2>
          <div class="flex gap-3">
            <button class="h-10 rounded-poso border border-line bg-white px-4 text-sm font-semibold text-blue hover:border-blue" onclick={addRow}>+ Pilih Produk</button>
            <button class="h-10 rounded-poso border border-line bg-white px-4 text-sm font-semibold text-blue hover:border-blue" onclick={addRow}>+ Tambah Baris</button>
          </div>
        </div>

        <div class="overflow-x-auto rounded-poso border border-line">
          <table class="w-full min-w-[900px] text-sm">
            <thead class="bg-soft text-left text-xs font-semibold uppercase text-muted">
              <tr>
                <th class="w-12 px-3 py-3">#</th>
                <th class="px-3 py-3">Produk / Jasa <span class="text-red">*</span></th>
                <th class="px-3 py-3">Deskripsi</th>
                <th class="w-24 px-3 py-3">Kuantitas</th>
                <th class="w-24 px-3 py-3">Satuan</th>
                <th class="w-36 px-3 py-3">Harga Satuan</th>
                <th class="w-24 px-3 py-3">Diskon</th>
                <th class="w-28 px-3 py-3">Pajak</th>
                <th class="w-32 px-3 py-3 text-right">Total</th>
                <th class="w-12 px-3 py-3"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-line bg-white">
              {#each rows as row, index (row.id)}
                <tr>
                  <td class="px-3 py-3 text-muted">{index + 1}</td>
                  <td class="px-3 py-3"><input class="field h-10" bind:value={row.product} placeholder="Pilih produk / jasa..." /></td>
                  <td class="px-3 py-3"><input class="field h-10" bind:value={row.description} placeholder="Deskripsi (opsional)" /></td>
                  <td class="px-3 py-3"><input class="field h-10" type="number" min="0" step="0.01" bind:value={row.quantity} /></td>
                  <td class="px-3 py-3">
                    <select class="field h-10 py-0" bind:value={row.unit}>
                      <option>Pcs</option>
                      <option>Box</option>
                      <option>Kg</option>
                      <option>Jam</option>
                    </select>
                  </td>
                  <td class="px-3 py-3"><input class="field h-10 text-right tabular" type="number" min="0" bind:value={row.price} /></td>
                  <td class="px-3 py-3"><input class="field h-10 text-right tabular" type="number" min="0" max="100" bind:value={row.discount} /></td>
                  <td class="px-3 py-3">
                    <select class="field h-10 py-0" bind:value={row.tax}>
                      <option value={0}>Non PPN</option>
                      <option value={11}>PPN 11%</option>
                      <option value={12}>PPN 12%</option>
                    </select>
                  </td>
                  <td class="px-3 py-3 text-right font-semibold tabular text-ink">
                    {formatRupiah(row.quantity * row.price * (1 - row.discount / 100) * (1 + row.tax / 100))}
                  </td>
                  <td class="px-3 py-3">
                    <button class="grid size-9 place-items-center rounded-poso border border-line text-red hover:bg-red-soft" onclick={() => removeRow(row.id)} aria-label="Hapus baris">×</button>
                  </td>
                </tr>
              {/each}
            </tbody>
          </table>
        </div>

        <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <button class="h-10 rounded-poso border border-line bg-white px-4 text-sm font-semibold text-blue hover:border-blue">▣ Catatan / Memo</button>
          <button class="h-10 rounded-poso px-4 text-sm font-semibold text-red hover:bg-red-soft" onclick={clearRows}>× Hapus semua baris</button>
        </div>
      </section>

      <section class="panel rounded-poso p-5">
        <label class="block">
          <span class="mb-2 block text-sm font-semibold text-ink">Syarat & Ketentuan <span class="font-normal text-muted">(opsional)</span></span>
          <textarea class="field min-h-24 resize-y" bind:value={terms} placeholder="Contoh: Pembayaran harus dilakukan sebelum jatuh tempo."></textarea>
        </label>
      </section>

      <p class="text-sm font-semibold text-blue">ⓘ Pastikan data sudah benar sebelum menyimpan dan menerbitkan dokumen.</p>
    </div>

    <aside class="space-y-5 xl:sticky xl:top-24 xl:self-start">
      <section class="panel rounded-poso p-5">
        <h2 class="mb-5 text-base font-bold text-ink">Ringkasan</h2>
        <div class="space-y-4 text-sm">
          <div class="flex justify-between gap-4 text-muted"><span>Subtotal</span><strong class="tabular text-ink">{formatRupiah(subtotal)}</strong></div>
          <div class="flex justify-between gap-4 text-muted"><span>Diskon</span><strong class="tabular text-ink">{formatRupiah(discountTotal)}</strong></div>
          <div class="flex justify-between gap-4 text-muted"><span>DPP</span><strong class="tabular text-ink">{formatRupiah(dpp)}</strong></div>
          <div class="flex justify-between gap-4 text-muted"><span>PPN</span><strong class="tabular text-ink">{formatRupiah(taxTotal)}</strong></div>
        </div>
        <div class="my-5 border-t border-line"></div>
        <div class="flex items-center justify-between gap-4">
          <span class="font-bold text-ink">Total</span>
          <strong class="text-2xl font-bold tabular text-ink">{formatRupiah(grandTotal)}</strong>
        </div>
        <div class="mt-5 rounded-poso bg-blue-soft p-4 text-sm font-medium text-blue">
          Total akan dihitung otomatis berdasarkan item, diskon, dan pajak.
        </div>
      </section>

      <section class="panel rounded-poso p-5">
        <div class="mb-4 flex items-start justify-between gap-3">
          <div>
            <h2 class="text-base font-bold text-ink">Jurnal Akunta</h2>
            <p class="mt-1 text-xs font-semibold text-muted">{posoContext.activeEntity?.name ?? 'Entitas belum dipilih'}</p>
          </div>
          <span class="rounded-full bg-blue-soft px-2.5 py-1 text-[10px] font-bold uppercase text-blue">Webhook</span>
        </div>

        <label class="block">
          <span class="mb-2 block text-sm font-semibold text-ink">Template Jurnal <span class="text-red">*</span></span>
          <select class="field" bind:value={selectedTemplateId} disabled={templatesLoading || journalTemplates.length === 0}>
            {#if templatesLoading}
              <option value="">Memuat template...</option>
            {:else if journalTemplates.length === 0}
              <option value="">Belum ada template Akunta</option>
            {:else}
              {#each journalTemplates as template (template.id)}
                <option value={template.id}>{template.code} — {template.name}</option>
              {/each}
            {/if}
          </select>
        </label>

        {#if templateError}
          <div class="mt-3 rounded-poso bg-red-soft px-3 py-2 text-xs font-semibold text-red">{templateError}</div>
        {/if}

        {#if selectedTemplate}
          <div class="mt-4 overflow-hidden rounded-poso border border-line bg-white">
            {#each selectedTemplate.lines as line (line.line_no)}
              <div class="flex items-start gap-3 border-b border-line px-3 py-3 last:border-b-0">
                <span class={`mt-0.5 rounded-full px-2 py-0.5 text-[10px] font-bold uppercase ${line.side === 'debit' ? 'bg-green-soft text-green' : 'bg-amber-soft text-amber'}`}>
                  {line.side === 'debit' ? 'Debit' : 'Kredit'}
                </span>
                <div class="min-w-0 flex-1">
                  <div class="truncate text-sm font-bold text-ink">{line.account.code} · {line.account.name}</div>
                  <div class="mt-0.5 text-xs text-muted">{line.memo ?? line.account.type}</div>
                </div>
              </div>
            {/each}
          </div>
        {/if}
      </section>

      <section class="panel rounded-poso p-5">
        <h2 class="mb-4 text-base font-bold text-ink">Pembayaran</h2>
        <label class="mb-4 block">
          <span class="mb-2 block text-sm font-semibold text-ink">Syarat Pembayaran</span>
          <select class="field" bind:value={paymentTerms}>
            <option>Net 14</option>
            <option>Net 30</option>
            <option>COD</option>
          </select>
        </label>
        <label class="block">
          <span class="mb-2 block text-sm font-semibold text-ink">Metode Pembayaran</span>
          <select class="field" bind:value={paymentMethod}>
            <option>Transfer Bank</option>
            <option>Tunai</option>
            <option>Kartu</option>
          </select>
        </label>
      </section>

      <section class="panel rounded-poso p-5">
        <h2 class="mb-4 text-base font-bold text-ink">Lampiran <span class="font-normal text-muted">(opsional)</span></h2>
        <button class="flex min-h-24 w-full flex-col items-center justify-center rounded-poso border border-dashed border-line bg-soft text-sm font-semibold text-muted hover:border-blue hover:text-blue">
          <span class="text-xl">⇧</span>
          Klik atau seret file ke sini
          <span class="mt-1 text-xs font-normal">Maks. 5MB per file</span>
        </button>
        <p class="mt-4 text-center text-sm text-muted">Belum ada lampiran</p>
      </section>
    </aside>
  </div>
</section>
