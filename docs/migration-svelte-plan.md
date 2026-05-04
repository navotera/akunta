# Migration Plan — Strip Filament, Adopt SvelteKit SPA + Laravel JSON API

> Target file path: `/Users/hendra/akunta/docs/migration-svelte-plan.md`
> Audience: senior Laravel + frontend engineer (1 dev) executing serially.
> Status: draft v2, 2026-05-03.
> Revision (v2, 2026-05-03): **Ecopa excluded** from scope per user decision. Stays on Filament v4. Phase 8 dropped. Total duration revised to 12-16 weeks (was 16-20).

---

## 1. Executive summary

Akunta saat ini berjalan di atas **Filament v3** (apps/accounting, apps/payroll, apps/cash-mgmt) dan **Filament v4** (ecopa). Plan ini melepas seluruh permukaan Filament dan menggantinya dengan **SvelteKit SPA decoupled** yang berbicara ke **Laravel JSON API v1** per app. Backend dipertahankan utuh: Models, Action classes, Policies, hook system (Akunta\Core\Hooks), audit log, multi-tenant resolver, ApiToken middleware, dan 152 Pest tests semua tetap hijau. Estimasi total ~16–20 minggu kerja kalender untuk satu developer mengerjakan empat app secara berurutan, dengan accounting menjadi pilot. Risiko utama: (1) feature parity gap pada bulk actions / slide-overs / dynamic notifications Filament, (2) kompleksitas OIDC PKCE flow pada SPA saat Ecopa SSO aktif, dan (3) drift fidelity dengan mockup karena custom-built component library. Mitigasi: phased migration per app, feature flag dual-UI, Playwright visual regression dari Phase 0.

---

## 2. Decisions register

| # | Question | Choice | Rationale (1 sentence) | Tradeoffs accepted |
|---|---|---|---|---|
| 1 | Repo layout | `apps/<app>-web/` sibling folders (`apps/accounting-web/`, `apps/payroll-web/`, `apps/cash-mgmt-web/`, `ecopa-web/`) + shared package `packages/akunta-ui/` (design tokens, primitives, money utils). | Cocok dengan monorepo Composer path-repo yang sudah ada (apps/* + modules/*); deploy independen per app dengan SSO pusat di Ecopa; CI matrix per app sudah ada untuk PHP dan akan mirror untuk JS. | CI matrix bertambah 4 jalur; version skew antar app; harus disiplin maintain `packages/akunta-ui` versi single-source. |
| 2 | API style | REST hand-rolled di Laravel: `routes/api.php` v1 + FormRequest (Laravel 11 form requests) + API Resource + envelope `{data, meta, links, errors}` mirip JSON:API tapi tidak strict. | Tim sudah punya pola di `apps/accounting/app/Http/Controllers/Api/V1/JournalController.php`; tidak butuh dependency baru; idempotency_key sudah dipakai di field-level. | Kehilangan auto-include relationships ala JSON:API; harus disiplin versioning manual. |
| 3a | Auth — standalone | Laravel **Sanctum SPA cookie auth** (same eTLD+1, `withCredentials`, CSRF cookie) untuk SPA. | Best DX untuk SPA + same-origin; tidak perlu refresh token rotation di FE; built-in Laravel. | Wajib same-domain (akunta.local + accounting.akunta.local); butuh CORS + cookie domain config. |
| 3b | Auth — Ecopa SSO mode | **Backend-mediated OIDC** (auth code + PKCE dipegang Laravel; SPA cuma redirect ke `/auth/ecopa/start` lalu balik ke `/auth/ecopa/callback`, session cookie ditukar Sanctum) — bukan PKCE langsung dari Svelte. | `modules/ecopa-client/` sudah punya glue OIDC; menyimpan token di FE = surface area lebih besar; back-channel logout & refresh sudah implemented backend-side. | SPA tidak punya akses langsung ke `id_token`; logout-everywhere harus lewat existing `/oidc/backchannel-logout`. |
| 4 | Tenant resolution | **Subdomain-per-tenant** sebagai primary (`{slug}.akunta.app`), `X-Tenant-Slug` header sebagai dev/test fallback. Sudah ada di `apps/accounting/config/tenancy.php`. | Cookie scope rapi per tenant; sesuai roll-own tenancy yang ada; header sebagai escape hatch. | Wildcard cert; DNS setup. |
| 5 | SvelteKit mode | **`adapter-static` + SPA fallback** (`fallback: 'index.html'`, `prerender: false` for app routes). | Backoffice tidak butuh SEO; first paint cukup cepat dengan code splitting; deploy via nginx static. | Tidak ada SSR (catatan: untuk public pages e.g. landing kita tetap pakai Laravel Blade welcome). |
| 6 | State management | **Svelte 5 runes** (`$state`, `$derived`, `$effect`) + writable stores untuk shared/global, **TanStack Query Svelte** untuk data fetching/cache/invalidation. | Runes untuk form state lokal; TanStack Query mature untuk server-state, optimistic updates, retry. | Dependency tambahan (~14kb gz), tapi worth it. |
| 7 | Forms + validation | **Superforms + Zod**. Server validation Laravel diterjemahkan ke `errors` envelope, di-map ke Superforms `setError`. | Type-safe schema; auto wiring ke `<form>` action; mendukung progressive enhancement. | Belajar Superforms idiom; Zod schema duplikasi rules backend (kompromi, generated dari OpenAPI di Phase 9). |
| 8 | Component library | **Bits UI** (headless) + **custom-styled** Tailwind components di `packages/akunta-ui`. **Tidak** pakai shadcn-svelte/Skeleton supaya mockup match 100%. | Mockup minta finesse khas (pill statuses, bordered debit/credit groups, slim sidebar) yang sulit di-override library opinionated; Bits UI ringan & headless. | Time investment Phase 0–1 lebih besar; payoff di phase 2+. |
| 9 | Charts | **Apache ECharts v6** (echart skill tersedia). | Mature, banyak chart type buat reporting (sankey/treemap untuk cashflow), theme-able. | Bundle ~150kb gz — lazy-loaded per route. |
| 10 | i18n | **paraglide-js (inlang)**. | Compile-time tree-shaken messages; kecil sekali; dual ID/EN sederhana. | Belajar inlang flow; commit `messages/*.json`. |
| 11 | Money/decimals | **`decimal.js`** di FE; **string-based bcmath** di BE; transport sebagai string `"27500000.00"`. Tidak pakai dinero.js (punya domain-spesifik currency wrapping). | bcmath compatibility tertinggi; tidak ada IEEE 754 risk. | Sedikit boilerplate format/parse; pasangkan dengan helper `formatRupiah(d)` di `packages/akunta-ui/money`. |
| 12 | Build/deploy | **Vite per app-web** (independent dev server :5173/:5174/etc.); Laravel-side `vite.config.js` direstore ke minimal (akan tetap kompilasi blade welcome + back-channel pages saja). Production: `bun build` → static, served by nginx via `apps/<app>-web/build/`. Docker compose ditambah service `<app>-web-dev` untuk hot reload. | Strict separation; tidak ada bundling dari Laravel side; CSP lebih bersih. | Dua dev servers running simultaneously selama transisi. |
| 13 | Testing | Backend: **Pest** (kept). API contract: **Pest feature tests** + **Spectator**/**openapi-psr7-validator** untuk schema validation di CI. FE unit: **Vitest + @testing-library/svelte**. E2E: **Playwright** (skill ada, multi-tenant via beforeAll seed). Visual regression: **Playwright screenshots + pixelmatch**, baseline per route. | Reuse skill yang ada; Pact ditolak (overhead sync schema lebih mahal dari OpenAPI). | OpenAPI schema harus di-maintain manual atau via `dedoc/scramble`. |

---

## 3. Target architecture

### 3.1 Topology (text diagram)

```
┌────────────────────────────────────────────────────────────────────┐
│ Browser (akunta.app, *.akunta.app)                                 │
│                                                                    │
│  ┌──────────────────────┐   ┌──────────────────────┐              │
│  │ accounting-web SPA   │   │ payroll-web SPA      │   ...         │
│  │ (Svelte 5+SvelteKit) │   │                      │              │
│  └──────────┬───────────┘   └──────────┬───────────┘              │
│             │ fetch (cookies, X-XSRF-TOKEN, X-Tenant-Slug)         │
└─────────────┼────────────────────────────┼─────────────────────────┘
              ▼                            ▼
   ┌──────────────────────┐    ┌──────────────────────┐
   │ accounting Laravel   │    │ payroll Laravel      │
   │  /api/v1/*           │    │  /api/v1/*           │
   │  /auth/sanctum/*     │    │  ...                 │
   │  /auth/ecopa/*  ◀────┼────┤ (OIDC RP via         │
   └──────────┬───────────┘    │  modules/ecopa-client)│
              │                └──────────────────────┘
              ▼
        ┌──────────────┐
        │ ecopa (IdP)  │  /oauth/authenticate · /oauth/token · /oauth/jwks.json
        │ Laravel 12   │  /api/users · /webhooks/* (back-channel logout)
        └──────────────┘
```

### 3.2 Auth flow per mode

**Standalone (no `ECOPA_CLIENT_ID`):**

```
SPA → POST /sanctum/csrf-cookie         (sets XSRF-TOKEN cookie)
SPA → POST /api/auth/login {email,pwd}  (sets laravel_session cookie)
SPA → GET  /api/v1/me                   (200, user+entities+permissions)
SPA → … any /api/v1/* with cookies + X-XSRF-TOKEN header
SPA → POST /api/auth/logout
```

**Ecopa SSO mode:**

```
SPA detects 401 + WWW-Authenticate: Ecopa from /api/v1/me
SPA → window.location = /auth/ecopa/start?redirect=/dashboard
Laravel redirects to Ecopa /oauth/authenticate (state, nonce, code_challenge)
Ecopa → /auth/ecopa/callback?code=…&state=…
Laravel exchanges code → tokens, verifies via JWKS, creates Sanctum session
Laravel redirects → / (SPA root); SPA refetches /api/v1/me, all good
Logout: SPA → POST /api/auth/logout → Laravel revokes session, calls Ecopa end-session
Back-channel: Ecopa POSTs to /oidc/backchannel-logout (already implemented)
```

### 3.3 Tenant resolution

- Primary: subdomain (`acme.akunta.app` → slug `acme`).
- Header fallback: `X-Tenant-Slug: acme` (dev, mobile).
- Existing `App\Http\Middleware\TenantResolver` works as-is — only adds CORS allowance for Origin matching `*.akunta.app`.
- SPA reads tenant from `window.location.host` (not from API) and writes to a `tenant` store; on tenant switch the SPA navigates to `https://other.akunta.app` (full reload).

### 3.4 Frontend repo layout (concrete)

```
/Users/hendra/akunta/
├── apps/
│   ├── accounting/                  (Laravel — kept)
│   ├── accounting-web/               (NEW, SvelteKit SPA)
│   │   ├── src/
│   │   │   ├── app.html
│   │   │   ├── app.css
│   │   │   ├── lib/
│   │   │   │   ├── api/              (fetch wrapper, query keys, hooks)
│   │   │   │   ├── components/        (page-specific)
│   │   │   │   ├── forms/             (Superforms schemas + bindings)
│   │   │   │   ├── stores/             (auth, tenant, ui, toast)
│   │   │   │   ├── tokens/             (mapped from theme-metronic.css)
│   │   │   │   └── i18n/               (paraglide messages id/en)
│   │   │   └── routes/
│   │   │       ├── (app)/
│   │   │       │   ├── +layout.svelte           (sidebar+topbar shell)
│   │   │       │   ├── dashboard/+page.svelte
│   │   │       │   ├── jurnal/
│   │   │       │   │   ├── +page.svelte         (list)
│   │   │       │   │   ├── baru/+page.svelte    (= form jurnal.jpg)
│   │   │       │   │   └── [id]/+page.svelte    (edit/show)
│   │   │       │   ├── akun/
│   │   │       │   ├── partner/
│   │   │       │   ├── periode/
│   │   │       │   ├── lampiran/
│   │   │       │   ├── laporan/
│   │   │       │   │   ├── neraca-saldo/+page.svelte
│   │   │       │   │   ├── laba-rugi/+page.svelte
│   │   │       │   │   ├── neraca/+page.svelte
│   │   │       │   │   └── buku-besar/+page.svelte
│   │   │       │   ├── onboarding/+page.svelte
│   │   │       │   └── pengaturan/
│   │   │       └── (auth)/
│   │   │           └── login/+page.svelte
│   │   ├── tests/                  (Vitest + Playwright)
│   │   │   ├── unit/
│   │   │   └── e2e/
│   │   ├── static/
│   │   ├── package.json
│   │   ├── svelte.config.js
│   │   ├── tailwind.config.ts
│   │   ├── vite.config.ts
│   │   └── playwright.config.ts
│   ├── payroll/                       (Laravel — kept)
│   ├── payroll-web/                    (NEW, mirror)
│   ├── cash-mgmt/
│   └── cash-mgmt-web/
├── ecopa/
└── ecopa-web/                          (NEW, IdP admin SPA)
└── packages/                            (NEW)
    └── akunta-ui/
        ├── src/
        │   ├── tokens/index.ts          (TS export of all CSS vars)
        │   ├── primitives/              (Button, Input, Select, ComboBox,
        │   │                            DatePicker, Modal, SlideOver, Toast,
        │   │                            Drawer, Tabs, Stepper, Tooltip, Popover,
        │   │                            FileUpload, KpiCard, DataTable, Repeater)
        │   ├── charts/                  (ECharts wrappers)
        │   ├── money/                  (Decimal helpers, formatRupiah)
        │   └── icons/                   (heroicons re-export curated set)
        ├── package.json
        └── tsconfig.json
```

### 3.5 Backend changes (apps/accounting/)

Additions:
```
app/Http/Controllers/Api/V1/
  AccountController.php           (new)
  JournalController.php           (extend: index, show, update, destroy, post, reverse, replicate)
  JournalTemplateController.php   (extend: full CRUD already partially there)
  PartnerController.php           (new)
  PeriodController.php            (new)
  AttachmentController.php        (new)
  ReportingController.php         (new)
  OnboardingController.php        (new — CoA template picker, first-run wizard)
  AuthController.php              (new — Sanctum login/logout/me)
  RecentJournalsController.php    (new — widget data)
  FinancialPulseController.php    (new — widget data)

app/Http/Requests/Api/V1/
  StoreJournalRequest.php
  UpdateJournalRequest.php
  PostJournalRequest.php
  ReverseJournalRequest.php
  StorePartnerRequest.php
  …

app/Http/Resources/Api/V1/
  JournalResource.php             (Eloquent → array)
  JournalCollection.php
  PartnerResource.php
  AccountResource.php
  …

config/sanctum.php                 (stateful_domains, supports_credentials=true)
config/cors.php                    (paths: api/* + sanctum/csrf-cookie + auth/*)
```

Removals (Phase 5):
```
app/Filament/                              (entire tree, ~14 resources + 11 pages + 2 widgets)
app/Providers/Filament/                   (AccountingPanelProvider.php)
resources/css/filament/                   (theme.css, theme-metronic.css, tailwind.config.metronic.js)
resources/css/filament/accounting/         (entire)
resources/views/filament/                 (custom blade overrides)
config/filament*.php                      (if any)
composer.json: drop filament/* + filament-socialite + dependencies
package.json: drop @tailwindcss/forms + autoprefixer (if only used by Filament theme)
```

---

## 4. API surface (accounting MVP)

### 4.1 Auth + tenant convention

- All `/api/v1/*` requires either: (a) `Authorization: Bearer <api-token>` (machine-to-machine, existing pattern) **or** (b) Sanctum cookie + `X-XSRF-TOKEN` (SPA).
- Tenant header: `X-Tenant-Slug: acme` (or via subdomain routing). Already enforced by `App\Http\Middleware\TenantResolver`.
- Idempotency: requests that mutate accept `Idempotency-Key: <ulid>` header (server stores hash of request+response for 24h). Sudah ada untuk Journal create.

### 4.2 Pagination / filter / sort / error envelope

**List response envelope:**
```json
{
  "data": [ {...}, {...} ],
  "meta": { "page": 1, "per_page": 25, "total": 137, "last_page": 6 },
  "links": { "self": "...", "next": "...", "prev": null }
}
```

**Single resource envelope:**
```json
{ "data": { "id": "01HZ...", "type": "journal", "attributes": {...}, "relationships": {...} } }
```

**Error envelope (422 validation):**
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "lines.0.account_code": ["Akun tidak ditemukan."],
    "date": ["Tanggal harus dalam periode terbuka."]
  }
}
```

**Filter syntax:** `?filter[status]=posted&filter[date_from]=2026-01-01&filter[date_to]=2026-01-31&filter[partner_id]=01H...`
**Sort:** `?sort=-date,number` (prefix `-` = desc).
**Field selection:** `?fields[journal]=id,number,date,total`.
**Pagination:** `?page=2&per_page=25` (max 200).

### 4.3 Endpoint list (accounting MVP)

| Resource | Method | Path | Notes |
|---|---|---|---|
| Auth | POST | `/api/auth/login` | Sanctum |
| Auth | POST | `/api/auth/logout` | |
| Auth | GET  | `/api/v1/me` | user+permissions+entities |
| Account | GET | `/api/v1/accounts` | tree=1 to get nested |
| Account | POST/PATCH/DELETE | `/api/v1/accounts[/{id}]` | |
| Journal | GET | `/api/v1/journals` | filter, sort, paginate |
| Journal | POST | `/api/v1/journals` | create draft (existing route → mirror under `/api/v1`) |
| Journal | GET | `/api/v1/journals/{id}` | with lines, attachments, audit |
| Journal | PATCH | `/api/v1/journals/{id}` | only when draft |
| Journal | DELETE | `/api/v1/journals/{id}` | only when draft |
| Journal | POST | `/api/v1/journals/{id}/post` | |
| Journal | POST | `/api/v1/journals/{id}/reverse` | |
| Journal | POST | `/api/v1/journals/{id}/replicate` | |
| Journal | POST | `/api/v1/journals/bulk` | existing |
| JournalTemplate | full CRUD | `/api/v1/journal-templates` | existing partial |
| RecurringJournal | full CRUD + pause/resume/run | `/api/v1/recurring-journals` | existing |
| Partner | full CRUD | `/api/v1/partners` | |
| Period | GET | `/api/v1/periods` | + open/close actions |
| Attachment | POST | `/api/v1/attachments` | multipart upload, returns `id` to attach to Journal/JournalEntry |
| Attachment | GET | `/api/v1/attachments/{id}` | streamed signed URL |
| Reporting | GET | `/api/v1/reports/trial-balance?period_id=…` | |
| Reporting | GET | `/api/v1/reports/balance-sheet?period_id=…&compare=true` | |
| Reporting | GET | `/api/v1/reports/income-statement?period_id=…&compare=true` | |
| Reporting | GET | `/api/v1/reports/general-ledger?account_id=…&from=…&to=…` | drill-down |
| Reporting | GET | `/api/v1/reports/{report}/export?format=pdf|xlsx` | streams file |
| Onboarding | GET | `/api/v1/onboarding/coa-templates` | list available |
| Onboarding | POST | `/api/v1/onboarding/apply-coa` | applies template via `ApplyCoaTemplateAction` |
| Widget | GET | `/api/v1/widgets/financial-pulse` | KPI summary |
| Widget | GET | `/api/v1/widgets/recent-journals?limit=10` | |

### 4.4 Sample requests/responses

**(a) `POST /api/v1/journals` — create draft journal**

Request:
```http
POST /api/v1/journals HTTP/1.1
Host: acme.akunta.app
X-XSRF-TOKEN: …
X-Tenant-Slug: acme
Idempotency-Key: 01HZAB...
Content-Type: application/json

{
  "data": {
    "type": "journal",
    "attributes": {
      "entity_id": "01HZ...",
      "type": "general",
      "date": "2026-05-01",
      "reference": "JU-2026-05-003",
      "memo": "Pembelian persediaan dari PT Surya Distribusi (kredit 30 hari)",
      "auto_reverse_on": null,
      "lines": [
        {"account_code": "1-1300", "debit": "25000000.00", "credit": "0.00", "memo": "Pembelian persediaan barang dagang"},
        {"account_code": "1-1450", "debit": "2750000.00",  "credit": "0.00", "memo": "PPN Masukan 11%"},
        {"account_code": "2-1010", "debit": "0.00",         "credit": "27750000.00", "memo": "Utang ke PT Surya Distribusi"}
      ]
    }
  }
}
```

Response 201:
```json
{
  "data": {
    "id": "01HZNEW...",
    "type": "journal",
    "attributes": {
      "number": "JU-2026-05-003",
      "date": "2026-05-01",
      "status": "draft",
      "total_debit": "27750000.00",
      "total_credit": "27750000.00",
      "balanced": true,
      "memo": "...",
      "created_at": "2026-05-02T03:14:00Z"
    },
    "relationships": {
      "lines": [ {...3 line objects with id, account_id, debit, credit, memo} ],
      "attachments": []
    },
    "links": { "self": "/api/v1/journals/01HZNEW...", "post": "/api/v1/journals/01HZNEW.../post" }
  }
}
```

**(b) `GET /api/v1/journals?filter[status]=posted&sort=-date&page=1&per_page=25`**

Response 200:
```json
{
  "data": [
    { "id": "01HZ...", "type": "journal",
      "attributes": { "number": "JU-2026-04-128", "date": "2026-04-30", "status": "posted",
                      "total_debit": "14250000.00", "memo": "Pelunasan invoice INV-2026-0411", "type_label": "Umum" } },
    …
  ],
  "meta": { "page": 1, "per_page": 25, "total": 412, "last_page": 17 },
  "links": { "self": "...?page=1", "next": "...?page=2", "prev": null, "first": "...?page=1", "last": "...?page=17" }
}
```

**(c) `GET /api/v1/reports/trial-balance?period_id=01HZ...&compare=true`**

Response 200:
```json
{
  "data": {
    "period": { "id": "01HZ...", "name": "Mei 2026", "start_date": "2026-05-01", "end_date": "2026-05-31" },
    "compare_period": { "id": "01HZ...", "name": "Apr 2026" },
    "rows": [
      { "account_code": "1-1100", "account_name": "Kas", "type": "asset",
        "debit": "150000000.00", "credit": "0.00",
        "compare_debit": "120000000.00", "compare_credit": "0.00" },
      …
    ],
    "totals": { "debit": "1234500000.00", "credit": "1234500000.00" }
  },
  "meta": { "generated_at": "2026-05-02T03:14:00Z", "currency": "IDR" }
}
```

---

## 5. Frontend foundation

### 5.1 Pinned versions (Phase 0)

```
node              ≥20.11
bun               1.1.x  (preferred runner — already in repo per ecopa/bun.lock)
sveltekit         2.20.x
svelte            5.20.x
vite              6.x
typescript        5.5.x
tailwindcss       3.4.x   (NOT v4 — keep parity with existing Filament theme variables for one cycle)
sveltekit-superforms 2.20.x
zod               3.23.x
@tanstack/svelte-query 5.x
bits-ui           0.21.x
echarts           6.x
decimal.js        10.x
@inlang/paraglide-js 1.x
playwright        1.45.x
vitest            2.x
@testing-library/svelte 5.x
```

### 5.2 Folder layout (per `apps/<app>-web/src/lib`)

```
api/
  client.ts              (fetch wrapper: cookies, CSRF, error envelope → typed)
  query-client.ts        (TanStack Query)
  queries/
    journals.ts          (queryKey factory + queryFn)
    accounts.ts
    reporting.ts
  mutations/
    journals.ts
    auth.ts
components/              (page-specific only — primitives in packages/akunta-ui)
forms/
  journal.schema.ts      (Zod)
  partner.schema.ts
stores/
  auth.svelte.ts         (rune-based)
  tenant.svelte.ts
  toast.svelte.ts
  command-palette.svelte.ts
tokens/
  index.ts               (re-export from packages/akunta-ui/tokens)
i18n/
  paraglide/             (generated)
```

### 5.3 Design token mapping (CSS → TS)

Source: `/Users/hendra/akunta/apps/accounting/resources/css/filament/accounting/theme-metronic.css` (lines 30–110).

Lift to `packages/akunta-ui/src/tokens/index.ts`:

```ts
export const colors = {
  primary:        '#2563EB',  // --acc-primary
  primaryLight:   '#EFF6FF',
  primaryActive: '#1D4ED8',
  primarySoft:    'rgba(37, 99, 235, 0.16)',
  paid:           '#16A34A',  // --acc-paid
  paidLight:      '#DCFCE7',
  unpaid:         '#DC2626',
  unpaidLight:    '#FEE2E2',
  metronic: {
    primary: '#1B84FF', primaryLight: '#EFF6FF', primaryActive: '#056EE9',
    success: '#17C653', warning: '#F6C000', danger: '#F8285A', info: '#7239EA',
  },
  gray: { 50:'#F9FAFB', 100:'#F3F4F6', 200:'#E5E7EB', 300:'#D1D5DB',
          400:'#9CA3AF', 500:'#6B7280', 600:'#4B5563', 700:'#374151',
          800:'#1F2937', 900:'#111827' },
  bg: { page: '#F9FAFB', card: '#FFFFFF' },
  border:    '#E5E7EB',
  borderSoft:'#F3F4F6',
  text:      '#111827',
  textMuted: '#6B7280',
};
export const radius = { sm: 6, md: 10, lg: 14 };
export const shadow = {
  xs: '0 1px 2px rgba(0,0,0,0.04)',
  sm: '0 2px 6px rgba(0,0,0,0.06)',
  md: '0 4px 13px rgba(15,23,42,0.07)',
  lg: '0 12px 28px rgba(15,23,42,0.12)',
  focus: '0 0 0 3px rgba(37,99,235,0.18)',
};
export const font = {
  sans: '"Inter", ui-sans-serif, system-ui, sans-serif',
  mono: '"JetBrains Mono", ui-monospace, monospace',
};
export const spacing = [0, 4, 8, 12, 16, 24, 32, 48, 64];
```

The same values are emitted as CSS variables in `packages/akunta-ui/src/tokens/tokens.css` so Tailwind config can reference them through `theme.extend.colors`.

### 5.4 Base layout shells

`apps/accounting-web/src/routes/(app)/+layout.svelte`:

- `<Sidebar>` — slim 240px, group headings (Operasional / Master / Laporan / Pengaturan), active state pill (matches existing Filament navigation groups in `JournalResource::$navigationGroup`).
- `<Topbar>` — global search (`Cari menu, data, laporan… ⌘K`), tenant switcher (entity dropdown bound to `me.entities`), notifications bell, user menu (email = `ryangrizky92@gmail.com` per current context).
- `<Toaster>` — bottom-right.
- `<CommandPalette>` — ⌘K, navigates to routes / triggers actions (Posting Journal, Reversal, Replicate).

Auth gate in `+layout.ts`:
```ts
export async function load({ fetch }) {
  const me = await api.get('/me', { fetch }).catch(() => null);
  if (!me) throw redirect(302, '/login');
  return { me };
}
```

---

## 6. Phased migration roadmap

> **Sequence:** accounting first (pilot), then payroll, cash-mgmt, ecopa. Each app follows phases 1–5 internally.

---

### Phase 0 — Foundation (1–2 weeks)

**Scope**: bootstrap monorepo for SvelteKit, set up auth/tenant/CORS, design tokens, base layout, dev infra, CI, Playwright skeleton.

**Files to add:**
- `packages/akunta-ui/` (full primitive library skeleton)
- `apps/accounting-web/` (SvelteKit app skeleton)
- `apps/accounting/config/sanctum.php` (extended)
- `apps/accounting/config/cors.php` (new)
- `apps/accounting/app/Http/Controllers/Api/V1/AuthController.php`
- `apps/accounting/routes/api.php` — add `/api/auth/*`, `/api/v1/me`
- `docker/docker-compose.yml` — services `accounting-web-dev`, etc., port 5173+
- `apps/accounting-web/playwright.config.ts`

**Files to delete:** none yet.

**Tests to add:**
- `apps/accounting/tests/Feature/Auth/SanctumLoginTest.php`
- `apps/accounting/tests/Feature/Api/V1/MeEndpointTest.php`
- `apps/accounting-web/tests/e2e/smoke.spec.ts` — login → /dashboard → 200

**Exit criteria:**
- Login from SvelteKit → cookie set → `/api/v1/me` returns 200 with user/entities/permissions.
- Tailwind builds, design tokens load, base layout renders sidebar/topbar.
- Playwright smoke green in CI.
- All 152 existing Pest tests still green.

**Risks:** CORS+cookie subdomain headache. Mitigation: dev env = single domain `*.akunta.local` via /etc/hosts, Sanctum `stateful_domains` includes wildcard.

**Duration:** 1.5 weeks.

---

### Phase 1 — POC: Form Jurnal (1 week)

**Scope:** port the journal create/edit form from `JournalResource.php` to Svelte matching `/Users/hendra/akunta/ui design/akunta/form jurnal.jpg` 100%. Validate via Playwright screenshot.

**Files to add:**
- `apps/accounting/app/Http/Controllers/Api/V1/JournalController.php` — extend with `index`, `show`, `update`, `destroy`, `post`, `reverse`, `replicate`
- `apps/accounting/app/Http/Requests/Api/V1/StoreJournalRequest.php`, `UpdateJournalRequest.php`
- `apps/accounting/app/Http/Resources/Api/V1/JournalResource.php`, `JournalCollection.php`
- `apps/accounting-web/src/lib/forms/journal.schema.ts` (Zod)
- `apps/accounting-web/src/routes/(app)/jurnal/baru/+page.svelte`
- `apps/accounting-web/src/routes/(app)/jurnal/[id]/+page.svelte`
- `apps/accounting-web/src/lib/components/journal/JournalForm.svelte`
- `apps/accounting-web/src/lib/components/journal/LineRepeater.svelte`
- `apps/accounting-web/src/lib/components/journal/TemplatePicker.svelte`
- `apps/accounting-web/src/lib/components/journal/RecentJournalCard.svelte`
- `packages/akunta-ui/src/primitives/{ComboBox,DatePicker,MoneyInput,Repeater}/`

**Files to delete:** none.

**Tests:**
- `apps/accounting/tests/Feature/Api/V1/JournalCrudApiTest.php` (full CRUD, post, reverse, replicate)
- `apps/accounting-web/tests/unit/JournalForm.test.ts` (validation, balanced check, template apply)
- `apps/accounting-web/tests/e2e/journal-create.spec.ts` (form jurnal full happy path + screenshot diff vs `form jurnal.jpg`)

**Exit criteria:**
- Pixel diff vs `form jurnal.jpg` ≤ 2% on Playwright screenshot at 1280×800.
- Create→post→reverse round-trip works against real DB.
- Existing `Filament/Resources/JournalResource` STILL works (dual UI).

**Risks:** ComboBox keyboard nav matching mockup; live-balanced footer pill (`Jurnal Balance`); template picker integration.

**Duration:** 1 week.

---

### Phase 2 — Accounting Reporting (2 weeks)

**Scope:** Trial Balance, Neraca (Balance Sheet), Laba Rugi (Income Statement), GL drill-down + PDF/XLSX export. Reuse existing `BalanceSheet.php`, `IncomeStatement.php`, `TrialBalance.php`, `GeneralLedger.php` page logic — extract to shared service.

**Files to add:**
- `apps/accounting/app/Services/Reporting/{TrialBalance,BalanceSheet,IncomeStatement,GeneralLedger}Builder.php` (extracted from Filament Pages)
- `apps/accounting/app/Http/Controllers/Api/V1/ReportingController.php`
- `apps/accounting/app/Http/Resources/Api/V1/Reports/{TrialBalance,BalanceSheet,IncomeStatement,GeneralLedger}Resource.php`
- `apps/accounting/app/Exports/{TrialBalance,BalanceSheet,…}XlsxExport.php`
- `apps/accounting/app/Exports/{TrialBalance,…}PdfExport.php` (DomPDF/Browsershot)
- `apps/accounting-web/src/routes/(app)/laporan/{neraca-saldo,laba-rugi,neraca,buku-besar}/+page.svelte`
- `apps/accounting-web/src/lib/components/reporting/{ReportToolbar,PeriodPicker,CompareToggle,ReportTable,DrillDownDrawer}.svelte`
- `packages/akunta-ui/src/primitives/{Drawer,Tabs}/`

**Files to delete (deferred to Phase 5):** none yet.

**Tests:**
- `apps/accounting/tests/Feature/Api/V1/Reports/TrialBalanceApiTest.php` (+3 more)
- `apps/accounting-web/tests/e2e/reporting.spec.ts`
- visual regression baselines for 4 reports

**Exit criteria:** all 4 reports parity with Filament; export PDF byte-for-byte equivalent (or better) via shared exporter.

**Risks:** existing report logic lives inside Filament Page classes — extract carefully; comparative period diffing.

**Duration:** 2 weeks.

---

### Phase 3 — Accounting CRUD core (2 weeks)

**Scope:** Account (CoA tree), Partner, Period (open/close), JournalTemplate, RecurringJournal, Attachment.

**Files to add:**
- 6× `app/Http/Controllers/Api/V1/{Account,Partner,Period,Attachment}Controller.php`
- 12+ FormRequests + Resources
- 6× `apps/accounting-web/src/routes/(app)/{akun,partner,periode,template-jurnal,jurnal-berulang,lampiran}/+page.svelte` + `[id]/+page.svelte`
- `packages/akunta-ui/src/primitives/{DataTable,FileUpload,Stepper,Tooltip,Popover}/`
- `apps/accounting-web/src/lib/components/coa/CoaTree.svelte` (drag-reorder optional)

**Files to delete:** none yet.

**Tests:**
- 6× CRUD API feature tests
- 6× E2E specs
- DataTable accessibility (axe-core) ≥ 95.

**Exit criteria:** all listed CRUDs reachable from Svelte sidebar, parity with Filament.

**Duration:** 2 weeks.

---

### Phase 4 — Onboarding + Dashboard (1 week)

**Scope:** new-tenant wizard with CoA template picker (reuse `ApplyCoaTemplateAction`), Dashboard widgets `FinancialPulse` + `RecentJournals`.

**Files to add:**
- `apps/accounting/app/Http/Controllers/Api/V1/OnboardingController.php`
- `apps/accounting/app/Http/Controllers/Api/V1/Widgets/{FinancialPulse,RecentJournals}Controller.php`
- `apps/accounting-web/src/routes/(app)/onboarding/+page.svelte` (Stepper)
- `apps/accounting-web/src/routes/(app)/dashboard/+page.svelte`
- `apps/accounting-web/src/lib/components/dashboard/{FinancialPulseCard,RecentJournalsList,JournalShortcuts}.svelte`
- ECharts wrapper `packages/akunta-ui/src/charts/SparklineChart.svelte`

**Tests:** OnboardingApiTest, dashboard widget API tests, E2E onboarding wizard.

**Duration:** 1 week.

---

### Phase 5 — Strip Filament from accounting (3–5 days)

**Scope:** delete Filament tree, remove packages, update routes.

**Files to delete:**
```
apps/accounting/app/Filament/                                   (entire tree — 14 resources, 11 pages, 2 widgets)
apps/accounting/app/Providers/Filament/AccountingPanelProvider.php
apps/accounting/app/Livewire/                                   (if only used by Filament)
apps/accounting/resources/css/filament/                          (entire tree, including theme-metronic.css)
apps/accounting/resources/views/filament/                       (custom blade overrides if any)
apps/accounting/resources/js/                                   (if only used by Filament; keep welcome.js if any)
```

**composer.json edits:**
- Remove: `filament/filament`, `filament/forms`, `filament/notifications`, `filament/tables`, `dutchcodingcompany/filament-socialite`, any `filament/*` plugins.
- Keep: `laravel/socialite` (still used), `akunta/*` modules.

**package.json edits:**
- Remove: `@tailwindcss/forms`, `@tailwindcss/typography`, `tailwindcss` (if no other consumer), `autoprefixer`.
- Keep: `axios` (used by webhook delivery? verify).

**routes/web.php edits:**
- Remove all routes that resolved into Filament panel (`/admin-accounting`, etc.).
- `Route::get('/')` now serves `accounting-web` index via redirect or static fallback (or nginx maps `/` → SPA).

**vite.config.js**: simplify to handle only OAuth/webhook minimal blade pages, OR remove entirely if all UI moved.

**Tests:** verify all 152 Pest tests still pass — Filament-specific tests (none currently in `tests/Feature/Filament/*`) need not be touched.

**Risks:**
- Hidden Filament dependency in audit / RBAC dashboards. Mitigation: grep for `Filament\\` in all source files before delete.
- `RoleResource`, `UserResource`, `AuditLogResource` features will be re-implemented in `apps/accounting-web/src/routes/(app)/pengaturan/*` BEFORE deletion (sub-phase 5a).

**Duration:** 3–5 days.

---

### Phase 6 — Payroll port (3 weeks)

Mirror Phases 1–5 for `apps/payroll/`:
- Resources: Employee, PayrollRun.
- Phase 6.0: foundation reuse (`packages/akunta-ui` already there).
- Phase 6.1–6.4: port resources + reports.
- Phase 6.5: strip Filament.

**Duration:** 3 weeks.

---

### Phase 7 — Cash-mgmt port (2 weeks)

Mirror for `apps/cash-mgmt/`:
- Resources: Expense, Fund.
- Smaller surface than accounting → 2 weeks.

---

### Phase 8 — ~~Ecopa (IdP) port~~ **DROPPED (v2 revision, 2026-05-03)**

Ecopa stays on Filament v4 by user decision. Rationale: back-office identity admin, low-traffic, no mockup coverage, Filament fit-for-purpose. Cross-app SSO via existing `/oauth/*` endpoints unchanged — SPA accounting redirects to Ecopa OAuth as before. Revisit only if Ecopa UI requirements outgrow Filament defaults later.

**Implication on other phases:** none. Phase 1-7 + 9 unchanged. Total scope reduced ~3-4 weeks.

**Risks:** custom MFA/Auth pages (`Filament/MultiFactor/*`, `Filament/Pages/Auth/*`) require careful porting; never break login-as-user impersonation.

**Duration:** 3–4 weeks.

---

### Phase 9 — Cleanup + hardening (1–2 weeks)

**Scope:**
- Remove `filament/*` from all `composer.json` files (root + 4 apps).
- Drop `theme-metronic.css` and Filament CSS files (already done per-app in Phases 5/6/7/8 — final sweep).
- Audit RBAC checks at API boundary: every controller hits `$this->authorize(...)` or Policy.
- CSP hardening: `default-src 'self'; script-src 'self' 'wasm-unsafe-eval'; style-src 'self' 'unsafe-inline'; connect-src 'self' https://api.akunta.app https://ecopa.akunta.app; img-src 'self' data: blob:; font-src 'self' https://fonts.bunny.net`.
- Visual regression baseline frozen (commit screenshots to `tests/e2e/__screenshots__/`).
- OpenAPI 3.1 spec generation via `dedoc/scramble` (optional) → published at `/api/openapi.json` per app.
- Update `AGENTS.md` and `docs/spec.md` to reflect new architecture.
- `composer dump-autoload --optimize` + `bun run build` size budget check (<400kb gz initial chunk).

**Files to delete:** any leftover Filament references found by `rg -l "Filament" apps/ ecopa/`.

**Tests:**
- Full Pest run: ≥152 tests still green.
- Full Playwright run: smoke + visual regression.
- `phpstan analyse` level 7 still passes.
- `pint --test` clean.

**Duration:** 1–2 weeks.

---

## 7. Backend API implementation pattern (worked example)

Decomposing `apps/accounting/app/Filament/Resources/JournalResource.php` into REST.

**Current Filament file:**
- `JournalResource.php` — defines `form()`, `table()`, `infolist()`, `getRelations()`, navigation.
- `JournalResource/Pages/{Create,Edit,List}Journal.php` — CRUD pages.

**Decomposition:**

### 7.1 `app/Http/Controllers/Api/V1/JournalController.php`

```php
namespace App\Http\Controllers\Api\V1;

use App\Actions\PostJournalAction;
use App\Actions\ReverseJournalAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\{StoreJournalRequest, UpdateJournalRequest};
use App\Http\Resources\Api\V1\{JournalCollection, JournalResource};
use App\Models\Journal;
use Illuminate\Http\Request;

class JournalController extends Controller
{
    public function __construct(
        private PostJournalAction $postAction,
        private ReverseJournalAction $reverseAction,
    ) {}

    public function index(Request $r) {
        $this->authorize('viewAny', Journal::class);
        $q = Journal::query()->with(['lines.account','attachments']);
        // apply filter[status], filter[date_from], filter[date_to], sort, paginate (helper trait)
        $q = (new \App\Support\ApiQuery($r))->apply($q, [
            'filterable' => ['status','type','date_from','date_to','partner_id'],
            'sortable'   => ['number','date','total_debit'],
            'default_sort' => '-date',
        ]);
        return new JournalCollection($q->paginate($r->integer('per_page', 25)));
    }

    public function show(Journal $journal) {
        $this->authorize('view', $journal);
        return new JournalResource($journal->load(['lines.account','attachments','audits']));
    }

    public function store(StoreJournalRequest $r) {
        // Reuses existing route /api/v1/journals (already in routes/api.php)
        // FormRequest covers idempotency_key, balance check, period check.
        $journal = $r->createJournalDraft();   // helper on the FormRequest
        return (new JournalResource($journal))->response()->setStatusCode(201);
    }

    public function update(UpdateJournalRequest $r, Journal $journal) {
        $this->authorize('update', $journal);
        $journal = $r->applyTo($journal);
        return new JournalResource($journal->refresh());
    }

    public function destroy(Journal $journal) {
        $this->authorize('delete', $journal);
        abort_unless($journal->status === Journal::STATUS_DRAFT, 422, 'Only draft journals can be deleted.');
        $journal->delete();
        return response()->noContent();
    }

    public function post(Request $r, Journal $journal) {
        $this->postAction->execute($journal, $r->user());
        return new JournalResource($journal->refresh());
    }

    public function reverse(Request $r, Journal $journal) {
        $reversal = $this->reverseAction->execute($journal, $r->user(), $r->date('reverse_on'));
        return (new JournalResource($reversal))->response()->setStatusCode(201);
    }

    public function replicate(Request $r, Journal $journal) {
        $copy = $journal->replicate(['posted_at','posted_by','status','idempotency_key','number']);
        $copy->status = Journal::STATUS_DRAFT;
        $copy->save();
        $journal->lines->each(fn($l) => $copy->lines()->create($l->only(['account_id','debit','credit','memo','partner_id','tax_code_id'])));
        return (new JournalResource($copy))->response()->setStatusCode(201);
    }
}
```

### 7.2 `app/Http/Requests/Api/V1/StoreJournalRequest.php`

Encapsulates the validation already in the controller today (lines 25–45 of `JournalController.php`), plus authorization. Returns errors in the agreed envelope automatically (Laravel default).

### 7.3 `app/Http/Resources/Api/V1/JournalResource.php`

Standard Eloquent Resource — serialize Journal into the JSON:API-ish envelope (id, type, attributes, relationships).

### 7.4 `routes/api.php` additions

```php
Route::prefix('v1')->middleware(['auth:sanctum','tenant'])->group(function () {
    Route::apiResource('journals', JournalController::class);
    Route::post('journals/{journal}/post',     [JournalController::class, 'post']);
    Route::post('journals/{journal}/reverse',  [JournalController::class, 'reverse']);
    Route::post('journals/{journal}/replicate',[JournalController::class, 'replicate']);
    // ...rest of MVP endpoints
});
// Legacy machine-to-machine routes under /v1 with api.token middleware: keep AS-IS for backwards compatibility (sibling apps depend on them).
```

**Reuse map:**
- `App\Actions\PostJournalAction` → unchanged.
- `App\Actions\ReverseJournalAction` → unchanged.
- `App\Models\Journal` → unchanged.
- `Akunta\Core\Hooks` → keep; wp-style hook events still fire.
- `App\Concerns\HasAttachments` → unchanged.
- Audit log via `Akunta\Audit\Models\AuditLog` → unchanged (BaseAction emits).

---

## 8. Frontend component implementation pattern (worked example)

The Journal form (matches `form jurnal.jpg`).

### 8.1 File tree

```
apps/accounting-web/src/routes/(app)/jurnal/baru/
  +page.svelte                       (route shell)
  +page.ts                            (load: prefetch templates, accounts, last journal)

apps/accounting-web/src/lib/components/journal/
  JournalForm.svelte                  (the form)
  HeaderRow.svelte                    (Tanggal / No. Bukti / Keterangan)
  DebitGroup.svelte                   (left card — debit lines)
  CreditGroup.svelte                  (left card — credit lines)
  LineRow.svelte                      (account ComboBox + memo + amount + delete)
  TemplatePicker.svelte               (right top — "Mulai dari template")
  RecentJournalCard.svelte            (right bottom — "Jurnal Terakhir")
  BalanceFooter.svelte                (sticky bottom — "Jurnal Balance" pill, Simpan/Batal/Posting)

apps/accounting-web/src/lib/forms/journal.schema.ts
```

### 8.2 Zod schema (server-mirroring)

```ts
import { z } from 'zod';
import Decimal from 'decimal.js';

export const lineSchema = z.object({
  id: z.string().optional(),
  account_id: z.string().min(26).max(26),     // ULID
  account_code: z.string().optional(),         // for display
  memo: z.string().max(200).optional(),
  debit:  z.string().regex(/^\d+(\.\d{1,2})?$/).default('0.00'),
  credit: z.string().regex(/^\d+(\.\d{1,2})?$/).default('0.00'),
  partner_id: z.string().optional(),
  tax_code_id: z.string().optional(),
}).refine(l => !(l.debit !== '0.00' && l.credit !== '0.00'), {
  message: 'Satu baris hanya boleh debit ATAU kredit, bukan keduanya.',
});

export const journalSchema = z.object({
  type: z.enum(['general','adjustment','closing','reversing','opening']).default('general'),
  date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/),
  reference: z.string().min(1).max(120),     // "No. Bukti"
  memo: z.string().min(1).max(400),          // "Keterangan"
  lines: z.array(lineSchema).min(2),
}).refine(j => {
  const d = j.lines.reduce((s,l) => s.plus(new Decimal(l.debit  || 0)), new Decimal(0));
  const c = j.lines.reduce((s,l) => s.plus(new Decimal(l.credit || 0)), new Decimal(0));
  return d.eq(c) && d.gt(0);
}, { message: 'Total debit harus sama dengan total kredit dan lebih dari nol.' });
```

### 8.3 Form component (excerpt)

```svelte
<!-- JournalForm.svelte -->
<script lang="ts">
  import { superForm } from 'sveltekit-superforms';
  import { zod } from 'sveltekit-superforms/adapters';
  import { journalSchema } from '$lib/forms/journal.schema';
  import { createMutation } from '@tanstack/svelte-query';
  import { api } from '$lib/api/client';
  import HeaderRow from './HeaderRow.svelte';
  import DebitGroup from './DebitGroup.svelte';
  import CreditGroup from './CreditGroup.svelte';
  import TemplatePicker from './TemplatePicker.svelte';
  import RecentJournalCard from './RecentJournalCard.svelte';
  import BalanceFooter from './BalanceFooter.svelte';

  let { initialData = null, accounts, templates, lastJournal } = $props();

  const { form, errors, enhance, submitting } = superForm(initialData ?? {
    type: 'general', date: new Date().toISOString().slice(0,10),
    reference: '', memo: '',
    lines: [
      { account_id: '', debit: '0.00', credit: '0.00', memo: '' },
      { account_id: '', debit: '0.00', credit: '0.00', memo: '' },
    ],
  }, { validators: zod(journalSchema), dataType: 'json' });

  const totals = $derived.by(() => {
    let d = 0, c = 0;
    for (const l of $form.lines) { d += +l.debit || 0; c += +l.credit || 0; }
    return { debit: d, credit: c, balanced: d === c && d > 0 };
  });

  const save = createMutation({
    mutationFn: (data: any) => api.post('/journals', { data: { type: 'journal', attributes: data }}),
  });
  const post  = createMutation({
    mutationFn: (id: string) => api.post(`/journals/${id}/post`),
  });
</script>

<form use:enhance method="POST" class="grid grid-cols-12 gap-6">
  <div class="col-span-12">
    <HeaderRow bind:value={$form} errors={$errors} />
  </div>

  <div class="col-span-8 space-y-4">
    <DebitGroup  bind:lines={$form.lines} {accounts} totalDebit={totals.debit}/>
    <CreditGroup bind:lines={$form.lines} {accounts} totalCredit={totals.credit}/>
    <!-- LAMPIRAN slot -->
  </div>

  <aside class="col-span-4 space-y-4">
    <TemplatePicker {templates} on:apply={e => $form = applyTemplate($form, e.detail)}/>
    <RecentJournalCard journal={lastJournal} on:duplicate={e => $form = duplicateFrom(e.detail)}/>
  </aside>

  <BalanceFooter
    balanced={totals.balanced}
    on:saveDraft={() => $save.mutate($form)}
    on:cancel={() => history.back()}
    on:post={async () => {
      const created = await $save.mutateAsync($form);
      await $post.mutateAsync(created.data.id);
      goto(`/jurnal/${created.data.id}`);
    }}
  />
</form>
```

### 8.4 Optimistic UX

- Save Draft: optimistically add to recent journals list via `queryClient.setQueryData(['recent-journals'], old => [optimistic, ...old])`. On error, rollback.
- Posting: button disables, spinner inline, toast on success.
- ComboBox account search: debounced `/api/v1/accounts?search=…`, cached via TanStack Query keyed by tenant.

### 8.5 Validation surface

Server returns `{ message, errors: { 'lines.0.account_code': ['...'] } }` → Superforms `setError(form, 'lines[0].account_code', msg)` → red ring on the corresponding `<ComboBox>` and inline message.

---

## 9. Risks + mitigations

| Risk | Severity | Mitigation |
|---|---|---|
| Filament features without 1:1 Svelte equivalents (bulk actions, slide-overs, dynamic notifications, infolists, relation managers) | High | Inventory in `docs/migration-svelte-plan.md` (this doc). Build equivalents in `packages/akunta-ui` before Phase 5. SlideOver = `Drawer` primitive; Bulk actions = DataTable selection + action bar; Notifications = Toast store + websocket channel via Laravel Reverb (or polling fallback). |
| Tenant-resolution race during cutover (subdomain not yet wired in dev) | Medium | Phase 0 hard requirement: `*.akunta.local` via dnsmasq + caddy; dual-mode `X-Tenant-Slug` header for CI. |
| OIDC PKCE complexity (back-channel logout race, token refresh) | High | Backend-mediated flow keeps tokens off SPA. Reuse existing `Akunta\EcopaClient\EcopaClient` and `OidcBackchannelLogoutController` as-is. |
| Money math drift (FE float vs BE bcmath) | High | All money is **string** in transport; FE wraps in `Decimal`; never use `Number()` on amount; lint rule via `eslint-plugin-no-restricted-syntax` blocking arithmetic on `*amount*` identifiers. |
| Timezone/date format drift (Asia/Jakarta vs UTC) | Medium | API returns ISO 8601 with offset; FE reads `me.timezone` and `Intl.DateTimeFormat('id-ID', { timeZone: 'Asia/Jakarta' })`. |
| Asset bundle bloat | Medium | Lazy load ECharts (`import('echarts')` in chart wrapper); per-route code-split; budget assertion in CI (`vite-bundle-visualizer` + check `<400kb gz` initial). |
| Dev productivity drop during migration (dual UI maintenance) | Medium | Feature flag per page (`config('ui.svelte_routes')` array). PRs can ship one route at a time without blocking team. |
| Visual regression flakiness on Linux CI (font rendering) | Low | Bake `Inter` + `JetBrains Mono` via `@fontsource` into Playwright trace; allow ≤2% pixel diff; manual approval workflow for baseline updates. |
| RBAC enforcement gap at API boundary | High | Phase 9 audit: every controller method either calls `$this->authorize()` or uses `authorizeResource()` in constructor; static analysis rule via PHPStan custom rule. |
| Filament audit log coverage loss after strip | Medium | Audit currently emitted by Action classes (`BaseAction::audit()`), not Filament — unaffected. Verify in tests. |
| ecopa Filament v4 → Svelte: custom auth pages (MFA, recover, login-as) | High | Build equivalents in `ecopa-web/src/routes/(auth)/*` BEFORE deleting Filament v4. Manual penetration test before cutover. |

---

## 10. Cutover + rollback strategy

### 10.1 Default cutover model

**Per-route feature flag dual-UI**, controlled by `config/ui.php`:

```php
return [
    'svelte_routes' => env('UI_SVELTE_ROUTES', '*'),  // CSV or '*'
    'mode' => env('UI_MODE', 'svelte'),               // svelte | filament | dual
];
```

Behaviour:
- `dual`: `/jurnal` rendered by SvelteKit; `/admin-accounting/journals` still renders Filament. Both authenticated, both write to same DB. QA can compare outputs side-by-side.
- `svelte`: Filament panel returns 404 / redirect.
- `filament`: SvelteKit returns "Disabled" for that route.

Cutover sequence per phase:
1. Develop on `dual` mode; Filament untouched.
2. QA & visual-regress sign-off.
3. Flip `UI_SVELTE_ROUTES` to include the new route in staging for 1 sprint.
4. Production flip.
5. Phase 5 of each app: hard-delete Filament after 2 weeks of stable Svelte usage in prod.

### 10.2 Per-phase deviations

- **Phase 1 (POC)**: dual only; never cutover; just demonstrate.
- **Phase 5 (strip Filament accounting)**: hard cutover. Tag `pre-strip-accounting` git tag for rollback safety. Keep Filament packages in `composer.json` for one more release after strip — easy revert.
- **Phase 8 (ecopa)**: extra-cautious because IdP downtime affects all apps. Maintenance window + canary tenant for 1 week before global flip.
- **Phase 9**: cleanup is non-reversible; only run after all consumer apps stable for 30 days.

### 10.3 Rollback procedure (per phase)

If a phase fails QA after staging cutover:
1. Set `UI_MODE=filament` for affected app via env (no deploy needed if config cached on env-only).
2. `php artisan config:clear && php artisan route:clear`.
3. Revert SvelteKit nginx vhost to fallback `/admin-accounting` Laravel.
4. Tag rollback in git: `git tag rollback-phase-N-YYYY-MM-DD`.
5. Open postmortem issue; do not retry the phase until root cause fixed.

If Phase 5 (strip) already shipped to prod and failure surfaces:
- `git revert <strip-commit>` → CI build → deploy. Filament packages restored, theme files restored.
- DB unaffected (no schema changes from strip).

---

## 11. Open items for follow-up

- **Mobile native app** (Capacitor wrapping the SPA, or Flutter). Defer; SPA mobile-responsive sufficient for MVP.
- **Public customer portal** (invoice viewer for partners). Separate SvelteKit app `apps/portal-web/`, Phase 10+.
- **e-Faktur DJP integration** (PPN reporting export). Backend Laravel job; UI eventually surfaces in `apps/accounting-web/src/routes/(app)/laporan/pajak/`.
- **Advanced security per spec v2** (mTLS for inter-app webhook, hardware key MFA at Ecopa). Track in `docs/spec.md`.
- **Real-time collaboration** (live cursors on journals). Reverb + Echo Svelte client; defer to v2.
- **Offline-first journal entry** (PWA + IndexedDB queue). Defer; nice-to-have for field accountants.
- **OpenAPI spec generation** (`dedoc/scramble` or hand-maintained). Decide in Phase 9; if hand-maintained, Postman collection sufficient interim.
- **i18n message extraction** automation (paraglide CLI in pre-commit).
- **Component library publish** to private npm if Akunta plug-ins evolve (later).
- **Filament v4 ecopa MFA pages** — confirm audit + recovery code flow has equivalent in Svelte before strip.

---

### Critical Files for Implementation

- /Users/hendra/akunta/apps/accounting/app/Http/Controllers/Api/V1/JournalController.php
- /Users/hendra/akunta/apps/accounting/app/Filament/Resources/JournalResource.php
- /Users/hendra/akunta/apps/accounting/resources/css/filament/accounting/theme-metronic.css
- /Users/hendra/akunta/apps/accounting/app/Http/Middleware/TenantResolver.php
- /Users/hendra/akunta/apps/accounting/config/tenancy.php

---

## 200-word summary

**Total estimated duration**: 16–20 calendar weeks for one developer working serially (accounting 7w pilot, payroll 3w, cash-mgmt 2w, ecopa 4w, hardening 2w, plus 2w of slack and integration). The plan strips Filament across four apps (accounting/payroll/cash-mgmt v3, ecopa v4) and replaces them with a per-app SvelteKit SPA driving a Laravel JSON API v1, while keeping every Action, Model, Policy, hook, audit-log behaviour, and 152 Pest tests untouched. A shared `packages/akunta-ui` library hosts design tokens lifted from `theme-metronic.css`, primitives, ECharts wrappers, and `decimal.js` money helpers. Auth is Sanctum cookie standalone, OIDC backend-mediated when Ecopa is configured. Tenant resolution stays subdomain-first.

**Top 3 risks**: (1) feature gaps in Filament-only patterns (bulk actions, slide-overs, infolists) requiring custom primitives before Phase 5; (2) OIDC PKCE flow + back-channel logout coordination across multiple SPAs; (3) money/decimal drift between FE float and BE bcmath — mitigated by string-based transport + lint rules.

**Next 3 steps for Phase 0**:
1. Bootstrap `apps/accounting-web/` SvelteKit + `packages/akunta-ui/` skeleton with Tailwind, Bits UI, Superforms, TanStack Query pinned versions.
2. Add Sanctum SPA auth: `config/sanctum.php`, `config/cors.php`, `AuthController` with login/logout/me, plus subdomain dev DNS (`*.akunta.local`).
3. Write a single Playwright smoke test (`login → /api/v1/me → /dashboard`) and wire into CI alongside existing Pest run.

---
