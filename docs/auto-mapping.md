# Auto Mapping

Auto Mapping menerima JSON eksternal melalui `POST /api/auto-mapping/ingest`
(alias versioned: `/api/v1/auto-mapping/ingest`). Integrasi menggunakan Bearer
API token Akunta dengan permission `journal.create` dan `journal.post`.

Payload minimal:

```json
{
  "entity_id": "01...",
  "source_type": "poso.sale",
  "source": "https://poso.example.com/events",
  "idempotency_key": "sale-123",
  "payload": {"source": "poso.sale", "date": "2026-08-07", "amount": 125000, "debit_account": "1101", "credit_account": "4101"}
}
```

Payload disimpan lebih dulu sebagai raw data, kemudian diproses oleh queue
`auto_journal`. Pattern adalah hash SHA-256 dari daftar key JSON yang sudah
dinormalisasi dan diurutkan; rule hanya cocok jika `entity`, `source_type`, dan
struktur key sama. Payload yang belum cocok muncul di menu Auto Mapping. User
menyusun field tanggal, deskripsi, akun, nominal, dan memo melalui drag-drop.

Tanggal jurnal dapat diatur ke `__today__` untuk memakai tanggal saat proses.
Deskripsi mendukung template teks dengan placeholder `{{field.path}}`, sehingga
field JSON dapat digabung dengan catatan tambahan.

Saat rule disimpan, raw data diproses ulang dan jurnal balance dibuat melalui
`PostJournalAction`. Jurnal menyimpan `auto_mapping_raw_data_id` dan
`auto_mapping_rule_id` untuk audit/tracing. Struktur JSON baru menghasilkan raw
data baru, sehingga perubahan kontrak sumber tidak diam-diam memakai rule lama.
