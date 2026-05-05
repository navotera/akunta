import { b as attr, e as escape_html, a as ensure_array_like, c as attr_class, f as derived } from "./renderer.js";
import { f as formatRupiah } from "./fixtures.js";
import { p as posoContext } from "./context.svelte.js";
function TransactionForm($$renderer, $$props) {
  $$renderer.component(($$renderer2) => {
    let { mode } = $$props;
    let isSales = derived(() => mode === "sales");
    let title = derived(() => isSales() ? "Buat Penjualan" : "Buat Pembelian");
    let root = derived(() => isSales() ? "/sales" : "/purchases");
    let partyLabel = derived(() => isSales() ? "Pelanggan" : "Pemasok");
    let contactPlaceholder = derived(() => isSales() ? "Pilih kontak (opsional)" : "Pilih PIC pemasok (opsional)");
    let numberLabel = derived(() => isSales() ? "No. Invoice" : "No. Tagihan");
    let primaryAction = derived(() => isSales() ? "Simpan & Terbitkan" : "Simpan & Kirim");
    function initialNumber() {
      return mode === "sales" ? "INV/2024/05/0010" : "BILL/2024/05/0013";
    }
    let rows = [
      {
        id: 1,
        product: "",
        description: "",
        quantity: 1,
        unit: "Pcs",
        price: 0,
        discount: 0,
        tax: 11
      },
      {
        id: 2,
        product: "",
        description: "",
        quantity: 1,
        unit: "Pcs",
        price: 0,
        discount: 0,
        tax: 11
      }
    ];
    let party = "";
    let contact = "";
    let address = "";
    let number = initialNumber();
    let issuedAt = "2024-05-23";
    let dueAt = "2024-06-06";
    let terms = "";
    let paymentTerms = "Net 14";
    let paymentMethod = "Transfer Bank";
    let journalTemplates = [];
    let selectedTemplateId = "";
    let saving = false;
    let subtotal = derived(() => rows.reduce((sum, row) => sum + row.quantity * row.price, 0));
    let discountTotal = derived(() => rows.reduce((sum, row) => sum + row.quantity * row.price * (row.discount / 100), 0));
    let dpp = derived(() => subtotal() - discountTotal());
    let taxTotal = derived(() => rows.reduce(
      (sum, row) => {
        const base = row.quantity * row.price * (1 - row.discount / 100);
        return sum + base * (row.tax / 100);
      },
      0
    ));
    let grandTotal = derived(() => dpp() + taxTotal());
    let selectedTemplate = derived(() => journalTemplates.find((template) => template.id === selectedTemplateId) ?? null);
    let canPublish = derived(() => Boolean(selectedTemplateId) && !saving);
    $$renderer2.push(`<section class="space-y-5"><header class="flex flex-col gap-4 border-b border-line pb-5 lg:flex-row lg:items-end lg:justify-between"><div class="flex items-start gap-4"><a class="mt-8 grid size-9 place-items-center rounded-poso border border-line bg-white text-muted hover:border-blue hover:text-blue"${attr("href", root())} aria-label="Kembali">‹</a> <div><div class="mb-2 flex items-center gap-2 text-sm text-muted"><a${attr("href", root())} class="text-blue">${escape_html(isSales() ? "Penjualan" : "Pembelian")}</a> <span>›</span> <span>${escape_html(title())}</span></div> <h1 class="text-2xl font-bold text-ink">${escape_html(title())}</h1> <p class="mt-2 text-sm text-muted">${escape_html(isSales() ? "Buat invoice penjualan untuk pelanggan." : "Catat tagihan pembelian dari pemasok.")}</p></div></div> <div class="flex gap-3"><button class="h-11 rounded-poso border border-line bg-white px-5 text-sm font-semibold text-blue shadow-sm hover:border-blue disabled:opacity-50"${attr("disabled", saving, true)}>${escape_html("Simpan Draft")}</button> <button class="h-11 rounded-poso bg-blue px-5 text-sm font-semibold text-white shadow-sm hover:bg-blue/90 disabled:bg-muted disabled:shadow-none"${attr("disabled", !canPublish(), true)}>${escape_html(primaryAction())}</button></div></header> `);
    {
      $$renderer2.push("<!--[-1-->");
    }
    $$renderer2.push(`<!--]--> `);
    {
      $$renderer2.push("<!--[-1-->");
    }
    $$renderer2.push(`<!--]--> <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_360px]"><div class="space-y-5"><section class="panel rounded-poso p-5"><h2 class="mb-5 text-base font-bold text-ink">Informasi ${escape_html(partyLabel())}</h2> <div class="grid gap-5 lg:grid-cols-2"><div class="space-y-4"><label class="block"><span class="mb-2 block text-sm font-semibold text-ink">${escape_html(partyLabel())} <span class="text-red">*</span></span> <div class="flex gap-3"><input class="field"${attr("value", party)}${attr("placeholder", `Pilih ${partyLabel().toLowerCase()}...`)}/> <button class="grid size-11 shrink-0 place-items-center rounded-poso border border-line bg-white text-xl text-blue hover:border-blue"${attr("aria-label", `Tambah ${partyLabel().toLowerCase()} baru`)}>+</button></div></label> <button class="text-sm font-semibold text-blue">+ Tambah ${escape_html(partyLabel().toLowerCase())} baru</button> <label class="block"><span class="mb-2 block text-sm font-semibold text-ink">Alamat</span> <textarea class="field min-h-28 resize-y"${attr("placeholder", `Alamat akan terisi otomatis berdasarkan ${partyLabel().toLowerCase()}`)}>`);
    const $$body = escape_html(address);
    if ($$body) {
      $$renderer2.push(`${$$body}`);
    }
    $$renderer2.push(`</textarea></label></div> <div class="space-y-4"><label class="block"><span class="mb-2 block text-sm font-semibold text-ink">Kontak</span> <input class="field"${attr("value", contact)}${attr("placeholder", contactPlaceholder())}/></label> <label class="block"><span class="mb-2 block text-sm font-semibold text-ink">${escape_html(numberLabel())} <span class="text-red">*</span></span> <input class="field"${attr("value", number)}/></label> <div class="grid gap-4 md:grid-cols-2"><label class="block"><span class="mb-2 block text-sm font-semibold text-ink">Tanggal <span class="text-red">*</span></span> <input class="field" type="date"${attr("value", issuedAt)}/></label> <label class="block"><span class="mb-2 block text-sm font-semibold text-ink">Jatuh Tempo <span class="text-red">*</span></span> <input class="field" type="date"${attr("value", dueAt)}/></label></div></div></div></section> <section class="panel rounded-poso p-5"><div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><h2 class="text-base font-bold text-ink">Detail Item</h2> <div class="flex gap-3"><button class="h-10 rounded-poso border border-line bg-white px-4 text-sm font-semibold text-blue hover:border-blue">+ Pilih Produk</button> <button class="h-10 rounded-poso border border-line bg-white px-4 text-sm font-semibold text-blue hover:border-blue">+ Tambah Baris</button></div></div> <div class="overflow-x-auto rounded-poso border border-line"><table class="w-full min-w-[900px] text-sm"><thead class="bg-soft text-left text-xs font-semibold uppercase text-muted"><tr><th class="w-12 px-3 py-3">#</th><th class="px-3 py-3">Produk / Jasa <span class="text-red">*</span></th><th class="px-3 py-3">Deskripsi</th><th class="w-24 px-3 py-3">Kuantitas</th><th class="w-24 px-3 py-3">Satuan</th><th class="w-36 px-3 py-3">Harga Satuan</th><th class="w-24 px-3 py-3">Diskon</th><th class="w-28 px-3 py-3">Pajak</th><th class="w-32 px-3 py-3 text-right">Total</th><th class="w-12 px-3 py-3"></th></tr></thead><tbody class="divide-y divide-line bg-white"><!--[-->`);
    const each_array = ensure_array_like(rows);
    for (let index = 0, $$length = each_array.length; index < $$length; index++) {
      let row = each_array[index];
      $$renderer2.push(`<tr><td class="px-3 py-3 text-muted">${escape_html(index + 1)}</td><td class="px-3 py-3"><input class="field h-10"${attr("value", row.product)} placeholder="Pilih produk / jasa..."/></td><td class="px-3 py-3"><input class="field h-10"${attr("value", row.description)} placeholder="Deskripsi (opsional)"/></td><td class="px-3 py-3"><input class="field h-10" type="number" min="0" step="0.01"${attr("value", row.quantity)}/></td><td class="px-3 py-3">`);
      $$renderer2.select({ class: "field h-10 py-0", value: row.unit }, ($$renderer3) => {
        $$renderer3.option({}, ($$renderer4) => {
          $$renderer4.push(`Pcs`);
        });
        $$renderer3.option({}, ($$renderer4) => {
          $$renderer4.push(`Box`);
        });
        $$renderer3.option({}, ($$renderer4) => {
          $$renderer4.push(`Kg`);
        });
        $$renderer3.option({}, ($$renderer4) => {
          $$renderer4.push(`Jam`);
        });
      });
      $$renderer2.push(`</td><td class="px-3 py-3"><input class="field h-10 text-right tabular" type="number" min="0"${attr("value", row.price)}/></td><td class="px-3 py-3"><input class="field h-10 text-right tabular" type="number" min="0" max="100"${attr("value", row.discount)}/></td><td class="px-3 py-3">`);
      $$renderer2.select({ class: "field h-10 py-0", value: row.tax }, ($$renderer3) => {
        $$renderer3.option({ value: 0 }, ($$renderer4) => {
          $$renderer4.push(`Non PPN`);
        });
        $$renderer3.option({ value: 11 }, ($$renderer4) => {
          $$renderer4.push(`PPN 11%`);
        });
        $$renderer3.option({ value: 12 }, ($$renderer4) => {
          $$renderer4.push(`PPN 12%`);
        });
      });
      $$renderer2.push(`</td><td class="px-3 py-3 text-right font-semibold tabular text-ink">${escape_html(formatRupiah(row.quantity * row.price * (1 - row.discount / 100) * (1 + row.tax / 100)))}</td><td class="px-3 py-3"><button class="grid size-9 place-items-center rounded-poso border border-line text-red hover:bg-red-soft" aria-label="Hapus baris">×</button></td></tr>`);
    }
    $$renderer2.push(`<!--]--></tbody></table></div> <div class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"><button class="h-10 rounded-poso border border-line bg-white px-4 text-sm font-semibold text-blue hover:border-blue">▣ Catatan / Memo</button> <button class="h-10 rounded-poso px-4 text-sm font-semibold text-red hover:bg-red-soft">× Hapus semua baris</button></div></section> <section class="panel rounded-poso p-5"><label class="block"><span class="mb-2 block text-sm font-semibold text-ink">Syarat &amp; Ketentuan <span class="font-normal text-muted">(opsional)</span></span> <textarea class="field min-h-24 resize-y" placeholder="Contoh: Pembayaran harus dilakukan sebelum jatuh tempo.">`);
    const $$body_1 = escape_html(terms);
    if ($$body_1) {
      $$renderer2.push(`${$$body_1}`);
    }
    $$renderer2.push(`</textarea></label></section> <p class="text-sm font-semibold text-blue">ⓘ Pastikan data sudah benar sebelum menyimpan dan menerbitkan dokumen.</p></div> <aside class="space-y-5 xl:sticky xl:top-24 xl:self-start"><section class="panel rounded-poso p-5"><h2 class="mb-5 text-base font-bold text-ink">Ringkasan</h2> <div class="space-y-4 text-sm"><div class="flex justify-between gap-4 text-muted"><span>Subtotal</span><strong class="tabular text-ink">${escape_html(formatRupiah(subtotal()))}</strong></div> <div class="flex justify-between gap-4 text-muted"><span>Diskon</span><strong class="tabular text-ink">${escape_html(formatRupiah(discountTotal()))}</strong></div> <div class="flex justify-between gap-4 text-muted"><span>DPP</span><strong class="tabular text-ink">${escape_html(formatRupiah(dpp()))}</strong></div> <div class="flex justify-between gap-4 text-muted"><span>PPN</span><strong class="tabular text-ink">${escape_html(formatRupiah(taxTotal()))}</strong></div></div> <div class="my-5 border-t border-line"></div> <div class="flex items-center justify-between gap-4"><span class="font-bold text-ink">Total</span> <strong class="text-2xl font-bold tabular text-ink">${escape_html(formatRupiah(grandTotal()))}</strong></div> <div class="mt-5 rounded-poso bg-blue-soft p-4 text-sm font-medium text-blue">Total akan dihitung otomatis berdasarkan item, diskon, dan pajak.</div></section> <section class="panel rounded-poso p-5"><div class="mb-4 flex items-start justify-between gap-3"><div><h2 class="text-base font-bold text-ink">Jurnal Akunta</h2> <p class="mt-1 text-xs font-semibold text-muted">${escape_html(posoContext.activeEntity?.name ?? "Entitas belum dipilih")}</p></div> <span class="rounded-full bg-blue-soft px-2.5 py-1 text-[10px] font-bold uppercase text-blue">Webhook</span></div> <label class="block"><span class="mb-2 block text-sm font-semibold text-ink">Template Jurnal <span class="text-red">*</span></span> `);
    $$renderer2.select(
      {
        class: "field",
        value: selectedTemplateId,
        disabled: journalTemplates.length === 0
      },
      ($$renderer3) => {
        if (journalTemplates.length === 0) {
          $$renderer3.push("<!--[1-->");
          $$renderer3.option({ value: "" }, ($$renderer4) => {
            $$renderer4.push(`Belum ada template Akunta`);
          });
        } else {
          $$renderer3.push("<!--[-1-->");
          $$renderer3.push(`<!--[-->`);
          const each_array_1 = ensure_array_like(journalTemplates);
          for (let $$index_1 = 0, $$length = each_array_1.length; $$index_1 < $$length; $$index_1++) {
            let template = each_array_1[$$index_1];
            $$renderer3.option({ value: template.id }, ($$renderer4) => {
              $$renderer4.push(`${escape_html(template.code)} — ${escape_html(template.name)}`);
            });
          }
          $$renderer3.push(`<!--]-->`);
        }
        $$renderer3.push(`<!--]-->`);
      }
    );
    $$renderer2.push(`</label> `);
    {
      $$renderer2.push("<!--[-1-->");
    }
    $$renderer2.push(`<!--]--> `);
    if (selectedTemplate()) {
      $$renderer2.push("<!--[0-->");
      $$renderer2.push(`<div class="mt-4 overflow-hidden rounded-poso border border-line bg-white"><!--[-->`);
      const each_array_2 = ensure_array_like(selectedTemplate().lines);
      for (let $$index_2 = 0, $$length = each_array_2.length; $$index_2 < $$length; $$index_2++) {
        let line = each_array_2[$$index_2];
        $$renderer2.push(`<div class="flex items-start gap-3 border-b border-line px-3 py-3 last:border-b-0"><span${attr_class(`mt-0.5 rounded-full px-2 py-0.5 text-[10px] font-bold uppercase ${line.side === "debit" ? "bg-green-soft text-green" : "bg-amber-soft text-amber"}`)}>${escape_html(line.side === "debit" ? "Debit" : "Kredit")}</span> <div class="min-w-0 flex-1"><div class="truncate text-sm font-bold text-ink">${escape_html(line.account.code)} · ${escape_html(line.account.name)}</div> <div class="mt-0.5 text-xs text-muted">${escape_html(line.memo ?? line.account.type)}</div></div></div>`);
      }
      $$renderer2.push(`<!--]--></div>`);
    } else {
      $$renderer2.push("<!--[-1-->");
    }
    $$renderer2.push(`<!--]--></section> <section class="panel rounded-poso p-5"><h2 class="mb-4 text-base font-bold text-ink">Pembayaran</h2> <label class="mb-4 block"><span class="mb-2 block text-sm font-semibold text-ink">Syarat Pembayaran</span> `);
    $$renderer2.select({ class: "field", value: paymentTerms }, ($$renderer3) => {
      $$renderer3.option({}, ($$renderer4) => {
        $$renderer4.push(`Net 14`);
      });
      $$renderer3.option({}, ($$renderer4) => {
        $$renderer4.push(`Net 30`);
      });
      $$renderer3.option({}, ($$renderer4) => {
        $$renderer4.push(`COD`);
      });
    });
    $$renderer2.push(`</label> <label class="block"><span class="mb-2 block text-sm font-semibold text-ink">Metode Pembayaran</span> `);
    $$renderer2.select({ class: "field", value: paymentMethod }, ($$renderer3) => {
      $$renderer3.option({}, ($$renderer4) => {
        $$renderer4.push(`Transfer Bank`);
      });
      $$renderer3.option({}, ($$renderer4) => {
        $$renderer4.push(`Tunai`);
      });
      $$renderer3.option({}, ($$renderer4) => {
        $$renderer4.push(`Kartu`);
      });
    });
    $$renderer2.push(`</label></section> <section class="panel rounded-poso p-5"><h2 class="mb-4 text-base font-bold text-ink">Lampiran <span class="font-normal text-muted">(opsional)</span></h2> <button class="flex min-h-24 w-full flex-col items-center justify-center rounded-poso border border-dashed border-line bg-soft text-sm font-semibold text-muted hover:border-blue hover:text-blue"><span class="text-xl">⇧</span> Klik atau seret file ke sini <span class="mt-1 text-xs font-normal">Maks. 5MB per file</span></button> <p class="mt-4 text-center text-sm text-muted">Belum ada lampiran</p></section></aside></div></section>`);
  });
}
export {
  TransactionForm as T
};
