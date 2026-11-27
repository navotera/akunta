# Migrasi data Akunta v1 → v2 (multi-organisasi Ecopa)

> Jenis dokumen: runbook + strategi verifikasi
> Keputusan acuan: `docs/decisions.md` → "Locked 2026-11-19 — migrasi data v1 ke
> v2 (multi-organisasi) tanpa kehilangan data" dan "shared database multi-tenant
> (pooled); tenant = organisasi Ecopa".
> Rencana kerja: `docs/plans/ecopa-multi-org-workspaces.md`.

**v1** = Akunta single-organisasi (satu tenant, data dipisah per `entity_id`).
**v2** = Akunta multi-organisasi: satu tenant per organisasi Ecopa, `tenant_id`
di setiap tabel milik tenant, `TenantContext`, lalu RLS.

Tujuan migrasi: semua data v1 tetap ada, tetap dapat diakses oleh orang yang
sama, dan seluruhnya menjadi milik **organisasi default Ecopa**. Tidak ada user
yang mendapat akses yang sebelumnya tidak ia miliki.

## 1. Apa arti "user terdaftar di organisasi default" di Akunta

`users` adalah identitas global, tanpa `tenant_id`. Keanggotaan organisasi
ditentukan dua sumber:

| Sumber | Peran dalam migrasi |
|---|---|
| Ecopa (`organization.id` di token, UserInfo, webhook) | **Sumber kebenaran** keanggotaan organisasi. Migrasi Ecopa 2026-11-17 sudah memberi semua user Ecopa lama organisasi awal, yang pada 2026-11-18 ditandai `is_default`. |
| Akunta `tenants.ecopa_organization_id` | Menautkan tenant v1 ke organisasi default (1:1). |
| Akunta `user_app_assignments.tenant_id` | Menautkan **keanggotaan dan role lokal** user ke tenant itu. |

Jadi user v1 "terdaftar di organisasi default" bila ketiga hal berikut benar:

1. tenant v1 tertaut ke `organization.id` default;
2. setiap assignment user (app-wide maupun per entity, dengan atau tanpa role
   lokal) memiliki `tenant_id` tenant tersebut;
3. di Ecopa, user dengan `sub = users.main_tier_user_id` adalah anggota
   organisasi default.

User tanpa assignment sama sekali **tidak diberi assignment baru**. Di v1 pun
ia tidak punya akses ke workspace mana pun. Membuat assignment untuknya sama
dengan memberi akses baru. Ia dilaporkan sebagai kategori tersendiri (lihat §4).

## 2. Prinsip yang tidak boleh dilanggar

1. **Aditif saja.** Kolom baru nullable → backfill → verifikasi → baru
   `NOT NULL`, composite foreign key, atau unique. Tidak ada `DROP`,
   `TRUNCATE`, `DELETE`, atau `UPDATE` atas nilai bisnis. Backfill hanya mengisi
   kolom yang masih `NULL`.
2. **Idempotent.** Setiap migration dan perintah aman dijalankan ulang tanpa
   duplikasi. `down()` hanya menghapus kolom yang ditambahkan, tidak menghapus
   baris.
3. **Tanpa tebakan.** `organization.id` default hanya diambil dari login SSO
   bertanda tangan dengan `platform_admin = true` atau dari perintah operator
   `ecopa:link-organization`. Tidak dari email, nama, atau "tenant pertama".
   Bila ada lebih dari satu tenant lama, operator wajib memilih `--tenant`.
4. **Tidak ada akses baru.** Migrasi tidak membuat user, assignment, atau role.
   Admin lama tetap admin, anggota tetap dengan role dan entity yang sama, user
   nonaktif tetap nonaktif (`disabled_at`, `revoked_at` tidak disentuh).
5. **Bukti jumlah.** Jumlah baris sebelum dan sesudah harus sama untuk setiap
   tabel yang diperiksa. Selisih membatalkan transaksi.
6. **Satu organisasi dulu.** Sampai scoping per organisasi selesai, hanya
   organisasi default yang boleh tertaut. Login organisasi lain ditolak 403 dan
   saklar Ecopa **multi-organization ready** untuk Akunta tetap mati.

## 3. Tahapan

### Tahap 0 — Persiapan (sebelum hari migrasi)

- Ambil `organization.id` dan `slug` organisasi default dari Ecopa (Platform ›
  Organizations, atau klaim `organization` saat Platform Owner login).
- **Rekonsiliasi identitas dengan Ecopa** (read-only): ekspor daftar
  `users.id, email, main_tier_user_id, disabled_at` dari Akunta dan cocokkan
  dengan user Ecopa berdasarkan `sub`. Klasifikasi:
  - `sub` ada di Ecopa dan organisasinya default → normal;
  - `sub` ada tetapi organisasinya **bukan** default → **hentikan**; selesaikan
    di Ecopa dulu (pada v1 seharusnya tidak ada);
  - `sub` tidak ditemukan di Ecopa → catat sebagai identitas yatim. Data dan
    histori tetap ada; user tidak bisa login sampai dipulihkan di Ecopa;
  - `main_tier_user_id` kosong (akun lokal/standalone, termasuk akun demo
    impersonation) → tetap milik tenant lewat assignment-nya, tidak memerlukan
    Ecopa.
- Latihan penuh di **salinan database production** (restore dump ke database
  disposable), lalu jalankan seluruh tahap 2–5 di sana. Hasil laporan disimpan.

### Tahap 1 — Backup dan freeze

- Backup lengkap database Accounting (`pg_dump -Fc`) plus folder penyimpanan
  lampiran. Catat path, ukuran, dan checksum. **Uji restore** dump ke database
  disposable sebelum lanjut.
- Aktifkan maintenance mode; hentikan scheduler (recurring journal, purge
  workspace) dan queue worker. Webhook Ecopa yang masuk saat maintenance boleh
  dijawab 503; Ecopa akan retry (60 s sampai 8 jam).
- Simpan **snapshot pembanding** (lihat §4) sebelum migration dijalankan.

### Tahap 2 — Penautan organisasi default (sudah diimplementasi, belum di-commit)

```bash
php artisan migrate --force
php artisan ecopa:link-organization <organization_id> --slug=<slug> --dry-run
php artisan ecopa:link-organization <organization_id> --slug=<slug>
```

- Migration `2026_11_19_000100_link_tenants_to_ecopa_organizations` menambah
  `tenants.ecopa_organization_id` (+ slug, waktu tautan) dan
  `user_app_assignments.tenant_id`. Backfill: dari entity assignment → dari role
  milik tenant → (instalasi satu tenant) tenant satu-satunya.
- `ecopa:link-organization` (layanan `EcopaOrganizationLinker`) mengunci baris
  `tenants`, menautkan tenant, mengisi sisa `tenant_id` assignment, mencatat
  audit `tenant.ecopa_organization_linked`, dan membandingkan jumlah tenant,
  entity, user, role, assignment, dan jurnal sebelum dan sesudah.
- Jalur alternatif tanpa operator: login pertama Platform Owner melakukan
  penautan yang sama.

### Tahap 3 — `tenant_id` di seluruh tabel milik tenant

Sesuai keputusan shared database. Untuk setiap tabel milik tenant (daftar di
§5): tambah `tenant_id` nullable → backfill dari induk (`entity_id` →
`entities.tenant_id`, `journal_id` → `journals.tenant_id`, dst.) → verifikasi
nol baris `NULL` → baru `NOT NULL` + composite foreign key `(tenant_id, …)`.
Satu migration per kelompok tabel agar kegagalan mudah dilokalisasi. Baris yang
induknya tidak dapat ditentukan **tidak** diisi dengan tebakan; migration
berhenti dan melaporkan id barisnya.

### Tahap 4 — Verifikasi (wajib lulus sebelum maintenance mode dimatikan)

Jalankan seluruh pemeriksaan §4 dan bandingkan dengan snapshot tahap 1. Satu
selisih = gagal → lanjut ke §6 (rollback).

### Tahap 5 — Smoke test fungsional

- Platform Owner login → melihat semua workspace lama tanpa wizard.
- Sampel user per kategori (admin lama, Supervisor, Operator, Inspector Fiskal,
  user nonaktif) login → melihat entity, role, dan menu yang sama seperti v1.
  Inspector tetap hanya Fiskal. User nonaktif tetap ditolak.
- Laporan Neraca Saldo, Neraca, dan Laba Rugi per entity untuk periode terakhir
  sama persis dengan hasil v1 (bandingkan angka dari snapshot).
- Kirim satu webhook uji `user.updated` dan satu auto-journal API → diterima
  dan masuk tenant yang benar.

Setelah lulus: matikan maintenance mode, jalankan lagi scheduler dan worker,
simpan laporan migrasi.

## 4. Pemeriksaan "tidak ada data hilang"

Snapshot diambil sebelum (tahap 1) dan sesudah (tahap 4) dengan query yang sama.

1. **Jumlah baris per tabel** untuk semua tabel milik tenant (§5) dan tabel
   global `users`, `roles`, `user_app_assignments`. Harus identik.
2. **Jumlah per entity**: jurnal per `(entity_id, status, book)`, entry per
   entity, akun per entity, periode per entity, attachment per entity.
3. **Checksum nilai uang**: `SUM(debit)`, `SUM(credit)` dari `journal_entries`
   per `(entity_id, book)`; jumlah `fiscal_adjustments.amount` per entity.
   Harus identik (bukan sekadar seimbang).
4. **Tidak ada yatim tenant**: `SELECT COUNT(*) … WHERE tenant_id IS NULL`
   bernilai 0 untuk setiap tabel milik tenant.
5. **Konsistensi tenant**: tidak ada baris yang `tenant_id`-nya berbeda dengan
   `entities.tenant_id` induknya (join per tabel).
6. **Keanggotaan user**, per user:
   - jumlah assignment aktif (`revoked_at IS NULL`) sebelum = sesudah;
   - semua assignment ber-`tenant_id` tenant default;
   - `role_id`, `entity_id`, `ecopa_role`, `valid_from/until` tidak berubah;
   - `disabled_at` tidak berubah.
7. **Kategori user** di laporan: (a) punya assignment → terdaftar di tenant
   default; (b) tanpa assignment tetapi `ecopa_role = admin` → mendapat akses
   admin lewat klaim SSO, tetap tanpa baris baru; (c) tanpa assignment dan
   bukan admin → tidak punya akses di v1 maupun v2, dicatat; (d) identitas
   yatim dari tahap 0. Jumlah (a)+(b)+(c) = total `users`.
8. **Penyimpanan lampiran**: setiap `attachments.path` masih menunjuk file yang
   ada (jumlah file dan total ukuran sama).

Pemeriksaan 1–8 dijalankan oleh perintah read-only `ecopa:migration-report`
(layanan `App\Services\EcopaMigrationReport`, test
`tests/Feature/Tenancy/EcopaMigrationReportTest.php`):

```bash
# Tahap 1, sebelum migration (gagal wajar: assignment lama belum ber-tenant_id)
php artisan ecopa:migration-report --snapshot=storage/app/migration-reports/before.json

# Tahap 4, sesudah migration dan penautan
php artisan ecopa:migration-report --compare=storage/app/migration-reports/before.json \
  --snapshot=storage/app/migration-reports/after.json --check-files
```

- Snapshot berisi jumlah baris per tabel, per entity (akun, periode, lampiran,
  koreksi fiskal dan totalnya, jurnal per buku dan status, baris entry serta
  `SUM(debit)`/`SUM(credit)` per buku), dan sidik jari tiap user (`sub`,
  `disabled_at`, serta kolom assignment yang tidak boleh berubah: id, app,
  entity, role, `ecopa_role`, masa berlaku, `revoked_at`). `tenant_id` dan
  timestamp sengaja tidak masuk sidik jari.
- Tabel log append-only (`audit_log`, log webhook, `cron_run_logs`,
  `webhook_deliveries`) boleh bertambah karena migrasi sendiri mencatat audit,
  tetapi tidak boleh berkurang.
- Pemeriksaan keadaan: tenant tertaut (gagal bila lebih dari satu organisasi),
  per tabel milik tenant `pending_column` / `ok` / `failed` (baris tanpa
  `tenant_id`, baris yang tenant-nya beda dari entity induk), kategori user,
  dan dengan `--check-files` keberadaan file lampiran.
- Exit code 1 bila ada perbedaan dengan snapshot atau pemeriksaan gagal.
  Perintah ini tidak menulis ke database; ia hanya menulis file snapshot.
- Kategori (b) di atas tidak dapat dihitung dari database karena status admin
  Ecopa tanpa assignment hanya ada di klaim SSO; laporan menampilkan (a)
  `with_assignment` dan gabungan (b)+(c) `without_assignment`.

## 5. Tabel milik tenant dan tabel global (daftar awal, ditinjau saat tahap 3)

| Milik tenant (wajib `tenant_id`) | Global (tanpa `tenant_id`) |
|---|---|
| `entities`, `roles` kustom (sudah punya) | `users`, `apps`, `permissions` |
| `user_app_assignments` (tahap 2) | role preset (`tenant_id` null) |
| `accounts`, `periods`, `journals`, `journal_entries` | `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `sessions`, `password_reset_tokens` |
| `journal_templates`, `journal_template_lines`, `recurring_journals` | `ecopa_config_integration`, `ecopa_webhook_receipts`, `ecopa_webhook_logs` |
| `attachments`, `fiscal_adjustments`, `tax_provisions`, `tax_codes` | `cron_settings`, `cron_run_logs` |
| `branches`, `cost_centers`, `projects` | |
| `auto_mapping_rules`, `auto_mapping_raw_data`, `auto_mapping_entity_links` | |
| `api_tokens`, `personal_access_tokens`, `webhook_subscriptions`, `webhook_deliveries` | |
| `audit_log`, `fake_data_records`, `source_ref_registry`, `documentation_notes` | |

Tabel yang belum jelas (misalnya `tax_codes` bila ternyata katalog global,
`documentation_notes`) diputuskan saat tahap 3 dan dicatat. Tabel baru wajib
dinyatakan milik tenant atau global.

## 6. Rollback

- Kegagalan sebelum tahap 4 lulus: **restore dump tahap 1** ke database
  Accounting dan kembalikan build v1. Jangan mengandalkan `down()` untuk
  memulihkan data; `down()` hanya menghapus kolom tambahan.
- Kegagalan setelah aplikasi dibuka kembali: hentikan penulisan (maintenance
  mode), bandingkan dengan snapshot. Bila data baru sudah masuk, jangan restore
  membabi buta; ekspor transaksi baru dulu, restore, lalu masukkan ulang lewat
  alur normal yang diaudit.
- Penautan organisasi yang salah tidak dibatalkan dengan menghapus baris. Kolom
  `ecopa_organization_*` dikosongkan oleh perintah operator yang diaudit (belum
  dibuat; buat hanya bila diperlukan).

## 6a. Status tahap 3 (diimplementasikan 2026-11-27)

Enam migration `2026_11_27_000100`–`000600` menambah `tenant_id` pada semua tabel
milik tenant (peta induk di `App\Support\Tenancy\TenantOwnership`). Pengecualian
yang diputuskan pemilik: `personal_access_tokens` global; `audit_log` mempertahankan
NULL untuk aksi tingkat platform; `api_tokens` dan `webhook_subscriptions` global
mengikuti tenant user pemiliknya atau satu-satunya tenant. Bukti: test
`tests/Feature/Tenancy/TenantColumnMigrationTest.php` (fixture v1 lengkap,
migrasi dijalankan dua kali, SQLite dan PostgreSQL disposable).

## 7. Yang belum ada

- Gladi penuh pada salinan production (tahap 10 prompt agent), termasuk
  `pg_dump` + uji restore. Migration tahap 3 sudah diuji pada fixture dan pada
  PostgreSQL disposable, belum pada data production.
- Scope query per tenant (`TenantContext`), tahap 5.
