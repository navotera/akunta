# Permission Management Accounting

Admin membuka **Settings → Role Management** pada workspace aktif.
Daftar role memiliki pencarian nama/deskripsi, tombol **Role Baru**, serta
detail dengan tab **Izin** dan **Anggota**. Tiga role bawaan adalah Supervisor,
Operator, dan Inspector Fiskal. Pengaturan satu workspace tidak mengubah preset
global atau hak role yang sama pada workspace lain.

Ketiga role bawaan memiliki tombol **Restore to Default** di kanan atas daftar
permission pada tab Izin. Setelah konfirmasi, izin role terpilih langsung
dipulihkan dan draft izinnya diganti dengan bawaan; anggota serta role lain
tetap sama.

## Konsep role dan anggota

Role adalah grup akses bernama yang berisi banyak user. Setiap user mendapat
hak melalui assignment User × Role × App × Entity yang sudah tersedia; tidak
ada tabel grup pengguna terpisah. Contoh: admin membuat **Accounting Assistant**
dengan izin awal Operator, mengubah izin yang diperlukan, lalu memilih beberapa
user pada tab Anggota dan menekan **Terapkan ke N user**.

Role baru dapat dimulai kosong atau menyalin izin tersimpan dari role lain.
Penyalinan dilakukan sekali, sehingga perubahan role sumber tidak mengubah
salinan. **Kembalikan bawaan** pada custom role kembali ke snapshot izin awalnya.
Nama/deskripsi custom role dapat diubah; tiga nama bawaan tetap dilindungi.
Role yang masih memiliki assignment belum dicabut tidak dapat dihapus.
Penghapusan role custom mempertahankan baris role untuk histori assignment.

Penetapan massal mengganti role pada assignment yang dipilih secara atomik,
bukan menambah role lain. Hanya assignment user aktif di workspace yang sama
dapat dipilih. Assignment semua workspace dikelola melalui alur admin yang
sudah ada, bukan melalui penetapan massal per workspace. Self-demotion admin,
perlindungan Super Admin/Admin, pencabutan sesi/token, dan audit tetap berlaku.

Kategori akses dapat dibuka/tutup secara terpisah dan awalnya tertutup.
Header kategori menampilkan jumlah izin aktif dibanding total izin, serta
jumlah bawaan untuk role yang dipilih. Hitungan aktif mengikuti draft
centang, termasuk perubahan dependency, dan tetap terlihat saat kategori ditutup.

## Aksi yang dapat dikonfigurasi

| Kelompok | Permission |
|---|---|
| Bagan Akun | Melihat/mencari, membuat, mengganti nama, mengedit struktur, mengaktifkan/nonaktifkan, menghapus |
| Template Jurnal | Melihat/memakai, membuat, mengedit, menghapus, bookmark |
| Jurnal | Melihat/riwayat, membuat/duplikasi draft, mengedit, Trashed/pemulihan, pengajuan/pembatalan review sendiri, review/revisi, posting, reversal |
| Lampiran | Melihat/mengunduh, menambah, menghapus |
| Periode | Melihat/memilih, membuat, mengedit, menghapus, menutup, membuka kembali |
| Jurnal Berulang | Melihat, membuat, mengedit/jeda/lanjutkan, menghapus, menjalankan manual |
| Auto Mapping | Melihat data/hasil, mengelola rule/memproses ulang |
| Dashboard dan laporan | Dashboard manajemen, laporan keuangan dan rincian sumber |
| Fiskal | Melihat koreksi/rekonsiliasi, mengelola koreksi draft, menyetujui koreksi, melihat/membuat provisi pajak |
| Pengaturan | Menerapkan template COA, melihat scheduler, mengelola data simulasi bertanda fake |

**account.rename**, **account.update**, **account.activate**, dan **account.delete**
terpisah. Izin mengganti nama tidak mengizinkan perubahan kode, struktur,
status aktif, atau penghapusan. Backend membandingkan field yang benar-benar
berubah, sehingga payload form lengkap tidak memperluas izin.

Supervisor secara bawaan dapat mereview/posting/reversal, mengedit jurnal
sesuai status, mengelola akun/template/periode/jadwal, serta melihat laporan
dan Fiskal. Operator secara bawaan melihat akun/template/periode/jadwal,
membuat/mengedit/mengajukan jurnal, mengelola lampiran pada record yang boleh
diubah, dan melihat dashboard. Operator tidak mengelola akun/template,
posting/reversal, laporan keuangan, atau persetujuan Fiskal tanpa grant admin.
Default lengkap dan dependency berada di
apps/accounting/config/accounting_permissions.php.
Inspector Fiskal secara bawaan membaca jurnal, akun, periode, laporan, lampiran,
dan koreksi Fiskal. UI mengunci izin di luar daftar baca ini. Backend juga
menolak grant di luar batas tersebut, termasuk pada role hasil salin Inspector.
Reaktivasi periode tetap khusus admin secara bawaan; Supervisor/Operator
memerlukan grant period.reopen secara eksplisit.

`period.deactivate` menonaktifkan periode tanpa menutupnya; bawaan Supervisor
memilikinya, sedangkan Operator/Inspector tidak. Custom role dan override lama
tidak mendapat izin ini otomatis. Restore to Default mengikuti default role atau
snapshot awal custom role. `period.reopen` tetap memerlukan alasan dan konfirmasi,
termasuk bagi admin; aktivasi tercatat sebagai `period.reopen` dalam audit.
Periode nonaktif/ditutup mengunci single edit, batch, lampiran, dan mutasi workflow
meskipun pelaku admin. Mengganti tanggal jurnal tidak melewati kunci periode asal.

`account.delete` melakukan soft delete. Jika masih dipakai jurnal, percobaan
pertama mengembalikan alert/jumlah dan daftar terkait. Referensi periode aktif
wajib diganti terlebih dahulu melalui izin `journal.update` dan aturan status
normal; izin hapus (termasuk admin) tidak melewati batas ini. Soft delete dengan
konfirmasi hanya mengizinkan referensi tersisa pada periode nonaktif atau
ditutup. `journal.read` tetap mengatur daftar, tab periode, dan jumlah yang dapat
dilihat; Inspector hanya menerima jumlah Fiskal. Role bawaan pemilik izin tetap
sama dan tidak ada permission baru. Akun sistem
dan induk dengan sub-akun belum terhapus tetap dilindungi. Relasi historis dan
nilai laporan tetap tersedia; akun terhapus tidak dapat dipilih pada jurnal baru.
Batch penggantian akun tetap memakai `journal.read`, `journal.update`, dan
`account.read` beserta aturan status/periode normal. Referensi akun historis yang
tidak diganti dipertahankan setelah pemeriksaan entity; hal ini tidak memberi
izin memakai akun terhapus sebagai target baru atau melewati hak edit jurnal.
**Pilih semua yang bisa diedit** lintas pagination memakai izin yang sama;
request pilihan ringkas pada endpoint daftar juga wajib memiliki ketiga izin
tersebut. Pemilihan disaring oleh backend berdasarkan status yang boleh diedit,
buku/workspace, dan periode terbuka. Inspector tidak dapat memakai aksi ini.
Preview dan simpan tetap memeriksa ulang semua jurnal pilihan; tidak ada kode
permission baru, perubahan default role, atau grant otomatis pada custom role.

Memilih aksi pada UI ikut mengaktifkan akses baca yang diperlukan.
Mencabut dependency ikut mencabut aksi yang memerlukannya. Backend menolak
konfigurasi yang dependency-nya tidak lengkap. User dengan beberapa role
memperoleh gabungan grant; pengaturan Operator tidak mencabut grant dari
role lain yang juga dipegang user tersebut.

## Batas yang tetap berlaku

- Jurnal Tersimpan tetap read-only untuk Operator. Grant journal.update tidak
  melewati pemeriksaan status dan level Supervisor/admin pada record Tersimpan.
  Pengeditan jurnal Di review memerlukan journal.review dan journal.update.
- Posting otomatis pada jadwal memerlukan journal.post, termasuk ketika
  menjalankan atau melanjutkan jadwal yang sudah memakai posting otomatis.
- Izin lampiran tidak melewati authorization record induk, status jurnal,
  atau immutability bukti koreksi approved.
- Inspector tetap hanya membaca Fiskal dan ditolak dari dashboard, termasuk
  ketika role lain memberikan grant.
- Akun wajib sistem, provenance fake data, buku
  Intern/Fiskal independen, dan periode tertutup tetap dilindungi.
- Entitas demo dapat dipakai untuk latihan edit jurnal dan periode sesuai izin
  normal. Pemulihan data contoh tetap mengikuti marker provenance dan tidak
  menghapus data manual tanpa bukti asal-usul.
- Pengelolaan permission, assignment role, kredensial integrasi, serta
  penghapusan workspace tidak dapat didelegasikan lewat matriks ini.
  Pengaturan permission tidak dapat dilakukan saat impersonation aktif.

## Penyimpanan dan kontrak

Metadata custom role, ID role bersama, profil batas akses, dan snapshot izin
awalnya disimpan dalam entities.workspace_settings.accounting_workspace_roles.
Role bersama memakai kode unik yang dibangkitkan server dan tenant_id; registry
workspace adalah batas assignability agar role tidak bocor ke entitas lain.
Nama tampilan tidak dipakai untuk menentukan otoritas admin atau Supervisor.

Override disimpan sebagai allowlist lengkap per role pada
entities.workspace_settings.role_permission_overrides. Ketiadaan key role
berarti memakai default; array kosong berarti tidak ada grant dari role itu.
Tidak ada perubahan pada pivot preset global role_permissions.

- GET /api/v1/spa/permission-management
- POST /api/v1/spa/permission-management/roles
- PATCH /api/v1/spa/permission-management/roles/{code}
- DELETE /api/v1/spa/permission-management/roles/{code}
- PUT /api/v1/spa/permission-management/{code}
- DELETE /api/v1/spa/permission-management/{code} (reset izin)
- POST /api/v1/spa/role-management/bulk-assign

Mutasi definisi role dan izin memerlukan revision dari pembacaan terakhir dan X-Tenant-Slug
workspace tujuan. PUT mengirim permissions: string[]; DELETE memulihkan bawaan.
Role baru mengirim name, description, base_role (nullable), dan revision.
Penetapan massal mengirim role_code serta assignment_ids (1–100, berbeda).
Revision mencakup definisi role dan override izin. Revision kedaluwarsa menghasilkan 409. Perubahan dan audit
permission.role_updated/permission.role_reset berada pada transaksi yang sama;
kegagalan audit menggagalkan perubahan.

Bootstrap /api/v1/me menyertakan permissions, roles, is_fiscal_inspector, dan can_manage_permissions per
entity. SPA memakai informasi itu untuk navigasi dan kontrol form.
Middleware SPA serta Gate tetap menjadi boundary backend, sehingga request
langsung dan sesi dengan UI lama tetap diperiksa.

Pengujian utama ada di WorkspaceRolesApiTest.php, PermissionManagementApiTest.php, dan
tests/e2e/permission-management.spec.ts. Fixture regression memberi permission
yang diperlukan secara eksplisit; middleware authorization tetap diaktifkan.
