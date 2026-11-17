# Auto Mapping: peta cepat untuk agent

Dokumen ini menjelaskan **implementasi saat ini**. Auto Mapping menerima JSON
dari integrasi eksternal, menyimpan raw data, memilih rule berdasarkan struktur
dan kondisi, lalu membuat satu jurnal Intern atau Fiskal. Untuk invariant jurnal
dan buku, baca [spec.md](spec.md) §8.7 dan [decisions.md](decisions.md) bagian
*independent Intern/Fiskal books*. Untuk aturan akses, baca
[permission-management.md](permission-management.md).

## Peta kode

| Kebutuhan | Sumber utama |
| --- | --- |
| Endpoint dan middleware | `apps/accounting/routes/api.php` |
| Validasi ingest | `apps/accounting/app/Http/Controllers/Api/V1/AutoMappingIngestController.php` |
| Validasi dan akses SPA | `apps/accounting/app/Http/Controllers/Api/Spa/AutoMappingController.php` |
| Hash, pemilihan rule, pembuatan jurnal | `apps/accounting/app/Services/AutoMappingEngine.php` |
| Queue | `apps/accounting/app/Jobs/ProcessAutoMappingRawData.php` |
| Validasi posting dan audit | `apps/accounting/app/Actions/PostJournalAction.php` |
| Data | `AutoMappingRawData`, `AutoMappingRule`, `AutoMappingEntityLink`, dan migration `2026_08_07_000400_*`, `2026_08_07_000410_*`, `2026_08_08_000420_*`, `2026_08_08_000430_*`, `2026_11_04_000100_*` di `apps/accounting/` |
| UI dan tipe request | `apps/accounting-web/src/routes/auto-mapping/`, `src/lib/api/auto-mapping.ts` |
| Test terdekat | `apps/accounting/tests/Feature/Api/AutoMappingIngestTest.php`, `tests/Unit/AutoMappingEngineTest.php` |

## Alur satu transaksi

```text
POST ingest + Bearer token
  -> simpan auto_mapping_raw_data (pending)
  -> dispatch ProcessAutoMappingRawData pada queue auto_journal
  -> cari rule aktif: entity_id + source_type + structure_hash
  -> evaluasi conditional_rules, pilih varian yang cocok
  -> cari periode terbuka dan akun, susun baris, cek debit = kredit
  -> buat jurnal draft dan entry dalam transaksi DB
  -> PostJournalAction (policy, validasi domain, audit posting)
  -> raw menjadi mapped + menyimpan rule_id/journal_id
```

Tanpa rule yang cocok, raw menjadi `unmapped`. Jika pembuatan atau posting
melempar exception, raw menjadi `failed` dan `error_message` menyimpan pesannya.
`mapped` tidak diproses ulang oleh engine. `pending` berarti menunggu proses.
Job memiliki `$tries = 3`, tetapi error yang sudah ditangkap engine menjadi
status `failed`, bukan exception queue yang otomatis diulang.

## Kontrak ingest

Kedua path memakai handler yang sama:

- `POST /api/auto-mapping/ingest`
- `POST /api/v1/auto-mapping/ingest`

Keduanya memakai `api.token`, throttle `60,1`, dan permission token
`journal.create` **serta** `journal.post`. Kirim tepat satu dari `entity_id`
(ULID Akunta, kompatibilitas lama) atau `source_entity_id` (ID stabil milik
aplikasi pengirim). `source_type` string maksimal 80 karakter; `source` URL
wajib maksimal 500 karakter; `payload` array JSON wajib; `idempotency_key`
string maksimal 160 karakter dan wajib saat memakai `source_entity_id`.
Respons sukses HTTP `202` berisi `raw_id` dan `status`.
App sumber harus terdaftar di katalog aplikasi Akunta agar tokennya memiliki
`app_id` yang dapat dipakai untuk mencari pemetaan.
Status bisa sudah berubah saat respons dibuat, bergantung driver queue.

```json
{
  "entity_id": "01J00000000000000000000000",
  "source_type": "poso.sale",
  "source": "https://poso.example.com/events",
  "idempotency_key": "sale-123",
  "payload": {
    "date": "2026-08-07",
    "amount": 125000,
    "debit_account": "1101",
    "credit_account": "4101",
    "description": "Penjualan POS"
  }
}
```

Engine menambahkan URL `source` dari envelope ke `payload.source` sebelum
menyimpan dan menghitung hash. Jika payload punya key `source`, URL envelope
yang dipakai. `source_payload` saat ingest berisi salinan payload hasil itu.
Pada request lama, deduplikasi adalah `(entity_id, source_type,
idempotency_key)` melalui `firstOrCreate`; pengiriman ulang dengan key sama
mengembalikan raw lama tanpa memperbarui payload. Pada request dengan
`source_entity_id`, key internal adalah SHA-256 dari `app_id` token,
`source_entity_id`, dan key eksternal. Raw juga menyimpan key eksternal asli.
Event dengan app, ID sumber, tipe, dan key eksternal yang sama tidak boleh
dibuat lagi pada entitas lain setelah pemetaan dipindah; API mengembalikan
`409 source_event_already_routed` dengan `raw_id` lama.
Karena lookup lama dengan key `null` dapat menggabungkan transaksi berbeda,
pengirim lama sebaiknya selalu memakai key unik.

## Penentuan entitas tujuan

**Perilaku sekarang:** identitas entitas sumber berada pada envelope, terpisah
dari field transaksi yang di-drag ke jurnal. Misalnya Asseto mengirim
`source_entity_id` berupa UUID X. Halaman Auto Mapping pada workspace Akunta
memetakan `(app_id Asseto, UUID X)` ke tepat satu `entity_id` Akunta. Identitas
aplikasi diambil dari Bearer token, bukan dari teks request. Rotasi token untuk
aplikasi yang sama tidak mengubah pemetaan. Contoh hubungan:

```text
App Asseto + source_entity_id X -> entitas Akunta A
App Asseto + source_entity_id Y -> entitas Akunta B
```

Contoh envelope yang diterima sesudah pemetaan X dibuat:

```json
{
  "source_type": "asseto.asset.acquired",
  "source": "https://asseto.example.com/events",
  "source_entity_id": "550e8400-e29b-41d4-a716-446655440000",
  "idempotency_key": "asset-event-123",
  "payload": { "date": "2026-11-04", "amount": 125000 }
}
```

Resolver berjalan **sebelum** raw dan queue: cari mapping dari `app_id` token
dan `source_entity_id`, cek entitas aktif, lalu periksa izin efektif pengirim
untuk `journal.create` dan `journal.post` pada entitas itu. Request tanpa kedua
ID atau dengan keduanya ditolak `422`; mapping yang belum ada ditolak `422`
`source_entity_unmapped`; target yang tidak diizinkan ditolak `403`
`entity_access_denied`. Tidak ada fallback ke entitas browser, entitas pertama,
atau nama perusahaan. ID sumber, app sumber, dan idempotency key eksternal
disimpan pada raw untuk provenance. Pemetaan unik per app dan ID sumber; UUID
sama pada app lain dapat menunjuk entitas lain. Karena token belum mempunyai
scope tenant tersendiri, izin pengguna token pada entitas target adalah batas
tenant wajib untuk setiap ingest, termasuk request lama dengan `entity_id`.

Pemetaan dikelola pada halaman Auto Mapping workspace tujuan. Daftar memakai
`automapping.read`; buat/hapus memakai permission terpisah
`automapping.entity_link.manage` dan menulis audit. Default Supervisor mendapat
izin itu, Operator hanya membaca, Inspector tidak mendapat akses. Menghapus
pemetaan menghentikan ingest baru dari ID sumber tersebut; raw/jurnal lama tidak
dipindah atau dihapus.

Endpoint SPA mengikuti workspace aktif (`X-Tenant-Slug`) dan autentikasi sesi:

| Aksi | Endpoint | Isi request |
| --- | --- | --- |
| Daftar | `GET /api/v1/spa/auto-mapping/entity-links` | — |
| Buat | `POST /api/v1/spa/auto-mapping/entity-links` | `{ "app_code": "asseto", "source_entity_id": "UUID-X" }` |
| Hapus | `DELETE /api/v1/spa/auto-mapping/entity-links/{linkId}` | — |

Entitas tujuan ditentukan dari workspace sesi pada saat membuat pemetaan.
Pasangan app dan ID sumber yang sudah terpakai ditolak
`409 source_entity_already_mapped` sehingga satu sumber tidak diam-diam diarahkan
ke dua perusahaan.

## Pencocokan rule

`structure_hash` adalah SHA-256 dari daftar path key JSON yang unik dan
diurutkan. Object bertingkat menyumbang path induk dan anak (misalnya
`customer` dan `customer.code`); array tidak diurai menurut elemennya. Urutan
key dan nilai tidak memengaruhi hash. URL `source` yang disisipkan juga menjadi
bagian struktur. Rule dicari hanya pada entitas, `source_type`, dan hash yang
sama, dengan `is_active = true`.

Beberapa rule/varian boleh mempunyai pola sama. Engine mengurutkan kandidat
terbaru, lalu memprioritaskan jumlah `conditional_rules` terbanyak dan mengambil
kandidat pertama yang semua kondisinya cocok. Tanpa kondisi, rule cocok untuk
setiap payload berpola sama. Operator: `equals`, `not_equals`, `contains`
(tanpa membedakan huruf besar/kecil), `greater_than`, `less_than`, `exists`,
`not_exists`. Kondisi satu varian digabung sebagai AND. Struktur baru tidak
memakai rule lama; tetapi `idempotency_key` lama tetap mengembalikan raw lama.

## Bentuk mapping dan hasil jurnal

SPA menyimpan rule melalui `POST /api/v1/spa/auto-mapping/{rawId}/mapping`
dengan `{ "name": "...", "mapping": { ... } }`. Contoh mapping untuk payload
di atas:

```json
{
  "date_field": "date",
  "journal_mode": "internal",
  "description_template": "Penjualan {{description}}",
  "lines": [
    { "side": "debit", "account_field": "debit_account", "amount_field": "amount" },
    { "side": "credit", "account_field": "credit_account", "amount_field": "amount" }
  ]
}
```

`date_field` adalah path JSON bertitik (misalnya `transaction.date`) atau
`__today__`. `reference_field`, `description_field`, dan `lines[].memo_field`
juga memakai path. `description_template` mendukung `{{path.field}}` dan
mengalahkan `description_field` jika tidak kosong. Setiap baris mengambil akun
dari `account_value` tetap jika ada, jika tidak dari `account_field`; nilai
dicari sebagai ID atau kode akun **di entitas raw**. Akun harus aktif dan dapat
diposting. `amount_field` harus menghasilkan angka positif; minimal dua baris
dan selisih total debit/kredit harus kurang dari `0.005`. Tanggal harus masuk
periode entitas yang `open`. `journal_mode` hanya `internal` atau `fiscal`,
default `internal`; satu jurnal Auto Mapping tidak memakai mode `both`.
`PostJournalAction` memeriksa izin posting, status, periode, akun yang tersedia
untuk buku tersebut, balance, dan menulis audit. Auto Mapping tidak membuat
pasangan jurnal Intern/Fiskal.

Jurnal menyimpan `auto_mapping_raw_data_id`, `auto_mapping_rule_id`,
`source_app` dari `source_type`, `source_id` berupa raw ID, dan
`idempotency_key` internal `auto-mapping:{rawId}`. Raw menyimpan ID rule dan
jurnal yang dihasilkan. Perubahan mapping berikutnya tidak otomatis mengubah
jurnal yang sudah `mapped`.

## SPA, akses, dan proses ulang

`GET /api/v1/spa/auto-mapping` menampilkan raw entitas aktif (pagination
backend, default 50, maksimum 100). `GET /api/v1/spa/auto-mapping/{id}`
menampilkan detail, varian aktif dengan pola sama, dan `pattern_count`. UI
`/auto-mapping` memisahkan `mapped` dan status lainnya; `/auto-mapping/[id]`
memakai payload untuk mapping, preview, kondisi, dan baris jurnal. Menyimpan
mapping **membuat rule baru** dan mengantrekan raw terpilih. Tombol reprocess
memanggil `POST /api/v1/spa/auto-mapping/rules/{ruleId}/reprocess`; handler
mengantrekan semua raw `unmapped`/`failed` pada entitas, `source_type`, dan hash
rule itu. Saat job berjalan, engine memilih lagi dari semua rule aktif yang
cocok; `ruleId` pada URL bukan pemaksaan rule tertentu.

SPA memakai cookie auth dan permission efektif workspace aktif.
`automapping.read` untuk daftar/detail; `automapping.manage` untuk menyimpan
rule dan reprocess, dengan dependency `automapping.read` dan `account.read`.
Default katalog: Supervisor mendapat read/manage/entity-link-manage, Operator read saja,
Inspector tidak mendapat keduanya. Backend `EnforceSpaPermissions` dan
controller memeriksa akses; penyembunyian UI bukan batas otorisasi. Ingest
memakai permission **API token**, bukan permission SPA tersebut.

## Batas implementasi saat ini

- `mapping.attachment_path` diterima dan disimpan, tetapi `generateJournal()`
  belum membuat attachment dari field itu.
- `saveRule()` selalu `create`, termasuk saat UI membuka varian lama. Belum ada
  endpoint update atau deactivation rule dalam controller ini.
- Pembuatan jurnal draft berada dalam transaksi DB tersendiri sebelum
  `PostJournalAction`. Jika posting gagal, periksa jurnal draft dan raw `failed`;
  jangan berasumsi seluruh proses rollback sebagai satu transaksi.
- `reprocess` tidak menyentuh raw `mapped` dan tidak membatasi URL sumber.
  Menyimpan rule baru hanya langsung mengantrekan raw yang sedang dibuka;
  raw lama lainnya perlu reprocess.
- UI daftar memakai halaman pertama API dan filter di browser. Angka/filter
  yang tampak belum mewakili seluruh halaman backend.

Untuk perubahan perilaku, mulai dari test ingest dan hash di atas; tambahkan
test feature untuk pemilihan varian, otorisasi/isolasi entitas, posting gagal,
serta buku Intern/Fiskal sesuai perubahan. Periksa juga
`docs/permission-management.md` bila aksi atau akses berubah.
