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

## Invariant buku Intern dan Fiskal

Konteks domain berikut sudah dikunci. Baca juga bagian “independent
Intern/Fiskal books and fiscal corrections” di `docs/decisions.md` dan §8.7 di
`docs/spec.md` sebelum mengubah jurnal, laporan, dashboard, akun, attachment,
onboarding, atau RBAC:

- `bookkeeping_mode=independent_books` berarti buku Intern dan Fiskal adalah
  dua ledger lengkap yang independen. Nilai pada setiap record jurnal tetap
  hanya `internal` atau `fiscal`; `both` bukan ledger ketiga.
- Pilihan input `Intern & Fiskal` membuat dua jurnal draft secara atomik.
  Keduanya memakai `transaction_code` dan `input_group_id` yang sama, tetapi
  mempunyai ID, nomor, status, posting, reversal, attachment, entry, dan saldo
  masing-masing. Setelah dibuat, perubahan salah satu jurnal tidak boleh
  otomatis mengubah pasangannya.
- Input tunggal `Intern` atau `Fiskal` tidak membuat jurnal pada buku lain.
  Input gabungan hanya boleh memakai akun dengan `availability=both`; input
  tunggal mengikuti `intern`, `fiskal`, atau `both` sesuai bukunya.
- Lampiran input gabungan disimpan sebagai attachment masing-masing jurnal.
  Kegagalan membuat salah satu jurnal harus menggagalkan seluruh pembuatan
  pasangan; jangan meninggalkan pasangan parsial.
- Koreksi Fiskal disimpan di `fiscal_adjustments`, bukan sebagai mutasi jurnal.
  Draft koreksi tidak memengaruhi laporan. Koreksi approved memengaruhi hanya
  rekonsiliasi/laporan Pajak Final, wajib memiliki bukti, dan tidak mengubah
  debit/kredit kedua buku.
- Dashboard manajemen mempunyai tab Intern/Fiskal. Tab Fiskal adalah estimasi
  dan rekonsiliasi, bukan angka SPT final; tarif simulasi, kredit pajak,
  kompensasi rugi, fasilitas tarif, dan pajak final harus dibedakan dengan jelas.
- Role `inspector` tidak boleh mengakses dashboard atau data Intern. Inspector
  diarahkan ke daftar jurnal, dan backend wajib memaksa journal/report read ke
  buku Fiskal. Penyembunyian menu frontend bukan security boundary.
- Label status daftar jurnal mengikuti status backend: `draft` = Diajukan,
  `submitted` = Di review, `posted` = Tersimpan, dan `rejected` = Perlu Revisi.
  Jurnal `posted`/Tersimpan read-only bagi operator; Supervisor/admin dapat
  mengubahnya melalui endpoint terotorisasi.
- Mode `internal_only` tidak menyediakan input/laporan Fiskal. Jangan izinkan
  perpindahan ke mode ini jika entitas sudah memiliki jurnal atau koreksi Fiskal.
- Perubahan invariant di atas memerlukan keputusan produk eksplisit, test
  regresi kedua buku, dan pembaruan `docs/decisions.md` serta `docs/spec.md`.

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
8. Data dummy/fake wajib memiliki provenance/marker yang eksplisit. Fitur
   `Clear Fake Data` atau `Clear Dummy Data` hanya boleh menghapus record yang
   dibuat dan ditandai oleh importer fake pada tenant/entity yang sama; jangan
   pernah melakukan mass-delete berdasarkan tenant, entity, group, tanggal,
   atau pola nama saja. Jika asal-usul record tidak dapat dibuktikan sebagai
   fake, jangan hapus dan tambahkan test yang memastikan data input manual user
   tetap ada.
9. Jangan mengedit artefak hasil generate atau dependency vendor: `vendor/`,
   `node_modules/`, `.svelte-kit/`, `build/`, `storage/`, dan cache. Edit
   source atau konfigurasi yang menghasilkan artefak tersebut.
10. Ikuti gaya file di sekitarnya. Untuk PHP gunakan Laravel Pint; file baru
    mengikuti namespace PSR-4 dan pola Pest yang dipakai aplikasi terkait.
11. Perubahan perilaku publik harus disertai test dan, bila perlu, update
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


## Efficient verification workflow

Use incremental checks during implementation so feedback remains fast while preserving the Definition of Done:

- For a small CSS-only change, inspect the focused diff and validate the affected stylesheet; do not run a production build after every individual edit.
- For a small TypeScript change, run the TypeScript check first. Add focused tests when behavior or business logic changes.
- When several related frontend edits are requested consecutively, batch them and run one production build at the end of the feature or at a meaningful checkpoint.
- Run a production build immediately when changing dependencies, build configuration, Vite configuration, code splitting, entry points, or other bundle-sensitive behavior.
- Before declaring a frontend feature complete, run the required production build once and report any warnings or exceptions.
- Do not repeat server, dependency, or unrelated test checks when the current change cannot affect them.
- For an explicitly requested cosmetic-only tweak, keep scope limited to the affected UI file/style and do not add routes, features, or broad repository checks unless the user asks for them or the focused check reveals a problem.
- Prefer the narrowest relevant check during iteration, then perform the complete proportional verification at final handoff.

## Development server startup

At the beginning of each agent session, start the local development services
before making changes, unless they are already running. Use the root launcher:

- Windows: `start-dev.bat`
- macOS/Linux: `./start-dev.sh`

The launcher uses the existing root `bun run dev` orchestrator when Bun is
available, and otherwise falls back to `php artisan serve` plus the installed
Vite CLI through Node. Do not start a second copy if ports 8000 or 5175 are
already serving the application. Keep the launcher process running while
working and stop it with `Ctrl+C` when the session ends.

## Current handover — 2026-08-24

The latest delivered work is committed and pushed on `main` as
`c71969e` (`Add versioned native fake data reset workflow`). `origin/main` was
synced after the push. The working tree was clean at handoff.

### Product state: native PT. Fake Data

`PT. Fake Data` is an installation-time, idempotent demo entity identified by
`entities.is_fake_data = true` and workspace code `FAKE-DATA`. Its complete
database-backed fixture is provisioned by `FakeDataEntitySeeder` through
`NativeFakeDataProvisioner`, not by the generic UI importer. The current fixture
is version `2026.1.0`, labelled `Demo 2026`, and must contain exactly one open
period from `2026-01-01` through `2026-12-31`.

The fixture intentionally includes COA, Intern/Fiskal journals in multiple
workflow statuses, journal templates, recurring-journal examples, fiscal
adjustments/evidence, dimensions, tax data, auto-mapping raw data/rules,
webhooks/deliveries, attachments, and demo users/roles. Recurring examples are
display-only: `RunRecurringJournalsCommand` skips fake entities.

When switching from an entity on period 2028 (or another year) to PT. Fake Data,
the frontend clears the old period selection and loads the sole Demo 2026
period. Backend period queries remain entity-scoped and reject cross-entity
period IDs.

### Import, reset, and immutability contracts

For normal entities, SPA fake-data import is deliberately limited to the COA
(`accounts`) and impersonation users (`users`). Import All, periods, journals,
templates, recurring journals, and auto-mapping imports are rejected. The
native entity cannot use generic import or clear endpoints.

Native reset endpoints are:

- `GET /api/v1/spa/fake-data/reset-preview`
- `POST /api/v1/spa/fake-data/reset`

`NativeFakeDataResetService` accepts only the exact confirmation phrase
`RESET DEMO 2026`, the expected dataset version, and a 64-character preview
fingerprint. It deletes only verified `fake_data_records` markers belonging to
the same entity, preserves manual/unverifiable/cross-entity records, rebuilds
the fixture transactionally, and records `fake_data.dataset_reset`. The audit
row is written before destructive record/storage work so an audit failure aborts
the reset. Attachment objects are cleaned only after a successful commit.

Native periods reject create/update/delete/close/reopen at the API boundary.
Native `posted` and `reversed` journals, plus their attachments, are read-only;
draft examples remain available for permission-appropriate simulation. The
guard is in `app/Http/Controllers/Api/Spa/Concerns/ProtectsNativeFakeData.php`.

### Frontend handover

Settings shows the dataset/version badge, immutable-period/journal notices, and
the preview/confirmation/reset modal. The Fake Data menu is shown only when the
active entity grants `settings.fake_data.manage` or the session is an SSO admin;
the backend remains the authorization boundary. The auth bootstrap exposes
`can_manage_fake_data` per entity. AppShell also displays the active dataset
badge.

Native E2E coverage is in:

- `apps/accounting-web/tests/e2e/native-fake-period-switch.spec.ts`
- `apps/accounting-web/tests/e2e/native-fake-reset.spec.ts`

Playwright is configured for `http://localhost:5175` and reuses an existing
frontend locally. Vitest's `bun run test` script uses `vitest run` and excludes
`tests/e2e/**`; use `bun run test:e2e` for Playwright.

### Verification baseline and continuation notes

At handoff, Accounting Pest completed with 289 passed, 1 skipped, and 1,282
assertions. The two native fake-data E2E tests passed, frontend unit tests passed,
the production build passed, `svelte-check` had 0 errors, and frontend lint had
0 errors. `git diff --check` passed.

There are still advisory Svelte accessibility/state warnings and unrelated
legacy Pint violations in the repository. Do not treat those warnings as a
reason to weaken the native fake-data authorization or provenance rules. Before
new behavior changes, read the native fake-data sections in `docs/spec.md` and
`docs/decisions.md`, then extend both backend tests and the corresponding SPA/E2E
test when the behavior is user-visible.
