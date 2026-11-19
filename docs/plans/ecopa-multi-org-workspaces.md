# TODO — Workspace per organisasi Ecopa (multi-organisasi)

**Status:** TODO — belum dikerjakan (dicatat 2026-11-19 atas permintaan pemilik, untuk dikerjakan AI/kontributor berikutnya)
**Scope:** Membuat Akunta aman dan benar ketika dipakai user dari beberapa organisasi Ecopa, dengan tiga alur masuk standar.
**References (repo Ecopa, `C:\laragon\www\ecopa`):**
- `docs/CHILD_APP_INTEGRATION_STANDARD.md` — bagian "Applications shared by several organisations" dan "Tenant onboarding flows" (sekitar baris 658–680).
- `docs/akunta-multi-org-readiness-2026-11-18.md` — temuan review read-only Akunta (file:line), risiko, dan daftar perubahan.
- Halaman Ecopa **Developer › Child App Integration** (`/v2/developer/integration`) — section "Organisasi, aplikasi bersama, dan peran" dan "Alur masuk per organisasi (standar)"; Coding Agent Prompt di paling atas.
- Ecopa ADR-014/015/016 di `docs/architecture-decisions.md` (Platform Admin, aplikasi lintas organisasi, aplikasi dikelola Platform Admin).
- Akunta `docs/decisions.md` → "Locked 2026-11-19 — shared database multi-tenant (pooled); tenant = organisasi Ecopa"
  (model penyimpanan dan lapis isolasi yang wajib dipakai rencana ini).

---

## Latar belakang

Ecopa sekarang multi-organisasi. Setiap token/UserInfo, webhook `user.*` dan `app.access.*` (`subject.organization`),
`app.admin_bootstrap` (`admins[].organization`) dan company API membawa `organization {id, slug, name}`.
Aplikasi dipasang oleh Platform Admin untuk satu organisasi; dipakai organisasi kedua hanya setelah Platform Admin
menyalakan saklar **multi-organization ready** di Ecopa. Saklar itu **sengaja masih mati untuk Akunta** karena review
menemukan Akunta mengasumsikan satu organisasi per instance (`docs/decisions.md` ~:941 "Multi-organization roadmap
dropped") — admin SSO organisasi B akan bisa melihat buku organisasi A. Platform Admin Ecopa selalu menerima
`app_role = "admin"` untuk semua aplikasi.

## Alur standar yang wajib (keputusan pemilik)

1. **Data lama & Platform Admin** — User A (Platform Admin) / user lama yang sudah di-assign Akunta → buka Akunta →
   melihat **data Akunta yang sudah ada**. Migrasi satu kali: workspace/tenant yang ada dihubungkan ke `organization.id`
   organisasi default Ecopa (organisasi user-user yang selama ini di-assign). Jangan membuat workspace baru untuk mereka
   dan jangan memindahkan data ke organisasi lain.
2. **Organisasi baru — Organization Admin** — User B, Organization Admin organisasi baru (mis. PT ABA) → buka Akunta →
   bila belum ada workspace untuk `organization.id` itu → **wizard buat workspace baru**. Hanya untuk `app_role = "admin"`
   organisasi tersebut; workspace menyimpan `ecopa_organization_id`; User B menjadi admin workspace itu saja.
3. **Anggota organisasi** — User C, anggota PT ABA yang diberi akses oleh Organization Admin-nya → buka Akunta →
   **langsung ke workspace** organisasinya. Tidak bisa memilih/melihat workspace organisasi lain. Bila workspace belum
   dibuat → pesan "Workspace organisasi Anda belum disiapkan. Hubungi Organization Admin." (tanpa wizard).

## Migrasi data lama — wajib aman (permintaan pemilik 2026-11-19)

Saat Akunta dimigrasikan ke model multi-organisasi Ecopa v2, **tidak ada data lama yang boleh terhapus atau hilang
aksesnya**. Semua yang sudah ada otomatis menjadi milik **organisasi default Ecopa**:

- **Tenant/workspace & entity** yang ada → `ecopa_organization_id` = id organisasi default Ecopa (ambil dari klaim
  `organization.id` login Platform Admin/admin lama, atau konfigurasi eksplisit; jangan menebak dari email).
- **User lokal** yang ada (punya `main_tier_user_id`) → tetap aktif, terhubung ke organisasi default; tidak dibuat ulang,
  tidak di-reset password/role.
- **Role management** — `roles`, `user_app_assignments` (app-wide maupun per entity), shadow assignment dari Ecopa,
  dan permission kustom → dipertahankan apa adanya dan diberi scope tenant organisasi default (bukan dihapus lalu dibuat
  ulang). Admin lama tetap admin; anggota tetap dengan role/entity yang sama.
- Jurnal, laporan, attachment, partner, periode, dan histori audit tidak disentuh selain penambahan kolom organisasi.

Aturan pelaksanaan:
- Hanya migration **aditif** (kolom baru nullable → backfill → baru dibuat NOT NULL/unique bila perlu). Tanpa `DROP`,
  `TRUNCATE`, atau `DELETE` data bisnis. Migration harus **idempotent** (aman dijalankan ulang) dan punya `down()` yang
  tidak menghapus data.
- **Backup database sebelum migrasi** (lokal dan production) + catat cara restore; jalankan dulu di salinan data.
- Laporan hasil migrasi: jumlah tenant, entity, user, role, dan assignment **sebelum dan sesudah harus sama**; tidak ada
  baris tanpa organisasi.
- Test migrasi: fixture data lama (tenant + entity + user admin & anggota + role kustom + assignment + jurnal) → jalankan
  migrasi → semua tetap ada, terikat organisasi default, dan login SSO user lama/Platform Admin langsung ke data yang sama.

## Langkah kerja (dari temuan review; verifikasi ulang nomor baris)

- [ ] Backup DB + `tenants.ecopa_organization_id` (+ slug) secara aditif; backfill tenant, entity, user, role, dan
      assignment yang ada ke organisasi default Ecopa (lihat "Migrasi data lama"); laporan jumlah sebelum = sesudah.
- [ ] `apps/accounting/app/Http/Controllers/Auth/EcopaController.php::provisionUser` — baca klaim `organization`, simpan
      `organization.id` pada user/session; tolak login tanpa organisasi bila multi-org aktif.
- [ ] Scope admin SSO ke tenant organisasinya: `User::getTenants`, `canAccessTenant`, `accessibleEntityIdsFromTenantWide`,
      `AccountingPermissions::canManage`, `WorkspaceController::authorizeAdmin`/`managedTenantId`, `ResolvesTenant`
      (saat ini `isSsoAdmin()` = `session('ecopa.app_role') === 'admin'` berlaku global).
- [ ] Assignment app-wide (`user_app_assignments` dengan `entity_id` null) terikat tenant organisasi.
- [ ] Onboarding per organisasi (bukan satu flag global di `ecopa_config_integration`): wizard alur 2, pesan alur 3.
- [ ] Webhook (`EcopaWebhookController`): pakai `subject.organization` / `admins[].organization` untuk shadow assignment;
      hapus fallback `Tenant::query()->first()` (entity webhook & reconcile).
- [ ] Role Management: daftar user yang bisa di-assign hanya dari organisasi aktor (`RoleManagementController` ~:124).
- [ ] `EcopaReconcile`: ganti `/api/entities` & `/api/apps/{code}/assignments` (tidak ada di Ecopa) ke
      `/api/apps/{slug}/accesses` dan `/api/users`, kelompokkan per `organization.id`.
- [x] Catat keputusan baru di `docs/decisions.md` (membalik "multi-organization roadmap dropped") — dicatat 2026-11-19
      sebagai "shared database multi-tenant (pooled); tenant = organisasi Ecopa". Keputusan itu juga mewajibkan
      `tenant_id` di semua tabel milik tenant, foreign key berpasangan, `TenantContext`, dan Row-Level Security
      PostgreSQL; kerjakan bersama langkah-langkah di rencana ini (lihat "Urutan implementasi" di keputusan tersebut).
- [ ] Setelah semua hijau: minta Platform Admin Ecopa menyalakan **multi-organization ready** untuk Akunta.

## Acceptance criteria

1. Platform Admin dan user lama melihat data yang sudah ada tanpa wizard (alur 1).
2. Organization Admin organisasi tanpa workspace mendapat wizard; workspace baru terikat `organization.id` (alur 2).
3. Anggota langsung masuk workspace organisasinya; tanpa workspace → pesan, tanpa wizard (alur 3).
4. Test dua organisasi: admin/anggota organisasi B tidak dapat melihat atau mengubah entity, jurnal, user, atau role
   assignment organisasi A (UI, API SPA, header `X-Tenant-Slug`, webhook).
5. Perilaku single-organisasi tidak berubah (suite yang ada tetap hijau).
6. Migrasi data lama tanpa kehilangan data: jumlah tenant, entity, user, role, assignment, dan jurnal sama sebelum dan
   sesudah; semuanya terikat organisasi default; role management dan user lama berfungsi seperti sebelumnya; migrasi
   dapat dijalankan ulang tanpa duplikasi; backup + restore teruji.
