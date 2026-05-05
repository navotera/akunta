import { c as attr_class, e as escape_html, a as ensure_array_like, b as attr, f as derived } from "./renderer.js";
import { I as Icon } from "./Icon.js";
import "clsx";
import { a as statusLabel, f as formatRupiah } from "./fixtures.js";
function KpiCard($$renderer, $$props) {
  let { label, value, caption, tone = "blue", icon = "document" } = $$props;
  const tones = {
    blue: "bg-blue-soft text-blue",
    green: "bg-green-soft text-green",
    amber: "bg-amber-soft text-amber",
    violet: "bg-violet-soft text-violet"
  };
  $$renderer.push(`<article class="panel rounded-poso p-5 shadow-sm"><div class="flex items-center gap-4"><div${attr_class(`grid size-12 shrink-0 place-items-center rounded-full ${tones[tone]}`)}>`);
  Icon($$renderer, { name: icon, size: 22, stroke: 1.9 });
  $$renderer.push(`<!----></div> <div class="min-w-0"><p class="truncate text-[13px] font-medium text-muted">${escape_html(label)}</p> <p class="mt-1 truncate text-xl font-extrabold tracking-tight text-ink">${escape_html(value)}</p> <p class="mt-1 truncate text-[13px] text-muted">${escape_html(caption)}</p></div></div></article>`);
}
function QuickIllustration($$renderer, $$props) {
  let { mode = "sales" } = $$props;
  $$renderer.push(`<div class="relative h-40 w-60 overflow-hidden rounded-poso bg-transparent xl:w-72" aria-hidden="true"><div class="absolute bottom-0 right-0 h-24 w-56 rounded-t-[32px] bg-blue-soft/70"></div> <div class="absolute bottom-5 right-16 h-28 w-32 rounded-[12px] border border-blue/10 bg-white shadow-poso"><div class="h-6 rounded-t-[12px] bg-[#eef3ff]"></div> <div class="mx-5 mt-5 h-2 rounded-full bg-[#d8e1f2]"></div> <div class="mx-5 mt-3 h-2 rounded-full bg-[#e4eaf5]"></div> <div class="mx-5 mt-3 h-2 rounded-full bg-[#e4eaf5]"></div> <div class="mx-5 mt-3 h-2 w-16 rounded-full bg-[#e4eaf5]"></div></div> <div class="absolute bottom-[88px] right-[164px] grid size-8 place-items-center rounded-full bg-green text-white shadow-sm"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 4 4L19 6"></path></svg></div> <div class="absolute bottom-0 right-7 h-20 w-9 rounded-t-full bg-[#7fcd92]/70"></div> <div class="absolute bottom-0 right-2 h-28 w-3 rounded-full bg-[#34a853]/70"></div> <div class="absolute bottom-14 right-4 h-10 w-5 -rotate-45 rounded-full bg-[#7fcd92]/80"></div> <div class="absolute bottom-9 right-0 h-10 w-5 rotate-45 rounded-full bg-[#7fcd92]/80"></div> <div class="absolute bottom-0 right-[108px] h-2 w-28 rounded-full bg-[#c7d4ea]"></div> <div class="absolute bottom-3 right-[132px] h-5 w-20 rounded-b-[10px] bg-[#d8e1f2]"></div> <div class="absolute bottom-[104px] right-[58px] rounded-full bg-white/80 px-2 py-1 text-[10px] font-bold text-blue shadow-sm">${escape_html(mode === "sales" ? "Invoice" : "Bill")}</div></div>`);
}
function StatusPill($$renderer, $$props) {
  $$renderer.component(($$renderer2) => {
    let { status } = $$props;
    const classes = {
      paid: "bg-green-soft text-green",
      partial: "bg-amber-soft text-amber",
      unpaid: "bg-red-soft text-red"
    };
    $$renderer2.push(`<span${attr_class(`inline-flex min-w-24 justify-center rounded-md px-2.5 py-1 text-xs font-semibold ${classes[status]}`)}>${escape_html(statusLabel(status))}</span>`);
  });
}
function TransactionTable($$renderer, $$props) {
  $$renderer.component(($$renderer2) => {
    let { rows, partyLabel, numberLabel } = $$props;
    $$renderer2.push(`<div class="overflow-hidden rounded-poso border border-line bg-white"><table class="w-full min-w-[860px] border-collapse bg-white text-sm"><thead class="bg-[#f8fafc] text-left text-[12px] font-bold uppercase tracking-[0.02em] text-muted"><tr><th class="w-12 px-4 py-3"><input type="checkbox" class="size-4 rounded border-line" aria-label="Pilih semua"/></th><th class="px-4 py-3">${escape_html(numberLabel)}</th><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">${escape_html(partyLabel)}</th><th class="px-4 py-3">Jatuh Tempo</th><th class="px-4 py-3 text-right">Total</th><th class="px-4 py-3 text-center">Status</th><th class="px-4 py-3 text-right">Aksi</th></tr></thead><tbody class="divide-y divide-line"><!--[-->`);
    const each_array = ensure_array_like(rows);
    for (let $$index = 0, $$length = each_array.length; $$index < $$length; $$index++) {
      let row = each_array[$$index];
      $$renderer2.push(`<tr class="h-[55px] hover:bg-soft/70"><td class="px-4 py-3"><input type="checkbox" class="size-4 rounded border-line accent-blue"${attr("aria-label", `Pilih ${row.number}`)}/></td><td class="px-4 py-3 font-bold text-ink">${escape_html(row.number)}</td><td class="px-4 py-3 text-muted">${escape_html(row.date)}</td><td class="px-4 py-3 text-ink">${escape_html(row.party)}</td><td class="px-4 py-3 text-muted">${escape_html(row.due)}</td><td class="px-4 py-3 text-right tabular font-bold text-ink">${escape_html(formatRupiah(row.total))}</td><td class="px-4 py-3 text-center">`);
      StatusPill($$renderer2, { status: row.status });
      $$renderer2.push(`<!----></td><td class="px-4 py-3"><div class="flex justify-end gap-2"><button class="grid size-8 place-items-center rounded-poso border border-line text-muted hover:border-blue hover:text-blue"${attr("aria-label", `Lihat ${row.number}`)}>`);
      Icon($$renderer2, { name: "eye", size: 15, stroke: 2 });
      $$renderer2.push(`<!----></button> <button class="grid size-8 place-items-center rounded-poso border border-line text-muted hover:border-blue hover:text-blue"${attr("aria-label", `Menu ${row.number}`)}>`);
      Icon($$renderer2, { name: "more-vertical", size: 15, stroke: 2.2 });
      $$renderer2.push(`<!----></button></div></td></tr>`);
    }
    $$renderer2.push(`<!--]--></tbody></table></div>`);
  });
}
function TransactionListPage($$renderer, $$props) {
  $$renderer.component(($$renderer2) => {
    let { mode, rows } = $$props;
    let isSales = derived(() => mode === "sales");
    let title = derived(() => isSales() ? "Penjualan" : "Pembelian");
    let subtitle = derived(() => isSales() ? "Kelola transaksi penjualan dan tagihan pelanggan." : "Kelola transaksi pembelian dan tagihan pemasok.");
    let newHref = derived(() => isSales() ? "/sales/new" : "/purchases/new");
    let numberLabel = derived(() => isSales() ? "No. Invoice" : "No. Tagihan");
    let partyLabel = derived(() => isSales() ? "Pelanggan" : "Pemasok");
    let quickItems = derived(() => isSales() ? [
      ["Invoice", "Tagihan untuk pelanggan"],
      ["Penawaran", "Buat penawaran harga"],
      ["Pesanan Penjualan", "Buat pesanan dari pelanggan"],
      ["Pembayaran Diterima", "Catat pembayaran dari pelanggan"],
      ["Retur Penjualan", "Catat retur dari pelanggan"]
    ] : [
      ["Tagihan", "Tagihan dari pemasok"],
      ["Pesanan Pembelian", "Buat pesanan ke pemasok"],
      ["Pembayaran Keluar", "Catat pembayaran ke pemasok"],
      ["Retur Pembelian", "Catat retur ke pemasok"],
      ["Penerimaan Barang", "Catat barang diterima"]
    ]);
    let tabs = derived(() => isSales() ? [
      "Semua",
      "Invoice",
      "Penawaran",
      "Pesanan Penjualan",
      "Pembayaran Diterima",
      "Retur Penjualan"
    ] : [
      "Semua",
      "Tagihan",
      "Pesanan Pembelian",
      "Pembayaran Keluar",
      "Retur Pembelian"
    ]);
    let total = derived(() => rows.reduce((sum, row) => sum + row.total, 0));
    let paid = derived(() => rows.filter((row) => row.status === "paid").reduce((sum, row) => sum + row.total, 0));
    let unpaid = derived(() => rows.filter((row) => row.status === "unpaid").reduce((sum, row) => sum + row.total, 0));
    $$renderer2.push(`<section class="space-y-5"><header class="flex flex-col gap-4 md:flex-row md:items-end md:justify-between"><div><h1 class="text-2xl font-bold text-ink">${escape_html(title())}</h1> <p class="mt-1 text-sm text-muted">${escape_html(subtitle())}</p></div> <div class="flex flex-wrap gap-3"><button class="inline-flex h-11 items-center gap-2 rounded-poso border border-line bg-white px-4 text-sm font-semibold text-blue shadow-sm hover:border-blue">`);
    Icon($$renderer2, { name: "upload", size: 17, stroke: 2.1 });
    $$renderer2.push(`<!----> Impor</button> <a class="inline-flex h-11 items-center gap-2 rounded-poso bg-blue px-4 text-sm font-semibold text-white shadow-sm hover:bg-blue/90"${attr("href", newHref())}>`);
    Icon($$renderer2, { name: "plus", size: 17, stroke: 2.2 });
    $$renderer2.push(`<!----> Buat ${escape_html(title())}</a></div></header> <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">`);
    KpiCard($$renderer2, {
      label: `Total ${title()} (Mei 2024)`,
      value: formatRupiah(total()),
      caption: "↑ 12% dari Apr 2024",
      icon: "document"
    });
    $$renderer2.push(`<!----> `);
    KpiCard($$renderer2, {
      label: isSales() ? "Terbayar" : "Sudah Dibayar",
      value: formatRupiah(paid()),
      caption: "78.8% dari total",
      tone: "green",
      icon: isSales() ? "cart" : "wallet"
    });
    $$renderer2.push(`<!----> `);
    KpiCard($$renderer2, {
      label: isSales() ? "Belum Terbayar" : "Belum Dibayar",
      value: formatRupiah(unpaid()),
      caption: "21.2% dari total",
      tone: "amber",
      icon: "clock"
    });
    $$renderer2.push(`<!----> `);
    KpiCard($$renderer2, {
      label: `Total ${isSales() ? "Invoice" : "Tagihan"}`,
      value: "32",
      caption: "8 draft",
      tone: "violet",
      icon: "file-text"
    });
    $$renderer2.push(`<!----></div> <section class="panel rounded-poso"><div class="flex gap-2 overflow-x-auto border-b border-line px-5"><!--[-->`);
    const each_array = ensure_array_like(tabs());
    for (let index = 0, $$length = each_array.length; index < $$length; index++) {
      let tab = each_array[index];
      $$renderer2.push(`<button${attr_class(`h-14 whitespace-nowrap border-b-2 px-1 text-sm font-semibold ${index === 1 ? "border-blue text-blue" : "border-transparent text-muted hover:text-ink"}`)}>${escape_html(tab)}</button>`);
    }
    $$renderer2.push(`<!--]--></div> <div class="flex flex-col gap-3 p-5 lg:flex-row lg:items-center lg:justify-between"><div class="grid gap-3 md:grid-cols-[minmax(240px,1fr)_170px_170px]"><label class="relative"><span class="sr-only">Cari</span> <input class="field pr-10"${attr("placeholder", `Cari nomor ${isSales() ? "invoice" : "tagihan"} / ${partyLabel().toLowerCase()}...`)}/> <span class="absolute right-3 top-1/2 -translate-y-1/2 text-muted">`);
    Icon($$renderer2, { name: "search", size: 16, stroke: 2 });
    $$renderer2.push(`<!----></span></label> <button class="field flex items-center justify-between text-left text-muted">Tanggal: Semua `);
    Icon($$renderer2, { name: "calendar", size: 16, stroke: 1.9 });
    $$renderer2.push(`<!----></button> <button class="field flex items-center justify-between text-left text-muted">Status: Semua `);
    Icon($$renderer2, { name: "chevron-down", size: 16, stroke: 2 });
    $$renderer2.push(`<!----></button></div> <div class="flex gap-3"><button class="inline-flex h-11 items-center gap-2 rounded-poso border border-line bg-white px-4 text-sm font-semibold text-muted hover:border-blue hover:text-blue">`);
    Icon($$renderer2, { name: "filter", size: 16, stroke: 2 });
    $$renderer2.push(`<!----> Filter</button> <button class="grid size-11 place-items-center rounded-poso border border-line bg-white text-muted hover:border-blue hover:text-blue" aria-label="Pengaturan tabel">`);
    Icon($$renderer2, { name: "gear", size: 16, stroke: 2 });
    $$renderer2.push(`<!----></button></div></div> <div class="px-5 pb-4">`);
    TransactionTable($$renderer2, { rows, partyLabel: partyLabel(), numberLabel: numberLabel() });
    $$renderer2.push(`<!----></div> <div class="flex flex-col gap-3 border-t border-line px-5 py-4 text-sm text-muted md:flex-row md:items-center md:justify-between"><p>Menampilkan 1 - 5 dari 32 data</p> <div class="flex items-center gap-2"><button class="grid size-8 place-items-center rounded-poso border border-line">`);
    Icon($$renderer2, { name: "chevron-left", size: 15 });
    $$renderer2.push(`<!----></button> <button class="grid size-8 place-items-center rounded-poso bg-blue font-semibold text-white">1</button> <button class="grid size-8 place-items-center rounded-poso border border-line">2</button> <button class="grid size-8 place-items-center rounded-poso border border-line">3</button> <button class="grid size-8 place-items-center rounded-poso border border-line">`);
    Icon($$renderer2, { name: "chevron-right", size: 15 });
    $$renderer2.push(`<!----></button></div></div></section> <section class="panel overflow-hidden rounded-poso"><div class="grid gap-6 p-6 md:grid-cols-[minmax(0,1fr)_220px] xl:grid-cols-[1fr_260px]"><div><h2 class="text-base font-bold text-ink">Buat transaksi ${escape_html(title().toLowerCase())} dengan cepat</h2> <p class="mt-1 text-sm text-muted">Pilih jenis transaksi yang ingin Anda buat.</p> <div class="mt-8 grid gap-5 md:grid-cols-3 xl:grid-cols-5"><!--[-->`);
    const each_array_1 = ensure_array_like(quickItems());
    for (let $$index_1 = 0, $$length = each_array_1.length; $$index_1 < $$length; $$index_1++) {
      let item = each_array_1[$$index_1];
      $$renderer2.push(`<a class="group flex items-start gap-3"${attr("href", newHref())}><span class="grid size-10 shrink-0 place-items-center rounded-poso bg-blue-soft text-blue group-hover:bg-blue group-hover:text-white">`);
      Icon($$renderer2, { name: "document", size: 18, stroke: 1.9 });
      $$renderer2.push(`<!----></span> <span><span class="block text-sm font-bold text-ink">${escape_html(item[0])}</span> <span class="mt-1 block text-sm text-muted">${escape_html(item[1])}</span></span></a>`);
    }
    $$renderer2.push(`<!--]--></div></div> <div class="hidden items-end justify-end md:flex">`);
    QuickIllustration($$renderer2, { mode });
    $$renderer2.push(`<!----></div></div></section></section>`);
  });
}
export {
  TransactionListPage as T
};
