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

## Menjalankan Project

Jalankan setiap aplikasi dari direktori aplikasinya masing-masing.

### Ecopa

```bash
cd ecopa
php artisan serve --port=8001
```

### Accounting API

```bash
cd apps/accounting
php artisan serve --port=8000
```

### Accounting Web

```bash
cd apps/accounting-web
bun run dev
```

Frontend Accounting berjalan di `http://localhost:5175`.

Pastikan PostgreSQL sudah aktif sebelum menjalankan aplikasi Laravel. Jalankan ketiga proses tersebut secara bersamaan pada terminal terpisah.
