# Ekosistem Aplikasi Akuntansi — Spesifikasi Lengkap

**Versi:** 0.6 (spec finalized — ready for architecture & coding phase)
**Tanggal:** 2026-04-23
**Sumber asli:** Google Docs v0.1 (dibuat oleh user) + proposed additions dari sesi diskusi.

> **Legend:**
> - 🟢 **[ORIGINAL]** — dari Google Docs versi pertama, tidak diubah.
> - 🟡 **[PROPOSED]** — tambahan/rekomendasi yang perlu dikonfirmasi user.
> - ✅ **[CONFIRMED]** — sudah di-approve user.
> - 🔴 **[TBD]** — masih belum ada keputusan, perlu input user.

---

## 0. Scope & Context Summary ✅ [CONFIRMED]

Ringkasan keputusan yang sudah di-lock — acuan untuk keputusan teknis ke depan.

| Aspek | Keputusan |
|-------|-----------|
| **Target Region** | Indonesia |
| **Target Segmen** | UKM (Usaha Kecil Menengah) |
| **Platform** | Web only (v1). Mobile app tidak di v1. |
| **Multi-tenancy (SaaS mode)** | **Database-per-tenant** |
| **Tax Scope v1** | PPN 11% + PPh (21/23/22/26/4-ayat-2). e-Faktur ditunda ke v2. |
| **Bank Integration** | Tidak di v1, akan di-add nanti via module |
| **Hook System** | Laravel Events + Listeners dengan naming convention WP-style (lihat 4.5) |
| **Roadmap v1** | Double-Entry → Payroll → Cash Management |
| **Design Principle** | "Design for extension points, not for features" — 4 kontrak di section 6 wajib. |

---

## 1. Overview 🟢 [ORIGINAL]

Ekosistem Aplikasi Akuntansi yang dibuat akan bisa dipakai dengan basis web dengan metode perhitungan yang dapat dibuat yakni bisa menggunakan **metode akrual** maupun **cash basis**, yang akan ditampilkan pada saat setup awal.

Aplikasi ini merupakan aplikasi ekosistem yang terbagi menjadi beberapa aplikasi yang saling terhubung namun dapat berdiri sendiri. Tiap aplikasi akan memiliki koneksi konfigurasi API, dimana **hulu dari tiap aplikasi adalah aplikasi double-entry (aplikasi akuntansi)**.

Aplikasi dapat diaktivasi pada menu **Apps** di aplikasi akuntansi. Menu Apps ini dapat di-klik dan dengan fitur accordion akan menampilkan aplikasi yang sudah di-install.

---

## 2. Arsitektur Tier

### 2.1 Struktur Tier 🟢 [ORIGINAL]

| Tier | Nama | Fungsi |
|------|------|--------|
| **Main tier** | Authentication Gateway | User registration, authentication gateway, aktivasi user pada aplikasi ekosistem beserta role-nya |
| **Second tier** | Double-Entry Journal | Aplikasi akuntansi inti (hub) |
| **Third tier** | Ecosystem Apps | Payroll, manajemen persediaan, manajemen aset, manajemen SDM, manajemen kas, dll. |

### 2.2 Mode Integrasi 🟢 [ORIGINAL]

Walaupun aplikasi ini berbentuk ekosistem, setting di env memungkinkan aplikasi dapat **independent tanpa main tier**:

- **Integrated mode:** Manajemen user dilakukan di main-tier app.
- **Standalone mode:** Manajemen user dan role cukup di second-tier saja.

Artinya aplikasi second-tier dan third-tier dapat memiliki user management dan authentication masing-masing sebagai fallback.

### 2.3 Diagram Arsitektur 🟡 [PROPOSED]

```
┌─────────────────────────────────────────────────────┐
│  MAIN TIER (optional, toggle via env)               │
│  - User registration / SSO                          │
│  - Auth gateway                                     │
│  - Ecosystem role activation                        │
└───────────────────┬─────────────────────────────────┘
                    │ (auth + user sync)
                    ▼
┌─────────────────────────────────────────────────────┐
│  SECOND TIER — Double-Entry Accounting (HUB)        │
│  - General / Adjustment / Closing journals          │
│  - Chart of Accounts                                │
│  - Auto-journal API (configurable templates)        │
│  - Multi-entity                                     │
│  - Reports (Neraca, L/R, Arus Kas, dll.)            │
│  - App Manager (install/activate third-tier)        │
└──┬────────┬────────┬────────┬────────┬──────────────┘
   │ API    │ API    │ API    │ API    │ API
   ▼        ▼        ▼        ▼        ▼
┌────────┐┌────────┐┌──────────┐┌──────┐┌──────────┐
│Payroll ││Invent. ││Asset Mgmt││ HR   ││Cash Mgmt │
└────────┘└────────┘└──────────┘└──────┘└──────────┘
       THIRD TIER (pluggable, subscription-based)
```

---

## 3. Business Model

### 3.1 Mode Distribusi 🟢 [ORIGINAL]

Aplikasi dapat dikomersialisasikan dalam dua bentuk:

**(A) Self-Hosted**
- User mendaftar di website berbasis WordPress
- Setelah login, user mendapatkan **API key**
- API key digunakan untuk meng-install aplikasi utama di server user sendiri

**(B) SaaS**
- Delivered sebagai **WordPress plugin**
- User dashboard menampilkan:
  1. Plan (subscription level)
  2. API key
  3. Aplikasi yang ter-install
  4. Profil user
- User bisa membeli aplikasi second-tier dengan metode subscription

### 3.2 Admin Dashboard (WordPress) 🟢 [ORIGINAL]

Admin (vendor) dapat melihat:
1. User yang mendaftar
2. API yang masih aktif (termasuk lama pemakaian)
3. Detail user
4. Jumlah user per plan (A, B, C)
5. Tool untuk membuat plan
6. URL aplikasi yang ter-install per user
7. **Toggle aktivasi penjualan per aplikasi** (berguna saat masa adopsi — aplikasi bisa dinonaktifkan dari marketplace)
8. Point 7 bisa di-extend sebagai **fitur trial**

### 3.3 Arsitektur Licensing 🟡 [PROPOSED]

- **Single codebase** untuk self-hosted & SaaS.
- Dua environment variable utama:
  - `APP_MODE=saas|self_hosted`
  - `USE_MAIN_TIER=true|false`
- License check:
  - **Self-hosted:** Laravel app memanggil WordPress REST API di setiap periode (cron) untuk validasi API key + subscription status.
  - **SaaS:** WordPress plugin-side gating; Laravel app di-provision otomatis per tenant saat purchase.

---

## 4. Spesifikasi Teknis

### 4.1 Stack 🟢 [ORIGINAL]

- **Backend:** Laravel + **Filament v3**
- **Frontend interaktif:** Alpine.js atau Svelte.js (untuk halaman yang butuh interaksi aktif)
- **Security:** SQL injection, XSS, Captcha, dll.

### 4.2 Aplikasi Ekosistem 🟢 [ORIGINAL]

Aplikasi akuntansi + secondary apps (payroll, aset, persediaan, investasi, kas kecil, manajemen karyawan) semua disebut sebagai **aplikasi main tier** dalam ekosistem.

### 4.3 Role & Activation 🟢 [ORIGINAL]

- Aplikasi main tier memiliki **role**
- User yang terdaftar dalam ekosistem dapat dipakai di seluruh ekosistem, **namun tetap perlu diaktivasi per aplikasi main tier**

### 4.4 Extensibility 🟢 [ORIGINAL]

- Aplikasi main tier memiliki fitur **hook-based**
- **Module** adalah fitur extensifikasi tiap tier yang dapat memanfaatkan hook

### 4.5 Implementasi Hook System ✅ [CONFIRMED]

**Keputusan final:** **Laravel Events + Listeners** dengan **naming convention WP-style** (`module.before_action` / `module.after_action`).

**Kenapa pilihan ini?**
- **Simple:** Native Laravel, tanpa wrapper layer tambahan. Developer Laravel langsung familiar.
- **Powerful:** Built-in support untuk queued listeners, wildcard subscribers, event subscribers, priority.
- **Testable:** `Event::fake()` dengan assertion library built-in.
- **WP-style naming** tetap dipertahankan supaya developer plugin familiar tanpa menambah abstraksi.

**Contoh:**
```php
// DISPATCH di core code
event('journal.before_post', [$journal, $user]);
event('journal.after_post', [$journal, $user]);

// LISTEN dari module Payroll
Event::listen('journal.before_post', function ($journal, $user) {
    // validation logic
});

// Untuk filter/transform data:
$filtered = app('hooks')->apply('journal.data', $journal);
// (thin helper class untuk filter-style hook, dibangun di atas Event)
```

**Naming convention wajib:**
- `{resource}.before_{action}` — sebelum action
- `{resource}.after_{action}` — sesudah action
- `{resource}.{action}_failed` — kalau action gagal

**Alternatif yang ditolak:**
- WordPress-style wrapper murni (`do_action`/`add_action`) — menambah layer indirection tanpa benefit signifikan di atas Laravel Events.
- Pure Laravel Events tanpa convention — kurang discoverable, developer plugin perlu tahu exact event class names.

### 4.6 Keamanan Dasar 🟡 [PROPOSED - detail]

- CSRF (built-in Laravel)
- SQL injection prevention (Eloquent + prepared statements)
- XSS (Blade auto-escape + CSP headers)
- Captcha di login, register, form publik (e.g. hCaptcha/reCAPTCHA)
- Rate limiting di API endpoints
- Audit log untuk semua operasi journal (immutable append-only table)
- 2FA optional untuk user dengan role admin/finance

### 4.7 System Spec / Non-Functional Requirements 🟡 [PROPOSED — melengkapi bullet kosong original]

Mengisi dua bullet kosong di original "Spesifikasi". Keputusan engineering untuk aspek non-fungsional:

**A. Logging**
- Monolog dengan daily rotation (default Laravel)
- Log level: `debug` di local, `info` di prod
- Sensitive data redaction (NPWP, password, API key TIDAK boleh muncul di log)
- Centralized log aggregation (e.g. Loki/ELK) — optional untuk SaaS mode, tidak wajib v1

**B. Caching**
- **Redis** untuk session + cache + queue
- Cache keys **scoped per tenant** (`tenant:{id}:key`) — penting karena multi-tenant DB-per-tenant
- Cache aggressive untuk: COA, permissions, company settings
- Cache invalidation via event listener (nyambung ke hook system 4.5)

**C. Queue & Background Jobs**
- **Laravel Horizon + Redis**
- Pisahkan queue: `default`, `reports` (long-running: PDF/Excel generation), `notifications`, `webhooks`, `auto_journal`
- Retry policy: exponential backoff, max 3 retries
- Failed job logging + notifikasi admin

**D. Deployment Target**
- **Docker-based** — Docker Compose untuk self-hosted, managed container service untuk SaaS
- **Stack versi:**
  - PHP 8.3+
  - Laravel 11 / Filament v3
  - MySQL 8 **atau** PostgreSQL 15 (PostgreSQL preferred untuk DB-per-tenant karena lebih ringan per-DB)
  - Nginx + PHP-FPM
  - Redis 7
- **Self-hosted minimum requirement:** 2 vCPU, 4 GB RAM, 40 GB disk (target UKM)

**E. Monitoring & Observability**
- **Error tracking:** Sentry (free tier cukup untuk self-hosted; paid untuk SaaS)
- **Dev tool:** Laravel Telescope di non-prod
- **Uptime monitoring:** UptimeRobot / Better Stack (SaaS mode only)
- Health check endpoint `/up` di tiap app (sudah built-in Laravel 11)

**F. Backup Strategy**
- **Self-hosted:** instruksi & script untuk daily DB dump + file storage backup; user tanggung jawab retensi
- **SaaS:** automated daily DB dump per tenant, retensi 30 hari, restore on-demand via admin dashboard
- **Point-in-time recovery** opsional untuk paid tier

**G. Performance Targets (UKM scale)**
- Response time: p95 < 500ms untuk operasi CRUD standar
- Report generation (Neraca/L-R): p95 < 5 detik
- Concurrent users per tenant: 20–50 (target UKM)
- Uptime SLA (SaaS): 99.5%

**H. Internationalization**
- Default locale: `id_ID` (Bahasa Indonesia)
- English (`en`) support
- Locale file uploadable via UI (sudah di-spec di 8.3)
- Angka format: pakai separator titik untuk ribuan, koma untuk desimal (`1.000.000,00`)
- Tanggal format: `DD-MM-YYYY` (Indonesia standard)
- Timezone: configurable per tenant, default `Asia/Jakarta`

**I. Browser Support**
- Modern evergreen browsers: Chrome, Firefox, Safari, Edge (last 2 versions)
- No IE support

**J. Accessibility**
- WCAG 2.1 Level AA dimana praktis (Filament v3 sudah mostly compliant)

**K. Data Import/Export**
- Export: CSV, XLSX, PDF untuk semua list view dan report
- Import: CSV/XLSX untuk master data (COA, employees, opening balance)
- Validation: schema check + business rule check sebelum commit ke DB

---

## 5. Security & RBAC (Role-Based Access Control) 🟡 [PROPOSED]

### 5.1 Prinsip Dasar

Setiap izin di dalam ekosistem = kombinasi **(User × Role × App × Entity)**. Kombinasi ini memberi fleksibilitas (user bisa punya peran berbeda di tiap app/entitas) tapi tetap secure (default deny, scope terbatas).

### 5.2 Model Data

```
User ─────────┬───> UserAppAssignment ───> Role ───> Permission
              │          │
              │          └── scope: App + Entity (+ opsional: Period)
              │
              └───> Satu user bisa punya banyak assignment berbeda
```

**Contoh nyata:**

| User | App | Entity | Role |
|------|-----|--------|------|
| Budi | Accounting | PT ABC | Accountant |
| Budi | Accounting | PT XYZ (anak perusahaan) | Viewer |
| Budi | Payroll | PT ABC | HR Staff |
| Siti | Accounting | Semua entitas | Super Admin |
| Auditor Eksternal | Accounting | PT ABC | Auditor (read-only, period-locked) |

### 5.3 5 Layer Security

1. **Authentication** — Main-tier SSO (OAuth2/OIDC) kalau `USE_MAIN_TIER=true`, atau local auth kalau standalone. MFA opsional untuk role finance/admin.
2. **App Activation** — User terdaftar di main-tier ≠ auto-access semua app. Harus di-activate per app explicit.
3. **Role Assignment** — Per tuple (User, App, Entity). Bisa stacking (satu user punya multiple roles).
4. **Permission Check** — Setiap action di-gate via Laravel Policy/Gate. **Default deny.**
5. **Data Scoping** — Eloquent global scope otomatis filter query ke entity yang user punya akses. User tidak bisa akses data entity lain walau tahu ID-nya.

### 5.4 Fine-grained Permissions (contoh)

**Accounting app:**
```
journal.view, journal.create, journal.post
journal.reverse, journal.delete
period.close, coa.manage
report.view.neraca, report.view.laba_rugi, report.view.arus_kas
entity.switch, api_token.manage
```

**Payroll app:**
```
payroll.view, payroll.create, payroll.approve, payroll.pay
employee.manage
```

### 5.5 Preset Roles ✅ [CONFIRMED]

| Role | Deskripsi |
|------|-----------|
| **Super Admin** | Pemilik tenant, akses semua |
| **App Admin** | Admin per aplikasi |
| **Owner / Direktur** | View all across apps & entities, no edit |
| **Finance Manager** | Approval level tinggi, cross-app financial oversight |
| **Accountant** | Full CRUD journal, view report |
| **Accountant Assistant** | Create draft journal, no post |
| **Approver** | Post/approve journal yang dibuat orang lain |
| **Tax Officer** | Khusus handle perpajakan — `tax.*` permissions |
| **HR Manager** | Full Payroll + HR |
| **HR Staff** | Create only |
| **Cashier** | Khusus kas kecil (Cash Management app) |
| **Internal Auditor** | Akses audit log + read-only seluruh app/entity |
| **Auditor (External)** | Read-only, bisa di-lock per periode |
| **Viewer** | Read-only dashboard |

**Deferred (akan ditambah bersama app terkait):** Warehouse Manager, Warehouse Staff (bersama Inventory app v2.0).

User bisa **bikin custom role** dengan pick-and-mix permissions via UI (lihat 5.8).

Super Admin, App Admin, dan Admin Aplikasi Ecopa tidak dapat mengubah assignment
role Akunta mereka sendiri. Perubahan tersebut harus dilakukan oleh admin lain;
aturan ini wajib dipaksakan oleh API, bukan hanya oleh UI.

### 5.6 Integrated vs Standalone Mode

| Aspek | Integrated Mode | Standalone Mode |
|-------|-----------------|-----------------|
| User store | Main-tier | Local per-app |
| Role/permission store | Main-tier, sync via webhook | Local per-app |
| Token | JWT dengan claims (user_id, app_id, entity_id, permissions[]) | Laravel session |
| Struktur RBAC | **Identik** | **Identik** |

**Kunci:** Struktur RBAC identik antar mode — yang beda hanya sumber data. Migrasi standalone → integrated ke depan jadi straightforward.

### 5.7 Multi-Entity Role Behavior ✅ [CONFIRMED]

- **Tidak ada auto-inheritance** — user yang punya role di parent entity TIDAK otomatis dapet akses ke subsidiary. Setiap akses harus **di-assign explicit** per entity.
- Role assignment dilakukan di **main-tier app**:
  - Admin main-tier memilih user → pilih app target → pilih entity target → assign role.
  - Konfigurasi di env per app menentukan app/entity mana yang available untuk di-assign.
- **Time-bound role assignment** (pinjam role sementara): **tidak di v1**. Tapi schema dibuat ready untuk extend — tabel `user_app_assignment` punya kolom nullable `valid_from` / `valid_until` dari awal, tinggal aktifkan logic di v2.

### 5.8 Flexible Permission Management via UI ✅ [CONFIRMED]

Permission system harus **dinamis dan dapat di-extend via UI** (bukan hardcoded). Requirement:

- **Permission registry** — Setiap app register permissions-nya saat install. UI admin bisa browse semua available permissions.
- **Custom role builder (UI)** — Admin bisa bikin role baru dengan pick-and-mix permissions, tanpa deploy code.
- **Assignment UI** — UI untuk assign (User × Role × App × Entity) dengan filter & search.
- **Permission inheritance structure** — Role bisa extend dari role lain (misal: "Senior Accountant" = Accountant + `journal.reverse`).
- **Preview mode** — Admin bisa "preview as user X" untuk test apa yang terlihat/bisa dilakukan user tsb.

### 5.9 Fitur Keamanan Tambahan (v1)

- **Audit log assignment** — Siapa assign role ke siapa, kapan, dari IP mana (immutable).
- **Impersonation** dengan audit trail (untuk admin support).
- **API token scoped** — Token external integration punya subset permissions sendiri, expirable.
- **Period lock** — Setelah closing, bahkan Super Admin tidak bisa edit journal di periode tsb tanpa reopen (yang ter-log).
- **Row-level entity isolation** — Enforce di DB level via `entity_id` + Eloquent global scope.

**Deferred ke v2+ (tetap hook-ready):** IP whitelist, session timeout per role, password policy, login attempt lockout, device fingerprinting, data export approval, activity-based permissions.

---

## 6. Extensibility Guarantees 🟡 [PROPOSED]

**Prinsip:** *"Design for extension points, not for features."*

Kita TIDAK build Segregation of Duties (SoD), approval workflow multi-level, atau fitur advanced lain di v1. **TAPI** kita pasang 4 kontrak engineering di bawah ini sekarang, supaya fitur-fitur tersebut bisa ditambahkan nanti sebagai **module additive** — tanpa refactor besar.

### 6.1 Empat Kontrak Engineering (WAJIB v1)

#### 1. Hooks di Titik Kritis Sejak Hari Pertama

Minimum set hook yang wajib ada sebelum launch v1:

```
journal.before_create / after_create
journal.before_post / after_post
journal.before_reverse / after_reverse
period.before_close / after_close
payroll.before_approve / after_approve
payroll.before_pay / after_pay
payment.before_execute / after_execute
user.role_assigned / role_revoked
```

**Aturan:** Setiap action kritis baru WAJIB pasang hook `before_*` dan `after_*`.

#### 2. Audit Log Immutable (Append-Only)

Schema minimum:

```
audit_log
├── id
├── actor_user_id       ← siapa yang melakukan
├── action              ← e.g. journal.post, payroll.approve
├── resource_type
├── resource_id
├── entity_id           ← scope multi-entitas
├── metadata (JSON)
├── ip_address
├── user_agent
└── created_at
```

**Aturan:** Tabel ini TIDAK boleh bisa di-update/delete. Enforced di DB level (revoke UPDATE/DELETE privilege dari app user).

**Retention Policy ✅ [CONFIRMED]:** Configurable per tenant, **default 3 tahun**. Catatan: standar akuntansi Indonesia mensyaratkan retensi dokumen 10 tahun (UU KUP Pasal 28 & UU Dokumen Perusahaan) — **tenant yang diatur dalam tax compliance harus set retention ≥ 10 tahun**. UI admin kasih warning kalau set < 10 tahun.

#### 3. Centralized Authorization Layer

```php
// ✅ Wajib
Gate::authorize('journal.post', $journal);

// ❌ Dilarang
if ($user->hasRole('approver')) { ... }
```

**Aturan:** Zero inline permission checks. Semua lewat Policy/Gate. Lint rule + code review enforce.

#### 4. Action sebagai First-Class Object

```php
// ✅ Wajib
app(PostJournalAction::class)->execute($journal, $user);

// ❌ Dilarang
// Logic journal posting inline di controller
```

**Aturan:** Semua business action ada class dedicated di `App\Actions\`. Bisa di-wrap/decorate oleh module tanpa sentuh original code.

### 6.2 Fitur yang Bisa Ditambahkan Sebagai Module Nanti

Kalau 4 kontrak di atas dipenuhi, fitur-fitur berikut jadi **ADDITIVE** (tinggal nyantol hook, tidak perlu refactor):

| Fitur Future | Cara Tambah | Status |
|--------------|-------------|--------|
| **Segregation of Duties (SoD)** | Module listen ke `journal.before_post`, check audit log: creator ≠ approver | Hook-ready |
| **Multi-level approval workflow** | Module listen ke `journal.before_post`, route ke approval queue table | Hook-ready |
| **Amount-based approval threshold** | Config table + listen ke `journal.before_post` | Hook-ready |
| **Time-bound approvals** | Extend dengan scheduler + listen ke hooks | Hook-ready |
| **External integrations** (e-Faktur, bank API) | Module baru yang nyantol hook | Hook-ready |
| **Analytics / anomaly detection** | Listen ke audit log stream | Hook-ready |
| **Multi-factor approval** | Module yang intercept di `before_*` hook | Hook-ready |
| **Bulk import/export dengan validation** ✅ | Handler generic yang pakai action class + validasi per resource | Design-ready |
| **Scheduled journal (depresiasi otomatis, recurring entries)** ✅ | Laravel Scheduler + `JournalTemplate` + action dispatch | Design-ready |
| **Document attachment per journal** ✅ | Polymorphic `attachments` table + storage driver (local/S3) | Design-ready |
| **Webhook outbound ke sistem eksternal** ✅ | Module listen ke semua `after_*` hook, forward ke subscriber URL | Design-ready |
| **Activity-based permissions** | Module yang combine audit log query + permission check | Hook-ready |

**Legenda:**
- **Hook-ready** — hooks sudah ada, tinggal build module.
- **Design-ready** ✅ — user sudah confirm fitur ini akan ada → DB schema & primitives harus disiapkan sejak v1 supaya nyambung natural nanti.

### 6.3 Risiko dan Mitigasi

| Risiko | Mitigasi |
|--------|----------|
| Developer lupa pasang hook di action baru | Code review checklist + generator (`php artisan make:action`) |
| Audit log di-skip "karena belum butuh" | Code review: tidak boleh merge action tanpa audit log entry |
| Inline `if ($user->role...)` check | Lint rule (PHPStan/Psalm) + code review |
| Action logic tercecer di controller | Convention + generator command |

### 6.4 Definition of Done untuk Action Kritis Baru

Setiap action kritis (yang mengubah financial state atau punya side effect) tidak dianggap selesai sampai:

- [ ] Ada dedicated Action class di `App\Actions\`
- [ ] Pasang `before_*` dan `after_*` hook
- [ ] Authorization check via Gate
- [ ] Audit log entry
- [ ] Test untuk hook firing
- [ ] Test untuk authorization (positive + negative case)

---

## 7. Alur Setup Awal 🟡 [PROPOSED - melengkapi original yang incomplete]

**Original hanya menyebutkan:** "User diminta isi profil perusahaan."

**Proposed complete flow:**

1. **Company Profile** — Nama perusahaan, logo, NPWP, alamat, kontak, jenis industri
2. **Accounting Method** — Pilih **Accrual** atau **Cash Basis** (tidak bisa diubah setelah ada transaksi)
3. **Fiscal Period** — Tanggal mulai periode, panjang periode (default 12 bulan)
4. **Currency & Locale** — Default IDR, locale id_ID
5. **COA Template Selection** — Pilih template Chart of Accounts:
   - General Trading (Perdagangan)
   - Service (Jasa)
   - Manufacturing (Manufaktur)
   - Custom (mulai dari kosong)
6. **Tax Configuration** — Aktifkan PPN (11%), PPh 21/23/4(2), dll.
7. **Opening Balances** — Input saldo awal (opsional, bisa di-skip dan diisi nanti)
8. **Admin User & Role** — Buat user admin pertama + tetapkan role

---

## 8. Aplikasi Double-Entry (Second Tier)

### 8.1 Overview 🟢 [ORIGINAL]

Aplikasi double-entry digunakan untuk pembuatan jurnal dengan **basis akuntansi Indonesia**.

Fitur inti:
- Template Chart of Account
- Manajemen jurnal: **General Journal**, **Adjustment Journal**, **Jurnal Penutup (Closing Journal)**

### 8.2 Setting Perusahaan 🟢 [ORIGINAL] + ✅ [CLARIFIED]

1. Edit profil perusahaan (nama, logo, NPWP, info lain)
2. Edit metode yang digunakan (akrual / cash basis)
3. Manajemen periode (mulai dan akhir)
4. **Metadata perusahaan lengkap** — data legal & corporate: nama perusahaan, bentuk badan usaha (PT/CV/UD), NPWP, NIB, SK Kemenkumham, alamat kantor pusat & cabang, data direksi & komisaris, email resmi, nomor telepon, website, logo, tanggal pendirian.

### 8.3 Fitur Utama 🟢 [ORIGINAL]

- **Multi-entitas** yang dapat dipilih di sebelah kanan menu aplikasi. Dengan bergantinya entitas, segala ekosistem juga mengikuti.
- Multi-entitas memiliki dua mode:
  - **Entitas berbeda** (independent)
  - **Entitas terhubung** (parent-subsidiary dengan relasi shareholding)
- **API auto-journal** untuk transaksi terkait karyawan, biaya, pendapatan
- API ini dapat di-setting dengan **template jurnal**, contoh:
  - Biaya Gaji ↔ Kas (saat pembayaran gaji)
  - Kas ↔ Pendapatan / Piutang (saat penerimaan pembayaran) 🟡 *[completed from truncated original]*
- **Translation** dengan fitur locale yang dapat di-upload dalam bentuk file

### 8.4 Chart of Accounts Template 🟡 [PROPOSED]

COA default (Indonesia) 4-digit:
- **1xxx** — Aktiva (Assets)
  - 11xx Aktiva Lancar (Current Assets)
  - 12xx Aktiva Tetap (Fixed Assets)
- **2xxx** — Kewajiban (Liabilities)
- **3xxx** — Ekuitas (Equity)
- **4xxx** — Pendapatan (Revenue)
- **5xxx** — HPP (COGS)
- **6xxx** — Biaya Operasional (Operating Expenses)
- **7xxx** — Pendapatan/Biaya Lain-lain

### 8.5 Integritas Double-Entry 🟡 [PROPOSED]

- Setiap journal entry **wajib balanced** (sum debit = sum credit), enforced di database constraint
- Journal entries **immutable** setelah posting — koreksi dilakukan via reversing/adjustment entry
- Period lock: setelah closing journal, periode terkunci (tidak bisa ada entry baru di periode tsb)

### 8.6 Jurnal Khusus (Special Journals) 🟡 [PROPOSED — added 2026-04-30]

**Tujuan:** UMKM Indonesia terbiasa dengan jurnal khusus (SAK-ETAP) — pisah workflow per kategori transaksi rutin agar non-akuntan dapat input tanpa berpikir debit/kredit.

**Storage strategy:** Single `journals` + `journal_entries` table (single source of truth). Discriminator via kolom `type`. Tidak ada tabel terpisah per jenis — preserve double-entry guarantees (balanced, immutable, period lock) yang sudah dibangun di §8.5.

**Type constants tambahan** (extend `Journal::TYPE_*`):

| Constant | Value | Indonesian | Number prefix |
|----------|-------|------------|---------------|
| `TYPE_SALES` | `sales` | Jurnal Penjualan | `JS-YYYY-MM-NNNN` |
| `TYPE_PURCHASE` | `purchase` | Jurnal Pembelian | `JP-YYYY-MM-NNNN` |
| `TYPE_CASH_RECEIPT` | `cash_receipt` | Jurnal Penerimaan Kas | `JKM-YYYY-MM-NNNN` |
| `TYPE_CASH_DISBURSEMENT` | `cash_disbursement` | Jurnal Pengeluaran Kas | `JKK-YYYY-MM-NNNN` |

(Existing types tetap: `general` → Jurnal Umum, `adjustment`, `closing`, `reversing`, `opening`.)

**Auto-posting rules per jenis:**

| Jenis | Trigger bisnis | Auto Debit | Auto Credit | Partner | Tax auto-inject |
|-------|---------------|-----------|-------------|---------|-----------------|
| Penjualan (JS) | Invoice keluar | AR / Kas | Pendapatan + PPN Keluaran | Customer wajib | PPN Out, PPh 23 (jasa) |
| Pembelian (JP) | Invoice masuk | Inventory/Beban + PPN Masukan | AP / Kas | Vendor wajib | PPN In, PPh 21/23/4(2) |
| Penerimaan Kas (JKM) | Pembayaran masuk | Kas/Bank | AR / Pendapatan tunai / Lain-lain | Customer (jika lunasi AR) | — |
| Pengeluaran Kas (JKK) | Pembayaran keluar | AP / Beban | Kas/Bank | Vendor (jika lunasi AP) | PPh withholding |

**Wizard form pattern (Filament Stepper):**

1. **Header** — input bisnis: tanggal, nomor (auto-generated), partner, reference (no faktur eksternal), memo
2. **Lines bisnis** — item-level: qty × harga, akun pendapatan/beban, cost center, project, tax code per line
3. **Review jurnal** — read-only preview `JournalEntry[]` hasil generation. Validate balanced. Post / Save Draft.

**Action class per type:**
- `PostSalesJournalAction` — generate lines dari header + items + tax codes → return posted `Journal`
- `PostPurchaseJournalAction`
- `PostCashReceiptJournalAction`
- `PostCashDisbursementJournalAction`

Setiap Action wajib transactional + idempotent (idempotency_key dukung sumber eksternal).

**Resource layout (opsi B — Resource per type, dipilih 2026-04-30):**

```
Filament nav group "Operasional":
 ├── Jurnal Penjualan        (SalesJournalResource)
 ├── Jurnal Pembelian        (PurchaseJournalResource)
 ├── Jurnal Penerimaan Kas   (CashReceiptJournalResource)
 ├── Jurnal Pengeluaran Kas  (CashDisbursementJournalResource)
 ├── Jurnal Umum             (JournalResource — existing, scope ke type general/adjustment/closing)
 ├── Template Jurnal         (JournalTemplateResource — existing)
 └── Jurnal Berulang         (RecurringJournalResource — existing)
```

Setiap Resource khusus share `App\Models\Journal`, scope `where('type', …)`, policy + number sequence + form wizard sendiri.

**Migration footprint (12c-i):**

```sql
ALTER TABLE journals ADD COLUMN partner_id ULID NULL REFERENCES partners(id);
ALTER TABLE journals ADD COLUMN business_total NUMERIC(20,2) NULL; -- gross sebelum tax
ALTER TABLE journal_templates ADD COLUMN applies_to_type VARCHAR NULL;
-- type column sudah ada — extend enum constraint untuk 4 value baru
```

**Integrasi existing:**
- `Partner` — relation `salesJournals()`, `purchaseJournals()`, `cashReceiptJournals()`, `cashDisbursementJournals()` via type-scoped `hasMany`
- `JournalTemplate.applies_to_type` — template bisa scoped per jenis ("Penjualan tunai retail")
- `RecurringJournal` — bisa schedule type apa saja
- `TaxCode` — auto-inject line PPN/PPh berdasarkan tax_code yang dipilih di line bisnis

**Reporting tambahan (phase 2 — 12c-iii):**
- Buku Pembantu Piutang: filter `sales` + `cash_receipt` per partner
- Buku Pembantu Hutang: filter `purchase` + `cash_disbursement` per partner
- Register Faktur PPN Keluaran: filter `sales` + tax_code group PPN-Out
- Register Faktur PPN Masukan: filter `purchase` + tax_code group PPN-In

**Phasing:**
- **12c-i** Sales + Purchase (foundation AR/AP — paling sering dipakai)
- **12c-ii** Cash Receipt + Cash Disbursement
- **12c-iii** Buku Pembantu + Register PPN reports
- **12c-iv** Template per-type + recurring per-type

### 8.7 Buku Intern dan Fiskal ✅ [IMPLEMENTED — updated 2026-08-21]

Entitas memilih `bookkeeping_mode` melalui onboarding atau pengaturan:

- `independent_books` — mengaktifkan ledger Intern dan Fiskal yang independen.
- `internal_only` — hanya mengaktifkan ledger Intern.

Pada `independent_books`, form jurnal menyediakan tiga mekanisme input:

| Pilihan input | Hasil persistensi |
|---|---|
| Intern | Satu jurnal dengan `journal_mode=internal` |
| Fiskal | Satu jurnal dengan `journal_mode=fiscal` |
| Intern & Fiskal | Dua jurnal draft atomik: satu `internal`, satu `fiscal` |

`Intern & Fiskal` bukan mode ledger ketiga. Kedua jurnal mempunyai
`transaction_code` dan `input_group_id` yang sama agar asal input dapat
ditelusuri, tetapi ID, nomor, entries, attachment, status workflow, posting,
reversal, dan saldo tetap independen. Sesudah create tidak ada sinkronisasi
otomatis antarpasangan. Mode gabungan hanya menerima akun
`availability=both`.

Akun dengan `availability=fiskal` atau `availability=both` wajib menyimpan
dasar hukum pajak. Akun `availability=intern` tidak mewajibkan metadata ini.

Setiap entitas wajib memiliki empat akun sistem untuk koreksi fiskal dan
provisi pajak kini: Pajak Dibayar di Muka (`both`), Utang PPh Badan - Provisi
(`intern`), Utang PPh Badan Definitif (`both`), dan Beban Pajak Penghasilan
Kini (`intern`). Akun ini dibuat saat entitas dibuat, dipastikan kembali ketika
template COA atau seeder dijalankan, serta ditandai dengan `system_key` unik per
entitas. Kode, nama, tipe, saldo normal, parent, status postable/aktif, dan
ketersediaannya tidak dapat diubah dari pengelolaan COA; akun sistem tidak dapat
dihapus. Deskripsi dan dasar hukum tetap dapat diperbarui.

Koreksi Fiskal berada pada `fiscal_adjustments`. Draft dapat diubah dan bukti
pendukungnya dapat ditambah atau dihapus. Persetujuan mewajibkan minimal satu
bukti; setelah disetujui, data koreksi dan buktinya tidak dapat diubah. Koreksi
approved dengan bukti memengaruhi rekonsiliasi pajak tanpa membuat atau
mengubah journal entry.

Koreksi dapat menyimpan referensi opsional ke satu jurnal Fiskal sumber yang
sudah `posted`. Referensi ini adalah audit trail untuk menelusuri akun, nominal,
uraian, dan bukti transaksi asal serta membantu mencegah koreksi ganda; referensi
tidak mengubah jurnal atau saldonya. Field boleh kosong bila koreksi berasal dari
rekap banyak jurnal, perhitungan eksternal, kebijakan, atau temuan yang tidak
dapat dipetakan ke satu jurnal. Untuk banyak sumber, pengguna menjelaskan
cakupannya pada alasan koreksi dan melampirkan rekap pendukung.

Pengakuan dampak pajak kini ke laporan keuangan menggunakan `tax_provisions`
dan jurnal penyesuaian Intern yang terpisah. Perhitungan menyimpan snapshot
penghasilan neto setelah koreksi, kompensasi rugi, tarif yang dikonfirmasi,
kredit pajak, penghasilan kena pajak, beban pajak kini, dan utang pajak. Saat
disimpan, sistem membuat jurnal `internal` berstatus `draft` dengan pola:

- Debit Beban Pajak Penghasilan Kini sebesar pajak bruto.
- Kredit Pajak Dibayar di Muka sebesar kredit yang digunakan, bila ada.
- Kredit Utang PPh Badan - Provisi sebesar sisa pajak kini terutang.

Jurnal tersebut baru memengaruhi Neraca Saldo dan laporan Intern setelah
diposting melalui workflow jurnal biasa. Perhitungan dapat memperbarui jurnal
yang masih draft/rejected; jurnal submitted/posted harus direverse sebelum
perhitungan pengganti dibuat. Koreksi Fiskal, buku Fiskal, dan jurnal sumber
tidak boleh ditulis ulang oleh proses ini. Hasil perhitungan bukan SPT yang
sudah dilaporkan.

Pada SPA, rincian penghitungan dan pajak yang perlu dicatat ditampilkan sebagai
teks baca-saja. Setiap jurnal yang diperlukan ditampilkan sebagai satu baris
rekomendasi. Aksi `Tambahkan jurnal` hanya membuka form jurnal standar dengan
buku Intern, tipe penyesuaian, tanggal, akun, deskripsi `Koreksi fiskal &
provisi`, dan nominal yang sudah terisi; jurnal belum dipersist hingga pengguna
meninjau dan menyimpan form tersebut. Jika penghitungan membutuhkan beberapa
jurnal, setiap jurnal wajib tampil sebagai baris terpisah.

Pajak tangguhan tidak dihitung otomatis dari arah koreksi positif/negatif.
Pengakuannya memerlukan jumlah tercatat, dasar pajak, pola pemulihan/pelunasan,
dan tarif yang berlaku untuk aset atau liabilitas terkait. Sebelum model dan
workflow khusus tersedia, pajak tangguhan dicatat melalui jurnal terpisah yang
telah direview dan API provisi wajib menyatakan status `not_calculated`.

Dashboard Fiskal menyajikan analisis potensi pajak sebagai simulasi, bukan nilai
SPT final.

Inspector adalah role read-only Fiskal: tidak dapat membuka dashboard atau data
Intern dan langsung diarahkan ke daftar jurnal Fiskal. Pembatasan wajib
ditegakkan di backend.

Label status daftar jurnal mengikuti status backend yang ada: `draft` ditampilkan
sebagai Diajukan, `submitted` sebagai Di review, `posted` sebagai Tersimpan, dan
`rejected` sebagai Perlu Revisi. Jurnal Tersimpan terkunci bagi operator;
Supervisor/admin dapat melakukan koreksi melalui jalur update yang terotorisasi.

### 8.8 Fake Data Lengkap dan Aman

Instalasi aplikasi yang menjalankan seeder menyediakan satu entitas bawaan `PT. Fake Data` dengan
penanda entity-level `is_fake_data`. Entitas ini diprovision secara idempotent
oleh seeder, bukan melalui aksi import di UI. Datasetnya adalah record domain
normal yang mencakup profil entitas, COA, satu periode tahunan `Demo 2026`,
jurnal Intern/Fiskal dan seluruh status workflow, template, jurnal berulang,
koreksi fiskal beserta
bukti, source-ref, dimensi, pajak simulasi, auto-mapping, integrasi webhook dan
log, serta user-role demo. Dashboard dan laporan wajib menghitung record tersebut
melalui query database yang sama dengan entitas biasa; tidak boleh memakai angka
statis khusus demo.

Seluruh ringkasan dashboard dibatasi oleh entitas aktif dan periode aktif. Client
mengirim ID keduanya, backend wajib memastikan periode tersebut dimiliki oleh
entitas aktif, dan hanya jurnal pada pasangan entity-periode itu yang boleh masuk
ke metrik periode. Saldo neraca ditampilkan sampai tanggal akhir periode aktif.
Pergantian entitas atau periode wajib memuat ulang seluruh ringkasan agar data
dari konteks sebelumnya tidak tertinggal di UI. Pilihan periode dari entitas
sebelumnya wajib dibuang ketika workspace diganti; karena PT. Fake Data hanya
memiliki periode native 2026, periode itulah yang aktif walaupun user berpindah
dari entitas dengan periode 2028 atau tahun lain.

`PT. Fake Data` dapat diaktifkan atau dinonaktifkan secara independen dari
Settings. Entitas nonaktif tetap tampil di daftar Settings tetapi tidak muncul
di pemilih entitas pada toolbar. Entitas demo aktif diberi badge dataset dan
versi, misalnya `Demo 2026 · v2026.1.0`, agar user dapat membedakan dataset
native dari data operasional. Import dan Clear Fake Data generik tidak tersedia
pada entitas demo bawaan.

Daftar Workspace menampilkan aktivitas terakhir berdasarkan audit log terbaru
atau waktu perubahan metadata workspace jika belum ada audit. Waktu ditampilkan
relatif hingga satu tahun, berubah menjadi tanggal absolut setelahnya, dan hover
selalu menampilkan timestamp lengkap. Daftar mempunyai tab Active dan Archive.
Admin hanya melihat aksi hapus pada workspace normal yang sudah nonaktif. Aksi
tersebut memerlukan modal konfirmasi dengan nama workspace yang sama persis dan
memindahkan workspace ke Archive, bukan langsung menghapus datanya. Restore
memindahkan workspace ke Active dalam status tetap nonaktif. Workspace yang
tetap diarsipkan selama satu tahun dijadwalkan untuk purge; scheduler hanya
memasukkan job unik ke queue dan queue worker wajib mengecek ulang status serta
masa retensi sebelum menghapus permanen dan mencatat audit. Admin juga dapat
memilih `Hapus Permanen` dari Archive dengan konfirmasi nama workspace; endpoint
hanya mengantrekan job dengan izin melewati masa retensi, sedangkan worker tetap
mengecek ulang bahwa workspace masih diarsipkan. `PT. Fake Data`
tidak dapat diarsipkan atau dihapus.

Seluruh pengaturan bisnis pada Settings berscope entity aktif. Format nomor
jurnal/transaksi, mode pembukuan, format tanggal, URL laporan issue, tema, dan
profil tidak boleh diwariskan atau digabung dengan nilai entity sebelumnya.
Cache preferensi browser yang memang diperlukan wajib memakai namespace ID
entity. Bahasa dan zona waktu yang hanya berupa informasi sistem statis tidak
membentuk state entity.

Format kode mendukung nilai awal increment transaksi dan nilai awal per tipe
jurnal pada entity aktif. Generator memakai nilai awal sebagai batas minimum;
jika suffix nomor yang sudah tersimpan untuk prefix terkait lebih tinggi,
generator melanjutkan dari nomor tertinggi ditambah satu dan tidak mundur.

Admin dapat mengembalikan dataset native melalui action `Reset Dataset` khusus
PT. Fake Data. Sebelum reset, backend memberikan preview versi saat ini/target,
jumlah marker per kelompok, marker stale, dan jumlah record manual yang akan
dipertahankan. Eksekusi memerlukan frasa konfirmasi eksplisit dan token preview;
token ditolak jika isi dataset berubah setelah preview. Reset hanya boleh
menghapus record yang memiliki provenance marker pada entitas demo yang sama,
membangun ulang versi dataset secara atomik, membersihkan attachment obsolete
setelah commit, dan menulis audit log. Record manual atau provenance yang tidak
dapat diverifikasi wajib dipertahankan.

Periode native PT. Fake Data bersifat immutable: create, update, close, reopen,
dan delete ditolak backend serta tidak ditawarkan UI. Jurnal demo berstatus
`posted` atau `reversed`, termasuk attachment-nya, juga read-only. Draft dan
contoh workflow lain tetap dapat dipakai untuk simulasi sesuai permission;
perubahan resmi terhadap baseline dilakukan melalui Reset Dataset.

Pada entitas non-demo, Settings hanya menyediakan import COA Teknologi & IT dan
penyiapan user-role fake untuk demo. Aksi impersonation ditampilkan di User &
Roles, bukan di Fake Data, dan tersedia untuk seluruh user aktif pada level
role yang sama atau lebih rendah dari pelaku. API wajib menolak target dengan
role lebih tinggi dan sesi impersonation aktif tidak boleh memulai impersonation
lagi; pembatasan ini tidak boleh hanya mengandalkan UI. Import All
serta import periode, jurnal, template, jurnal berulang, dan raw auto-mapping
tidak tersedia. Dataset lengkap hanya dimiliki PT. Fake Data.

Jurnal berulang native tetap ditampilkan sebagai contoh dengan status yang
representatif, tetapi command background `accounting:run-recurring` wajib
mengecualikan seluruh entitas `is_fake_data`; contoh tersebut tidak boleh
membuat jurnal baru melalui scheduler.

Setiap model yang benar-benar dibuat importer dicatat di `fake_data_records`.
Record yang sudah ada atau dibuat manual tidak boleh diadopsi sebagai fake.
Penghapusan hanya mengikuti marker pada entity yang sama, memeriksa dependency,
dan wajib mempertahankan data manual, termasuk saat marker rusak menunjuk ke
entity lain.

Akun yang masih mempunyai marker fake ditandai titik abu-abu di halaman COA
dan pemilih akun jurnal. Ketika akun tersebut digunakan oleh jurnal non-fake,
marker akun dan seluruh akun induknya harus dilepas dalam transaksi yang sama.
Akun itu menjadi permanen dan tidak lagi menjadi target Clear Fake Data.

Import COA bersifat idempotent dan tidak boleh membuat akun ekuivalen hanya
karena variasi kapitalisasi, spasi, tanda baca, atau nama yang sangat mirip.
Pencocokan nama harus konservatif: tipe akun, saldo normal, sifat postable, dan
ketersediaan buku wajib kompatibel. Akun manual yang dipakai sebagai ekuivalen
tidak boleh diberi marker fake atau ditimpa atributnya.

### 8.9 Deskripsi Bagan Akun

Setiap akun dapat menyimpan deskripsi operasional hingga 2.000 karakter.
Deskripsi yang disediakan template harus menjelaskan definisi akun, kondisi
penggunaan, dan contoh peristiwa ekonomi. Daftar, tree, dan T-view COA
menampilkan deskripsi sebagai tooltip saat item akun diarahkan dengan pointer;
form akun menjadi sumber untuk membuat atau memperbarui teks tersebut.

Template Teknologi & IT wajib mempunyai deskripsi eksplisit untuk seluruh akun,
termasuk akun induk. Import fake data mengisi deskripsi yang sama. Import ulang
hanya boleh memperbarui deskripsi akun yang memiliki marker fake pada entity
yang sama dan tidak boleh menimpa nama atau deskripsi akun manual yang kebetulan
memakai kode serupa.

COA Teknologi & IT mencakup kebutuhan custom software, SaaS, hosting/domain,
resale hardware dan lisensi, implementasi/integrasi, support/maintenance,
managed IT services, keamanan siber/audit TI, pelatihan, infrastruktur internal,
riset produk, compliance, serta biaya langsung per kontrak pelanggan.

### 8.10 SOP Akun dan Review Ketersediaan Buku

Setiap akun tersimpan, baik manual maupun fake, wajib mempunyai deskripsi SOP
yang menjelaskan definisi, waktu penggunaan, dan contoh peristiwa ekonomi.
Backfill SOP bersifat fail-closed: jika satu nama akun belum mempunyai definisi
terkurasi, tidak satu pun perubahan dalam scope eksekusi boleh ditulis. Deskripsi
manual yang sudah terisi tidak boleh ditimpa oleh backfill.

Pada workspace `independent_books`, akun ekonomi biasa seperti kas, piutang,
persediaan, utang, ekuitas, pendapatan, HPP, pajak transaksi, dan beban
operasional, Pajak Dibayar di Muka, dan Utang PPh Badan Definitif tersedia pada
Intern & Fiskal. Akun estimasi/alokasi manajemen, penyusutan komersial,
kapitalisasi produk software, Beban Pajak Penghasilan Kini, dan Utang PPh
Badan - Provisi tersedia pada Intern. Akun penyusutan fiskal tersedia pada Fiskal;
penyajian koreksi berasal dari `fiscal_adjustments`, bukan akun ledger khusus.
Workspace `internal_only` tetap memetakan seluruh akun ke Intern.

Editor akun menampilkan pilihan ketersediaan sebagai tiga badge radio berwarna,
bukan dropdown. Warna mengikuti bahasa visual COA: Intern hijau, Fiskal kuning,
dan Intern & Fiskal berupa gradasi hijau-kuning.

---

## 9. Perpajakan Indonesia 🟡 [PROPOSED — belum ada di original]

**Scope v1 (rekomendasi):**
- **PPN (VAT 11%)** — COA termasuk akun PPN Masukan & PPN Keluaran
- **PPh** — Tax codes per transaksi (PPh 21, 23, 4 ayat 2, 22, 26)
- **Tax report generator** — rekap PPN Masukan/Keluaran per periode
- **❌ e-Faktur integration — NOT in v1** (defer ke v2 karena kompleks & butuh sertifikat DJP)

**Scope v2 (future):**
- e-Faktur direct integration (CSV/XML export untuk upload ke e-Faktur Client Desktop)
- SPT Masa PPN generator
- SPT PPh generator

---

## 10. Laporan Keuangan 🟡 [PROPOSED — belum ada di original]

**Standard reports v1:**

| Report | Format | Filter |
|--------|--------|--------|
| Neraca (Balance Sheet) | Comparative (2 periode) | Per entitas, per tanggal |
| Laba Rugi (Income Statement) | Comparative | Per entitas, range periode |
| Arus Kas (Cash Flow) | Indirect method | Range periode |
| Buku Besar (General Ledger) | Per akun | Range periode, range akun |
| Neraca Saldo (Trial Balance) | Standard | Per tanggal |
| Jurnal Umum | Listing | Range periode |
| Aged AR (Piutang Usaha) | Aging buckets 30/60/90 | Per tanggal |
| Aged AP (Hutang Usaha) | Aging buckets 30/60/90 | Per tanggal |

Pada entitas `independent_books`, seluruh laporan ledger menyediakan tab
`Intern`, `Fiskal`, dan `Intern & Fiskal`. Tab gabungan menampilkan nilai kedua
buku secara berdampingan untuk perbandingan dan tidak membentuk ledger ketiga
atau menggabungkan saldo Intern dengan Fiskal.

**Export:** PDF, Excel (xlsx), CSV.

**Consolidated report:** Untuk multi-entitas mode parent-subsidiary — konsolidasi otomatis dengan eliminasi transaksi intercompany.

---

## 11. Multi-Tenancy (SaaS Mode) ✅ [CONFIRMED]

**Keputusan: Database-per-tenant.**

**Alasan:**
- Data akuntansi sensitif — isolasi kuat adalah best practice
- Per-client backup/restore straightforward
- Compliance/audit lebih mudah (data benar-benar terpisah secara fisik)
- Performance isolation — satu client besar tidak impact client lain

**Trade-off:**
- Lebih berat di ops (butuh automation untuk provisioning DB baru)
- Migration harus di-run across semua tenant DBs

**Alternatif (kalau ops capacity terbatas):**
- Shared DB dengan `tenant_id` column di semua table + row-level security
- Lebih mudah maintained, tapi lebih rentan bug isolation

---

## 12. Roadmap Aplikasi Third-Tier ✅ [CONFIRMED]

**Urutan build (rekomendasi):**

| Phase | Apps | Alasan |
|-------|------|--------|
| **v1.0** | Double-Entry (core) | Fondasi — semua app lain bergantung |
| **v1.1** | Payroll | Most requested; covers auto-journal API pattern end-to-end |
| **v1.2** | Cash Management (Kas Kecil) | Scope kecil, quick win |
| **v2.0** | Inventory + COGS | Kompleks (FIFO/Average valuation), tapi critical untuk trading/manufaktur |
| **v2.1** | Asset Management (Fixed Assets) | Depresiasi otomatis |
| **v2.2** | HR Management | Expand dari Payroll |
| **v3.0** | Investment Management | Niche — build kalau ada demand |

---

## 13. Struktur Repo 🟡 [PROPOSED]

**Monorepo** dengan Composer path repositories:

```
accounting-ecosystem/
├── apps/
│   ├── main-tier/              # Auth gateway (opsional)
│   ├── accounting/             # Second tier (hub)
│   └── payroll/                # Third tier example
├── packages/
│   ├── ecosystem-core/         # Hook system, shared contracts
│   ├── ecosystem-rbac/         # RBAC + authorization (dipakai semua app)
│   ├── ecosystem-audit/        # Audit log (dipakai semua app)
│   ├── ecosystem-ui/           # Shared Filament components
│   └── ecosystem-api-client/   # API client untuk inter-app comm
├── wordpress-plugin/           # SaaS licensing plugin
└── docs/
```

---

## 14. Decisions & Remaining Questions

### 14.1 Confirmed Decisions ✅

**Scope & Context:**
- [x] Target region: **Indonesia**
- [x] Target segmen: **UKM**
- [x] Platform: **Web only** (v1)
- [x] Bank integration: deferred, akan di-add nanti sebagai module
- [x] Multi-tenancy: **Database-per-tenant**
- [x] Tax scope v1: PPN + PPh, **tanpa e-Faktur** (deferred ke v2)
- [x] Hook system: **Laravel Events + Listeners** dengan naming convention WP-style (dipilih oleh Claude atas permintaan user)
- [x] Roadmap: Double-Entry → Payroll → Cash Management
- [x] Setup flow (section 7): **OK as proposed**
- [x] Laporan (section 10): **OK as proposed**

**RBAC & Roles (Section 5):**
- [x] RBAC approach (User × Role × App × Entity)
- [x] Permission system harus **flexible & extensible via UI** (section 5.8 ditambah)
- [x] Preset roles diperluas: **+Tax Officer, +Finance Manager, +Internal Auditor, +Cashier, +Owner/Direktur**
- [x] Warehouse Manager & Staff **ditunda** ke bersama Inventory app (v2.0)
- [x] Multi-entity: **no auto-inheritance**, explicit assignment per entity (opsi 1)
- [x] Role assignment dilakukan dari main-tier app, dengan visibility app/entity per env
- [x] Time-bound role assignment: **tidak di v1**, tapi schema ready untuk extend (kolom `valid_from`/`valid_until` nullable)
- [x] Fitur keamanan tambahan v1: audit assignment log, impersonation, scoped API token, period lock, entity isolation
- [x] Advanced security (IP whitelist, password policy, lockout, device fingerprint, dll) **deferred ke v2**, tapi hook-ready

**Extensibility (Section 6):**
- [x] 4 kontrak engineering (hooks, audit log, centralized auth, action classes) — **wajib v1**
- [x] Hook minimum set diterima; tambahan hook bisa di-add seiring waktu
- [x] Audit log field **sudah cukup** (no addition)
- [x] Audit log retention: **configurable per tenant, default 3 tahun** (plus warning UI kalau < 10 tahun untuk tenant tax-regulated)
- [x] Tidak ada fitur yang harus dipindah ke v1 dari future list
- [x] Tambahan fitur future yang **design-ready** (DB & primitives disiapkan sejak v1):
  - Bulk import/export dengan validation
  - Scheduled journal (depresiasi, recurring entries)
  - Document attachment per journal
  - Webhook outbound ke sistem eksternal

**Clarifications:**
- [x] 8.2 point 4 (bullet kosong "Setting Perusahaan"): = metadata perusahaan lengkap (nama, bentuk badan usaha, NPWP, NIB, SK, alamat, direksi, dll) — sudah di-fill
- [x] Tax e-Faktur: OK tanpa e-Faktur di v1, extend nanti

### 14.2 Remaining Questions

**Tidak ada — semua item sudah terjawab.** ✅

Item terakhir (dua bullet kosong di "Spesifikasi" original) sudah di-resolve di v0.6 dengan tambahan **section 4.7 System Spec / Non-Functional Requirements** (logging, caching, queue, deployment, monitoring, backup, performance target, i18n, browser support, accessibility, import/export).

### 14.3 Next Phase — Architecture & Coding

Spec sudah stabil. Next step:
1. **Generate high-level architecture plan** (komponen, interaksi, DB schema awal)
2. **Setup project skeleton** — monorepo + Laravel/Filament scaffolding + `ecosystem-core` package
3. **Build foundation** — hook system, audit log, RBAC package (dipakai semua app)
4. **Build Double-Entry app** (v1.0) dengan foundation di atas
5. **Build Payroll app** (v1.1)
6. **Build Cash Management app** (v1.2)

### 14.4 Parking Lot — Ditunda untuk Dibahas Nanti

Item yang sudah didiskusikan tapi keputusannya ditunda. Angkat kembali saat siap.

- **Plugin Security Model (Android-style capability-based permissions)** — Concept: plugin manifest declare permissions → admin review at install → runtime enforcement → per-permission grant/revoke. Meliputi: manifest format, permission categories (data.read/write, hooks, network, storage, sensitive.pii/financial/external), install-time UI, 4-layer runtime enforcement, audit dengan `plugin_id`, honest trade-off bahwa PHP tidak punya hard sandbox (mitigasi: static scan + signing + optional process isolation di v2+). **Status:** didiskusikan 2026-04-23, ditunda user. Angkat saat masuk fase module/plugin system design.

- **Webhook System — Detailed Design** — Sudah ditandai "design-ready" di section 6.2 (outbound webhook ke sistem eksternal) dan sudah ada queue `webhooks` di section 4.7.C. Yang masih perlu dibahas nanti:
  - **Outbound vs Inbound** — v1 focus outbound saja, atau sekaligus inbound (terima webhook dari sistem eksternal, misal bank, payment gateway)?
  - **Event subscription model** — Per-endpoint subscribe ke event specific (e.g. hanya `journal.after_post`), atau firehose?
  - **Security:**
    - HMAC signature untuk outgoing (HMAC-SHA256 dengan secret per-endpoint)
    - Signature verification untuk incoming
    - URL whitelist per tenant (cegah SSRF)
    - TLS-only (reject HTTP plain)
  - **Reliability:**
    - Retry policy (exponential backoff, max attempts, dead-letter queue)
    - Deduplication key (idempotency header)
    - Circuit breaker (kalau endpoint down terus, auto-disable temporarily)
  - **Payload:** JSON schema standard, schema versioning (`schema_version: 1.0`)
  - **Rate limiting** per-endpoint (cegah abuse)
  - **Audit** — setiap outbound/inbound webhook ter-log lengkap
  - **UI management** — admin bisa: add/edit/disable endpoint, lihat delivery log, manual retry, test-fire
  - **Integrasi dengan plugin security model** (parking lot item di atas) — plugin yang mau terima webhook perlu permission `network.inbound`

  **Status:** didiskusikan 2026-04-23, ditunda user. Angkat bersamaan dengan fase integrasi eksternal atau saat build module pertama yang butuh webhook.

### 14.5 Ecopa child-app registration and role ownership

- Before login, an installation without active integration shows a blocking
  wizard containing only Ecopa URL and Registration Token. Akunta derives its
  name, canonical slug `accounting`, base URL, callback, and standard webhook
  URL from server configuration.
- HTTP 200/202 from Ecopa leaves the installation pending. Ecopa completes the
  flow by sending a bootstrap-signed `app.registration.approved` callback to
  `{base_url}/webhooks/ecopa`, including generated SSO credentials and the
  exact redirect URI registered by Ecopa. Akunta keeps the permanent webhook
  secret it generated before submission, encrypts the SSO credentials, and only
  then writes `integration_status=on`. Rejection returns the wizard to a
  retryable state.
- Once active, the registration wizard disappears, Ecopa SSO starts, and the
  first Ecopa admin completes Akunta onboarding: initial entity, bookkeeping
  mode, COA, and period.
- This is a one-time installation onboarding flow. After completion, later
  Ecopa admins and users must enter the dashboard rather than repeat the
  wizard; newly created entities are configured through ordinary workspace and
  settings flows.
- Ecopa controls identity and the coarse app level (`admin` or `user`). Akunta
  controls the detailed Accounting role per entity. Admin Akunta can assign or
  clear a local role from Settings, and each change is audited.
- The Ecopa webhook is HMAC-verified and idempotent by `event_id`. Disabling,
  deleting, or revoking a user removes active access and credentials while
  preserving the local user row, journals, attachments, and audit attribution.
- The standard Akunta receiver is exactly `POST {APP_URL}/webhooks/ecopa` and
  accepts `app.registration.approved`, `app.registration.rejected`,
  `app.admin_bootstrap`, `app.access.granted`, `app.access.restored`,
  `app.access.revoked`, and the user lifecycle aliases.
  Registration callbacks use the Registration Token for HMAC verification;
  active user lifecycle events use the permanent webhook secret.
- A trusted `user.assigned` webhook provisions the local shadow user and an
  app-wide or entity-scoped assignment with `role_id=null`. Until an Admin
  Akunta selects a local role, a regular user has no Accounting action
  permissions and sees the global permission guidance screen on denied pages.
- Every Ecopa delivery attempt is written to `ecopa_webhook_logs`, including
  invalid signatures, rejected payloads, retryable dependencies, successful
  processing, and idempotent replay. The log stores only bounded operational
  metadata and subject identifiers; secrets, signature headers, and complete
  raw payloads are excluded. Successful idempotency receipts remain separate
  in `ecopa_webhook_receipts`.
- Admin Aplikasi can inspect the latest logs in Settings > Integration through
  the read-only `/api/v1/spa/ecopa-integration/webhook-logs` endpoint. Ecopa
  webhook logs have a 12-month retention period and are pruned by the existing
  scheduled webhook-log maintenance command.

---

## 15. Revision History

| Versi | Tanggal | Perubahan | Oleh |
|-------|---------|-----------|------|
| 0.1 | (awal) | Initial draft di Google Docs | User |
| 0.2 | 2026-04-23 | Consolidated + proposed additions | Claude (draft, perlu review user) |
| 0.3 | 2026-04-23 | Tambah section 5 (Security & RBAC) + section 6 (Extensibility Guarantees). Renumber section 5–13 lama → 7–15. Confirmed items di-check di Open Questions. | Claude |
| 0.4 | 2026-04-23 | Restructure section 14 Open Questions jadi 4 subsection (14.1 Adjustment Section 5, 14.2 Adjustment Section 6, 14.3 General Open Questions, 14.4 Confirmed Items) dengan format Q&A agar mudah dijawab user. | Claude |
| 0.5 | 2026-04-23 | Process jawaban user. Tambah section 0 (Scope Context). Lock hook system = Laravel Events + WP-style naming (4.5). Tambah 5 preset roles baru (5.5). Tambah section 5.7 Multi-Entity Role Behavior + section 5.8 Flexible Permission Management (UI-based). Lock audit retention default 3 tahun configurable (6.1). Update section 6.2 future features dengan status "design-ready" untuk 4 fitur yang user request. Fill 8.2 point 4 dengan metadata perusahaan detail. Mark multi-tenancy & roadmap sebagai CONFIRMED. Restructure section 14 jadi list confirmed decisions + 1 remaining question. | Claude |
| 0.6 | 2026-04-23 | Resolve remaining question (user answered A). Tambah section 4.7 "System Spec / Non-Functional Requirements" (logging, caching, queue, deployment target, monitoring, backup, performance target, i18n, browser support, accessibility, import/export). Section 14.2 closed. Tambah section 14.3 Next Phase. | Claude |

---

## 16. Session Resume Guide 📌

**Untuk melanjutkan project ini di sesi Claude Code baru:**

### Cepat (1 perintah)
Di sesi baru, jalankan:
```
Baca /Users/hendra/accounting-ecosystem-spec.md lalu lanjut dari section 14.3 (Next Phase)
```

Claude akan auto-load spec ini dan tahu konteks lengkapnya.

### File yang relevan
- `/Users/hendra/accounting-ecosystem-spec.md` — **spec lengkap, source of truth**
- `/Users/hendra/CLAUDE.md` — pointer memory file (akan dibuat di akhir sesi ini)
- Google Docs original: `https://docs.google.com/document/d/1BisqtS5yF1Yv7XcbPx0GnStKJ1S9dEeSZHn_fzwV6WI/edit`

### Status sesi saat ini (per 2026-04-23)
- ✅ Spec **v0.6 finalized**
- ✅ Semua scope decisions locked
- 🔜 Belum mulai: architecture plan & code
- 🔜 Next step: generate architecture plan → setup project skeleton

### Checkpoint untuk resume
Apa yang mau dilakukan berikutnya (pilih satu di sesi baru):
1. **Generate architecture plan** — high-level komponen, DB schema awal, sequence diagram
2. **Setup project skeleton** — monorepo + Laravel + Filament + `ecosystem-core` package
3. **Build foundation** — hook system, audit log, RBAC packages
4. **Re-review / edit spec** — kalau ada yang mau diubah

### Tips
- Kalau spec sudah panjang & banyak edit, bisa minta Claude update revision history di setiap perubahan besar
- Kalau mau split jadi file-file terpisah (misal `spec-rbac.md`, `spec-hooks.md`), minta Claude reorganize
- Kalau sudah mulai coding, buat `CLAUDE.md` di project root yang reference spec ini
