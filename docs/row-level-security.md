# Row-Level Security PostgreSQL (tahap 9)

> Jenis dokumen: runbook. Keputusan acuan: `docs/decisions.md` → "Locked 2026-11-19 —
> shared database multi-tenant (pooled)" dan butir "Diimplementasikan 2026-11-27
> (tahap 9…)". Rencana kerja: `docs/plans/ecopa-multi-org-workspaces.md`.

Lapisan ketiga isolasi antar organisasi, di bawah `tenant_id` + composite foreign key
(tahap 4) dan `TenantContext` + global scope (tahap 5). Jika sebuah query lupa menyaring
tenant, database tetap tidak mengembalikan baris organisasi lain.

**Default: MATI dan harus tetap mati.** Menyalakannya bertahap — test → staging →
production — dan production **hanya atas perintah pemilik**.

## 1. Dua saklar independen

| Saklar | Cara | Efek |
|---|---|---|
| Kebijakan | `php artisan akunta:rls enable` (rollback: `disable`; `status`; `--dry-run`) | `ENABLE` + `FORCE ROW LEVEL SECURITY` dan empat policy per tabel milik tenant (30 tabel). Tidak membaca atau mengubah data. |
| Lapisan aplikasi | `AKUNTA_RLS=true` (`config('tenancy.rls.enabled')`) | Aplikasi menyalin `TenantContext` ke setting transaksi-lokal `app.tenant_id` untuk setiap statement. |

Kebijakan tanpa lapisan aplikasi = aplikasi melihat nol baris. Lapisan aplikasi tanpa
kebijakan = tidak ada efek. Urutan menyalakan: kebijakan di database uji, lapisan aplikasi
di staging, lalu keduanya di production saat pemilik memerintahkan. Rollback: matikan
`AKUNTA_RLS`, lalu `akunta:rls disable`.

## 2. Aturan yang dikodekan policy

- Baris terlihat dan dapat ditulis hanya bila `tenant_id = app.tenant_id`.
- **Tanpa konteks = tanpa baris** (setting kosong menjadi NULL dan tidak cocok dengan apa pun).
- Mode **baca lintas tenant yang eksplisit** (`app.platform_read = on`) hanya dapat `SELECT`,
  tidak pernah menulis.
- `roles`: preset global (`tenant_id` NULL) tetap terbaca dan dapat dibuat.
- `audit_log`: entri tingkat platform (`tenant_id` NULL) dapat ditambahkan; append-only,
  tidak ada policy `UPDATE` maupun `DELETE` untuk siapa pun (kecuali role owner).
- Tabel global (`tenants`, `users`, `apps`, `permissions`, `personal_access_tokens`, log
  Ecopa, cron) tidak diberi RLS.

## 3. Role database

Aplikasi dan worker antrean memakai role yang **bukan owner dan tanpa BYPASSRLS**. Migration
dan maintenance (termasuk `akunta:rls`, `ecopa:link-organization`, `ecopa:migration-report`,
trimming audit) memakai role owner dengan `BYPASSRLS`. Tanpa `FORCE`, owner juga lolos; dengan
`FORCE` hanya role ber-`BYPASSRLS` atau superuser yang lolos.

```sql
CREATE ROLE akunta_owner LOGIN BYPASSRLS PASSWORD '...';          -- pemilik tabel; migrasi dan maintenance
CREATE ROLE akunta_app   LOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE PASSWORD '...';
GRANT CONNECT ON DATABASE akunta TO akunta_app;
GRANT USAGE ON SCHEMA public TO akunta_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO akunta_app;
GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO akunta_app;       -- tabel ber-serial, mis. ecopa_webhook_receipts
ALTER DEFAULT PRIVILEGES FOR ROLE akunta_owner IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO akunta_app;
ALTER DEFAULT PRIVILEGES FOR ROLE akunta_owner IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO akunta_app;
```

Jalankan `php artisan migrate --force` dan `php artisan akunta:rls ...` dengan `DB_USERNAME` /
`DB_PASSWORD` role owner (override env saat deploy); proses web dan queue memakai role aplikasi.
Isi sebenarnya ada di `docker/production.env.example` (tanpa secret).

## 4. Bagaimana konteks sampai ke database

`App\Database\TenantAwarePostgresConnection` menulis konteks sebagai setting **transaksi-lokal**
(`set_config(..., true)`), tidak pernah sebagai setting sesi:

- di dalam transaksi: ditulis saat transaksi mulai dan setiap kali `TenantContext` berubah;
- statement di luar transaksi dijalankan dalam transaksi sendiri sehingga setting hanya
  mencakup statement itu lalu hilang (tambahan beberapa round-trip per statement).

Setting hilang saat transaksi selesai, sehingga koneksi persisten, worker antrean yang berpindah
tenant, dan PgBouncer mode transaksi tidak dapat membawa konteks satu tenant ke pekerjaan
berikutnya. (PgBouncer mode transaksi tetap memerlukan konfigurasi prepared statement tersendiri;
itu di luar RLS.)

## 5. Jalur lintas tenant yang eksplisit

Tidak ada bypass dari jalur request biasa. Hanya dua pintu, keduanya **hanya-baca** dan keduanya
terlihat di kode:

- `TenantContext::platformRead(fn)`: pencarian tingkat instalasi yang terjadi sebelum konteks ada
  (tenant milik orang/entity ini, token API berdasarkan hash, apakah sebuah workspace milik
  organisasi lain, hitungan pembanding saat penautan, pencabutan token/assignment lintas tenant
  yang kemudian ditulis per tenant).
- `TenantContext::without(fn)`: agregat instalasi (ringkasan penggunaan Platform Owner).

Semua penulisan selalu milik satu tenant: handler webhook Ecopa, wizard, dan pencabutan akses
berjalan di dalam `TenantContext::run($tenant, …)`.

## 6. Yang tidak didukung saat RLS menyala (langkah owner)

- Menautkan data pra-multi-organisasi saat login Platform Owner (`linkLegacyTenants`): langkah
  sekali jalan lewat `php artisan ecopa:link-organization` dengan role owner, sebelum RLS dinyalakan.
- Grant webhook tanpa tenant pada instalasi baru yang belum punya tenant sama sekali.
- Perintah artisan ad hoc yang membaca tabel tenant tanpa konteks: gunakan role owner atau
  `TenantContext::run($tenantId, …)`. Perintah terjadwal sudah berjalan per tenant.

## 7. Pengujian

- `tests/Feature/Tenancy/RowLevelSecurityTest.php` (PostgreSQL saja, role non-owner): tanpa
  konteks nol baris di semua tabel milik tenant, tiap tenant hanya melihat barisnya, penulisan
  lintas tenant ditolak, mode baca platform tidak menulis, tidak ada konteks yang bocor ke pekerjaan
  berikutnya, preset role dan audit append-only.
- Mode diagnostik seluruh suite: `AKUNTA_RLS_TEST_HTTP=1` (PostgreSQL, role `akunta_app` atau
  `AKUNTA_RLS_TEST_ROLE`). Setiap request HTTP dalam test berjalan sebagai role non-owner dengan
  policy terpasang, sedangkan fixture dan asersi memakai owner. Test yang memang tidak berlaku saat
  RLS menyala diberi `skip(fn () => rlsHttpMode(), …)` beserta alasannya.

```powershell
# database uji sekali pakai + role non-owner
createdb akunta_rls
psql -d akunta_rls -c "CREATE ROLE akunta_app LOGIN NOSUPERUSER NOBYPASSRLS"
# lalu GRANT / ALTER DEFAULT PRIVILEGES seperti di bagian 3
$env:DB_CONNECTION='pgsql'; $env:DB_DATABASE='akunta_rls'; $env:AKUNTA_RLS_TEST_HTTP='1'
php vendor/bin/pest
```

## 7a. Urutan rollback migration dan RLS

Policy bergantung pada kolom `tenant_id`. Selama policy terpasang, rollback migration yang menghapus kolom itu
gagal (`cannot drop column tenant_id ... other objects depend on it`). Matikan dulu: `AKUNTA_RLS=false`, lalu
`php artisan akunta:rls disable`, baru rollback. Test yang membuang kolom (`TenantColumnMigrationTest`) melakukan
`akunta:rls disable` lebih dulu karena mode diagnostik suite memasang policy.

## 8. Checklist rilis RLS (eksekusi production hanya atas perintah pemilik)

1. Backup + uji restore (runbook migrasi §3 tahap 1).
2. Buat role owner dan aplikasi (§3); pastikan migrasi dan penautan organisasi selesai dengan role owner.
3. Database uji: `akunta:rls enable`, jalankan suite dalam mode diagnostik.
4. Staging: `akunta:rls enable`, lalu `AKUNTA_RLS=true`; smoke test per jenis user dan antrean.
5. Production (atas perintah pemilik): jendela maintenance, `akunta:rls enable`, `AKUNTA_RLS=true`,
   pantau error `row-level security` dan latensi. Rollback: `AKUNTA_RLS=false`, `akunta:rls disable`.
