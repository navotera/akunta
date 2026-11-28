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

## Tahap 6 — template COA per organisasi dan branding global (prompt agent)

**Selesai 2026-11-27.** Keputusan pemilik atas tiga pertanyaan (branding, cara mengubah template, izin) dan rinciannya ada di
`docs/decisions.md` (butir "Diimplementasikan 2026-11-27 (tahap 6…)").
- Template: migration `2026_11_28_000100` (`coa_templates`, `coa_template_accounts`), model `CoaTemplate`/`CoaTemplateAccount`,
  `CoaTemplateRegistry` (bawaan + organisasi, kunci `org:{id}`), `ApplyCoaTemplateAction`, `CoaTemplateController`,
  `coa_template_key` di `POST workspaces`; UI: Settings > Template Bagan Akun, tombol "Simpan sebagai template" di Bagan Akun,
  pilihan template di form Workspace Baru.
- Branding: migration `2026_11_28_000200` (`installation_settings`, global), `BrandingController`
  (`GET /api/auth/branding` publik, `POST /api/v1/spa/platform/branding` PO), UI: Settings > Branding (PO), nama dan logo di shell
  dan halaman login. Nama/logo entitas tetap milik OO.
- Test: `OrganizationCoaTemplateSpaApiTest` (6), `BrandingSpaApiTest` (5), endpoint branding di dataset `PlatformOwnerBoundaryTest`,
  e2e `branding-and-coa-templates.spec.ts` (4). Suite Accounting penuh: 705 lulus pada SQLite; test baru dan Tenancy/Auth lulus
  pada PostgreSQL.

## Tahap 7 — alur masuk per organisasi (prompt agent)

**Selesai 2026-11-27.** Rincian: `docs/decisions.md` (butir "Diimplementasikan 2026-11-27 (tahap 7…)").
- Backend: `OrganizationEntryState`, `OrganizationOnboardingController`, `EstablishTenantContext` (sesi organisasi tanpa
  tenant), `InstallationOnboardingService` per tenant + migration `2026_11_29_000100`, `organization` di `/me`,
  flag `tenancy.multi_organization` (default mati) pada `EcopaOrganizationLinker`.
- Frontend: `/onboarding` memakai ulang wizard yang ada (OO membuat workspace organisasi, PO tetap lewat endpoint instalasi),
  layout mengarahkan OO ke wizard dan menampilkan pesan Member, dashboard tidak menanyakan status instalasi untuk OO
  tanpa tenant.
- Test: `tests/Feature/Tenancy/OrganizationEntryFlowsTest.php` (7: tiga alur, wizard tidak muncul untuk organisasi yang sudah
  punya tenant, flag mati tetap menolak organisasi kedua, tanpa database fisik, migrasi flag lama), e2e
  `organization-entry-flows.spec.ts` (3). Suite Accounting penuh: 712 lulus pada SQLite; test terkait lulus pada PostgreSQL.
- Belum: tes isolasi dua organisasi menyeluruh (tahap 8) sebelum `AKUNTA_MULTI_ORGANIZATION` boleh dinyalakan.

## Tahap 8 — tes isolasi dua organisasi (prompt agent, gerbang keamanan)

**Selesai 2026-11-27.** Rincian bukti, temuan, dan pengecualian: `docs/decisions.md` (butir "Diimplementasikan 2026-11-27
(tahap 8…)").
- Tes: `TwoOrganizationIsolationTest` (sapuan 128 route SPA: owner/member/inspector B dan PO organisasi lain),
  `TwoOrganizationChannelsTest` (token API 24 route, webhook Ecopa, dispatch webhook, job, perintah terjadwal, lampiran,
  cache, Role/Permission Management, regresi webhook dan pagar tulis), fixture dan helper di `Concerns_two_organizations.php`.
- Perbaikan yang ditemukan: validasi entity dan tenant pada `POST /api/v1/webhooks` (token B tidak lagi dapat memasang
  subscription di workspace A), `WebhookDispatcher` per tenant, pagar tulis lintas tenant di `BelongsToTenant`.
- Acceptance criteria 4 (organisasi B tidak dapat melihat atau mengubah entity, jurnal, user, atau role assignment organisasi
  A lewat UI/API SPA, header `X-Tenant-Slug`, token, webhook) terbukti oleh tes di atas.
- Suite Accounting penuh: 725+ lulus pada SQLite; PostgreSQL disposable lulus.
- Sisa sebelum rilis: RLS (tahap 9), gladi migrasi dan penyalaan `AKUNTA_MULTI_ORGANIZATION` (tahap 10).

## Tahap 9 — PostgreSQL Row-Level Security (prompt agent)

**Selesai 2026-11-27, opt-in dan mati secara default.** Runbook: `docs/row-level-security.md`; keputusan dan bukti:
`docs/decisions.md` (butir "Diimplementasikan 2026-11-27 (tahap 9…)").
- `akunta:rls enable|disable|status [--dry-run]` memasang policy di 29 tabel; `AKUNTA_RLS=true` menyalakan lapisan aplikasi
  (`TenantAwarePostgresConnection`, setting transaksi-lokal `app.tenant_id`). Pintu lintas tenant hanya
  `TenantContext::platformRead()` / `without()` (hanya-baca).
- Tes: `RowLevelSecurityTest` (role non-owner) dan mode diagnostik seluruh suite `AKUNTA_RLS_TEST_HTTP=1`.
- Tidak didukung saat RLS menyala: penautan legacy saat login PO (gunakan `ecopa:link-organization` dengan role owner lebih
  dulu) dan grant webhook tanpa tenant pada instalasi kosong.
- Production hanya atas perintah pemilik. Sisa: gladi migrasi dan penyalaan `AKUNTA_MULTI_ORGANIZATION` (tahap 10).

## Tahap 10 — gladi migrasi dan rilis (prompt agent)

**Gladi selesai 2026-11-27; eksekusi production BELUM dilakukan dan hanya atas perintah eksplisit pemilik.** Checklist
rilis: `docs/release-readiness-multi-org.md`. Prosedur: `docs/ecopa-multi-org-migration.md` §4a.

Laporan gladi (PostgreSQL 16 sekali pakai; DB v1 dibuat dengan build v1 asli `b05f1df`: 1 tenant, 3 entity, 8 user
(PO, admin lama, Supervisor, Operator, Inspector, nonaktif, akun lokal tanpa identitas Ecopa, tanpa akses), 9 assignment,
15.144 jurnal dan 30.288 entry di kedua buku dan semua status, 48 lampiran berkas nyata, koreksi fiskal, template,
recurring, webhook, token API, audit). Perintah: `bash scripts/migration-rehearsal.sh`.

| Langkah | Hasil | Durasi |
|---|---|---|
| `pg_dump -Fc` (928.732 byte, SHA-256 dicatat di log) | lulus | 0,6 s |
| Uji restore ke database sekali pakai (12 tabel kunci) | jumlah baris identik | 9,1 s |
| Snapshot sebelum: `ecopa:migration-report` dan laporan dengan build v1 | lulus | 1,4 s + 2,0 s |
| Maintenance mode on | lulus | 0,9 s |
| `migrate --force` (seluruh migration v2) | lulus | 7,3 s |
| `ecopa:link-organization --dry-run` / nyata | lulus (1 tenant tertaut ke `org-default`) | 1,1 s / 1,3 s |
| `ecopa:migration-report --compare --check-files` | tidak ada perbedaan; 48 lampiran, 0 hilang | 1,5 s |
| Laporan Neraca Saldo, Neraca, Laba Rugi per entity, buku Intern dan Fiskal | **identik** dengan v1 (3 entity) | 2,4 s |
| Ulang `migrate`, `link`, `compare` | tanpa perubahan | 1,1 s, 1,1 s, 1,5 s |
| Smoke test per jenis user (`tests/Rehearsal`, 5 test, 49 asersi) | lulus | 3,7 s |
| Maintenance mode off | lulus | 0,9 s |

Selisih: nol. Kontrol negatif: pembanding laporan memberi exit 1 pada as-of yang berbeda; pembanding migrasi memberi
exit 1 bila total uang atau jurnal berubah (test `EcopaMigrationReportTest`).

Temuan gladi dan perbaikan:
1. `ecopa:migration-report --compare` menggagalkan migrasi yang benar karena empat tabel yang dibuat migration itu
   sendiri (`coa_templates`, `coa_template_accounts`, `installation_settings`, `platform_audit_log`) tidak ada di snapshot
   sebelum. Kini tabel baru yang masih kosong bukan selisih; tabel baru yang berisi tetap dilaporkan (test baru).
2. Fixture gladi pertama tidak mencatat flag onboarding v1 (`installation_onboarding_completed_at`), sehingga admin lama
   tampil `needs_setup`. Itu artefak fixture, bukan regresi: instalasi v1 yang sudah dipakai memiliki flag itu (atau
   backfill `2026_08_31_000760`), dan migration tahap 7 menyalinnya ke tenant. Fixture diperbaiki; smoke test kini memastikan
   Platform Owner dan admin lama tidak melihat `needs_setup` atau `needs_workspace`.
3. Alat gladi: `scripts/migration-rehearsal.sh`, `scripts/report-snapshot.php` (juga membaca build v1 lewat `--root`),
   `tests/Rehearsal/PostMigrationSmokeTest.php` (di luar suite biasa; hanya-baca, dibatalkan lewat transaksi).
   Klaim Platform Owner disimulasikan di dalam transaksi seperti login pertama setelah rilis.
4. Pada beberapa run awal koneksi ke database gladi diputus oleh proses lain di klaster yang sama
   ("terminating connection due to administrator command"); pelakunya tidak teridentifikasi. Gladi diulang pada database
   dengan nama unik dan lulus. Bukan perilaku aplikasi.

Belum dibuktikan pada gladi pertama: data production sungguhan, volume production, dan login SSO nyata terhadap Ecopa
(smoke test memakai sesi yang disimulasikan). Dua hal terakhir ditutup oleh bagian berikut; data production tetap belum.

### Tahap 10 lanjutan — pembuktian pada stack nyata lokal (2026-11-28)

Tidak menyentuh production. Susunan: PostgreSQL 16 sekali pakai (Accounting v2 dari repo ini), **Ecopa asli** dari repo
saudara (`git archive HEAD` dari `main` ke folder sementara, SQLite; perubahan kerja yang belum di-commit di repo itu
tidak dipakai maupun diubah), Accounting Web (Vite) dan browser sungguhan. Di Windows PHP Ecopa memerlukan
`OPENSSL_CONF` (mis. `<php>/extras/ssl/openssl.cnf`) agar bisa membuat kunci JWKS; hanya variabel lingkungan.

**1. Login SSO nyata atas data hasil migrasi.** Database v1 (dump gladi, 15.144 jurnal) di-restore, di-`migrate`, lalu
`ecopa:link-organization 1` dengan organisasi default Ecopa sungguhan; Akunta didaftarkan lewat wizard registrasi (token,
persetujuan, callback), dan user Ecopa dipetakan ke user v1 lewat `main_tier_user_id`.

| Pengguna | Hasil login OIDC nyata |
|---|---|
| Platform Owner | `is_platform_admin` true, organisasi `ready`, 3 entity, 45 permission tiap entity, `can_manage_cron` true; mendarat di `/dashboard` tanpa wizard |
| Admin lama | `is_sso_admin` true, bukan Platform Owner, 3 entity, `can_manage_cron` false |
| Inspector | mendarat di `/journals?status=draft&journal_mode=fiscal`, 2 entity, 6 permission baca, `is_fiscal_inspector` true |
| Operator | 2 entity, 15 permission, tanpa `journal.post` |
| Dinonaktifkan di Akunta, aktif di Ecopa | 403 "Akses Akunta untuk akun ini telah dinonaktifkan di Ecopa" |
| Non-admin tanpa penugasan aplikasi di Ecopa | 403 "Akun belum di-assign ke Akunta" (klaim `app_role` kosong) |

Setelah itu `users.ecopa_platform_admin` true hanya untuk Platform Owner dan tidak ada entity tanpa `tenant_id`.

**2. Alur 2 dan 3 end-to-end** (`AKUNTA_MULTI_ORGANIZATION=true`; pada Ecopa lokal saklar "multi-organization ready"
dinyalakan hanya di salinan itu): Member organisasi baru sebelum ada workspace mendapat "Workspace organisasi Anda belum
disiapkan. Hubungi Organization Owner." Organization Owner masuk ke wizard `/onboarding` (entity, mode buku, COA, periode)
sampai "Selesai"; tenant baru bertaut ke organisasi 2 dengan 1 entity, Owner hanya melihat entity itu, organisasi 1 tidak
berubah (3 entity, 13 akun tiap entity). Member sesudah workspace ada: state `no_role`, "Hubungi Organization Owner untuk
mendapatkan peran", tanpa entity.

**3. RLS pada salinan hasil migrasi.** `akunta:rls enable` 29 tabel (114 policy) 1,0 s, `disable` 1,0 s, data utuh (15.144
jurnal, 30.288 entry). Smoke test gladi (`tests/Rehearsal`, kini juga bisa berjalan dengan RLS; pembacaan lintas tenant
fixture memakai `platformRead`) lulus 5 test dan 49 asersi sebagai role `akunta_app` dengan `AKUNTA_RLS=true`. Login SSO
nyata di bawah RLS dan multi-organisasi: Platform Owner melihat 3 entity, 2.524 jurnal Intern per entity, total Kas identik
dengan snapshot v1; Organization Owner organisasi lain mendapat 422 "Tenant is not accessible" untuk jurnal dan Neraca Saldo
entity organisasi 1 lewat `X-Tenant-Slug`.

**4. Gladi rollback.** Dump sebelum migrasi di-restore ke database baru (3,2 s) dan dijalankan dengan build v1 (`b05f1df`):
tidak ada migration tertunda, laporan Neraca Saldo, Neraca, dan Laba Rugi per entity dan buku **IDENTIK** dengan snapshot
sebelum migrasi (1,9 s), 48 baris lampiran = 48 berkas. Prosedur: `docs/release-readiness-multi-org.md` bagian 4.

**5. Volume 10x** (151.440 jurnal, 302.880 entry, database 220 MB; dibuat dengan
`apps/accounting/scripts/rehearsal-multiply-volume.sql`, 49 s). Gladi penuh lulus dengan selisih nol.

| Langkah | 1x (15 rb jurnal) | 10x (151 rb jurnal) |
|---|---|---|
| `pg_dump -Fc` / uji restore | 0,6 s / 6,7 s | 2,7 s / 25,7 s |
| `migrate --force` | 7,6 s | 62,9 s |
| `ecopa:link-organization` | 1,1 s | 1,5 s |
| laporan identik dengan v1 | 2,1 s | 18,6 s |
| smoke test per jenis user | 3,6 s | 13,4 s |

Skala hampir linear. Dari maintenance on sampai off (termasuk verifikasi dan ulang idempoten) sekitar 107 s pada 10x.
Ukuran database naik dari 220 MB menjadi 437 MB karena backfill `tenant_id` memakai UPDATE (tuple lama menjadi dead
tuple); `VACUUM (ANALYZE)` (5 s) tidak mengecilkan berkas, `VACUUM FULL journals, journal_entries` (19 s, kunci eksklusif)
menurunkannya ke 245 MB (selisih nyata 25 MB: kolom dan indeks baru). Sediakan ruang disk sekitar 2x ukuran database.
Biaya RLS pada 10x: Neraca Saldo 3 entity 646-719 ms menjadi 698-746 ms (+5-8%), satu halaman daftar jurnal 22,5 menjadi
28 ms, 200 statement kecil di luar transaksi 130 menjadi 232 ms, dalam satu transaksi 129 menjadi 138-141 ms
(`docs/row-level-security.md`).

Temuan:
1. Flag onboarding v1 (`ecopa_config_integration.installation_onboarding_completed_at`) harus sudah ada **sebelum**
   `migrate`: migration tahap 7 menyalinnya ke `tenants.onboarding_completed_at`. Bila flag dimasukkan sesudahnya,
   mode multi-organisasi menampilkan wizard kepada Platform Owner (kolom tenant masih kosong). Pra-pemeriksaan ditambahkan
   ke checklist rilis.
2. Pengguna non-admin hanya dapat masuk bila Ecopa menugaskan aplikasi kepadanya (klaim `app_role`); event
   `app.access.granted` menambah penugasan seluruh-aplikasi tanpa role dan tidak mengubah role per entity yang ada
   (diperiksa: 6 baris sebelum, 3 baris tambahan, role lama utuh). Event yang sama mengaktifkan kembali user yang
   dinonaktifkan lokal (sesuai kontrak "user.assigned membuat atau mengaktifkan shadow user"); kontrol penonaktifan yang
   berlaku adalah blokir atau penghapusan di Ecopa.
3. Persetujuan registrasi di Ecopa memakai `OutboundUrl`; URL lokal hanya diterima saat `ECOPA_ALLOW_PRIVATE_APP_URLS`
   menyala atau APP_ENV bukan production.

Masih belum dibuktikan: data dan volume production sungguhan, Ecopa production (yang dipakai adalah salinan `main` lokal),
biaya RLS pada perangkat keras staging, dan webhook `user.updated` Platform Owner langsung dari Ecopa nyata.

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

### Bukti per acceptance criteria (2026-11-27)

| # | Bukti | Di mana |
|---|---|---|
| 1 | Flow 1: Platform Owner dan user lama langsung ke data lama tanpa wizard; pada gladi, Platform Owner dan admin lama tidak melihat `needs_setup`/`needs_workspace` | `OrganizationEntryFlowsTest` ("flow 1"), `tests/Rehearsal/PostMigrationSmokeTest.php` |
| 2 | Flow 2: OO organisasi baru mendapat wizard, tenant baru bertaut ke `organization.id`, OO hanya mengelola tenant itu | `OrganizationEntryFlowsTest` ("flow 2") |
| 3 | Flow 3: Member langsung masuk atau mendapat pesan "Hubungi Organization Owner"; tidak pernah melihat wizard; organisasi yang sudah punya workspace tidak melihat wizard | `OrganizationEntryFlowsTest` ("flow 3", "shows no wizard...") |
| 4 | Admin dan anggota organisasi B tidak dapat membaca atau mengubah milik A lewat UI/API SPA, header `X-Tenant-Slug`, token API, webhook, antrean, perintah terjadwal, lampiran, cache; diulang di bawah RLS non-owner | `TwoOrganizationIsolationTest`, `TwoOrganizationChannelsTest`, `RowLevelSecurityTest`, mode diagnostik `AKUNTA_RLS_TEST_HTTP=1` |
| 5 | Perilaku single-organisasi tidak berubah: `AKUNTA_MULTI_ORGANIZATION` mati secara default dan organisasi kedua ditolak; suite Accounting yang ada tetap hijau | `OrganizationEntryFlowsTest` ("keeps refusing a second organisation..."), suite penuh SQLite dan PostgreSQL |
| 6 | Jumlah tenant, entity, user, role, assignment, jurnal, nilai uang, dan keanggotaan sama sebelum dan sesudah; semuanya terikat organisasi default; migrasi dijalankan ulang tanpa duplikasi; backup + restore teruji; laporan keuangan identik | `TenantColumnMigrationTest` (dua kali), `EcopaMigrationReportTest`, gladi `scripts/migration-rehearsal.sh` (build v1 asli, 15.144 jurnal) |

**Status plan:** seluruh kriteria terbukti di lingkungan uji dan gladi pada data buatan, kini juga dengan login SSO nyata
terhadap Ecopa lokal, alur 2 dan 3 di browser, RLS pada salinan hasil migrasi, gladi rollback, dan volume 10x (Tahap 10
lanjutan). Plan BELUM DONE: tersisa (a) gladi pada salinan production, (b) eksekusi rilis production atas perintah
eksplisit pemilik (`docs/release-readiness-multi-org.md`), (c) saklar Ecopa "multi-organization ready" production dan uji
satu organisasi baru end-to-end di sana setelah production lulus.
