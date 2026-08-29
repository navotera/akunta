# Panduan Registrasi Aplikasi Anak ke Ecopa

Dokumen ini menetapkan alur instalasi Akunta dan aplikasi anak lain yang memakai
Ecopa sebagai pusat identitas. Registrasi dan onboarding domain aplikasi anak
adalah dua tahap terpisah.

## Alur pengguna

1. Administrator membuka URL aplikasi anak.
2. Jika integrasi belum aktif, aplikasi menampilkan wizard publik yang hanya
   meminta `Ecopa URL` dan `Registration Token`.
3. Backend aplikasi anak mengirim request registrasi ke Ecopa. UI masuk ke status
   menunggu dan tidak menampilkan form login atau konten aplikasi.
4. Administrator Ecopa menyetujui atau menolak request.
5. Ecopa mengirim callback bertanda tangan ke URL webhook standar aplikasi anak.
6. Setelah approval tervalidasi, wizard registrasi hilang dan aplikasi memulai
   login Ecopa.
7. Admin Ecopa pertama menyelesaikan onboarding domain aplikasi anak. Untuk
   Akunta tahap ini membuat entitas, memilih mode buku dan COA, membuat periode,
   lalu selesai.

Tidak ada langkah manual untuk mengisi client ID, client secret, webhook secret,
atau URL webhook pada child app setelah approval.

## Request registrasi

Aplikasi anak mengirim request berikut dari backend:

```http
POST {ECOPA_URL}/api/app-registration-requests
Accept: application/json
Content-Type: application/json
X-Ecopa-Registration-Token: {registration-token}

{
  "name": "Akunta",
  "slug": "accounting",
  "base_url": "https://accounting.example.com"
}
```

Untuk Akunta, slug canonical selalu `accounting`. `base_url` berasal dari
konfigurasi canonical aplikasi (`APP_URL`), bukan input tambahan di wizard.
Webhook selalu diturunkan dari base URL:

```text
POST https://accounting.example.com/webhooks/ecopa
```

Response `202 Accepted` untuk request baru dan `200 OK` untuk retry identik
harus memuat `data.id` dan status `pending`. Registration Token tidak boleh
ditulis ke log atau dikembalikan ke browser. Child menyimpannya terenkripsi
hanya sebagai bootstrap verification secret agar callback approval/rejection
dan retry callback dapat diverifikasi.

## Callback approval registrasi

Ecopa wajib mengirim event berikut ke webhook standar setelah request disetujui:

```json
{
  "event": "app.registration.approved",
  "event_id": "unique-approval-event-id",
  "subject": {
    "registration_request_id": "123",
    "app_slug": "accounting",
    "client_id": "generated-client-id",
    "client_secret": "generated-client-secret",
    "webhook_secret": "generated-webhook-secret-minimum-32-characters",
    "api_token": "optional-server-api-token"
  }
}
```

Header `X-Ecopa-Signature` dihitung atas raw request body memakai Registration
Token request tersebut:

```text
X-Ecopa-Signature: sha256={hex_hmac_sha256(raw_body, registration_token)}
```

Child memverifikasi signature, `registration_request_id`, dan `app_slug` sebelum
menyimpan client secret serta webhook secret secara terenkripsi. Event replay
dengan `event_id` yang sama mengembalikan sukses `already_processed` dan tidak
boleh merotasi credential.

Jika request ditolak, Ecopa mengirim event dengan skema yang sama:

```json
{
  "event": "app.registration.rejected",
  "event_id": "unique-rejection-event-id",
  "subject": {
    "registration_request_id": "123",
    "reason": "Alasan yang aman ditampilkan kepada administrator"
  }
}
```

Setelah rejection, wizard boleh menerima Registration Token baru dan mengirim
request baru.

## Webhook lifecycle user

Setelah integrasi aktif, semua event user ditandatangani memakai
`webhook_secret` permanen, bukan Registration Token. Aplikasi anak wajib
menangani event berikut secara idempotent:

| Event | Tindakan aplikasi anak |
|---|---|
| `user.updated` | Perbarui nama/email shadow user lokal. |
| `user.deleted` | Nonaktifkan user, cabut sesi/token/assignment, pertahankan data historis. |
| `user.assigned` | Buat/aktifkan shadow user dan assignment aplikasi dengan local role kosong. |
| `user.revoked` | Cabut assignment dan sesi/token tanpa menghapus record historis. |

## Daftar webhook Akunta

Akunta menerima seluruh event Ecopa melalui satu endpoint:

```http
POST {APP_URL}/webhooks/ecopa
Content-Type: application/json
X-Ecopa-Signature: sha256={hex_hmac_sha256(raw_body, secret)}
```

Event standar yang harus dikirim Ecopa adalah:

| Event | Secret signature | Hasil di Akunta |
|---|---|---|
| `app.registration.approved` | Registration Token | Mengaktifkan integrasi dan menyimpan credential SSO/webhook terenkripsi. |
| `app.registration.rejected` | Registration Token | Menandai registrasi ditolak dan membuka retry wizard. |
| `user.assigned` | Permanent webhook secret | Membuat/mengaktifkan shadow user dan assignment dengan `role_id=null`. |
| `user.updated` | Permanent webhook secret | Memperbarui nama/email shadow user yang sudah ada. |
| `user.revoked` | Permanent webhook secret | Mencabut assignment, sesi, dan token tanpa menghapus histori. |
| `user.deleted` | Permanent webhook secret | Menonaktifkan user dan akses aktif tanpa menghapus record user atau data akuntansi. |

Untuk kompatibilitas integrasi lama, Akunta juga masih menerima
`user.disabled`, `user.enabled`, `entity.created`, `entity.updated`,
`entity.deleted`, `assignment.granted`, `assignment.revoked`,
`assignment.role_changed`, serta event `app_permission.*`. Integrasi Ecopa baru
wajib memakai enam event standar pada tabel di atas untuk registrasi dan
lifecycle user.

Payload minimum `user.assigned`:

```json
{
  "event": "user.assigned",
  "event_id": "unique-assignment-event-id",
  "subject": {
    "user_id": "ecopa-user-id",
    "email": "user@example.com",
    "name": "Nama User",
    "app_role": "user"
  }
}
```

`app_role` dari Ecopa hanya `admin` atau `user`. Role akuntansi rinci tidak
boleh ditentukan payload Ecopa. Assignment lokal dibuat dengan `role_id=null`,
lalu Admin Akunta memilih role melalui **Settings → User & Roles**. Jika
`entity_id` disertakan, assignment berlaku untuk entitas tersebut; jika tidak,
assignment berlaku pada seluruh entitas child app setelah role lokal dipilih.

## Respons dan idempotensi

- Response 2xx hanya diberikan jika event sudah diterapkan atau `event_id`
  pernah diproses.
- Dependency yang belum tersedia mengembalikan non-2xx retryable dan tidak
  mempertahankan receipt final.
- Payload/event tidak valid mengembalikan non-2xx dan tidak boleh mengubah data.
- `user.deleted` tidak menghapus row user, jurnal, attachment, audit log, atau
  foreign key attribution yang dibuat user tersebut.

| HTTP | Status respons | Arti untuk Ecopa |
|---|---|---|
| `200` | `applied` | Event berhasil diterapkan. Jangan retry. |
| `200` | `already_processed` | `event_id` sudah pernah diproses. Jangan retry. |
| `409` | `pending` + `retryable=true` | Dependency lokal belum tersedia. Retry dengan `event_id` yang sama. |
| `422` | `rejected` | Event/payload tidak didukung. Perbaiki payload sebelum mengirim ulang. |
| `429` | response rate limit | Batas request tercapai. Retry mengikuti `Retry-After`. |
| `401` | response autentikasi | Signature hilang atau tidak valid. Jangan retry tanpa memperbaiki signature. |
| `503` | response konfigurasi | Verification secret belum tersedia. Retry setelah konfigurasi diperbaiki. |

## Log webhook Ecopa di Akunta

Setiap percobaan request ke `/webhooks/ecopa` menghasilkan satu operational log
di `ecopa_webhook_logs`, termasuk request sukses, replay idempotent, dependency
retryable, payload ditolak, signature gagal, dan error server. Receipt sukses
tetap disimpan terpisah di `ecopa_webhook_receipts`; satu `event_id` hanya boleh
memiliki satu receipt, tetapi dapat memiliki beberapa log percobaan.

Log berisi event, event ID, referensi subject terbatas, outcome, result code,
HTTP status, hasil verifikasi signature, flag retryable, durasi, pesan error
terbatas, serta waktu terima/selesai. Log tidak menyimpan Registration Token,
client secret, webhook secret, header signature, atau raw payload lengkap.

Admin Aplikasi Akunta dapat melihat 50 log terbaru melalui **Settings >
Integration > Log webhook Ecopa**. API read-only yang dipakai UI adalah:

```http
GET /api/v1/spa/ecopa-integration/webhook-logs?per_page=50
```

Endpoint log membutuhkan session Akunta dan hak Admin Aplikasi (`Ecopa admin`
atau permission `workspace.manage`). Log disimpan selama 12 bulan dan dibersihkan
oleh command terjadwal `accounting:prune-webhook-logs`.

## Checklist Ecopa

- Menyediakan request registration idempotent `POST /api/app-registration-requests`.
- Menginfer webhook child sebagai `{base_url}/webhooks/ecopa`.
- Mengirim `app.registration.approved` atau `app.registration.rejected` dengan
  signature bootstrap.
- Mengirim empat event lifecycle user dengan `event_id` unik dan signature
  webhook permanen.
- Memicu `user.assigned` setiap user mendapat akses child app dan
  `user.revoked` saat akses tersebut dicabut.

## Checklist aplikasi anak

- Menampilkan wizard integrasi sebelum login bila status belum aktif.
- Tidak meminta base URL atau credential SSO secara manual di wizard.
- Menyimpan seluruh secret terenkripsi dan tidak mengeksposnya di API status.
- Menyediakan tepat satu URL webhook standar `/webhooks/ecopa`.
- Menyelesaikan onboarding domain hanya setelah approval dan login admin Ecopa.
- Menyediakan UI lokal untuk memilih role rinci user yang baru di-assign.
- Menyimpan satu operational log aman untuk setiap percobaan webhook dan
  membatasi akses log kepada Admin Aplikasi.
