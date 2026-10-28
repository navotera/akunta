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
12. Penanda versi Accounting Web bersumber dari
   `apps/accounting-web/src/lib/config/app-version.ts` dan ditampilkan di
   footer sidebar. Saat menyiapkan pull request, wajib naikkan versi sesuai
   Semantic Versioning: patch untuk bug/perubahan kecil, minor untuk fitur
   kompatibel, dan major untuk breaking change. Lakukan bump setelah seluruh
   perubahan PR selesai agar satu PR hanya menghasilkan satu kenaikan versi.
   Versi aplikasi ini terpisah dari versi dataset Fake Data `2026.1.0`.
13. Utamakan penggunaan ulang UI, komponen, route, dan alur bisnis yang sudah
    tersedia. Ikuti aturan pada bagian berikut sebelum menambahkan halaman,
    form, modal, atau alur baru.
14. Setiap penambahan, perubahan, atau penghapusan fitur wajib meninjau dampaknya
    terhadap Permission Management dan menyesuaikan implementasi yang relevan.
    Pemeriksaan ini adalah bagian dari penyelesaian fitur, bukan pekerjaan
    tambahan yang menunggu permintaan user. Ikuti bagian berikut.

## Permission Management wajib mengikuti perubahan fitur

Agent harus secara proaktif mengintegrasikan kontrol akses ketika mengubah
fitur. Jangan menunggu user mengingatkan untuk menambahkan permission, default
role, atau pemeriksaan backend setelah UI selesai dibuat.

- Petakan aksi yang ditambah atau berubah: melihat daftar/detail, membuat,
  mengganti nama, mengedit, mengaktifkan/nonaktifkan, menghapus, approval/posting,
  import/export, lampiran, dan aksi massal/otomatis yang relevan. Gunakan kembali
  permission yang maknanya sama; tambah kode baru hanya jika wewenangnya memang
  berbeda. Perubahan tampilan murni tidak memerlukan permission baru.
- Untuk Accounting, baca [docs/permission-management.md](docs/permission-management.md)
  dan telusuri implementasi utama: `apps/accounting/config/accounting_permissions.php`,
  `AccountingPermissions`, `EnforceSpaPermissions`, Gate/policy/action terkait,
  serta `apps/accounting-web/src/lib/stores/permissions.svelte.ts`.
  Untuk aplikasi lain, gunakan registry dan mekanisme otorisasi aplikasi tersebut;
  jangan membuat sistem permission paralel.
- Jika perlu permission baru, daftarkan kode, label/kategori, dependency, dan
  default role pada sumber katalog yang tersedia agar muncul di Role Management.
  Periksa juga dampaknya pada Restore to Default. Jangan mengubah arti kode lama
  tanpa meninjau kontrak dan penggunaannya.
- Tetapkan bawaan Supervisor, Operator, dan Inspector Fiskal sesuai wewenang
  yang terdokumentasi. Jangan memberi semua role akses tulis ke fitur baru
  sebagai jalan pintas. Inspector dan salinannya tetap hanya membaca Fiskal,
  tanpa akses Intern, dashboard, atau mutasi data. Operasi khusus admin dan
  batas status/periode jurnal tetap mengikuti invariant domain.
- Role custom harus dapat diberi atau dicabut permission melalui UI yang sudah
  tersedia. Pertahankan allowlist eksplisit per workspace, gabungan izin beberapa
  role, serta snapshot izin awal custom role. Permission baru tidak otomatis
  diberikan pada role custom atau override yang tidak mencantumkannya; jangan
  menimpa konfigurasi admin atau pivot preset global demi mengaktifkan fitur.
- Otorisasi wajib diterapkan pada setiap jalur backend yang terdampak, termasuk
  request langsung dan jalur massal/otomatis. Pertahankan tenant/entity context
  dan pemeriksaan terhadap record induk. Memiliki akses ke satu aksi tidak boleh
  menjadi cara melewati izin aksi lain, misalnya menjalankan jadwal untuk
  melewati izin posting.
- Navigasi, tombol, input, dan mode read-only memakai permission efektif pada
  workspace aktif. Jangan hanya memeriksa nama role untuk aksi yang dapat
  dikonfigurasi, atau mengandalkan penyembunyian tombol sebagai otorisasi.
  Gunakan form utama yang sama untuk detail dan edit sesuai aturan di bawah.
- Entitas demo memakai permission dan aturan bisnis normal yang sama. Jangan
  menambahkan bypass otorisasi maupun kunci tulis khusus demo untuk fitur baru;
  pemulihan data demo tetap mengikuti kontrak provenance.
- Untuk perubahan permission atau aksi baru, tambahkan/perbarui test izin
  diberikan dan ditolak, request langsung, grant/revoke pada role custom, dan
  isolasi workspace. Sertakan regresi Inspector serta Intern/Fiskal bila
  terdampak; periksa UI yang relevan tanpa menonaktifkan middleware otorisasi.
- Perbarui dokumentasi/kontrak bila perilaku akses berubah. Saat menyerahkan
  perubahan permission, jelaskan izin yang dipakai/ditambah, default role yang
  berubah, dan hasil pengujiannya. Jika peninjauan tidak menemukan dampak akses,
  pertahankan permission yang ada tanpa menambah pengaturan yang tidak diperlukan.

## Gunakan ulang UI dan alur yang sudah tersedia

**Jangan reinvent the wheel.** Penuhi kebutuhan user dengan memanfaatkan UI dan
fitur yang sudah ada terlebih dahulu. Permintaan fitur baru tidak otomatis
berarti perlu halaman baru, form baru, popup baru, atau implementasi paralel.
Perluas UI yang tersedia secukupnya apabila kebutuhan dapat dipenuhi di sana.
Aturan ini berlaku untuk seluruh aplikasi di monorepo, termasuk Bagan Akun,
jurnal, template jurnal, laporan, Settings, dan alur pemulihan data.

### Pemeriksaan wajib sebelum membuat UI

- Telusuri route, halaman daftar/detail, form, komponen bersama, action, dan
  endpoint yang menangani objek serta pekerjaan yang sama. Baca implementasinya;
  jangan menyimpulkan fitur belum ada hanya karena tombolnya tidak terlihat.
- Periksa apakah masalah sebenarnya berupa navigasi yang belum tersambung,
  menu yang tersembunyi, filter yang belum diterapkan, atau kondisi permission
  yang salah. Perbaiki penyebab tersebut pada alur yang tersedia.
- Identifikasi form utama, validasi, aturan status/periode, tenant context,
  permission, dan audit trail yang harus dipakai kembali. Periksa juga variasi
  mode edit dan read-only yang sudah dimiliki komponen tersebut.
- Pilih perubahan terkecil yang memenuhi kebutuhan: sambungkan tombol/tautan,
  kirim konteks melalui parameter route, gunakan filter daftar, atau tambahkan
  informasi pada halaman yang sudah ada. Hindari membuat tujuan navigasi baru
  untuk pekerjaan yang telah didukung halaman lain.

### Satu form utama untuk detail dan edit objek yang sama

- Gunakan form utama yang sama untuk menampilkan detail dan mengedit record.
  Perbedaan pintu masuk, tujuan user, atau role bukan alasan untuk menyalin form
  maupun membuat editor alternatif.
- Jika user memiliki izin membaca tetapi tidak memiliki izin mengubah record,
  tampilkan form tersebut dalam mode read-only. Jika user berhak mengubah dan
  status record serta periode mengizinkan, aktifkan input dan action edit pada
  form yang sama. Jika izin membaca tidak ada, akses harus ditolak.
- Mode read-only harus mencegah perubahan dan penyimpanan, termasuk melalui
  pemilih akun, penambahan/penghapusan baris, lampiran, dan action terkait.
  Informasi tetap dapat dibaca. Backend wajib mengotorisasi setiap mutasi;
  kondisi disabled/read-only di frontend tidak menggantikan authorization.
- Bila form utama belum mendukung kebutuhan tersebut, perluas komponen itu
  dengan state atau parameter yang jelas. Jangan membuat salinan form khusus
  untuk satu konteks, role, tombol, atau proses penghapusan.
- Gunakan kembali validasi, endpoint, audit trail, dan aturan bisnis yang sama.
  Jangan menyediakan jalur simpan kedua yang melewati pemeriksaan pada alur
  utama, mengubah ledger pasangan secara otomatis, atau mengabaikan jurnal
  balance, status, periode, tenant, maupun batas akses Intern/Fiskal.

### Kasus wajib: mengganti akun sebelum penghapusan Bagan Akun

- Saat akun tidak dapat dihapus karena dipakai jurnal, arahkan user ke daftar
  jurnal yang sudah tersedia dengan filter akun terkait. Gunakan daftar,
  pagination, tab status, dan pembatasan buku yang sudah ada.
- Petunjuk langkah dan jumlah jurnal yang masih menggunakan akun dapat
  ditambahkan sebagai alert lembut di atas daftar tersebut. Penambahan konteks
  ini tidak memerlukan halaman daftar jurnal alternatif atau editor baru.
- Saat user membuka salah satu jurnal terkait, buka form detail jurnal utama:
  `apps/accounting-web/src/routes/journals/[id]/+page.svelte`, yang menggunakan
  `apps/accounting-web/src/lib/components/journal/JournalForm.svelte`.
  Jika struktur aplikasi berubah, cari pengganti form utama tersebut terlebih
  dahulu; jangan membuat form duplikat karena path lama sudah berubah.
- User yang berhak mengedit mengganti akun langsung pada input debit/kredit
  form tersebut, lalu menyimpan melalui proses edit jurnal yang tersedia.
  User yang hanya boleh membaca melihat form yang sama secara read-only,
  dengan penjelasan jika memerlukan bantuan role yang berwenang.
- Pertahankan konteks navigasi agar user dapat kembali ke daftar jurnal terkait,
  melihat jumlah terbaru setelah penyimpanan, lalu kembali ke Bagan Akun untuk
  mencoba penghapusan. Pemeriksaan penghapusan tetap dilakukan oleh backend.
- Jangan membuat halaman khusus penggantian akun, popup edit jurnal, form edit
  ringkas, atau penggantian akun massal sebagai solusi otomatis untuk kasus ini.
- Jika user secara eksplisit meminta penggantian akun batch, gunakan daftar
  jurnal terkait dan popup pemilihan akun/konfirmasi yang terbatas pada jurnal
  pilihan user. Pakai pemeriksaan edit jurnal yang sama, transaksi atomik, dan
  audit per jurnal; jangan membuat editor jurnal paralel atau mengganti pasangan
  buku yang tidak dipilih. Permintaan batch eksplisit ini adalah pengecualian
  terhadap larangan membuat penggantian massal secara otomatis di atas.

### Batas pembuatan halaman, form, dan popup baru

- UI baru hanya dibuat jika UI yang tersedia memang tidak dapat memenuhi
  kebutuhan, atau user secara eksplisit meminta bentuk UI baru tersebut.
  Kesulitan menemukan kode, kebutuhan meneruskan konteks, atau kemudahan
  implementasi bukan bukti bahwa UI yang tersedia tidak dapat digunakan.
- Sebelum mengambil pengecualian, jelaskan secara konkret UI yang sudah
  diperiksa, kebutuhan yang belum dapat dipenuhi, dan alasan perluasannya tidak
  memadai. Catat alasan tersebut dalam penjelasan perubahan atau PR agar
  keputusan dapat ditinjau. Jangan mengada-adakan hambatan untuk membenarkan
  duplikasi.
- Bila UI baru memang diperlukan, gunakan kembali komponen, validasi, action,
  serta kontrak API yang tersedia. Tetapkan satu implementasi utama untuk
  perilaku yang sama; jangan membiarkan salinan logika berkembang sendiri.
- Popup/modal untuk konfirmasi tindakan yang memang memerlukan konfirmasi,
  seperti penghapusan atau pemulihan dataset, tetap dapat digunakan. Jangan
  menjadikan popup sebagai editor alternatif ketika form edit utama sudah ada.

### Kriteria penyelesaian perubahan UI

- Tinjau diff untuk memastikan tidak ada route, halaman, form, modal, endpoint,
  atau logika simpan duplikat yang tidak diperlukan.
- Verifikasi pintu masuk baru menggunakan alur utama dan mempertahankan konteks
  kembali. Untuk perubahan perilaku, cakup kondisi boleh membaca saja, boleh
  mengedit, dan tidak boleh mengakses, sesuai risiko perubahan.
- Pastikan hak edit ditentukan oleh permission dan keadaan record, bukan oleh
  halaman asal user. Pastikan perubahan tetap tercatat melalui audit yang sama.
- Jika menemukan duplikasi pada area yang sedang diubah, arahkan pekerjaan baru
  ke implementasi utama dan konsolidasikan bagian yang diperlukan dalam scope.
  Jangan memperluas pekerjaan menjadi refactor seluruh aplikasi tanpa kebutuhan.

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

## Handover terbaru — 2026-10-27

Baca [`docs/handover-2026-10-27.md`](docs/handover-2026-10-27.md) sebelum
melanjutkan pekerjaan sesi berikutnya. Dokumen tersebut memuat konteks alur
penghapusan akun, penggunaan ulang form jurnal, batch penggantian akun,
pemulihan demo, dan catatan verifikasi. Untuk perilaku PT. Fake Data, ikuti
amendment sandbox latihan di `docs/spec.md` dan `docs/decisions.md`; handover
Agustus di bawah merupakan catatan historis.

## Current handover — 2026-08-24

The latest delivered work is committed and pushed on `main` as
`c71969e` (`Add versioned native fake data reset workflow`). `origin/main` was
synced after the push. The working tree was clean at handoff.

### Product state: native PT. Fake Data

`PT. Fake Data` is an installation-time, idempotent demo entity identified by
`entities.is_fake_data = true` and workspace code `FAKE-DATA`. Its complete
database-backed fixture is provisioned by `FakeDataEntitySeeder` through
`NativeFakeDataProvisioner`, not by the generic UI importer. The current fixture
is version `2026.1.0`, labelled `Demo 2026`, and initially contains one open
baseline period from `2026-01-01` through `2026-12-31`. Additional manual
practice periods may exist and are preserved by dataset restore.

The fixture intentionally includes COA, Intern/Fiskal journals in multiple
workflow statuses, journal templates, recurring-journal examples, fiscal
adjustments/evidence, dimensions, tax data, auto-mapping raw data/rules,
webhooks/deliveries, attachments, and demo users/roles. Recurring examples are
display-only: `RunRecurringJournalsCommand` skips fake entities.

When switching from an entity on period 2028 (or another year) to PT. Fake Data,
the frontend clears the old period selection and loads an eligible period in
that entity (initially Demo 2026). Backend period queries remain entity-scoped and reject cross-entity
period IDs.

### Import, reset, and sandbox contracts

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

Product amendment 2026-10-27: PT. Fake Data is an editable practice sandbox.
Native periods, journals, and journal attachments use the same role, status,
open-period, and balancing rules as ordinary entities. There is no additional
native-demo write lock. Supervisor/admin may edit posted examples with the
required permissions; operators still cannot edit posted journals, and Inspector
remains fiscal-only/read-only. Reversed journals remain read-only by the normal
workflow. Restore Demo Data rebuilds verified marked fixtures and preserves
manual/unverifiable records. Background recurring execution remains disabled.

### Frontend handover

Settings shows the dataset/version badge, editable-sandbox guidance, and
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
