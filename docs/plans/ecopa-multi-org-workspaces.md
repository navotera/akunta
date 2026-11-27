# TODO — Workspace per organisasi Ecopa (multi-organisasi)

**Status:** TODO — belum dikerjakan (dicatat 2026-11-19 atas permintaan pemilik, untuk dikerjakan AI/kontributor berikutnya)
**Scope:** Membuat Akunta aman dan benar ketika dipakai user dari beberapa organisasi Ecopa, dengan tiga alur masuk standar.
**References (repo Ecopa, `C:\laragon\www\ecopa`):**
- `docs/CHILD_APP_INTEGRATION_STANDARD.md` — bagian "Applications shared by several organisations" dan "Tenant onboarding flows" (sekitar baris 658–680).
- `docs/akunta-multi-org-readiness-2026-11-18.md` — temuan review read-only Akunta (file:line), risiko, dan daftar perubahan.
- Halaman Ecopa **Developer › Child App Integration** (`/v2/developer/integration`) — section "Organisasi, aplikasi bersama, dan peran" dan "Alur masuk per organisasi (standar)"; Coding Agent Prompt di paling atas.
- Ecopa ADR-014/015/016 di `docs/architecture-decisions.md` (Platform Owner, aplikasi lintas organisasi, aplikasi dikelola Platform Owner).
- Akunta `docs/decisions.md` → "Locked 2026-11-19 — shared database multi-tenant (pooled); tenant = organisasi Ecopa"
  (model penyimpanan dan lapis isolasi yang wajib dipakai rencana ini).

---

## Latar belakang

Ecopa sekarang multi-organisasi. Setiap token/UserInfo, webhook `user.*` dan `app.access.*` (`subject.organization`),
`app.admin_bootstrap` (`admins[].organization`) dan company API membawa `organization {id, slug, name}`.
Aplikasi dipasang oleh Platform Owner untuk satu organisasi; dipakai organisasi kedua hanya setelah Platform Owner
menyalakan saklar **multi-organization ready** di Ecopa. Saklar itu **sengaja masih mati untuk Akunta** karena review
menemukan Akunta mengasumsikan satu organisasi per instance (`docs/decisions.md` ~:941 "Multi-organization roadmap
dropped") — admin SSO organisasi B akan bisa melihat buku organisasi A. Platform Owner Ecopa selalu menerima
`app_role = "admin"` untuk semua aplikasi.

## Alur standar yang wajib (keputusan pemilik)

1. **Data lama & Platform Owner** — User A (Platform Owner) / user lama yang sudah di-assign Akunta → buka Akunta →
   melihat **data Akunta yang sudah ada**. Migrasi satu kali: workspace/tenant yang ada dihubungkan ke `organization.id`
   organisasi default Ecopa (organisasi user-user yang selama ini di-assign). Jangan membuat workspace baru untuk mereka
   dan jangan memindahkan data ke organisasi lain.
2. **Organisasi baru — Organization Owner** — User B, Organization Owner organisasi baru (mis. PT ABA) → buka Akunta →
   bila belum ada workspace untuk `organization.id` itu → **wizard buat workspace baru**. Hanya untuk `app_role = "admin"`
   organisasi tersebut; workspace menyimpan `ecopa_organization_id`; User B menjadi admin workspace itu saja.
3. **Anggota organisasi** — User C, anggota PT ABA yang diberi akses oleh Organization Owner-nya → buka Akunta →
   **langsung ke workspace** organisasinya. Tidak bisa memilih/melihat workspace organisasi lain. Bila workspace belum
   dibuat → pesan "Workspace organisasi Anda belum disiapkan. Hubungi Organization Owner." (tanpa wizard).

## Migrasi data lama — wajib aman (permintaan pemilik 2026-11-19)

Saat Akunta dimigrasikan ke model multi-organisasi Ecopa v2, **tidak ada data lama yang boleh terhapus atau hilang
aksesnya**. Semua yang sudah ada otomatis menjadi milik **organisasi default Ecopa**:

- **Tenant/workspace & entity** yang ada → `ecopa_organization_id` = id organisasi default Ecopa (ambil dari klaim
  `organization.id` login Platform Owner/admin lama, atau konfigurasi eksplisit; jangan menebak dari email).
- **User lokal** yang ada (punya `main_tier_user_id`) → tetap aktif, terhubung ke organisasi default; tidak dibuat ulang,
  tidak di-reset password/role.
- **Role management** — `roles`, `user_app_assignments` (app-wide maupun per entity), shadow assignment dari Ecopa,
  dan permission kustom → dipertahankan apa adanya dan diberi scope tenant organisasi default (bukan dihapus lalu dibuat
  ulang). Admin lama tetap admin; anggota tetap dengan role/entity yang sama.
- Jurnal, laporan, attachment, partner, periode, dan histori audit tidak disentuh selain penambahan kolom organisasi.

Runbook lengkap (tahapan, daftar tabel milik tenant, query verifikasi, rollback): `docs/ecopa-multi-org-migration.md`.
Prompt agent tahap 1 sampai rilis (satu tahap per putaran): `docs/plans/ecopa-multi-org-agent-prompt.md`.
Keputusan: `docs/decisions.md` → "migrasi data v1 ke v2 (multi-organisasi) tanpa kehilangan data" dan "Platform Owner,
Organization Owner, dan Member di Akunta".

Aturan pelaksanaan:
- Hanya migration **aditif** (kolom baru nullable → backfill → baru dibuat NOT NULL/unique bila perlu). Tanpa `DROP`,
  `TRUNCATE`, atau `DELETE` data bisnis. Migration harus **idempotent** (aman dijalankan ulang) dan punya `down()` yang
  tidak menghapus data.
- **Backup database sebelum migrasi** (lokal dan production) + catat cara restore; jalankan dulu di salinan data.
- Laporan hasil migrasi: jumlah tenant, entity, user, role, dan assignment **sebelum dan sesudah harus sama**; tidak ada
  baris tanpa organisasi.
- Test migrasi: fixture data lama (tenant + entity + user admin & anggota + role kustom + assignment + jurnal) → jalankan
  migrasi → semua tetap ada, terikat organisasi default, dan login SSO user lama/Platform Owner langsung ke data yang sama.

## Pengaturan level platform hanya untuk Platform Owner (permintaan pemilik 2026-11-19)

Ecopa kini mengirim klaim `platform_admin` di ID token dan UserInfo (`true` hanya untuk Platform Owner Ecopa; Organization
Admin juga mendapat `app_role = "admin"`, jadi jangan membedakan dari `app_role`). Di Akunta:

- Menu dan endpoint pengaturan instance — **setup Cron/scheduler**, email keluar, queue/worker, integrasi Ecopa (client,
  webhook, secret, reconcile, onboarding instalasi), retensi log, backup, feature flag, konfigurasi server — hanya tampil
  dan hanya diterima bila `platform_admin` true (simpan klaim di session saat login, cek di backend, bukan hanya UI).
- Organization Owner hanya mengatur workspace organisasinya: anggota, role, entity, dan pengaturan bisnis/akuntansi.
- Test: Organization Owner tidak melihat menu tersebut dan mendapat 403 saat memanggil endpoint-nya langsung; Platform
  Admin bisa.

## Langkah kerja (dari temuan review; verifikasi ulang nomor baris)

- [x] Backup DB + `tenants.ecopa_organization_id` (+ slug) secara aditif; backfill tenant, entity, user, role, dan
      assignment yang ada ke organisasi default Ecopa (lihat "Migrasi data lama"); laporan jumlah sebelum = sesudah.
      *Selesai 2026-11-19 (alur 1):* migration `modules/rbac/.../2026_11_19_000100_link_tenants_to_ecopa_organizations.php`
      menambah kolom organisasi pada `tenants` dan `user_app_assignments.tenant_id` (backfill). Tenant lama ditautkan ke
      organisasi default oleh login Platform Owner pertama (klaim `platform_admin` + `organization`) atau oleh
      `php artisan ecopa:link-organization <organization_id> [--slug=] [--tenant=] [--dry-run]`, yang mencetak jumlah
      sebelum/sesudah. Layanan: `App\Services\EcopaOrganizationLinker`. Test:
      `apps/accounting/tests/Feature/Auth/EcopaOrganizationLinkTest.php`. Backup database tetap langkah operator.
      Perbaikan terkait: `ResolvesTenant` kini menerima admin Ecopa tanpa assignment lokal (sebelumnya Platform Owner
      melihat daftar workspace tetapi semua endpoint SPA mengembalikan 422 "Tenant is not accessible").
- [~] `apps/accounting/app/Http/Controllers/Auth/EcopaController.php::provisionUser` — baca klaim `organization`, simpan
      `organization.id` pada user/session; tolak login tanpa organisasi bila multi-org aktif.
      *Sebagian 2026-11-19:* klaim `organization` dan `platform_admin` disimpan di session (`ecopa.organization`,
      `ecopa.platform_admin`). Setelah satu organisasi tertaut, login organisasi lain ditolak 403 (sementara, sampai
      alur 2–3 dibuat); login tanpa klaim `organization` masih diterima seperti sebelumnya.
- [x] Scope admin SSO ke tenant organisasinya (2026-11-27, tahap 5): `User::getTenants`, `canAccessTenant`, `accessibleEntityIdsFromTenantWide`,
      `AccountingPermissions::canManage`, `WorkspaceController::authorizeAdmin`/`managedTenantId`, `ResolvesTenant`
      (saat ini `isSsoAdmin()` = `session('ecopa.app_role') === 'admin'` berlaku global).
- [x] Assignment app-wide (`user_app_assignments` dengan `entity_id` null) terikat tenant organisasi (2026-11-27).
- [ ] Onboarding per organisasi (bukan satu flag global di `ecopa_config_integration`): wizard alur 2, pesan alur 3.
- [x] Webhook (`EcopaWebhookController`) (2026-11-27): pakai `subject.organization` / `admins[].organization` untuk shadow assignment;
      hapus fallback `Tenant::query()->first()` (entity webhook & reconcile).
- [x] Role Management (2026-11-27): daftar user yang bisa di-assign hanya dari organisasi aktor (`RoleManagementController` ~:124).
- [x] `EcopaReconcile` (2026-11-27): ganti `/api/entities` & `/api/apps/{code}/assignments` (tidak ada di Ecopa) ke
      `/api/apps/{slug}/accesses` dan `/api/users`, kelompokkan per `organization.id`.
- [x] Catat keputusan baru di `docs/decisions.md` (membalik "multi-organization roadmap dropped") — dicatat 2026-11-19
      sebagai "shared database multi-tenant (pooled); tenant = organisasi Ecopa". Keputusan itu juga mewajibkan
      `tenant_id` di semua tabel milik tenant, foreign key berpasangan, `TenantContext`, dan Row-Level Security
      PostgreSQL; kerjakan bersama langkah-langkah di rencana ini (lihat "Urutan implementasi" di keputusan tersebut).
- [ ] Simpan klaim `platform_admin` di session; batasi menu & endpoint pengaturan level platform (setup Cron, email,
      queue, integrasi Ecopa, log, backup, feature flag) ke Platform Owner — lihat bagian "Pengaturan level platform".
- [ ] Rekonsiliasi identitas Akunta ↔ Ecopa sebelum migrasi (`sub` → organisasi default); hentikan bila ada user
      organisasi lain (runbook tahap 0).
- [x] `tenant_id` di seluruh tabel milik tenant (runbook §5) secara aditif + composite foreign key (runbook tahap 3).
      *Selesai 2026-11-27 (tahap 4 prompt agent):* lihat bagian "Tahap 4" di bawah.
- [x] Perintah read-only `ecopa:migration-report` (snapshot + pembanding runbook §4). *Selesai 2026-11-19:*
      `App\Services\EcopaMigrationReport`, `EcopaMigrationReportCommand`, test
      `tests/Feature/Tenancy/EcopaMigrationReportTest.php` (11 test lulus bersama 11 test penautan). Daftar tabel
      milik tenant di laporan mengikuti runbook §5; tabel yang belum punya `tenant_id` tampil `pending_column`.
- [x] Perluas `counts()` di `EcopaOrganizationLinker` ke tabel tahap 3 (tanpa log append-only).
- [x] Test migrasi fixture v1 lengkap dijalankan dua kali (runbook §7): `TenantColumnMigrationTest`.
- [ ] Peran PO/OO/Member sesuai keputusan 2026-11-19: menu & endpoint PO-only, template COA per organisasi + bawaan,
      API token/webhook per organisasi, branding global PO, wizard PO dan wizard OO, audit tingkat platform.
- [x] Kontrak Ecopa: `platform_admin` di payload `user.updated`; Akunta memutus sesi saat nilainya berubah.
      *Selesai 2026-11-26 (tahap 1 prompt agent):* kolom `users.ecopa_platform_admin` (migration
      `modules/rbac/.../2026_11_26_000100_add_ecopa_platform_admin_to_users_table.php`), sinkronisasi di semua event
      `user.*` pada `EcopaWebhookController`, nilai diperbarui saat login SSO, `UserAccessRevoker::endLoginSessions`,
      dan middleware web `EndStalePlatformOwnerSession` untuk sesi di luar database (redis). Lihat keputusan PO/OO
      butir 8. Test: `apps/accounting/tests/Feature/Webhooks/EcopaPlatformAdminWebhookTest.php` (14 test, 71
      assertion) + assertion login di `EcopaOrganizationLinkTest.php`.
- [ ] Setelah semua hijau: minta Platform Owner Ecopa menyalakan **multi-organization ready** untuk Akunta.

## Tahap 2 — batas Platform Owner vs Organization Owner (prompt agent)

**2a selesai 2026-11-26.** `App\Support\PlatformOwner::check()` menjadi satu-satunya sumber status PO.
- Mode Ecopa (`integration_status = on`): PO hanya bila `session('ecopa.platform_admin') === true` **dan**
  `users.ecopa_platform_admin === true`. `app_role`, preset `super_admin`, dan nama role tidak pernah menjadikan PO;
  sesi tanpa klaim = non-PO.
- Mode standalone (integrasi belum `on`): PO = super admin instalasi, yaitu assignment Accounting aktif yang berlaku
  untuk seluruh aplikasi (`entity_id` NULL) dengan role preset global `super_admin`, seperti yang diberikan oleh onboarding
  instalasi, `SuperAdminSeeder`, atau `akunta:set-superadmin`. **Perlu konfirmasi pemilik (P1).**
- Selalu non-PO saat impersonation, saat user nonaktif, dan untuk tamu.
- Test: `apps/accounting/tests/Feature/Auth/PlatformOwnerTest.php` (19 test). Helper ini **belum dipakai** oleh endpoint
  mana pun; penerapannya (2c–2g) menunggu persetujuan inventaris di bawah.

**2c–2g selesai 2026-11-26.** Pemilik menyetujui inventaris di bawah beserta semua usulan default (P1, A1–A5).
Implementasi dan alasannya dicatat di `docs/decisions.md` (keputusan PO/OO/Member, butir "Diimplementasikan
2026-11-26 (tahap 2…)") dan `docs/permission-management.md`. Bukti:
- Backend: middleware `platform.owner`, `PlatformAuditLogger` + tabel `platform_audit_log`, A2 di
  `EcopaIntegrationController`, kode katalog A1 dihapus (override lama difilter di `AccountingPermissions::roleCodes`).
- Frontend: `is_platform_admin` di bootstrap; menu Cron, Integration, Fake Data, tombol Hapus Permanen, dan wizard
  instalasi memakai `isPlatformOwner()`.
- Test: `tests/Feature/Auth/PlatformOwnerBoundaryTest.php` (63 test: setiap endpoint PO ditolak untuk OO, sesi tanpa
  klaim, role kustom berizin penuh, dan impersonation; PO lolos; endpoint OO tetap terbuka; standalone; A2; audit
  append-only/redaksi) serta penyesuaian test lama yang sebelumnya menganggap admin Ecopa = pengelola instalasi.

### 2b — Inventaris (disetujui 2026-11-26)

Semua route di bawah memakai prefix `/api/v1/spa` dan middleware `web`, `auth:sanctum`, `EnforceSpaPermissions`, kecuali
yang ditandai *publik*. "Izin saat ini" adalah pemeriksaan yang berlaku hari ini.

**Usulan: hanya PO**

| # | Route | Controller | Izin saat ini | Menu / halaman SPA | Usulan |
|---|---|---|---|---|---|
| 1 | `GET scheduler/status` | `SchedulerHealthController@index` | permission katalog `settings.cron.manage` (per workspace; default Supervisor/Operator tidak) | Settings › Cron & Scheduler (`canManageCron`) | PO |
| 2 | `GET ecopa-integration` | `EcopaIntegrationController@show` | **hanya login** (tanpa permission) | Settings › Integration | PO |
| 3 | `GET ecopa-integration/webhook-logs` | `EcopaWebhookLogController@index` | `isSsoAdmin()` atau `workspace.manage` | Settings › Integration (log webhook Ecopa) | PO |
| 4 | `GET installation-onboarding/status`, `POST installation-onboarding/entity` | `InstallationOnboardingController` | `isSsoAdmin()` (OO juga lolos) | `/onboarding` (wizard instalasi; `EcopaController::successRedirect`) | PO (2g) |
| 5 | `GET fake-data`, `GET fake-data/reset-preview`, `POST fake-data/reset` | `FakeDataController` | `settings.fake_data.manage` | Settings › Fake Data & Pemulihan (hanya di PT. Fake Data) | PO (dataset native) |
| 6 | `DELETE workspaces/{id}/permanent` | `WorkspaceController@purge` | `isSsoAdmin()` atau `workspace.manage` | Settings › Workspace › tab Arsip › hapus permanen | PO (purge workspace) |

**Ambigu, perlu keputusan**

| # | Route / hal | Kondisi saat ini | Pertanyaan |
|---|---|---|---|
| A1 | Permission katalog `settings.cron.manage` dan `settings.fake_data.manage` | Bisa diberikan ke role kustom lewat Role Management | Setelah #1 dan #5 menjadi PO, kode ini tidak berguna lagi. Hapus dari katalog (berdampak pada Restore to Default dan override yang mencantumkannya), atau biarkan tanpa efek? |
| A2 | *Publik* `GET auth/integration-status`, `POST auth/ecopa-registration`, `POST auth/ecopa-registration/cancel` | Tanpa login. Layanan menolak registrasi ulang bila integrasi `on`/`pending`, tetapi **siapa pun dapat membatalkan registrasi yang sedang `pending`** | Usulan: status tetap publik (dipakai halaman login); register/cancel tanpa login hanya selama integrasi belum dikonfigurasi, setelah itu wajib PO. Setuju? |
| A3 | `POST fake-data/import-all`, `POST fake-data/{group}/import`, `DELETE fake-data/{group}` | `settings.fake_data.manage`; untuk entity biasa hanya impor COA dan user impersonation | Ini data latihan per workspace. PO (ikut #5) atau OO? |
| A4 | `POST workspaces/{id}/logo` | `workspace.manage`; logo tersimpan **per workspace** | Keputusan menyebut branding satu untuk instalasi (PO). Ini logo entitas atau branding aplikasi? Usulan: tetap OO sekarang, diputuskan di tahap 6. |
| A5 | Audit tingkat platform (2f) | Tidak ada tabel tanpa kewajiban `tenant_id`; `audit_log` akan wajib `tenant_id` di tahap 4 | Usulan: tabel baru aditif `platform_audit_log` (aktor, aksi, waktu, nilai disamarkan untuk secret). Setuju? |

**Belum ada endpoint HTTP (tidak ada yang perlu dikunci sekarang).** Email keluar, queue/worker, backup, feature flag,
branding global, dan retensi log (`CronSetting.retention_days` tanpa endpoint) belum punya UI/API. Reconcile
(`ecopa:reconcile`), link organisasi, `akunta:set-superadmin`, `IssueApiToken`, prune, dan purge terjadwal hanya berupa
perintah artisan untuk operator server. Bila nanti dibuat UI, wajib memakai `PlatformOwner`.

**Tetap OO (harus tidak ikut terkunci ke PO):** `workspaces` index/store/update/destroy/restore, `periods/*`,
`permission-management/*`, `role-management/*` (termasuk impersonate), `webhooks/*`, `auto-mapping/*`,
`recurring-journals/*`, `journal-templates/*`, `onboarding/bookkeeping-mode` dan `onboarding/apply-coa`,
`documentation-notes/*`, serta pengaturan workspace (General/`issue_report_url`, Format Kode, Entity Profile) melalui
`PATCH workspaces/{id}`. Belum ada UI SPA untuk API token (hanya `IssueApiToken`); bila dibuat, itu milik OO.

**Temuan di luar batas PO/OO (dicatat, belum diubah):**
- F1 — `webhooks/*` SPA (`WebhookSubscriptionController`) **tidak memeriksa permission apa pun** selain resolusi
  workspace, dan menampilkan subscription global (`entity_id` NULL) ke semua workspace. Perlu permission dan scoping
  tenant (lihat juga tahap 5).
- F2 — Sesi user yang dinonaktifkan Ecopa hanya diputus lewat tabel `sessions`; dengan `SESSION_DRIVER=redis` sesi
  lama tetap hidup (lihat tahap 1).

**Frontend yang akan disesuaikan di 2d:** `routes/settings/+page.svelte` (section `cron`, `integration`, `fake-data`,
tombol hapus permanen workspace), `routes/onboarding`, `lib/stores/permissions.svelte.ts`, dan payload
`AuthController::userPayload` (tambah `is_platform_admin`).

## Tahap 3 — log penggunaan untuk PO (prompt agent)

**Selesai 2026-11-26.** Metrik dan default Q1–Q3 disetujui pemilik; keputusan di `docs/decisions.md` (butir "Diimplementasikan
2026-11-26 (tahap 3…)").
- Backend: `GET /api/v1/spa/platform/usage` (`platform.owner`), `App\Services\PlatformUsageReport` (agregat saja),
  migration aditif `ecopa_webhook_logs.ecopa_organization_id`, pengisian di `LogEcopaWebhook`, audit
  `platform.usage_viewed`.
- Frontend: `PlatformUsagePanel.svelte` di Settings › Integration, hanya dirender untuk PO.
- Test: `tests/Feature/Auth/PlatformUsageTest.php` (11 test: agregat dua organisasi, tidak ada isi transaksi/PII,
  OO/tanpa klaim/impersonation 403, tamu 401, audit, atribusi organisasi webhook), endpoint ditambahkan ke dataset
  `PlatformOwnerBoundaryTest`, e2e `ecopa-webhook-logs.spec.ts` (PO melihat panel; OO tidak melihat dan tidak memanggil).
- Catatan: atribusi per organisasi memakai `entities.tenant_id` / `user_app_assignments.tenant_id`; beralih ke
  `tenant_id` langsung pada tabel jurnal/audit setelah tahap 4. Log webhook sebelum migration tidak punya organisasi.

## Tahap 4 — `tenant_id` di seluruh tabel milik tenant (prompt agent)

**Selesai 2026-11-27.** Daftar tabel dan lima usulan untuk tabel ambigu disetujui pemilik; keputusan dan alasannya di
`docs/decisions.md` (butir "Diimplementasikan 2026-11-27 (tahap 4…)").
- Migration: `apps/accounting/database/migrations/2026_11_27_000100`–`000600` (workspace, pajak/dimensi, integrasi/catatan,
  anak jurnal, webhook/token, audit_log). Langkah bersama: `App\Support\Tenancy\TenantColumnMigrator`; peta induk:
  `App\Support\Tenancy\TenantOwnership`.
- Model: trait `App\Models\Concerns\BelongsToTenant` pada 22 model; `AuditLog` lewat listener di `AppServiceProvider`.
- Laporan: `EcopaMigrationReport::TENANT_OWNED_TABLES` (tanpa `personal_access_tokens`, yang kini global),
  pemeriksaan tenant terhadap induk per tabel; `EcopaOrganizationLinker::counts()` diperluas.
- Test: `tests/Feature/Tenancy/TenantColumnMigrationTest.php` (v1 lengkap dua kali, berhenti tanpa menebak, trait,
  ketidaksesuaian tenant) ditambah tes khusus PostgreSQL (NOT NULL dan composite FK menolak baris lintas tenant).
- Belum: scope query per tenant (`TenantContext`) dan RLS (tahap 5 dan 9); gladi pada salinan production (tahap 10).

## Tahap 5 — TenantContext dan scoping per organisasi (prompt agent)

**Selesai 2026-11-27.** Rincian keputusan: `docs/decisions.md` (butir "Diimplementasikan 2026-11-27 (tahap 5…)").
- Konteks dan scope: `modules/rbac/src/Support/TenantContext.php`, `Scopes/{TenantScope,AssignmentTenantScope,SharedOrTenantScope}.php`
  pada `Entity`, `UserAppAssignment`, `Role`, dan trait `BelongsToTenant` untuk 22 model bisnis; penentuan tenant dari identitas:
  `App\Support\Tenancy\RequestTenant`, middleware `EstablishTenantContext`, `ApiTokenAuth`.
- Akses: `App\Models\User`, `AccountingPermissions::canManage`, `ResolvesTenant`, `RoleManagementController` (urutan deterministik).
- Webhook dan reconcile: `EcopaWebhookController`, `UserAccessRevoker`, `EcopaReconcile` (ditulis ulang).
- Terjadwal dan antrean: `EachTenant`, empat perintah terjadwal, `RunInTenant` + tiga job; namespace: migration `000700`
  (idempotency), prefix path lampiran, `TenantCache`.
- Test: `tests/Feature/Tenancy/TenantContextTest.php` (17 test: scope model, konteks dari organisasi, header lintas organisasi
  ditolak, sesi organisasi tanpa tenant, assignment app-wide per tenant, hak admin per organisasi, Role Management, webhook,
  reconcile, token API, idempotency, perintah terjadwal, job, cache). Suite Accounting penuh hijau pada SQLite dan PostgreSQL.
- Belum (tahap berikutnya): wizard OO dan pesan Member (tahap 7); tes isolasi dua organisasi menyeluruh (tahap 8) yang menjadi
  syarat menghapus aturan sementara "hanya satu organisasi tertaut" di login; RLS (tahap 9).

## Acceptance criteria

1. Platform Owner dan user lama melihat data yang sudah ada tanpa wizard (alur 1).
2. Organization Owner organisasi tanpa workspace mendapat wizard; workspace baru terikat `organization.id` (alur 2).
3. Anggota langsung masuk workspace organisasinya; tanpa workspace → pesan, tanpa wizard (alur 3).
4. Test dua organisasi: admin/anggota organisasi B tidak dapat melihat atau mengubah entity, jurnal, user, atau role
   assignment organisasi A (UI, API SPA, header `X-Tenant-Slug`, webhook).
5. Perilaku single-organisasi tidak berubah (suite yang ada tetap hijau).
6. Migrasi data lama tanpa kehilangan data: jumlah tenant, entity, user, role, assignment, dan jurnal sama sebelum dan
   sesudah; semuanya terikat organisasi default; role management dan user lama berfungsi seperti sebelumnya; migrasi
   dapat dijalankan ulang tanpa duplikasi; backup + restore teruji.
