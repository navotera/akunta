# Prompt agent — Akunta v2 multi-organisasi Ecopa (tahap 1 sampai rilis)

> Cara pakai: salin seluruh blok di bawah ke sesi agent di repo Akunta. Agent
> mengerjakan **satu tahap per putaran**, lalu berhenti dan melapor. Untuk
> melanjutkan, kirim: `Lanjut tahap N.`
>
> Dokumen ini adalah instruksi kerja. Status dan bukti tetap dicatat di
> [`ecopa-multi-org-workspaces.md`](ecopa-multi-org-workspaces.md); keputusan
> di [`../decisions.md`](../decisions.md); runbook migrasi di
> [`../ecopa-multi-org-migration.md`](../ecopa-multi-org-migration.md).
> Bila isi dokumen ini berbeda dengan keputusan terbaru di `decisions.md`,
> keputusan yang menang.

````text
Kamu bekerja di monorepo Akunta (C:\laragon\www\akunta). Tujuan: Akunta v2 aman dipakai banyak
organisasi Ecopa, tanpa kehilangan data v1, dengan tiga lapis akses: Platform Owner (PO),
Organization Owner (OO), dan Member.

Kerjakan SATU tahap per putaran sesuai urutan di bawah. Setelah tahap selesai, berhenti dan laporkan
(format di bagian "Laporan per tahap"). Jangan lanjut ke tahap berikutnya sebelum pemilik membalas
"Lanjut tahap N". Bila menemukan hal yang belum diputuskan, tanyakan; jangan diasumsikan.

====================================================================================================
BAGIAN A — BACA DULU (setiap awal sesi)
====================================================================================================
1. AGENTS.md — aturan kerja, "Permission Management wajib mengikuti perubahan fitur", "Gunakan ulang UI
   dan alur yang sudah tersedia", invariant Intern/Fiskal.
2. docs/decisions.md — entry:
   - "Locked 2026-11-19 — shared database multi-tenant (pooled); tenant = organisasi Ecopa" (+ Amendment)
   - "Locked 2026-11-19 — Platform Owner, Organization Owner, dan Member di Akunta"
   - "Locked 2026-11-19 — migrasi data v1 ke v2 (multi-organisasi) tanpa kehilangan data"
   - "Locked 2026-08-28 — Ecopa registration and local Accounting roles"
   - "Locked 2026-10-27 — workspace role and permission management" (+ amendment named roles)
3. docs/plans/ecopa-multi-org-workspaces.md — checklist dan acceptance criteria.
4. docs/ecopa-multi-org-migration.md — runbook, daftar tabel milik tenant (§5), verifikasi (§4).
5. docs/permission-management.md.
6. Kontrak Ecopa (sumber kebenaran integrasi; jangan mengarang field atau endpoint):
   C:\laragon\www\ecopa\docs\CHILD_APP_INTEGRATION_STANDARD.md — bagian `organization` claim,
   "Applications shared by several organisations", "Platform-level settings belong to the Platform
   Admin", "Tenant onboarding flows", "Handle user lifecycle events" (`platform_admin`), dan
   "Reconcile App Access when required".
7. `git status` dan `git diff` — pahami perubahan yang belum di-commit sebelum mengedit.

====================================================================================================
BAGIAN B — KONDISI AWAL
====================================================================================================
Sudah ada di working tree (belum di-commit, WAJIB dipertahankan):
- migration modules/rbac/database/migrations/2026_11_19_000100_link_tenants_to_ecopa_organizations.php
  (tenants.ecopa_organization_id/slug/linked_at, user_app_assignments.tenant_id + backfill)
- App\Services\EcopaOrganizationLinker, EcopaOrganizationLinkDryRun, command ecopa:link-organization
- App\Services\EcopaMigrationReport, command ecopa:migration-report (snapshot/compare, read-only)
- tests/Feature/Auth/EcopaOrganizationLinkTest.php, tests/Feature/Tenancy/EcopaMigrationReportTest.php
- perubahan di ResolvesTenant, InstallationOnboardingController, EcopaController (klaim organization dan
  platform_admin disimpan di session ecopa.organization / ecopa.platform_admin), Tenant, UserAppAssignment

Sisi Ecopa sudah siap:
- ID token dan UserInfo membawa `organization {id, slug, name}` dan `platform_admin`.
- Webhook user.* membawa `organization` dan `platform_admin`; user.updated dikirim saat status
  Platform Owner berubah. app.access.* membawa subject.organization; app.admin_bootstrap membawa
  admins[].organization; company API /api/users membawa organization per user.
- Saklar Ecopa "multi-organization ready" untuk Akunta MATI dan harus tetap mati sampai tahap 10.

====================================================================================================
BAGIAN C — KEPUTUSAN PEMILIK (tidak boleh dilanggar)
====================================================================================================
Peran:
- PO dikenali HANYA dari klaim bertanda tangan `platform_admin = true`. Jangan membedakan PO dan OO
  dari `app_role` (keduanya "admin"), dari preset super_admin, atau dari nama role.
- PO = pengatur instalasi + admin di organisasi default (data v1). PO TIDAK melihat data bisnis
  (jurnal, laporan, user, role) organisasi lain. Tidak ada bypass RLS dari jalur request.
- OO = admin semua workspace dalam tenant organisasinya saja.
- Member = hanya izin dari role lokal yang diberikan OO. Member tanpa role lokal tidak punya izin
  apa pun dan melihat pesan "Hubungi Organization Owner". Member tidak pernah melihat wizard.
- Mode standalone (tanpa Ecopa): super admin instalasi = PO, satu tenant default, admin tenant = OO.

Pembagian menu:
- Hanya PO: scheduler/cron, email keluar, queue/worker, integrasi Ecopa (ecopa_config_integration,
  Ecopa webhook logs, reconcile, registrasi, wizard instalasi), retensi/prune log, purge workspace,
  backup, feature flag, dataset native PT. Fake Data, branding (satu untuk seluruh instalasi).
- OO per organisasi: workspace/entity, periode, Role Management + assignment role lokal, API token,
  webhook outbound, Auto Mapping, recurring, pengaturan workspace, template COA kustom.
- Template COA bawaan (Indonesian 4-digit, Technology & IT) tetap tersedia read-only untuk semua
  organisasi dan dapat disalin OO menjadi template organisasinya.
- Log untuk PO = metadata dan agregat per organisasi saja (tanpa isi transaksi).

Penegakan:
- Hak PO tidak masuk katalog Permission Management, tidak dapat diberikan ke role kustom, tidak
  terbawa saat impersonation, dan PO tidak dipetakan ke role di tabel roles.
- Endpoint PO ditolak 403 untuk non-PO walaupun menu disembunyikan.
- Perubahan setting instalasi diaudit di audit tingkat platform (audit_log milik tenant).

Migrasi data:
- Aditif dan idempotent: tanpa DROP/TRUNCATE/DELETE/UPDATE nilai bisnis; backfill hanya baris NULL;
  down() tidak menghapus data. Baris tanpa induk yang jelas → hentikan dan laporkan, jangan menebak.
- Tidak ada akses baru: jangan membuat user, assignment, atau role untuk siapa pun.
- Organisasi default hanya dari login PO bertanda tangan atau `ecopa:link-organization`.
- Jangan menjalankan migration ke database development/production milik user. Pakai database test
  atau salinan disposable. Jangan mengubah .env, credential, atau data production.

====================================================================================================
BAGIAN D — TAHAPAN
====================================================================================================

----------------------------------------------------------------------------------------------------
TAHAP 1 — Webhook user.updated membawa platform_admin
----------------------------------------------------------------------------------------------------
Tujuan: status PO di Akunta ikut berubah tanpa menunggu login ulang.
Langkah:
1. Telusuri handler webhook Ecopa (EcopaWebhookController / service user.*) dan cara Akunta memutus
   sesi dan token pada user.disabled / app.access.revoked. Pakai mekanisme yang sama.
2. Simpan status PO terakhir per user secara aditif (mis. kolom users.ecopa_platform_admin nullable
   atau field di tabel identitas Ecopa yang sudah ada; pilih yang paling kecil dan catat alasannya).
3. Pada user.updated (dan user.* lain yang membawa field ini): bila `platform_admin` berbeda dari
   nilai tersimpan → simpan nilai baru dan putus semua sesi + token login user itu. Bila sama → tidak
   ada efek. Field tidak ada (payload lama) → abaikan. Jangan mengubah role bisnis.
4. Idempotent per event_id seperti handler user.* yang ada; log webhook tidak menyimpan payload mentah.
5. Saat login SSO, perbarui nilai tersimpan dari klaim token.
Test: promosi dan demosi memutus sesi; nilai sama tidak memutus sesi; payload tanpa field aman;
duplicate event_id tidak diproses dua kali; role lokal tidak berubah.
Selesai bila: test lulus, suite webhook/auth yang ada tetap hijau.

----------------------------------------------------------------------------------------------------
TAHAP 2 — Batas Platform Owner vs Organization Owner
----------------------------------------------------------------------------------------------------
Tujuan: menu dan endpoint tingkat instalasi hanya bagi PO.
Langkah:
a. Satu sumber status PO: helper/service (mis. App\Support\PlatformOwner::check($request)) yang
   membaca session `ecopa.platform_admin`; di mode standalone, super admin instalasi = PO. Semua
   pemeriksaan PO lewat helper ini.
b. INVENTARIS DULU, LALU BERHENTI: telusuri route API SPA, controller Settings, dan menu di
   apps/accounting-web yang menyentuh item "Hanya PO" di Bagian C. Tulis tabel ke
   docs/plans/ecopa-multi-org-workspaces.md: route, controller, permission saat ini, menu/halaman,
   usulan PO atau OO. Tandai item ambigu. Laporkan dan tunggu persetujuan pemilik sebelum c–g.
c. Backend: middleware/gate PO (mis. alias `platform.owner`) di semua endpoint yang disetujui. Non-PO
   → 403, termasuk OO dan role kustom dengan semua permission. Selama impersonation hak PO tidak
   berlaku. Jangan menambah kode permission PO ke config/accounting_permissions.php.
d. Bootstrap auth SPA: tambahkan `is_platform_admin` ke payload auth/permissions yang sudah ada;
   apps/accounting-web (stores/permissions.svelte.ts, navigasi Settings) menyembunyikan menu PO
   bagi non-PO. Gunakan halaman yang ada; jangan membuat halaman paralel.
e. Pastikan endpoint OO (Bagian C) tidak ikut terkunci ke PO.
f. Audit tingkat platform: perubahan setting instalasi dicatat dengan aktor, aksi, waktu, dan nilai
   yang disamarkan untuk secret, di tempat tanpa kewajiban tenant_id. Cek dulu apakah tabel yang ada
   bisa dipakai; tabel baru dibuat aditif dan keputusannya dicatat di docs/decisions.md.
g. Pindahkan wizard registrasi/onboarding instalasi ke PO (wizard OO dikerjakan di tahap 7).
Test: PO boleh dan OO 403 untuk tiap endpoint inventaris (request langsung); OO tetap boleh di
endpoint OO; role kustom penuh tetap 403; impersonation tanpa hak PO; session tanpa klaim = non-PO;
standalone super admin = PO; audit tercatat; menu PO tidak tampil untuk OO (test frontend yang ada).

----------------------------------------------------------------------------------------------------
TAHAP 3 — Log penggunaan untuk PO (metadata dan agregat)
----------------------------------------------------------------------------------------------------
Tujuan: PO memantau penggunaan tiap organisasi tanpa melihat isi transaksi.
Langkah:
1. Usulkan dulu daftar metrik per organisasi/tenant, lalu tunggu persetujuan: login terakhir, jumlah
   user aktif, jumlah workspace, jumlah jurnal per periode, error server, status webhook dan antrean,
   aktivitas setting oleh OO (jenis aksi + waktu, tanpa nilai bisnis).
2. Sumber data: query agregat read-only (COUNT/MAX per tenant), bukan membuka audit_log baris per
   baris lintas tenant. Endpoint khusus PO dengan konteks tanpa tenant yang eksplisit dan terbatas
   pada agregat; diberi komentar dan test bahwa tidak ada kolom isi transaksi yang keluar.
3. UI: pakai halaman Settings/Integration yang sudah ada bila memungkinkan; halaman baru hanya
   dengan alasan tertulis (AGENTS.md "Batas pembuatan halaman").
Test: PO melihat agregat semua organisasi; OO 403; respons tidak memuat nomor/memo/nilai jurnal,
nama akun, atau data pribadi selain yang disetujui.

----------------------------------------------------------------------------------------------------
TAHAP 4 — tenant_id di seluruh tabel milik tenant (migrasi tahap 3 runbook)
----------------------------------------------------------------------------------------------------
Tujuan: setiap baris milik tenant punya tenant_id yang benar.
Langkah:
1. Konfirmasi daftar tabel runbook §5 terhadap schema aktual (termasuk tabel module audit/rbac).
   Tabel ambigu (mis. tax_codes, documentation_notes) → tanyakan dulu.
2. Satu migration per kelompok tabel: tambah tenant_id nullable + index → backfill dari induk
   (entity_id → entities.tenant_id; journal_id → journals.tenant_id; dst.) hanya untuk baris NULL →
   bila masih ada NULL, hentikan dan laporkan id barisnya → baru NOT NULL + unique (tenant_id, id)
   pada entities + composite foreign key (tenant_id, entity_id) / (tenant_id, journal_id).
   Sesuaikan untuk SQLite (test) dan PostgreSQL.
3. Model baru/terdampak mengisi tenant_id otomatis saat create (trait bersama; scope penuh di tahap 5).
4. Perluas counts() di EcopaOrganizationLinker dan TENANT_OWNED_TABLES di EcopaMigrationReport
   bila daftar berubah.
5. Test migrasi fixture v1 lengkap (runbook §7): tenant, dua entity, admin, Supervisor, Operator,
   Inspector, role kustom, assignment app-wide dan per entity, user nonaktif, user tanpa assignment,
   jurnal dua buku, attachment, koreksi fiskal → migrasi dijalankan dua kali →
   `ecopa:migration-report --compare` lulus dan semua tabel milik tenant berstatus ok.
Selesai bila: tidak ada tabel milik tenant berstatus pending_column/failed di laporan.

----------------------------------------------------------------------------------------------------
TAHAP 5 — TenantContext dan scoping per organisasi
----------------------------------------------------------------------------------------------------
Tujuan: setiap request dan job hanya melihat data tenant organisasinya.
Langkah:
1. TenantContext per request/job, ditentukan dari identitas terautentikasi: organisasi pada session
   Ecopa → tenant via tenants.ecopa_organization_id; token API → tenant pemilik token; mode
   standalone → tenant default. Header X-Tenant-Slug hanya memilih entity di dalam tenant itu dan
   ditolak bila beda tenant.
2. Global scope bersama pada model milik tenant (filter + isi tenant_id). Query DB::table mentah
   ditinjau satu per satu.
3. Perbaiki: ResolvesTenant, User::getTenants, canAccessTenant, accessibleEntityIdsFromTenantWide,
   AccountingPermissions::canManage, WorkspaceController::authorizeAdmin/managedTenantId,
   RoleManagementController (daftar user hanya organisasi aktor). Admin Ecopa tanpa assignment hanya
   mendapat workspace dalam tenant organisasinya. Assignment app-wide hanya berlaku di tenant-nya.
4. Webhook: pakai subject.organization / admins[].organization untuk menentukan tenant shadow
   assignment; hapus fallback Tenant::query()->first() di EcopaWebhookController dan EcopaReconcile.
   Event untuk organisasi yang belum punya tenant: simpan identitas tanpa assignment, jangan
   menempelkan ke tenant lain.
5. EcopaReconcile: ganti /api/entities dan /api/apps/{code}/assignments (tidak ada di Ecopa) dengan
   /api/apps/{slug}/accesses dan /api/users, dikelompokkan per organization.id.
6. Perintah terjadwal (recurring, auto reversal, purge, prune) iterasi per tenant dengan konteks
   per tenant; namespace tenant untuk key cache, path lampiran baru, payload job, idempotency key.
7. Login: hapus aturan sementara "hanya satu organisasi tertaut" setelah scoping terbukti di tahap 8;
   sampai saat itu tetap tolak organisasi kedua.
Test: unit/feature per titik di atas; suite Accounting penuh tetap hijau (perilaku satu organisasi
tidak berubah).

----------------------------------------------------------------------------------------------------
TAHAP 6 — Template COA per organisasi dan branding global
----------------------------------------------------------------------------------------------------
Langkah:
1. Template COA bawaan tetap global dan read-only. OO dapat menyalin bawaan menjadi template milik
   tenant-nya, mengubahnya, dan memakainya saat membuat workspace. Template tenant lain tidak
   terlihat. Gunakan UI COA/template yang sudah ada.
2. Branding (logo, nama tampilan, warna) satu untuk instalasi, hanya PO yang mengubah. Bila saat ini
   branding tersimpan per workspace, tanyakan dulu sebelum memindahkan.
3. Permission: tinjau katalog Permission Management untuk aksi template COA (AGENTS.md).
Test: OO hanya melihat template organisasinya + bawaan; salinan tidak mengubah bawaan; OO 403 untuk
branding; PO boleh.

----------------------------------------------------------------------------------------------------
TAHAP 7 — Alur masuk per organisasi (wizard OO dan pesan Member)
----------------------------------------------------------------------------------------------------
Sesuai "Tenant onboarding flows" di kontrak Ecopa:
1. Alur 1 — PO dan user lama: langsung ke data lama di tenant default, tanpa wizard.
2. Alur 2 — OO organisasi tanpa tenant: wizard buat workspace (entity, mode pembukuan, template COA,
   periode). Tenant baru dibuat dengan ecopa_organization_id dari klaim; OO menjadi admin tenant itu
   saja. Pakai ulang komponen wizard onboarding yang ada; onboarding menjadi status per tenant, bukan
   satu flag instalasi. PO menjalankan wizard PO (tahap 2) dan juga wizard OO untuk organisasi default.
3. Alur 3 — Member: langsung ke workspace organisasinya. Tenant belum ada → pesan "Workspace
   organisasi Anda belum disiapkan. Hubungi Organization Owner." Member tanpa role lokal → pesan
   "Hubungi Organization Owner untuk mendapatkan peran." Tanpa wizard.
4. Tenant baru TIDAK membuat database fisik (TenantProvisioner tidak dipanggil di provisioning default).
Test: tiga alur; OO organisasi B tidak bisa memilih workspace organisasi A; wizard tidak muncul
untuk Member atau untuk organisasi yang sudah punya tenant.

----------------------------------------------------------------------------------------------------
TAHAP 8 — Test isolasi dua organisasi (gerbang keamanan)
----------------------------------------------------------------------------------------------------
Fixture dua organisasi (A dan B), masing-masing OO, Member, Inspector, entity, jurnal dua buku,
lampiran, API token, webhook subscription, recurring, auto-mapping. Buktikan admin/anggota B TIDAK
dapat membaca atau mengubah milik A melalui:
- UI dan API SPA (semua route list/detail/mutasi utama, termasuk laporan dan dashboard)
- manipulasi header X-Tenant-Slug dan parameter entity_id/period_id
- API token B ke data A (auto-journal, auto-mapping)
- webhook Ecopa dengan organization B
- job/queue dan perintah terjadwal
- path lampiran dan key cache
- Role Management (daftar user dan assignment)
Selesai bila: semua test isolasi lulus dan suite Accounting penuh hijau. Setelah ini, aturan
sementara "satu organisasi tertaut" boleh dihapus (tahap 5 langkah 7) dengan test penggantinya.

----------------------------------------------------------------------------------------------------
TAHAP 9 — Row-Level Security PostgreSQL
----------------------------------------------------------------------------------------------------
Langkah:
1. Policy ENABLE + FORCE ROW LEVEL SECURITY pada tabel milik tenant:
   tenant_id = current_setting('app.tenant_id', true); konteks kosong = tanpa baris.
2. Role database aplikasi non-owner tanpa BYPASSRLS; migration/maintenance memakai role owner.
   Dokumentasikan env/role di docker/production.env.example tanpa secret.
3. set_config('app.tenant_id', ..., true) per transaksi atau awal request/job, selalu di-reset
   di akhir (aman untuk koneksi persisten, queue worker, PgBouncer mode transaksi).
4. Endpoint agregat PO (tahap 3) dan perintah lintas tenant memakai jalur yang eksplisit dan
   terdokumentasi, bukan bypass dari jalur request biasa.
5. Test pada PostgreSQL disposable (bukan SQLite): query tanpa konteks mengembalikan nol baris;
   konteks A tidak melihat B; worker yang berganti tenant tidak membawa konteks lama.
Aktifkan bertahap: test → staging → production (production hanya atas perintah pemilik).

----------------------------------------------------------------------------------------------------
TAHAP 10 — Gladi migrasi dan rilis
----------------------------------------------------------------------------------------------------
Ikuti docs/ecopa-multi-org-migration.md:
1. Tahap 0 runbook: rekonsiliasi identitas (sub → organisasi default) memakai Ecopa /api/users.
   User dari organisasi lain → hentikan.
2. Gladi penuh pada salinan production disposable: backup + uji restore → maintenance mode →
   `ecopa:migration-report --snapshot=before.json` → migrate → `ecopa:link-organization --dry-run` →
   link → `ecopa:migration-report --compare=before.json --check-files` lulus → smoke test per jenis
   user (PO, OO lama, Supervisor, Operator, Inspector, user nonaktif) → laporan Neraca Saldo, Neraca,
   Laba Rugi per entity identik dengan v1.
3. Tulis laporan gladi (perintah, durasi, hasil, selisih) di docs/plans/ecopa-multi-org-workspaces.md.
4. Siapkan checklist rilis (release-readiness): backup, jendela maintenance, rollback via restore,
   pemantauan setelah rilis. Eksekusi di production HANYA atas perintah eksplisit pemilik.
5. Setelah production lulus: minta pemilik/PO Ecopa menyalakan "multi-organization ready" untuk
   Akunta, lalu uji satu organisasi baru end-to-end (alur 2 dan 3).
Selesai bila: semua acceptance criteria di ecopa-multi-org-workspaces.md terbukti; plan diubah
menjadi DONE.

====================================================================================================
BAGIAN E — VERIFIKASI DAN LAPORAN PER TAHAP
====================================================================================================
Verifikasi:
- Test Pest untuk izin diberikan dan ditolak, request langsung, dan isolasi. Dari apps/accounting:
  `php -d memory_limit=1G vendor/bin/pest <file>` lalu suite terkait; suite penuh sebelum menutup tahap.
- Frontend (bila tersentuh), dari apps/accounting-web: `bun run check`, `bun run lint`, `bun run test`,
  `bun run build` sekali di akhir tahap.
- `vendor/bin/pint` pada file PHP yang diubah (dari apps/accounting); `git diff --check`.
- Untuk tahap yang menyentuh data: `ecopa:migration-report` pada database test/disposable.

Dokumentasi:
- Centang langkah di docs/plans/ecopa-multi-org-workspaces.md HANYA dengan bukti (perintah + hasil).
- Keputusan baru → docs/decisions.md. Perubahan perilaku akses → docs/permission-management.md.
- Jangan menyalin jumlah test lama sebagai bukti.

Laporan per tahap (balasan ke pemilik):
1. Tahap yang dikerjakan dan apa yang sekarang berlaku.
2. File yang diubah.
3. Perintah yang dijalankan dan hasilnya (lulus/gagal, jumlah).
4. Test yang gagal atau belum dijalankan, beserta alasannya.
5. Pertanyaan/keputusan yang dibutuhkan dan risiko tersisa.
6. Usulan tahap berikutnya.

Jangan commit, push, reset, atau menghapus perubahan kecuali diminta pemilik.

Mulai dari TAHAP 1.
````

## Ringkasan tahapan

| Tahap | Isi | Titik berhenti wajib |
|---|---|---|
| 1 | `platform_admin` di webhook `user.updated` → simpan + putus sesi | Setelah test lulus |
| 2 | Batas PO vs OO (backend 403, menu, audit platform, wizard PO) | Setelah inventaris (2b), lalu setelah selesai |
| 3 | Log penggunaan untuk PO (agregat) | Setelah daftar metrik diusulkan |
| 4 | `tenant_id` di semua tabel milik tenant + test migrasi v1 | Bila ada baris tanpa induk atau tabel ambigu |
| 5 | `TenantContext`, scoping, webhook, reconcile, job per tenant | Setelah suite penuh hijau |
| 6 | Template COA per organisasi, branding global | Bila branding saat ini per workspace |
| 7 | Alur masuk: wizard OO, pesan Member | Setelah test tiga alur |
| 8 | Test isolasi dua organisasi (gerbang keamanan) | Wajib lulus sebelum tahap 9–10 |
| 9 | Row-Level Security PostgreSQL | Production hanya atas perintah pemilik |
| 10 | Gladi migrasi, rilis, nyalakan multi-organization ready | Eksekusi production hanya atas perintah pemilik |
