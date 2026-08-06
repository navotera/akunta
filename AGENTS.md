# AGENTS.md

Panduan kerja untuk agent dan contributor di repository Akunta. Panduan ini
berlaku untuk seluruh monorepo. Jika suatu subdirektori memiliki `AGENTS.md`
sendiri, aturan yang lebih dekat dengan file yang diubah berlaku sebagai
tambahan.

## Gambaran proyek

Akunta adalah monorepo ekosistem aplikasi akuntansi untuk UKM Indonesia.
Komponen utamanya:

- `apps/accounting/` — backend Accounting dan panel administratif Laravel 11.
- `apps/accounting-web/` — frontend SvelteKit untuk Accounting.
- `apps/payroll/` dan `apps/cash-mgmt/` — aplikasi domain yang menggunakan
  Accounting melalui API.
- `apps/poso/` dan `apps/poso-web/` — API serta frontend POSO.
- `ecopa/` — Main Tier untuk autentikasi, SSO, dan katalog aplikasi (Laravel
  12 + Filament 4).
- `modules/` — package Composer bersama, termasuk core, RBAC, audit, UI, API
  client, dan Ecopa client.
- `packages/akunta-ui/` — package TypeScript bersama.
- `docker/` — Dockerfile dan template Compose untuk development/production.
- `docs/` — specification, keputusan arsitektur, testing, dan handover.

`apps/main-tier/` adalah path lama/deprecated. Jangan menambahkan implementasi
baru di sana kecuali diminta secara eksplisit.

## Dokumentasi yang harus dibaca

Gunakan dokumentasi sesuai konteks perubahan:

- [`GETTING_STARTED.md`](GETTING_STARTED.md) — setup lokal, port, dan alur SSO.
- [`README.md`](README.md) — gambaran monorepo dan quick start.
- [`docs/spec.md`](docs/spec.md) — perilaku produk dan kontrak domain.
- [`docs/decisions.md`](docs/decisions.md) — keputusan teknis yang sudah
  disepakati.
- [`docs/architecture.md`](docs/architecture.md) — batas komponen dan alur
  sistem.
- [`docs/testing.md`](docs/testing.md) — test suite dan pola pengujian.
- `docs/handover-*.md` — konteks sesi terbaru; ini bukan pengganti spec atau
  keputusan yang sudah locked.

Jika dokumentasi bertentangan dengan implementasi, periksa commit dan test
terbaru, lalu dokumentasikan keputusan yang diperlukan. Jangan menyalin status,
jumlah test, atau rencana lama ke file ini.

## Aturan perubahan

1. Periksa `git status` dan diff yang sudah ada sebelum mengedit. Pertahankan
   perubahan user yang tidak terkait.
2. Pahami boundary aplikasi sebelum mengubah kode. Perubahan lintas aplikasi
   harus mempertimbangkan kontrak API, autentikasi, tenant, idempotensi, dan
   test di kedua sisi.
3. Gunakan package bersama yang sudah tersedia. Aplikasi tier ketiga harus
   berkomunikasi dengan Accounting melalui `modules/api-client`, bukan dengan
   mengimpor model atau service internal Accounting.
4. Semua endpoint dan query yang memproses data tenant wajib mempertahankan
   autentikasi, authorization, dan tenant context. Jangan menonaktifkan
   `TenantResolver`, policy, atau permission hanya untuk membuat test atau
   demo lewat, kecuali pada fixture test yang memang mengisolasi middleware.
5. Jurnal harus tetap balance. Pertahankan invariant domain seperti periode
   terbuka, status posting, fiscal/intern mode, dan aturan pajak; cek
   `docs/spec.md` serta `docs/decisions.md` sebelum mengubahnya.
6. Migration baru harus forward-compatible dan tidak mengubah migration yang
   sudah pernah dijalankan. Untuk perubahan schema, tambahkan atau perbarui
   test migrasi/model yang relevan.
7. Jangan menambahkan secret ke repository. Jangan mengubah `.env` lokal,
   data database, atau konfigurasi production sebagai bagian dari perubahan
   kode biasa.
8. Jangan mengedit artefak hasil generate atau dependency vendor: `vendor/`,
   `node_modules/`, `.svelte-kit/`, `build/`, `storage/`, dan cache. Edit
   source atau konfigurasi yang menghasilkan artefak tersebut.
9. Ikuti gaya file di sekitarnya. Untuk PHP gunakan Laravel Pint; file baru
   mengikuti namespace PSR-4 dan pola Pest yang dipakai aplikasi terkait.
10. Perubahan perilaku publik harus disertai test dan, bila perlu, update
    dokumentasi atau kontrak API.

## Perintah kerja dan verifikasi

Jalankan dari root kecuali diberi prefix `cd`:

```bash
# Dependency
composer install
bun install

# Static checks root
composer lint:check
composer stan

# Regression suite yang terdokumentasi
(cd apps/accounting && ./vendor/bin/pest)
(cd apps/payroll && ./vendor/bin/pest)
(cd apps/cash-mgmt && ./vendor/bin/pest)
(cd modules/api-client && ./vendor/bin/pest)

# Frontend Accounting
(cd apps/accounting-web && bun run check)
(cd apps/accounting-web && bun run lint)
(cd apps/accounting-web && bun run build)
(cd apps/accounting-web && bun run test)
(cd apps/accounting-web && bun run test:e2e)
```

`composer ci` menjalankan lint check, PHPStan, dan test root. Untuk perubahan
yang hanya menyentuh satu app atau module, jalankan suite dan formatter yang
relevan terlebih dahulu; tambahkan regression suite penuh bila risikonya
lintas boundary atau menyentuh shared module.

Untuk test tertentu, jalankan dari direktori aplikasi terkait, misalnya:

```bash
cd apps/accounting
./vendor/bin/pest tests/Feature/Path/ToTest.php
./vendor/bin/pest --filter="deskripsi test"
```

Untuk test yang memanggil aplikasi lain, gunakan `Http::fake()` dan verifikasi
payload serta idempotency key. Untuk cookie tenant/entity yang memang tidak
terenkripsi, gunakan helper unencrypted yang sesuai dengan pola di
`docs/testing.md`. Jangan menghapus middleware global untuk menyembunyikan
masalah integrasi; nonaktifkan hanya dalam test unit/middleware yang sedang
mengisolasi komponen tersebut.

## Frontend dan development server

`bun run dev` di root menjalankan Accounting API dan Accounting Web. Ecopa
dijalankan terpisah dari `ecopa/` jika alur SSO diperlukan. Ikuti port dan
konfigurasi di `GETTING_STARTED.md`; jangan mengasumsikan service lokal
tersedia hanya karena dependency sudah terpasang.

Gunakan script yang benar-benar didefinisikan oleh `package.json` masing-masing
package. Jangan menambahkan script baru hanya untuk menjalankan satu pemeriksaan
lokal tanpa alasan yang jelas.

## Database, Docker, dan deployment

- Test otomatis menggunakan konfigurasi test aplikasi; hindari database
  development atau production.
- Untuk smoke test schema, gunakan database SQLite sementara atau database
  disposable yang ditentukan dokumentasi.
- Baseline production adalah `docker/docker-compose.example.yml` dengan
  `docker/production.env.example`. File secret `.env` berada di direktori
  deployment dan tidak boleh di-commit.
- Jangan menghidupkan kembali path `simulate_production/` yang sudah dihapus.
- Perubahan Docker/Compose harus divalidasi dengan `docker compose ... config`
  dan, bila relevan, healthcheck serta log service.
- Jangan menjalankan migration production, `pull`, atau `up` pada server hanya
  karena diminta melakukan perubahan kode; itu adalah langkah deployment
  terpisah dan memerlukan target serta otorisasi yang jelas.

## Sebelum menyerahkan pekerjaan

- Jalankan formatter, test, dan static check yang relevan.
- Jalankan `git diff --check`.
- Tinjau `git diff` agar tidak ada perubahan generated file, secret, atau
  perubahan tidak terkait.
- Laporkan perintah yang dijalankan, hasilnya, dan test yang belum dijalankan
  atau gagal karena masalah yang sudah ada.
- Jangan membuat commit, push, reset, atau menghapus perubahan user kecuali
  diminta secara eksplisit.
