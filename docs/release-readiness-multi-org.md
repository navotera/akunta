# Checklist rilis Akunta v2 (multi-organisasi Ecopa)

> Jenis dokumen: checklist rilis. Prosedur dan bukti pemeriksaan: `docs/ecopa-multi-org-migration.md`.
> Rencana kerja dan laporan gladi: `docs/plans/ecopa-multi-org-workspaces.md` (Tahap 10).
> RLS: `docs/row-level-security.md`.

**Eksekusi di production HANYA atas perintah eksplisit pemilik.** Dokumen ini disiapkan agar
perintah itu dapat dijalankan tanpa improvisasi; tidak ada langkah di sini yang sudah dijalankan
di production.

## 0. Keputusan yang harus ada sebelum jadwal ditetapkan

- [ ] Pemilik memerintahkan rilis dan menetapkan jendela maintenance.
- [ ] `organization.id` dan `slug` organisasi default dari Ecopa (Platform › Organizations).
- [ ] Rekonsiliasi identitas (migrasi §3 tahap 0) selesai: tidak ada user v1 yang organisasinya
      bukan default; identitas yatim dicatat.
- [ ] Keputusan RLS: tetap mati saat rilis (default, disarankan) atau dinyalakan setelah rilis stabil.
      RLS tidak boleh dinyalakan bersamaan dengan migrasi.

## 1. Gladi (sudah dijalankan pada salinan sekali pakai; ulangi pada salinan production sebelum rilis)

- [ ] Restore dump production terbaru ke database sekali pakai dan salin folder lampiran.
- [ ] Jalankan `apps/accounting/scripts/migration-rehearsal.sh` (variabel lingkungan ada di kepala
      skrip). Hasil harus `RESULT: PASSED`; simpan folder laporannya.
- [ ] Catat durasi tiap langkah (menentukan lebar jendela maintenance).
- [ ] Smoke test per jenis user (`tests/Rehearsal`) lulus dan laporan Neraca Saldo, Neraca, Laba
      Rugi per entity identik dengan build v1.

## 2. Hari rilis (urutan tetap)

1. [ ] **Backup**: `pg_dump -Fc` database Accounting + salinan folder lampiran. Catat path, ukuran, SHA-256.
2. [ ] **Uji restore** dump ke database sekali pakai; jumlah baris tabel kunci sama (skrip gladi melakukannya).
3. [ ] **Maintenance mode** aktif; hentikan scheduler dan queue worker.
4. [ ] Snapshot sebelum: `php artisan ecopa:migration-report --snapshot=...before.json` dan snapshot laporan
       dari build v1 (`scripts/report-snapshot.php ... --root=<folder v1>`).
5. [ ] Deploy build v2, lalu dengan **role owner** database: `php artisan migrate --force`.
6. [ ] `php artisan ecopa:link-organization <organization_id> --slug=<slug> --dry-run`, lalu tanpa `--dry-run`.
7. [ ] Verifikasi: `ecopa:migration-report --compare=...before.json --check-files` lulus dan
       `scripts/report-snapshot.php ... --compare=...` menyatakan IDENTICAL. **Satu selisih = rollback (bagian 4).**
8. [ ] Smoke test fungsional: Platform Owner, OO lama, Supervisor, Operator, Inspector, user nonaktif;
       satu webhook `user.updated`; satu auto-journal API.
9. [ ] Matikan maintenance mode; jalankan lagi scheduler dan queue worker.
10. [ ] Simpan laporan migrasi (snapshot before/after, log).

Env yang tidak boleh berubah saat rilis: `AKUNTA_MULTI_ORGANIZATION=false` dan `AKUNTA_RLS=false`
(keduanya default). Saklar Ecopa "multi-organization ready" untuk Akunta tetap MATI.

## 3. Pemantauan setelah rilis

- [ ] 1 jam pertama: log aplikasi (`storage/logs`), antrean gagal, webhook Ecopa yang ditolak
      (audit `tenant.*` dan log webhook), login Platform Owner dan user lama.
- [ ] 24 jam: jalankan ulang `ecopa:migration-report --compare=...before.json` (log append-only boleh
      bertambah, tidak boleh berkurang) dan jalankan satu siklus scheduler penuh.
- [ ] Tidak ada baris baru dengan `tenant_id` NULL pada tabel milik tenant.

## 4. Rollback

- Kegagalan sebelum langkah 9: **restore dump langkah 1**, kembalikan build v1, hidupkan kembali
  scheduler dan worker. Jangan mengandalkan `down()` migration.
- Kegagalan setelah aplikasi dibuka: aktifkan maintenance mode, ekspor transaksi baru, restore,
  masukkan ulang lewat alur normal yang diaudit (migrasi §6).
- Bila RLS sempat menyala: matikan dulu (`AKUNTA_RLS=false`, lalu `php artisan akunta:rls disable`)
  sebelum rollback migration yang menghapus kolom `tenant_id`; policy bergantung pada kolom itu.

## 5. Setelah production lulus

1. [ ] Minta pemilik/PO Ecopa menyalakan saklar "multi-organization ready" untuk Akunta.
2. [ ] Nyalakan `AKUNTA_MULTI_ORGANIZATION=true` (dan hanya setelah langkah 1).
3. [ ] Uji satu organisasi baru end-to-end: alur 2 (OO membuat workspace lewat wizard) dan alur 3
       (Member masuk; pesan bila workspace atau role belum ada).
4. [ ] RLS (opsional, bertahap, atas perintah pemilik): `docs/row-level-security.md` §8.
