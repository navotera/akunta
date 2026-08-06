# Getting Started

## Port Aplikasi

| Aplikasi | Port | URL |
| --- | ---: | --- |
| Ecopa | 8001 | `http://localhost:8001` |
| Accounting API | 8000 | `http://localhost:8000` |
| Accounting Web | 5175 | `http://localhost:5175` |

## Requirements

- PHP dan Composer
- PostgreSQL
- Bun
- Node.js, sesuai kebutuhan Bun dan tooling frontend

Untuk Windows, PostgreSQL dapat dijalankan menggunakan [Laragon](https://laragon.org/).

## Konfigurasi Ecopa SSO

`client_id` dan `client_secret` Accounting dibuat melalui Company App di Ecopa.

1. Jalankan Ecopa di `http://localhost:8001` dan login sebagai admin.
2. Buka **Apps Management** lalu pilih **Create App** atau **Add Company App**.
3. Isi URL aplikasi dengan `http://localhost:5175`.
4. Masukkan nama aplikasi, misalnya `Akunta - Accounting`, lalu simpan.
5. Buka tab **SSO Integration** pada aplikasi tersebut.
6. Buat atau aktifkan integrasi SSO.
7. Salin `client_id` dan `client_secret` yang dibuat Ecopa.
8. Tambahkan nilainya ke `apps/accounting/.env`:

```env
ECOPA_URL=http://127.0.0.1:8001/
ECOPA_CLIENT_ID=...
ECOPA_CLIENT_SECRET=...
ECOPA_REDIRECT_URI=http://localhost:8000/auth/ecopa/callback
```

Pastikan redirect URI yang didaftarkan di Ecopa sama persis dengan
`ECOPA_REDIRECT_URI`. Setelah mengubah `.env`, jalankan `php artisan config:clear`
di direktori `apps/accounting`, lalu restart Accounting API.

## Menjalankan Project

Dari root repository, jalankan satu command berikut:

```bash
bun run dev
```

Script ini menyalakan Accounting API, Vite di aplikasi Laravel, dan Accounting
Web secara bersamaan. Tekan `Ctrl+C` untuk menghentikan seluruh proses.

Frontend Accounting berjalan di `http://localhost:5175`. Pastikan PostgreSQL
sudah aktif sebelum menjalankan aplikasi Laravel.

Jika membutuhkan Ecopa untuk alur SSO lokal, jalankan juga dari terminal lain:

```bash
cd ecopa
php artisan serve --port=8001
```
