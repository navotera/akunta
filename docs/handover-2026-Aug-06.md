# Handover Akunta — 6 Aug 2026

## Snapshot

- Branch: `development`
- HEAD: `9f50a1b4f33d53ce74e9de4711cd5c892f13f4b0` — `remove simulation`
- Commit terakhir: 6 Aug 2026, 08:02 GMT+8
- Sebelum dokumen ini dibuat, working tree bersih dan branch mengikuti `origin/development`.
- Commit terakhir hanya menghapus konfigurasi Compose simulasi; tidak mengubah runtime aplikasi.

## Perubahan terakhir

### Deployment dan Docker

Commit `9f50a1b` menghapus:

```text
simulate_production/docker-compose.yaml
```

Jangan lagi menggunakan path tersebut sebagai acuan deployment. Baseline production yang berlaku sekarang adalah:

- [`docker/docker-compose.example.yml`](../docker/docker-compose.example.yml)
- [`docker/production.env.example`](../docker/production.env.example)

Compose production dirancang untuk disalin ke direktori deployment sebagai `docker-compose.yaml`, dengan file rahasia `.env` di direktori yang sama. File Compose merujuk ke `./.env`, sehingga `docker compose` dari checkout ini belum dapat divalidasi tanpa membuat file runtime tersebut.

Perubahan deployment yang masih relevan dari commit sebelumnya:

- `0185d61` menambahkan workflow publish image ke GHCR di [`.github/workflows/publish-ghcr.yml`](../.github/workflows/publish-ghcr.yml).
- Workflow berjalan pada push ke branch `development` dan membangun image `accounting` serta `accounting-web`.
- Tag yang dipublikasikan mencakup `latest`, tag branch, dan tag SHA.
- `10d83bc` menambahkan `apps/accounting/composer.lock` agar build CI deterministik.
- `2db7cdf` menetapkan konfigurasi production memakai `.env` di samping file Compose dan memaksa URL HTTPS ketika environment Laravel adalah production.

Detail penting deployment:

- API default bind ke `127.0.0.1:8000`; frontend default bind ke `127.0.0.1:8080`.
- PostgreSQL memakai external Docker network, default `homeopensynergic_default`; nilainya harus cocok dengan stack PostgreSQL yang sebenarnya.
- Redis dan storage accounting memakai bind mount host (`redis_data` dan `accounting_storage`).
- Reverse proxy di host harus menangani TLS dan meneruskan trafik ke port tersebut.
- Semua nilai `CHANGE_ME` di [`docker/production.env.example`](../docker/production.env.example) wajib diganti dan file `.env` tidak boleh di-commit.

### Perbaikan Ecopa SSO

Dua commit sebelum commit terakhir memperbaiki alur kegagalan login SSO:

- `3d3ca51`:
  - callback dengan state tidak valid tidak lagi mengonsumsi state valid yang masih tersimpan;
  - kegagalan state atau token exchange diarahkan ke halaman login dengan query `sso_error`;
  - halaman login Svelte tidak otomatis memulai ulang redirect SSO ketika `sso_error` ada;
  - menambahkan test callback dan state serta smoke test Playwright.
- `7d80d31`:
  - route backend `/login?sso_error=...` merender halaman error yang bisa dipulihkan;
  - tersedia tombol **Coba lagi dengan Ecopa**;
  - kegagalan dicatat dengan konteks `reason`, host callback, URL Ecopa, dan redirect URI;
  - menambahkan pemetaan pesan untuk `state_mismatch`, `token_exchange`, `callback_params`, dan `provider_error`.

File utama:

- [`apps/accounting-web/src/routes/login/+page.svelte`](../apps/accounting-web/src/routes/login/+page.svelte)
- [`apps/accounting/routes/web.php`](../apps/accounting/routes/web.php)
- [`apps/accounting/resources/views/auth/sso-error.blade.php`](../apps/accounting/resources/views/auth/sso-error.blade.php)
- [`modules/ecopa-client/src/Http/EcopaAuthController.php`](../modules/ecopa-client/src/Http/EcopaAuthController.php)
- [`modules/ecopa-client/src/EcopaClient.php`](../modules/ecopa-client/src/EcopaClient.php)

Pastikan `ECOPA_REDIRECT_URI` sama persis dengan redirect URI yang terdaftar di Ecopa. Rujuk [`GETTING_STARTED.md`](../GETTING_STARTED.md) dan [`ecopa/docs/INTEGRATION.md`](../ecopa/docs/INTEGRATION.md) untuk kontrak konfigurasi SSO.

### Fitur Fiskal yang Sudah Ditambahkan

Akunta sekarang membedakan jurnal **Intern** dan **Fiskal**. Mode ini sudah terhubung ke pembuatan nomor jurnal (`JI-*` untuk intern dan `JF-*` untuk fiskal), pemilihan akun, template jurnal, dan laporan neraca dengan opsi pembanding fiskal.

Perubahan utama:

- Akun memiliki availability `intern`, `fiskal`, atau `both`; akun yang dipilih harus sesuai dengan mode jurnal.
- Jurnal dan template dapat ditandai sebagai Intern atau Fiskal.
- Tax code mendukung PPN Keluaran, PPN Masukan, PPh 21, PPh 23, PPh Final 4(2), PPh 26, dan jenis pajak lainnya.
- Transaksi penjualan dan pembelian dapat menghitung pajak dari DPP, lalu membuat baris jurnal pajak secara otomatis.
- Tersedia fondasi laporan pajak berdasarkan periode, tax code, DPP, dan nilai pajak.
- Tersedia export CSV PPN Keluaran untuk format awal Coretax/e-Faktur.

Export e-Faktur masih merupakan **first cut** dan belum menjadi integrasi langsung ke DJP. Sebelum digunakan untuk pelaporan resmi, format CSV perlu divalidasi terhadap template Coretax/e-Faktur yang berlaku.

Referensi implementasi: [`Journal.php`](../apps/accounting/app/Models/Journal.php), [`Account.php`](../apps/accounting/app/Models/Account.php), [`TaxCode.php`](../apps/accounting/app/Models/TaxCode.php), [`TaxReportService.php`](../apps/accounting/app/Services/Reporting/TaxReportService.php), dan [`EfakturCsvExporter.php`](../apps/accounting/app/Services/EfakturCsvExporter.php).

### Rencana Fitur Berikutnya — Queue Transaksi

Fitur berikutnya adalah **Queue Transaksi** untuk menerima transaksi dari aplikasi lain, misalnya aplikasi Penggajian.

Alur yang direncanakan:

1. Aplikasi sumber membuat transaksi dan mengirim webhook ke Akunta.
2. Akunta memvalidasi webhook lalu menyimpan transaksi sebagai **pending**, tanpa langsung mem-posting jurnal.
3. User membuka antrean transaksi, memeriksa detailnya, lalu menentukan transaksi tersebut masuk ke akun yang tepat.
4. Setelah disetujui, Akunta membuat atau mem-posting jurnal. Transaksi juga dapat ditolak atau dikembalikan untuk diperbaiki.

Payload minimum yang diusulkan bersifat standar dan sederhana:

```json
{
  "name": "Gaji Januari",
  "amount": 15000000,
  "description": "Pembayaran gaji karyawan",
  "direction": "out"
}
```

`direction` menggunakan `in` atau `out`. Saat implementasi, payload sebaiknya juga membawa identitas aplikasi sumber, ID transaksi sumber, entity/tenant tujuan, dan idempotency key agar webhook aman diproses ulang tanpa membuat transaksi ganda.

## Verifikasi terakhir

- `apps/accounting/vendor/bin/pest tests/Feature/Auth/EcopaCallbackFailureTest.php tests/Feature/Auth/EcopaStateTest.php`
  - **Lulus:** 4 test, 16 assertion.
- `npm run check` di `apps/accounting-web`
  - **0 error**, 29 warning aksesibilitas pada 8 file. Warning terkait elemen modal/static element dengan handler klik, role, dan tabindex; belum ditindaklanjuti dalam rangkaian commit ini.
- `git diff --check HEAD~6..HEAD`
  - **Lulus.**
- Validasi Compose belum selesai karena `docker/.env` belum tersedia di checkout. Jalankan validasi di direktori deployment setelah `.env` production dibuat.
- Full regression suite dan Playwright E2E belum dijalankan pada sesi handover ini. Prosedur lengkap ada di [`docs/testing.md`](testing.md).

## Checklist untuk sesi berikutnya

1. Siapkan direktori deployment dari [`docker/docker-compose.example.yml`](../docker/docker-compose.example.yml) dan [`docker/production.env.example`](../docker/production.env.example); jangan menghidupkan kembali `simulate_production/docker-compose.yaml`.
2. Isi secret production, kredensial PostgreSQL/Redis, URL API/SPA, dan konfigurasi Ecopa; pastikan file `.env` tetap di luar Git.
3. Pastikan external PostgreSQL network tersedia dan reverse proxy/TLS sudah mengarah ke port API dan web yang benar.
4. Jalankan `docker compose ... config`, `pull`, dan `up -d` dari direktori deployment, lalu periksa healthcheck `/up` dan status ketiga service.
5. Uji login normal, callback dengan state mismatch/token exchange failure, tampilan error yang dapat dipulihkan, dan tombol retry SSO.
6. Periksa log untuk event `ecopa.oauth.state_mismatch` dan `ecopa.oauth.callback_failed` bila masalah SSO berulang.
7. Jalankan full test suite sesuai [`docs/testing.md`](testing.md); pertimbangkan tiket terpisah untuk 29 warning aksesibilitas frontend.
8. Rancang kontrak webhook dan lifecycle pending/approved/rejected untuk Queue Transaksi.

## Referensi commit

| Commit | Ringkasan |
| --- | --- |
| `9f50a1b` | Hapus konfigurasi Compose simulasi production. |
| `7d80d31` | Render error SSO yang dapat dipulihkan pada route login. |
| `3d3ca51` | Perkuat state/callback handling Ecopa dan tambahkan test. |
| `2db7cdf` | Sesuaikan baseline Compose production dan force HTTPS. |
| `10d83bc` | Tambahkan accounting Composer lockfile untuk CI. |
| `0185d61` | Tambahkan publish image ke GHCR dan template environment production. |
| `d225470` | Tambahkan mode jurnal dan availability akun untuk Intern/Fiskal. |
| `2f17196` | Tambahkan mode jurnal Fiskal. |
