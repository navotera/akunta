import { e as escape_html, b as attr, a as ensure_array_like, c as attr_class, f as derived, d as store_get, u as unsubscribe_stores } from "../../../../chunks/renderer.js";
import { p as page } from "../../../../chunks/stores.js";
import { I as Icon } from "../../../../chunks/Icon.js";
import { f as formatRupiah } from "../../../../chunks/fixtures.js";
import { p as posoContext } from "../../../../chunks/context.svelte.js";
function ModuleDataTable($$renderer, $$props) {
  $$renderer.component(($$renderer2) => {
    let { title, rows, columns, moneyColumns = [] } = $$props;
    let search = "";
    let filteredRows = derived(() => rows.filter((row) => {
      const needle = search.trim().toLowerCase();
      if (!needle) return true;
      return columns.some((column) => rowValue(row, column).toLowerCase().includes(needle));
    }));
    function rowValue(row, key) {
      const value = row[key];
      if (value === null || value === void 0 || value === "") return "-";
      if (typeof value === "number") return String(value);
      if (typeof value === "boolean") return value ? "Aktif" : "Nonaktif";
      return String(value);
    }
    function money(value) {
      return formatRupiah(Number(value ?? 0));
    }
    function statusClass(status) {
      if (status === "sent" || status === "paid" || status === "Aktif") return "bg-green-soft text-green";
      if (status === "failed") return "bg-red-soft text-red";
      return "bg-amber-soft text-amber";
    }
    $$renderer2.push(`<section class="panel overflow-hidden rounded-poso"><div class="flex flex-col gap-3 border-b border-line px-5 py-4 md:flex-row md:items-center md:justify-between"><h2 class="text-base font-bold text-ink">${escape_html(title)}</h2> <label class="relative w-full md:max-w-xs"><span class="sr-only">Cari</span> <input class="field h-10 pr-10"${attr("value", search)} placeholder="Cari data..."/> <span class="absolute right-3 top-1/2 -translate-y-1/2 text-muted">`);
    Icon($$renderer2, { name: "grid", size: 15 });
    $$renderer2.push(`<!----></span></label></div> <div class="overflow-x-auto"><table class="w-full min-w-[860px] text-sm"><thead class="bg-soft text-left text-[11px] font-bold uppercase tracking-[0.02em] text-muted"><tr><!--[-->`);
    const each_array = ensure_array_like(columns);
    for (let $$index = 0, $$length = each_array.length; $$index < $$length; $$index++) {
      let column = each_array[$$index];
      $$renderer2.push(`<th class="px-4 py-3">${escape_html(column.replaceAll("_", " "))}</th>`);
    }
    $$renderer2.push(`<!--]--><th class="w-12 px-4 py-3"></th></tr></thead><tbody class="divide-y divide-line bg-white">`);
    if (filteredRows().length === 0) {
      $$renderer2.push("<!--[0-->");
      $$renderer2.push(`<tr><td class="px-4 py-8 text-center text-sm font-semibold text-muted"${attr("colspan", columns.length + 1)}>${escape_html("Belum ada data")}</td></tr>`);
    } else {
      $$renderer2.push("<!--[-1-->");
      $$renderer2.push(`<!--[-->`);
      const each_array_1 = ensure_array_like(filteredRows());
      for (let $$index_2 = 0, $$length = each_array_1.length; $$index_2 < $$length; $$index_2++) {
        let row = each_array_1[$$index_2];
        $$renderer2.push(`<tr class="hover:bg-soft/70"><!--[-->`);
        const each_array_2 = ensure_array_like(columns);
        for (let $$index_1 = 0, $$length2 = each_array_2.length; $$index_1 < $$length2; $$index_1++) {
          let column = each_array_2[$$index_1];
          $$renderer2.push(`<td class="max-w-[260px] truncate px-4 py-3 text-ink">`);
          if (column === "status") {
            $$renderer2.push("<!--[0-->");
            $$renderer2.push(`<span${attr_class(`rounded-full px-2.5 py-1 text-xs font-bold ${statusClass(row[column])}`)}>${escape_html(rowValue(row, column))}</span>`);
          } else if (moneyColumns.includes(column)) {
            $$renderer2.push("<!--[1-->");
            $$renderer2.push(`<span class="font-semibold tabular">${escape_html(money(row[column]))}</span>`);
          } else {
            $$renderer2.push("<!--[-1-->");
            $$renderer2.push(`${escape_html(rowValue(row, column))}`);
          }
          $$renderer2.push(`<!--]--></td>`);
        }
        $$renderer2.push(`<!--]--><td class="px-4 py-3"><button class="grid size-8 place-items-center rounded-poso border border-line text-muted hover:border-blue hover:text-blue" aria-label="Detail">`);
        Icon($$renderer2, { name: "eye", size: 15 });
        $$renderer2.push(`<!----></button></td></tr>`);
      }
      $$renderer2.push(`<!--]-->`);
    }
    $$renderer2.push(`<!--]--></tbody></table></div> <div class="border-t border-line px-5 py-3 text-xs font-semibold text-muted">Menampilkan ${escape_html(filteredRows().length)} dari ${escape_html(rows.length)} data</div></section>`);
  });
}
const endpoints = {
  dashboard: "/api/v1/dashboard",
  customers: "/api/v1/customers",
  suppliers: "/api/v1/suppliers",
  products: "/api/v1/products",
  "price-lists": "/api/v1/price-lists",
  inventory: "/api/v1/inventory",
  payments: "/api/v1/payments",
  reports: "/api/v1/reports/summary",
  "integrations/akunta": "/api/v1/integrations/akunta/events",
  users: "/api/v1/admin/users",
  roles: "/api/v1/admin/roles",
  settings: "/api/v1/settings",
  "audit-log": "/api/v1/admin/audit-log"
};
function isModuleKey(path) {
  return path in endpoints;
}
function _page($$renderer, $$props) {
  $$renderer.component(($$renderer2) => {
    var $$store_subs;
    const modules = {
      dashboard: {
        title: "Dashboard",
        description: "Ringkasan penjualan, pembelian, dan sinkronisasi Akunta.",
        icon: "grid"
      },
      customers: {
        title: "Manajemen Pelanggan",
        description: "Profil pelanggan, kontak, alamat, termin, dan riwayat transaksi.",
        icon: "users",
        action: "Tambah Pelanggan"
      },
      suppliers: {
        title: "Manajemen Pemasok",
        description: "Profil pemasok, kontak PIC, termin pembelian, dan alamat.",
        icon: "truck",
        action: "Tambah Pemasok"
      },
      products: {
        title: "Produk & Jasa",
        description: "Katalog produk, jasa, SKU, satuan, pajak, dan harga dasar.",
        icon: "package",
        action: "Tambah Produk"
      },
      "price-lists": {
        title: "Daftar Harga",
        description: "Harga jual, harga beli, margin, dan pajak per produk.",
        icon: "tag"
      },
      inventory: {
        title: "Stok & Gudang",
        description: "Stok tersedia, titik pemesanan ulang, dan lokasi gudang.",
        icon: "layers"
      },
      payments: {
        title: "Pembayaran",
        description: "Tagihan masuk dan keluar yang perlu ditindaklanjuti.",
        icon: "wallet"
      },
      reports: {
        title: "Laporan",
        description: "Ringkasan operasional penjualan, pembelian, piutang, dan hutang.",
        icon: "book-open"
      },
      "integrations/akunta": {
        title: "Integrasi Akunta",
        description: "Outbox webhook, status retry, dan template jurnal yang dikirim.",
        icon: "link"
      },
      users: {
        title: "Manajemen User",
        description: "User POSO yang tersambung ke main tier Ecopa.",
        icon: "users"
      },
      roles: {
        title: "Role & Akses",
        description: "Role dan batas akses operasional per fungsi.",
        icon: "shield"
      },
      settings: {
        title: "Setting POSO",
        description: "Penomoran dokumen, pajak, dan mapping template jurnal.",
        icon: "settings"
      },
      "audit-log": {
        title: "Audit Log",
        description: "Riwayat aktivitas penting dan perubahan data operasional.",
        icon: "grid"
      }
    };
    let path = derived(() => store_get($$store_subs ??= {}, "$page", page).params.path ?? "dashboard");
    let moduleKey = derived(() => isModuleKey(path()) ? path() : "dashboard");
    let meta = derived(() => modules[moduleKey()]);
    let rows = [];
    let savingMapping = null;
    let mappingDrafts = {};
    function dashboardCards() {
      return [];
    }
    function dashboardSync() {
      return {};
    }
    function reportData() {
      return {};
    }
    function settingsData() {
      return {};
    }
    function settingsPayload() {
      return {};
    }
    function templateOptions() {
      return settingsPayload().accounting_templates ?? [];
    }
    function selectedTemplate(templateId) {
      return templateOptions().find((template) => template.id === templateId);
    }
    function rowValue(row, key) {
      const value = row[key];
      if (value === null || value === void 0 || value === "") return "-";
      if (typeof value === "number") return String(value);
      if (typeof value === "boolean") return value ? "Aktif" : "Nonaktif";
      return String(value);
    }
    function money(value) {
      return formatRupiah(Number(value ?? 0));
    }
    function formatKpi(card) {
      if (card.format === "money") return money(card.value);
      return new Intl.NumberFormat("id-ID").format(Number(card.value ?? 0));
    }
    function statusClass(status) {
      if (status === "sent" || status === "paid" || status === "Aktif") return "bg-green-soft text-green";
      if (status === "failed") return "bg-red-soft text-red";
      return "bg-amber-soft text-amber";
    }
    $$renderer2.push(`<section class="space-y-5"><header class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between"><div class="flex items-start gap-4"><div class="grid size-12 shrink-0 place-items-center rounded-poso bg-blue-soft text-blue">`);
    Icon($$renderer2, { name: meta().icon, size: 22, stroke: 1.9 });
    $$renderer2.push(`<!----></div> <div><h1 class="text-2xl font-extrabold tracking-tight text-ink">${escape_html(meta().title)}</h1> <p class="mt-1 text-sm text-muted">${escape_html(meta().description)}</p></div></div> <div class="flex flex-wrap gap-3"><button class="h-11 rounded-poso border border-line bg-white px-4 text-sm font-semibold text-muted shadow-sm hover:border-blue hover:text-blue">Refresh</button> `);
    if (meta().action) {
      $$renderer2.push("<!--[0-->");
      $$renderer2.push(`<button class="h-11 rounded-poso bg-blue px-4 text-sm font-semibold text-white shadow-sm hover:bg-blue/90">${escape_html(meta().action)}</button>`);
    } else {
      $$renderer2.push("<!--[-1-->");
    }
    $$renderer2.push(`<!--]--></div></header> `);
    {
      $$renderer2.push("<!--[-1-->");
    }
    $$renderer2.push(`<!--]--> `);
    {
      $$renderer2.push("<!--[-1-->");
    }
    $$renderer2.push(`<!--]--> `);
    {
      $$renderer2.push("<!--[-1-->");
    }
    $$renderer2.push(`<!--]--> `);
    if (moduleKey() === "dashboard") {
      $$renderer2.push("<!--[1-->");
      $$renderer2.push(`<div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4"><!--[-->`);
      const each_array = ensure_array_like(dashboardCards());
      for (let $$index = 0, $$length = each_array.length; $$index < $$length; $$index++) {
        let card = each_array[$$index];
        $$renderer2.push(`<section class="panel rounded-poso p-5"><div class="text-sm font-semibold text-muted">${escape_html(card.label)}</div> <div class="mt-3 text-2xl font-extrabold tabular text-ink">${escape_html(formatKpi(card))}</div></section>`);
      }
      $$renderer2.push(`<!--]--></div> <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_340px]">`);
      ModuleDataTable($$renderer2, {
        title: "Transaksi Terbaru",
        rows,
        columns: ["type", "number", "party", "date", "amount", "status"],
        moneyColumns: ["amount"]
      });
      $$renderer2.push(`<!----> <section class="panel rounded-poso p-5"><h2 class="text-base font-bold text-ink">Status Webhook Akunta</h2> <div class="mt-5 space-y-3"><!--[-->`);
      const each_array_1 = ensure_array_like(Object.entries(dashboardSync()));
      for (let $$index_1 = 0, $$length = each_array_1.length; $$index_1 < $$length; $$index_1++) {
        let [key, value] = each_array_1[$$index_1];
        $$renderer2.push(`<div class="flex items-center justify-between rounded-poso border border-line px-3 py-3"><span class="text-sm font-semibold capitalize text-muted">${escape_html(key)}</span> <span${attr_class(`rounded-full px-2.5 py-1 text-xs font-bold ${statusClass(key)}`)}>${escape_html(value)}</span></div>`);
      }
      $$renderer2.push(`<!--]--></div></section></div>`);
    } else if (moduleKey() === "reports") {
      $$renderer2.push("<!--[2-->");
      $$renderer2.push(`<div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4"><!--[-->`);
      const each_array_2 = ensure_array_like(Object.entries(reportData()));
      for (let $$index_2 = 0, $$length = each_array_2.length; $$index_2 < $$length; $$index_2++) {
        let [key, value] = each_array_2[$$index_2];
        $$renderer2.push(`<section class="panel rounded-poso p-5"><div class="text-sm font-semibold capitalize text-muted">${escape_html(key.replaceAll("_", " "))}</div> <div class="mt-3 text-2xl font-extrabold tabular text-ink">${escape_html(money(value))}</div></section>`);
      }
      $$renderer2.push(`<!--]--></div> <section class="panel rounded-poso p-5"><h2 class="text-base font-bold text-ink">Laporan Siap Ekspor</h2> <div class="mt-4 grid gap-3 md:grid-cols-3"><button class="rounded-poso border border-line bg-white px-4 py-4 text-left text-sm font-bold text-ink hover:border-blue">Rekap Penjualan</button> <button class="rounded-poso border border-line bg-white px-4 py-4 text-left text-sm font-bold text-ink hover:border-blue">Rekap Pembelian</button> <button class="rounded-poso border border-line bg-white px-4 py-4 text-left text-sm font-bold text-ink hover:border-blue">Piutang &amp; Hutang</button></div></section>`);
    } else if (moduleKey() === "settings") {
      $$renderer2.push("<!--[3-->");
      $$renderer2.push(`<section class="panel rounded-poso p-5"><div class="flex flex-col gap-3 border-b border-line pb-5 md:flex-row md:items-start md:justify-between"><div><h2 class="text-base font-bold text-ink">Mapping Template Jurnal</h2> <p class="mt-1 text-sm text-muted">${escape_html(posoContext.activeEntity?.name ?? "Entitas aktif")} · Akunta COA</p></div> <span class="inline-flex w-fit rounded-full bg-blue-soft px-3 py-1 text-xs font-bold text-blue">Default transaksi</span></div> <div class="mt-5 grid gap-4 xl:grid-cols-2"><!--[-->`);
      const each_array_3 = ensure_array_like(settingsPayload().journal_template_mappings ?? []);
      for (let $$index_5 = 0, $$length = each_array_3.length; $$index_5 < $$length; $$index_5++) {
        let mapping = each_array_3[$$index_5];
        $$renderer2.push(`<article class="rounded-poso border border-line bg-white p-4 shadow-sm"><div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between"><div><h3 class="text-sm font-extrabold text-ink">${escape_html(mapping.label)}</h3> <p class="mt-1 text-xs leading-5 text-muted">${escape_html(mapping.description)}</p></div> <span${attr_class(`w-fit rounded-full px-2.5 py-1 text-[10px] font-bold uppercase ${mappingDrafts[mapping.transaction_type]?.is_active ? "bg-green-soft text-green" : "bg-amber-soft text-amber"}`)}>${escape_html(mappingDrafts[mapping.transaction_type]?.is_active ? "Aktif" : "Draft")}</span></div> <!--[-->`);
        const each_array_4 = ensure_array_like([mappingDrafts[mapping.transaction_type]]);
        for (let $$index_4 = 0, $$length2 = each_array_4.length; $$index_4 < $$length2; $$index_4++) {
          let draft = each_array_4[$$index_4];
          if (draft) {
            $$renderer2.push("<!--[0-->");
            $$renderer2.push(`<label class="mt-4 block"><span class="mb-2 block text-sm font-semibold text-ink">Template Akunta</span> `);
            $$renderer2.select({ class: "field", value: draft.journal_template_id }, ($$renderer3) => {
              $$renderer3.option({ value: "" }, ($$renderer4) => {
                $$renderer4.push(`Pilih template jurnal`);
              });
              $$renderer3.push(`<!--[-->`);
              const each_array_5 = ensure_array_like(templateOptions());
              for (let $$index_3 = 0, $$length3 = each_array_5.length; $$index_3 < $$length3; $$index_3++) {
                let template = each_array_5[$$index_3];
                $$renderer3.option({ value: template.id }, ($$renderer4) => {
                  $$renderer4.push(`${escape_html(template.code)} — ${escape_html(template.name)}`);
                });
              }
              $$renderer3.push(`<!--]-->`);
            });
            $$renderer2.push(`</label> `);
            if (selectedTemplate(draft.journal_template_id)) {
              $$renderer2.push("<!--[0-->");
              const chosen = selectedTemplate(draft.journal_template_id);
              $$renderer2.push(`<div class="mt-3 rounded-poso bg-soft px-3 py-2 text-xs font-semibold text-muted">${escape_html(chosen?.code)} · ${escape_html(chosen?.name)}</div>`);
            } else {
              $$renderer2.push("<!--[-1-->");
            }
            $$renderer2.push(`<!--]--> <div class="mt-4 grid gap-3 md:grid-cols-3"><label class="flex items-center gap-2 rounded-poso border border-line px-3 py-2 text-sm font-semibold text-ink"><input class="accent-blue" type="checkbox"${attr("checked", draft.is_required, true)}/> Wajib</label> <label class="flex items-center gap-2 rounded-poso border border-line px-3 py-2 text-sm font-semibold text-ink"><input class="accent-blue" type="checkbox"${attr("checked", draft.auto_queue_webhook, true)}/> Webhook</label> <label class="flex items-center gap-2 rounded-poso border border-line px-3 py-2 text-sm font-semibold text-ink"><input class="accent-blue" type="checkbox"${attr("checked", draft.is_active, true)}/> Aktif</label></div> <div class="mt-4 flex justify-end"><button class="h-10 rounded-poso bg-blue px-4 text-sm font-semibold text-white hover:bg-blue/90 disabled:opacity-60"${attr("disabled", savingMapping === mapping.transaction_type || !draft.journal_template_id, true)}>${escape_html(savingMapping === mapping.transaction_type ? "Menyimpan..." : "Simpan Mapping")}</button></div>`);
          } else {
            $$renderer2.push("<!--[-1-->");
          }
          $$renderer2.push(`<!--]-->`);
        }
        $$renderer2.push(`<!--]--></article>`);
      }
      $$renderer2.push(`<!--]--></div></section> <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_360px]"><section class="panel rounded-poso p-5"><h2 class="text-base font-bold text-ink">Penomoran Dokumen</h2> <div class="mt-4 divide-y divide-line rounded-poso border border-line bg-white"><!--[-->`);
      const each_array_6 = ensure_array_like(settingsData().document_numbering ?? []);
      for (let $$index_6 = 0, $$length = each_array_6.length; $$index_6 < $$length; $$index_6++) {
        let row = each_array_6[$$index_6];
        $$renderer2.push(`<div class="grid gap-3 px-4 py-3 text-sm md:grid-cols-3"><span class="font-bold text-ink">${escape_html(rowValue(row, "type"))}</span> <span class="text-muted">Prefix: ${escape_html(rowValue(row, "prefix"))}</span> <span class="font-semibold tabular text-blue">${escape_html(rowValue(row, "sample"))}</span></div>`);
      }
      $$renderer2.push(`<!--]--></div></section> <section class="panel rounded-poso p-5"><h2 class="text-base font-bold text-ink">Pajak</h2> <div class="mt-4 space-y-3"><!--[-->`);
      const each_array_7 = ensure_array_like(settingsData().taxes ?? []);
      for (let $$index_7 = 0, $$length = each_array_7.length; $$index_7 < $$length; $$index_7++) {
        let tax = each_array_7[$$index_7];
        $$renderer2.push(`<div class="flex items-center justify-between rounded-poso border border-line px-3 py-3"><span class="text-sm font-bold text-ink">${escape_html(rowValue(tax, "name"))}</span> <span class="rounded-full bg-blue-soft px-2.5 py-1 text-xs font-bold text-blue">${escape_html(rowValue(tax, "rate"))}%</span></div>`);
      }
      $$renderer2.push(`<!--]--></div></section></div> `);
      ModuleDataTable($$renderer2, {
        title: "Template Jurnal Akunta",
        rows: settingsData().accounting_templates ?? [],
        columns: ["code", "name", "journal_type", "matches_document_type"]
      });
      $$renderer2.push(`<!---->`);
    } else if (moduleKey() === "customers") {
      $$renderer2.push("<!--[4-->");
      ModuleDataTable($$renderer2, {
        title: "Daftar Pelanggan",
        rows,
        columns: [
          "code",
          "name",
          "email",
          "phone",
          "transactions_count",
          "total"
        ],
        moneyColumns: ["total"]
      });
    } else if (moduleKey() === "suppliers") {
      $$renderer2.push("<!--[5-->");
      ModuleDataTable($$renderer2, {
        title: "Daftar Pemasok",
        rows,
        columns: [
          "code",
          "name",
          "email",
          "phone",
          "transactions_count",
          "total"
        ],
        moneyColumns: ["total"]
      });
    } else if (moduleKey() === "products") {
      $$renderer2.push("<!--[6-->");
      ModuleDataTable($$renderer2, {
        title: "Katalog Produk & Jasa",
        rows,
        columns: [
          "sku",
          "name",
          "type",
          "unit",
          "sales_price",
          "purchase_price",
          "tax_rate",
          "is_active"
        ],
        moneyColumns: ["sales_price", "purchase_price"]
      });
    } else if (moduleKey() === "price-lists") {
      $$renderer2.push("<!--[7-->");
      ModuleDataTable($$renderer2, {
        title: "Daftar Harga",
        rows,
        columns: [
          "sku",
          "name",
          "sales_price",
          "purchase_price",
          "margin",
          "tax_rate"
        ],
        moneyColumns: ["sales_price", "purchase_price", "margin"]
      });
    } else if (moduleKey() === "inventory") {
      $$renderer2.push("<!--[8-->");
      ModuleDataTable($$renderer2, {
        title: "Stok & Gudang",
        rows,
        columns: [
          "sku",
          "name",
          "warehouse",
          "stock_on_hand",
          "reorder_point",
          "unit"
        ]
      });
    } else if (moduleKey() === "payments") {
      $$renderer2.push("<!--[9-->");
      ModuleDataTable($$renderer2, {
        title: "Antrian Pembayaran",
        rows,
        columns: ["type", "number", "party", "due_at", "amount", "status"],
        moneyColumns: ["amount"]
      });
    } else if (moduleKey() === "integrations/akunta") {
      $$renderer2.push("<!--[10-->");
      ModuleDataTable($$renderer2, {
        title: "Outbox Webhook Akunta",
        rows,
        columns: [
          "event_type",
          "template",
          "status",
          "attempts",
          "available_at",
          "sent_at",
          "last_error"
        ]
      });
    } else if (moduleKey() === "users") {
      $$renderer2.push("<!--[11-->");
      ModuleDataTable($$renderer2, {
        title: "User POSO",
        rows,
        columns: ["name", "email", "status", "created_at"]
      });
    } else if (moduleKey() === "roles") {
      $$renderer2.push("<!--[12-->");
      ModuleDataTable($$renderer2, {
        title: "Role & Permission",
        rows,
        columns: ["code", "name", "is_preset", "created_at"]
      });
    } else if (moduleKey() === "audit-log") {
      $$renderer2.push("<!--[13-->");
      ModuleDataTable($$renderer2, {
        title: "Audit Log",
        rows,
        columns: [
          "event",
          "auditable_type",
          "auditable_id",
          "user_id",
          "created_at"
        ]
      });
    } else {
      $$renderer2.push("<!--[-1-->");
    }
    $$renderer2.push(`<!--]--></section>`);
    if ($$store_subs) unsubscribe_stores($$store_subs);
  });
}
export {
  _page as default
};
