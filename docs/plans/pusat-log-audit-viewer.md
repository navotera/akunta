# Plan — Pusat Log terpadu + Audit Viewer

**Status:** DRAFT (belum di-lock; perlu review pemilik produk)
**Dibuat:** 2026-11-12
**Scope:** Satukan log operasional yang tersebar menjadi satu "Pusat Log" di Settings, tambah viewer `audit_log` read-only, dan wire retensi per kategori (termasuk retensi audit yang saat ini tersimpan tapi tidak ditegakkan). Memenuhi LOG-001/LOG-002 pada standar umum (`docs/app-standard/standards/general-app-requirements.md` bagian 13) dan bagian 10 (retensi audit end-to-end).
**References:** blueprint bagian 10 & 13; `app-spesifik/akunta/ui-baseline.md`. Backend Laravel `apps/accounting`, frontend SvelteKit `apps/accounting-web`.

---

## Keadaan saat ini (hasil survei kode)

- **Audit append-only ADA, viewer TIDAK:** `modules/audit/src/AuditLogger.php` + `modules/audit/src/Models/AuditLog.php` (immutable: `updating`/`deleting` → `ImmutableAuditException`). Ditulis luas (permission, role assignment, user.role_changed, entity_assigned, impersonate) tetapi tidak ada route/halaman SPA yang menampilkannya. Satu-satunya tampilan "audit" hanya timeline per-jurnal (`journals/[id]`).
- **Log operasional terpecah 3 tempat:** cron (`SchedulerHealthController` + `CronRunLog`, Settings→Cron), delivery webhook Ecopa (`EcopaWebhookLogController` + `EcopaWebhookLog`, filter event/outcome + pagination, Settings→Integration), delivery webhook keluar (`WebhookSubscriptionController::logs`). Tidak ada viewer log request API maupun error aplikasi.
- **Retensi:** cron `CronSetting.retention_days` (30–120h, editable). Ecopa webhook fixed 12 bulan (display saja, `PruneWebhookLogsCommand`). Trashed journal 30h. **Audit: `audit_retention_days` tersimpan pada tenant (default 1095) tetapi tidak ada UI maupun job pemusnahan.**
- **Korelasi:** request ID belum konsisten lintas kategori.

---

## Acceptance criteria

1. **Pusat Log UI** (`settings?section=logs`, SvelteKit) menampilkan kategori yang berlaku dalam satu alur: request API, error aplikasi, cron/job, awal/akhir + aksi impersonate, dan integrasi/webhook. Tampilan terpadu boleh membaca penyimpanan terpisah (tidak memindahkan audit ke tabel log operasional).
2. **Filter + pagination backend** per kategori: periode, kategori, tingkat/hasil, aktor, scope entity/workspace, dan request/correlation ID. Empty/loading/error state. Permission **melihat log** terpisah dari **mengubah konfigurasi**; keduanya dibatasi scope.
3. **Audit Viewer** read-only untuk `audit_log`: permission audit tersendiri (mis. `log.audit.view`), dibatasi scope data, filter waktu/pelaku/aksi/objek, menampilkan urutan perubahan (nilai lama/baru/ringkasan) tanpa membocorkan data privat yang tak berhak dilihat. Akses ke audit sensitif ikut tercatat.
4. **Redaction/minimisasi:** request API mencatat method, pola route, status, durasi, request ID; TIDAK menyimpan body, token, header rahasia, query string sensitif, atau data pribadi berlebihan. Pesan error disanitasi.
5. **LOG-002 retensi/aktivasi:** pengelola berizin dapat mengaktifkan/menonaktifkan pencatatan **operasional opsional** per kategori dan mengatur retensi per kategori. **Audit wajib tidak dapat dimatikan**; retensi audit, bila diatur via UI, dibatasi kebijakan audit dan setiap perubahannya diaudit.
6. **Retensi audit ditegakkan:** job pemangkasan menegakkan `audit_retention_days` sesuai batas kebijakan (tidak memangkas di bawah batas minimum bukti), terjadwal + dapat dipantau; tidak menyentuh data bisnis/receipt idempotensi. Perubahan konfigurasi retensi diaudit.
7. **Request ID korelasi:** satu request/correlation ID dapat menelusuri entri lintas kategori (API ↔ error ↔ impersonate) tanpa secret.
8. **Test:** akses negatif (tanpa izin ditolak), isolasi scope (tidak bocor antar entity), redaction (tidak ada secret pada entri), penelusuran request ID, pemangkasan per kategori menghapus log kedaluwarsa tanpa menyentuh audit/data bisnis, dan audit retensi tidak memangkas di bawah batas.

---

## Fase implementasi (usulan)

**Fase 0 — Keputusan produk (ADR).** Kategori log yang wajib ada; permission baru (`log.view`, `log.configure`, `log.audit.view`); batas minimum/maksimum retensi audit sesuai regulasi; kategori operasional mana yang boleh dimatikan. Jangan tetapkan angka retensi sebagai "sudah disepakati" tanpa keputusan.

**Fase 1 — Request ID + logging infrastruktur (backend).** Middleware korelasi request ID (reuse bila sudah ada di `bootstrap/app.php`), channel log request API dengan metadata aman + redaction, dan model/penyimpanan log request + error aplikasi. Tulis tanpa body/secret.

**Fase 2 — API Pusat Log (backend).** Controller read terpadu (`Api/Spa/LogCenterController`) membaca sumber yang ada (CronRunLog, EcopaWebhookLog, WebhookSubscription logs) + sumber baru (API/error/impersonate), dengan filter + pagination backend + scope + permission `EnforceSpaPermissions`. Jangan menyalin audit ke tabel operasional.

**Fase 3 — Audit Viewer API (backend).** Endpoint read-only `audit_log` dengan filter + pagination + scope, redaction field privat, dan pencatatan akses audit sensitif. Permission `log.audit.view`.

**Fase 4 — UI (SvelteKit).** Section `logs` di `settings/+page.svelte` (atau rute khusus) dengan sub-tab kategori, mengikuti pola list standar (pagination, filter + indikator filter aktif titik merah, empty/loading/error, read-only by permission). API client baru `src/lib/api/logs.ts`.

**Fase 5 — Konfigurasi + retensi (LOG-002).** Model/UI konfigurasi aktif-nonaktif + retensi per kategori (perluas pola `CronSetting`). Wire job pemangkasan audit yang menegakkan `audit_retention_days` dengan batas; daftarkan di `routes/console.php` seperti `PruneCronLogsCommand`/`PruneWebhookLogsCommand`. Audit wajib tidak bisa dimatikan; perubahan konfigurasi diaudit.

**Fase 6 — Test + verifikasi.** Pest/unit backend (akses negatif, scope, redaction, retensi) + Vitest/Playwright frontend (filter, pagination, permission, indikator). Jalankan `bun run check`/`lint`/`test` di accounting-web dan suite backend.

---

## Risiko & catatan

- Jangan mengubah sifat append-only `audit_log`; retensi audit hanya memangkas entri melewati batas kebijakan, bukan mengubah/menghapus selektif oleh alur aplikasi biasa.
- Tampilan terpadu membaca banyak penyimpanan; hindari N+1 dan dataset tak terbatas (wajib pagination backend, bagian 14).
- Redaction harus berlaku juga pada error/log operasional, bukan hanya audit.
- Pemangkasan operasional tidak boleh menyentuh audit, data bisnis, receipt idempotensi, atau bukti transaksi.
- Koordinasi dengan refactor kit UI yang sedang berjalan (branch `ui-kit-extract`) bila UI Pusat Log memakai komponen generik.

---

## Langkah berikutnya
Review Fase 0 dengan pemilik produk, lalu lock plan ini (isi `Locked:` + baseline test) sebelum implementasi Fase 1.
