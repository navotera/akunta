import { a as ensure_array_like, e as escape_html, b as attr, c as attr_class, d as store_get, u as unsubscribe_stores, f as derived } from "../../../chunks/renderer.js";
import { p as page } from "../../../chunks/stores.js";
import { I as Icon } from "../../../chunks/Icon.js";
import { p as posoContext } from "../../../chunks/context.svelte.js";
function Sidebar($$renderer, $$props) {
  $$renderer.component(($$renderer2) => {
    let { currentPath = "/sales" } = $$props;
    const sections = [
      {
        label: "Utama",
        items: [
          { href: "/dashboard", label: "Dashboard", icon: "home" },
          { href: "/sales", label: "Penjualan", icon: "receipt" },
          { href: "/purchases", label: "Pembelian", icon: "cart" }
        ]
      },
      {
        label: "Master Data",
        items: [
          { href: "/customers", label: "Pelanggan", icon: "users" },
          { href: "/suppliers", label: "Pemasok", icon: "truck" },
          { href: "/products", label: "Produk & Jasa", icon: "package" },
          { href: "/price-lists", label: "Daftar Harga", icon: "tag" },
          { href: "/inventory", label: "Stok & Gudang", icon: "layers" }
        ]
      },
      {
        label: "Keuangan",
        items: [
          { href: "/payments", label: "Pembayaran", icon: "wallet" },
          { href: "/reports", label: "Laporan", icon: "book-open" },
          {
            href: "/integrations/akunta",
            label: "Integrasi Akunta",
            icon: "link",
            badge: "Sync"
          }
        ]
      },
      {
        label: "Administrasi",
        items: [
          { href: "/users", label: "Manajemen User", icon: "users" },
          { href: "/roles", label: "Role & Akses", icon: "shield" },
          { href: "/settings", label: "Setting POSO", icon: "settings" },
          { href: "/audit-log", label: "Audit Log", icon: "grid" }
        ]
      }
    ];
    function isActive(href) {
      if (href === "/dashboard") return currentPath === href;
      return currentPath === href || currentPath.startsWith(`${href}/`);
    }
    $$renderer2.push(`<aside class="hidden min-h-screen w-[264px] shrink-0 border-r border-line bg-white md:block"><div class="sticky top-0 flex h-screen flex-col"><div class="flex h-16 items-center gap-3 border-b border-line px-5"><a href="/sales" class="flex items-center gap-3"><span class="grid size-9 place-items-center rounded-poso bg-blue text-sm font-black text-white shadow-sm">P</span> <span><span class="block text-base font-extrabold tracking-tight text-ink">POSO</span> <span class="block text-[11px] font-semibold uppercase tracking-[0.12em] text-muted">Sales &amp; Purchase</span></span></a></div> <nav class="flex-1 overflow-y-auto px-3 py-4"><!--[-->`);
    const each_array = ensure_array_like(sections);
    for (let $$index_1 = 0, $$length = each_array.length; $$index_1 < $$length; $$index_1++) {
      let section = each_array[$$index_1];
      $$renderer2.push(`<div class="mb-5"><div class="px-3 pb-2 text-[11px] font-bold uppercase tracking-[0.12em] text-muted/80">${escape_html(section.label)}</div> <div class="space-y-1"><!--[-->`);
      const each_array_1 = ensure_array_like(section.items);
      for (let $$index = 0, $$length2 = each_array_1.length; $$index < $$length2; $$index++) {
        let item = each_array_1[$$index];
        $$renderer2.push(`<a${attr("href", item.href)}${attr_class(`group flex h-10 items-center gap-3 rounded-poso px-3 text-sm font-semibold transition ${isActive(item.href) ? "bg-blue text-white shadow-sm" : "text-muted hover:bg-soft hover:text-ink"}`)}><span${attr_class(`grid size-7 place-items-center rounded-md ${isActive(item.href) ? "bg-white/15 text-white" : "bg-soft text-muted group-hover:text-blue"}`)}>`);
        Icon($$renderer2, { name: item.icon, size: 16, stroke: 2 });
        $$renderer2.push(`<!----></span> <span class="min-w-0 flex-1 truncate">${escape_html(item.label)}</span> `);
        if (item.badge) {
          $$renderer2.push("<!--[0-->");
          $$renderer2.push(`<span${attr_class(`rounded-full px-2 py-0.5 text-[10px] font-bold ${isActive(item.href) ? "bg-white/20 text-white" : "bg-blue-soft text-blue"}`)}>${escape_html(item.badge)}</span>`);
        } else {
          $$renderer2.push("<!--[-1-->");
        }
        $$renderer2.push(`<!--]--></a>`);
      }
      $$renderer2.push(`<!--]--></div></div>`);
    }
    $$renderer2.push(`<!--]--></nav> <div class="border-t border-line p-4"><div class="rounded-poso border border-blue/15 bg-blue-soft/70 p-3"><div class="text-xs font-bold text-blue">Akunta sync</div> <div class="mt-1 text-xs leading-5 text-muted">POSO mengirim event penjualan dan pembelian ke Akunta untuk double entry.</div></div></div></div></aside>`);
  });
}
function _layout($$renderer, $$props) {
  $$renderer.component(($$renderer2) => {
    var $$store_subs;
    let { children } = $$props;
    let entitySelectValue = "";
    let userInitials = derived(() => posoContext.user.name.split(" ").filter(Boolean).slice(0, 2).map((part) => part[0]?.toUpperCase()).join("") || "AD");
    async function onEntityChange(event) {
      const value = event.currentTarget.value;
      if (!value) return;
      entitySelectValue = value;
      await posoContext.chooseEntity(value);
    }
    $$renderer2.push(`<div class="min-h-screen md:flex">`);
    Sidebar($$renderer2, {
      currentPath: store_get($$store_subs ??= {}, "$page", page).url.pathname
    });
    $$renderer2.push(`<!----> <div class="min-w-0 flex-1"><header class="sticky top-0 z-20 border-b border-line bg-white/95 backdrop-blur"><div class="mx-auto flex h-16 max-w-[1440px] items-center gap-4 px-4 sm:px-6"><a href="/sales" class="flex items-center gap-3 md:hidden"><span class="grid size-9 place-items-center rounded-poso bg-blue font-black text-white">P</span> <span class="hidden font-bold tracking-tight text-ink sm:block">POSO</span></a> <label class="relative hidden flex-1 md:block"><span class="sr-only">Cari</span> <input class="field h-11 max-w-xl pl-4 pr-20" placeholder="Cari menu, data, laporan..."/> <span class="absolute right-14 top-1/2 -translate-y-1/2 text-muted">⌕</span> <span class="absolute right-3 top-1/2 -translate-y-1/2 rounded border border-line bg-soft px-1.5 py-0.5 text-xs font-semibold text-muted">⌘ K</span></label> <label class="relative ml-auto hidden md:block"><span class="sr-only">Entitas aktif</span> <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-muted">`);
    Icon($$renderer2, { name: "building", size: 16, stroke: 2 });
    $$renderer2.push(`<!----></span> `);
    $$renderer2.select(
      {
        class: "h-11 min-w-56 appearance-none rounded-poso border border-line bg-white py-0 pl-10 pr-9 text-sm font-semibold text-ink shadow-sm outline-none hover:border-blue focus:border-blue focus:ring-4 focus:ring-blue/10",
        value: entitySelectValue,
        onchange: onEntityChange,
        "aria-label": "Entitas aktif"
      },
      ($$renderer3) => {
        if (posoContext.entities.length > 0) {
          $$renderer3.push("<!--[0-->");
          $$renderer3.push(`<!--[-->`);
          const each_array = ensure_array_like(posoContext.entities);
          for (let $$index = 0, $$length = each_array.length; $$index < $$length; $$index++) {
            let entity = each_array[$$index];
            $$renderer3.option({ value: entity.id }, ($$renderer4) => {
              $$renderer4.push(`${escape_html(entity.name)}`);
            });
          }
          $$renderer3.push(`<!--]-->`);
        } else {
          $$renderer3.push("<!--[-1-->");
          $$renderer3.option({ value: "" }, ($$renderer4) => {
            $$renderer4.push(`${escape_html(posoContext.loading ? "Memuat entitas..." : "Belum ada entitas sinkron")}`);
          });
        }
        $$renderer3.push(`<!--]-->`);
      }
    );
    $$renderer2.push(` <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-muted">`);
    Icon($$renderer2, { name: "chevron-down", size: 15, stroke: 2 });
    $$renderer2.push(`<!----></span></label> <button class="relative grid size-11 place-items-center rounded-poso border border-line bg-white text-muted shadow-sm hover:border-blue hover:text-blue" aria-label="Notifikasi">`);
    Icon($$renderer2, { name: "bell", size: 18, stroke: 2 });
    $$renderer2.push(`<!----> <span class="absolute -right-1 -top-1 grid size-5 place-items-center rounded-full bg-red text-xs font-bold text-white">3</span></button> <button class="flex items-center gap-3 rounded-poso px-2 py-1.5 hover:bg-soft"><span class="grid size-10 place-items-center rounded-full bg-amber-soft font-bold text-amber">${escape_html(userInitials())}</span> <span class="hidden text-left sm:block"><span class="block text-sm font-bold text-ink">${escape_html(posoContext.user.name)}</span> <span class="block text-xs text-muted">${escape_html(posoContext.user.role)}</span></span> <span class="hidden text-muted sm:block">`);
    Icon($$renderer2, { name: "chevron-down", size: 15, stroke: 2 });
    $$renderer2.push(`<!----></span></button></div></header> <main class="mx-auto max-w-[1280px] px-4 py-6 sm:px-6">`);
    children?.($$renderer2);
    $$renderer2.push(`<!----></main></div></div>`);
    if ($$store_subs) unsubscribe_stores($$store_subs);
  });
}
export {
  _layout as default
};
