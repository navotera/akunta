# General App Requirements untuk Aplikasi Baru

**Status:** Catatan pengembangan untuk aplikasi berikutnya; rincian domain tetap ditetapkan per aplikasi

**Cakupan:** Semua aplikasi baru yang saya buat, baik aplikasi mandiri di luar Akunta maupun child app Akunta

**Acuan khusus child app Akunta:** `docs/CHILD_APP_REGISTRATION_GUIDE.md`, `docs/cross-app-rbac.md`, `docs/permission-management.md`, `docs/spec.md`, dan `docs/decisions.md`

Dokumen ini adalah catatan dan checklist untuk merancang aplikasi berikutnya, bukan spesifikasi satu domain atau kewajiban memasukkan semua aplikasi ke monorepo Akunta. Bagian umum di bawah diterapkan sesuai kebutuhan produk: aplikasi tanpa tenant, entity, scheduler, atau integrasi eksternal tidak perlu membuat fitur tersebut hanya demi memenuhi checklist. Setiap aplikasi menetapkan data, aturan domain, stack, dan cara deployment sendiri. Bagian **Jika app adalah child app Akunta** menambahkan kontrak ekosistem yang hanya berlaku bagi aplikasi yang benar-benar terintegrasi dengan Akunta/Ecopa. Untuk child app, keputusan yang sudah dikunci dalam dokumen acuan tetap berlaku; bila dokumen historis dan implementasi terbaru berbeda, verifikasi kontrak dan test sebelum mengambil keputusan baru.

## 1. Role Management dan Permission Management (UI dan logic)

- Jika aplikasi memiliki beberapa role, tetapkan katalog permission domain sendiri. Definisikan permission per aksi yang berbeda wewenang: baca daftar/detail, buat, ubah, hapus, persetujuan, impor/ekspor, dan aksi otomatis bila ada. Kode permission stabil, berlabel jelas, dikelompokkan menurut fitur, dan memiliki dependency bila suatu aksi memerlukan izin baca.
- Bila role dapat dikelola pengguna, sediakan UI untuk mencari role, membuat role custom, mengubah nama/deskripsi, melihat anggota, mengatur matriks izin, dan memulihkan izin bawaan. Gunakan satu alur utama untuk pengelolaan role.
- Role bawaan dan hak awalnya ditetapkan per aplikasi. Role custom dapat dimulai kosong atau menyalin snapshot izin role lain. Pemulihan role custom kembali ke snapshot awalnya; pemulihan role bawaan kembali ke default aplikasi. Pada aplikasi multi-workspace, perubahan satu workspace tidak mengubah workspace lain atau preset global.
- Hak efektif dihitung dari seluruh assignment aktif pada scope yang benar, dengan allowlist eksplisit dan batas domain yang tidak boleh dilampaui. Permission baru tidak otomatis diberikan kepada role custom atau override yang belum mencantumkannya.
- UI mengikuti hak efektif, tetapi setiap endpoint dan job tetap melakukan otorisasi backend terhadap aksi, record induk, scope data, status, dan kondisi domain. Tidak ada akses hanya karena tombol tersembunyi atau nama role tampak cocok.
- Perubahan role, permission, dan penetapan anggota memiliki audit trail. Perubahan bersamaan memakai mekanisme revisi/konflik agar penyimpanan lama tidak menimpa perubahan admin lain. Batasi self-demotion dan hilangnya administrator terakhir sesuai aturan aplikasi.
- Untuk fitur baru, tinjau permission, default role, grant/revoke role custom, akses request langsung, dan isolasi workspace jika ada. Batas role khusus suatu produk tidak otomatis menjadi role aplikasi berikutnya.

**Selesai bila:** pengguna tanpa izin ditolak oleh backend, grant/revoke role custom berlaku pada scope yang tepat, dan UI menampilkan keadaan read-only untuk pengguna yang hanya boleh membaca.

## 2. User List (UI)

- Sediakan daftar pengguna yang berhak masuk aplikasi pada scope aktif. Jika ada penyedia identitas eksternal, tampilkan pengguna yang tersedia untuk ditetapkan sesuai kontraknya. Bedakan dengan jelas **ditetapkan**, **belum memiliki role lokal**, **dicabut**, dan **dinonaktifkan**.
- Tampilkan nama, email, status akses, role lokal, serta cakupan entity/workspace yang relevan. Sediakan pencarian, filter status/role, pagination, empty state, loading state, dan pesan kegagalan yang dapat ditindaklanjuti.
- Daftar tidak menjadi sumber utama perubahan identitas bila aplikasi memakai penyedia identitas eksternal. Nama/email dalam mode itu berasal dari penyedia identitas dan disinkronkan sebagai shadow user. Aksi yang tersedia tetap tunduk pada permission dan batas scope data.
- Detail dan edit role mengarah ke alur utama yang sama, bukan form tandingan.

**Selesai bila:** user di luar scope tidak terlihat, status sinkronisasi/assignment tidak disamakan dengan role lokal bila integrasi identitas dipakai, dan daftar tetap dapat dipakai saat kosong atau saat sebagian data gagal dimuat.

## 3. User Assignment to Role (UI dan logic)

- Pisahkan keputusan memberi akses masuk aplikasi dari keputusan memberi role domain lokal. Jika identitas dikelola pihak luar, payload penyedia identitas tidak boleh menentukan role domain rinci tanpa kontrak yang eksplisit.
- Jika assignment diterima melalui event, buat atau aktifkan shadow user dengan role lokal kosong. Sampai role lokal dipilih, akses domain mengikuti deny-by-default. Pilihan role dibatasi pada role yang tersedia di scope tersebut.
- UI dapat menetapkan, mengganti, dan mencabut role lokal sesuai wewenang. Bila operasi massal disediakan, batasi pilihan pada assignment aktif di workspace yang sama, tampilkan dampaknya sebelum simpan, dan terapkan secara atomik.
- Perubahan role mengubah hak efektif pada request berikutnya; pencabutan akses atau penonaktifan user mencabut sesi/token aktif. Pertahankan record historis dan attribution audit.
- Lindungi penetapan diri sendiri, admin, serta role yang sedang dipakai sesuai kebijakan aplikasi. Catat pelaku, target, role lama/baru, workspace, dan waktu dalam audit.

**Selesai bila:** assignment di luar scope ditolak, role kosong tidak memperoleh izin domain, dan perubahan dapat diuji melalui UI maupun request API langsung.

## 4. Integrasi identitas eksternal (jika ada)

Bagian ini hanya berlaku bila aplikasi memakai SSO, registrasi, atau sinkronisasi pengguna dari layanan identitas lain. Aplikasi mandiri dengan autentikasi lokal tidak memerlukan Ecopa maupun kontrak webhook Akunta.

- Tentukan pemilik identitas, alur registrasi/login, aturan assignment, dan sumber kebenaran nama/email sebelum integrasi dibuat.
- Verifikasi signature callback atas raw body, validasi event dan subject, simpan credential dengan aman, serta terapkan event secara idempotent. Pencabutan akses harus berlaku pada sesi/token aktif.
- Balas sukses hanya setelah event diterapkan atau dikenali sebagai pengulangan. Catat receipt sukses dan percobaan delivery secara terpisah; sediakan retry serta rekonsiliasi untuk event yang terlewat.
- Tetapkan perilaku saat layanan identitas tidak tersedia. Mode lokal, bila disediakan, memakai autentikasi dan permission normal tanpa bypass.

**Selesai bila:** replay event aman, urutan event yang tertunda dapat dipulihkan, secret tidak muncul pada respons/log, dan pencabutan akses langsung berlaku.

## 5. Cron Management

### 5.1 Setup dan alerting

- Bila ada tugas terjadwal, dokumentasikan cara menjalankan scheduler sesuai framework dan OS/deployment, jadwal tiap tugas, timezone, serta kebutuhan queue worker bila job berjalan asinkron. Halaman Settings dapat menampilkan instruksi setup yang sesuai deployment.
- Jalankan heartbeat terjadwal dan tampilkan waktu terakhir, sehat/terlambat/tidak pernah berjalan. Untuk tugas penting, tampilkan status terakhir dan perkiraan waktu eksekusi berikutnya. Ambang keterlambatan ditetapkan berdasar jadwal aplikasi.
- Tampilkan peringatan in-app untuk scheduler mati, job gagal, atau job overdue. **Usulan untuk review:** notifikasi eksternal dan eskalasi insiden ditambahkan bila ada kanal operasional yang disepakati.
- Cegah eksekusi tumpang tindih dan duplikasi pada deployment multi-server. Job harus aman diulang dan tidak boleh memperoleh hak domain lebih luas daripada aksi yang diwakilinya.

### 5.2 History

- Catat nama tugas, waktu mulai/selesai, durasi, hasil, exit code, serta pesan error yang disanitasi. Sediakan riwayat terbatas dengan filter tugas/status dan akses hanya bagi pengelola operasional yang berwenang.
- Pisahkan kegagalan tugas dari masalah scheduler global; keduanya perlu diagnosa yang berbeda. Riwayat tidak menyimpan token, secret, data pribadi berlebihan, atau payload penuh.

### 5.3 Auto trash history

- Hapus **hanya log operasional cron** yang melewati masa retensi terkonfigurasi. Jangan menghapus data bisnis, audit, receipt idempotensi, atau bukti transaksi melalui pemangkasan riwayat cron.
- Tetapkan retensi log cron yang sesuai kebutuhan aplikasi; pertahankan audit dan data bisnis menurut kebijakan retensinya sendiri.
- Job pemangkasan sendiri perlu dapat dipantau tanpa menghasilkan pertumbuhan log yang tidak terbatas. Uji batas tanggal, isolasi data, serta kegagalan pemangkasan.

## 6. SPA dan tabbed page

- Halaman interaktif memakai shell aplikasi bersama: autentikasi, scope data aktif bila ada, navigasi, state loading/error, dan permission efektif. Saat scope berganti, kosongkan state yang terikat pada scope lama dan muat ulang data serta hak akses.
- Settings dapat menggunakan section/tab dalam satu halaman. Tab User & Roles, Role Management, Integration, dan Cron hanya ditampilkan jika relevan dan berizin. Tab aktif boleh dicerminkan dalam URL agar deep link, refresh, back/forward browser, dan kembali dari halaman detail tetap masuk ke konteks yang tepat.
- Perubahan tab tidak boleh mencampur data dua scope atau meninggalkan form yang dapat disimpan terhadap scope lama. Definisikan perilaku untuk draft belum disimpan, pemuatan lambat, serta tab yang aksesnya dicabut saat sesi berjalan.
- Detail dan edit objek yang sama menggunakan satu form utama dengan mode edit/read-only berdasar permission dan status record. Endpoint tetap memeriksa setiap mutasi. Modal dipakai untuk konfirmasi atau pilihan terbatas, bukan editor paralel.
- Sediakan state tab yang aksesibel: label, fokus keyboard, penanda aktif, dan relasi tab-panel yang benar. Navigasi tetap dapat digunakan ketika JavaScript lambat atau API sedang gagal, sejauh arsitektur aplikasi mengizinkan.
- **Indikator state aktif pada kontrol daftar (wajib bila halaman memakai filter):** setiap kontrol filter pada halaman/tabel daftar menampilkan penanda yang jelas saat filter sedang aktif, misalnya titik merah kecil di pojok kanan atas ikon filter atau badge jumlah filter aktif, sehingga pengguna segera tahu daftar sedang terfilter. Penanda memiliki `aria-label`/teks alternatif yang menyebut keadaan atau jumlah filter aktif (misal "Filter, 2 aktif"), hilang ketika filter direset, dan tidak menggantikan otorisasi atau scope data. Terapkan prinsip yang sama pada sort atau pencarian lanjutan yang mengubah hasil daftar bila ada. **Tetapkan satu gaya penanda sebagai standar aplikasi** (titik merah atau badge angka) agar konsisten antar halaman. Akunta sudah memakai pola ini — titik merah `journal-filter-active-dot` pada `apps/accounting-web/src/routes/journals/+page.svelte` dan badge jumlah pada `akun/+page.svelte`; samakan menjadi satu gaya untuk app baru.

### 6.1 Pemulihan preferensi dan riwayat kerja setelah browser ditutup

- Simpan pilihan tampilan yang memang merupakan preferensi user, termasuk light/dark mode dan tema/warna workspace bila tersedia. Perubahan berlaku segera; saat browser dibuka lagi pada hari berikutnya, pilihan terakhir diterapkan sebelum atau saat shell aplikasi tampil agar tidak berkedip ke tema default.
- Simpan daftar tab aplikasi yang masih terbuka, urutannya, dan tab aktif saat user membuka, berpindah, menutup, atau mengubah tab. Setelah browser ditutup dan dibuka lagi pada perangkat/browser yang sama, pulihkan tab yang masih terbuka beserta tab aktif terakhir. Tab yang sengaja ditutup tidak muncul kembali. Riwayat ini adalah state navigasi, bukan salinan isi halaman.
- Pisahkan riwayat tab menurut aplikasi, user, dan workspace/entity bila ada. Saat login sebagai user lain atau berpindah workspace, jangan tampilkan tab maupun data milik scope sebelumnya. Preferensi global seperti dark mode boleh tetap berlaku pada browser yang sama; preferensi khusus workspace mengikuti workspace terkait.
- Setelah login dan sebelum memulihkan tiap tab, validasi ulang sesi, assignment, permission efektif, keberadaan route/record, serta scope entity. Buang tab yang sudah tidak boleh diakses atau tidak valid. Jika tab aktif terakhir tidak dapat dipulihkan, buka tab lain yang valid atau halaman awal yang diizinkan. Jangan memulihkan data rahasia, respons API, token, isi form sensitif, atau draft transaksi dari penyimpanan browser hanya untuk membuka tab.
- Settings dapat mengingat section/tab terakhir bila user kembali ke Settings, selama izin section tersebut masih berlaku. Deep link yang secara eksplisit dipilih user tetap diutamakan atas riwayat lokal. Untuk form dengan perubahan belum disimpan, tampilkan peringatan saat menutup tab/navigasi; penyimpanan draft lintas sesi memerlukan aturan domain dan keamanan tersendiri.
- Jika penyimpanan browser tidak tersedia, penuh, atau korup, aplikasi tetap dapat digunakan dengan preferensi default dan tab awal yang aman. Jangan menganggap `localStorage` sebagai sumber otorisasi ataupun sinkronisasi antar perangkat; kebutuhan lintas perangkat harus diputuskan dan dirancang terpisah.

**Selesai bila:** dark mode dan tab terbuka bertahan setelah browser ditutup lalu dibuka kembali pada browser yang sama; tab yang ditutup tidak kembali; deep link, pergantian user/workspace, dan pencabutan izin tidak menampilkan data yang tidak berhak diakses; pengguna read-only tidak dapat menyimpan melalui UI ataupun API.

## 7. Bahasa pemrograman, framework, dan bahasa antarmuka

- Pilih bahasa dan framework per aplikasi melalui keputusan arsitektur yang mencatat kebutuhan domain, kemampuan tim, komponen yang dapat digunakan ulang, integrasi, deployment, dan biaya pemeliharaan. Stack Akunta tidak otomatis wajib untuk aplikasi mandiri.
- Jika aplikasi memerlukan SPA, pilih tooling yang sesuai dan pertahankan kontrak API, autentikasi, permission, serta pengalaman pengguna yang konsisten di dalam aplikasi.
- UI untuk pengguna Indonesia memakai **Bahasa Indonesia** sebagai default, format tanggal/angka/mata uang sesuai locale dan timezone workspace. Kode, kontrak API, dan nama permission memakai penamaan teknis yang konsisten. **Usulan untuk review:** dukungan UI multi-bahasa sejak versi pertama atau disiapkan untuk fase berikutnya.
- Jika aplikasi terhubung ke layanan lain untuk membuat transaksi, gunakan kontrak API layanan tersebut. Sertakan autentikasi service, scope data, idempotency key, dan penanganan kegagalan lintas aplikasi bila relevan.

## 8. Error handling

- API mengembalikan status HTTP dan bentuk error yang konsisten: kode mesin yang stabil, pesan aman bagi pengguna, dan detail validasi per field bila relevan. Bedakan 401 (belum login), 403 (tidak berizin), 404 (tidak ada/tidak terlihat dalam scope), 409 (konflik), 422 (validasi), 429 (rate limit), dan 5xx (gangguan layanan).
- UI menampilkan error dekat tindakan yang gagal; pertahankan input/draft yang belum tersimpan. Gunakan toast untuk pemberitahuan singkat, pesan field untuk validasi, serta halaman/state khusus untuk akses ditolak dan layanan tidak tersedia. Jangan menampilkan stack trace atau secret.
- Request ke layanan eksternal yang timeout atau gagal sementara memakai retry terbatas dengan backoff dan idempotensi. Kegagalan permanen masuk antrean penanganan atau status yang terlihat operator; jangan diam-diam menganggap sinkronisasi berhasil.
- Log server memakai correlation/request ID dan konteks aman untuk diagnosis, tanpa credential atau payload sensitif. Kesalahan domain tidak boleh meninggalkan perubahan parsial; gunakan transaksi pada operasi yang harus atomik.

## 9. Security

- Wajib ada pemeriksaan autentikasi, permission, dan akses record pada backend; periksa juga assignment aplikasi dan tenant/entity bila arsitekturnya memakai keduanya. Terapkan deny-by-default, least privilege, perlindungan CSRF untuk sesi, validasi input, escaping output, batas upload, rate limiting, dan proteksi terhadap IDOR/mass assignment.
- Secret SSO, webhook, API, dan database disimpan melalui konfigurasi aman serta dienkripsi bila persisten. Sediakan rotasi/revokasi credential. Verifikasi signature webhook pada raw body, lindungi replay, dan jangan mengekspos detail internal lewat error.
- Audit aksi sensitif mengikuti persyaratan khusus pada bagian 10. Audit bisnis serta data historis mengikuti kebijakan retensi masing-masing, terpisah dari cron/webhook log operasional.
- Uji akses negatif, isolasi scope data, role custom, pencabutan sesi, upload, serta jalur otomatis sesuai fitur aplikasi. Jika ada webhook, uji signature palsu dan replay. Jalankan review dependency dan threat modeling pada batas integrasi sebelum rilis.
- Repository [UnitOneAI/SecuritySkills](https://github.com/UnitOneAI/SecuritySkills) dapat dipakai sebagai **bahan bantu review keamanan** (misalnya threat modeling dan secure code review). Skill eksternal harus ditinjau sebelum dijalankan; temuan harus dibuktikan terhadap kode, test, dan kontrak aplikasi. Dokumen ini tidak menganggap repository tersebut sudah terpasang atau audit keamanan sudah dilakukan.

## 10. Audit trail data ekonomis dan privat

- Setiap aplikasi mengidentifikasi data yang **berdampak ekonomis** (misalnya nilai aset, penyusutan, gaji, pembayaran, harga, kewajiban, dan transaksi) dan data **privat** (misalnya identitas pegawai, dokumen internal, kontrak, rekening, dan lampiran) bila data tersebut ada. Daftar field/objek yang diaudit ditetapkan pada spesifikasi domain aplikasi.
- Catat pembuatan, perubahan, penghapusan/pemulihan, perubahan status, approval/penolakan, serta pengiriman atau sinkronisasi yang mengubah state ekonomi. Catat juga pembacaan detail, unduhan lampiran, dan ekspor data sensitif sesuai klasifikasi data dan risiko; setiap ekspor massal wajib diaudit. Percobaan akses yang ditolak dicatat sebagai security event tanpa membocorkan isi record.
- Setiap event memuat pelaku (atau identitas service/job), aksi, waktu, scope data bila ada, jenis dan ID resource, hasil, sumber permintaan (UI/API/job), serta request/correlation ID. Untuk mutasi, simpan perubahan yang relevan sebagai nilai lama/baru atau ringkasan perubahan yang dapat ditelusuri. Catat alasan keputusan dan referensi transaksi eksternal bila workflow mewajibkannya.
- Metadata audit memakai minimisasi data: jangan simpan password, token, secret, nomor rekening lengkap, dokumen penuh, payload webhook penuh, atau isi pribadi yang tidak diperlukan untuk pembuktian. Masking/redaction harus berlaku juga pada error dan log operasional. Jika nilai penuh memang diperlukan sebagai bukti, simpan di penyimpanan bukti yang akses dan retensinya diatur tersendiri; audit menyimpan referensinya.
- Audit bersifat append-only dan tahan terhadap perubahan/penghapusan oleh alur aplikasi biasa. Mutasi bisnis kritis dan pencatatan audit keberhasilannya harus atomik; kegagalan mencatat audit membatalkan mutasi. Event pembacaan/akses ditolak dapat memakai jalur terpisah yang tetap andal dan tidak mengubah data bisnis.
- UI riwayat audit hanya dapat dibuka oleh role dengan izin audit yang sesuai, dibatasi scope data, dapat difilter berdasarkan waktu, pelaku, aksi, dan objek, serta dapat menunjukkan urutan perubahan tanpa mengungkap data privat yang tidak berhak dilihat pengguna. Akses terhadap audit itu sendiri juga tercatat bila menyangkut data sensitif.
- Retensi audit terpisah dari retensi riwayat cron. Tetapkan masa retensi menurut domain dan regulasi yang berlaku; jangan memangkas audit bersama job auto trash cron. Pemusnahan setelah masa retensi mengikuti kebijakan data dan kebutuhan bukti yang berlaku.
- Bila aplikasi menjanjikan pengaturan masa retensi audit melalui Settings, pengaturan itu harus benar-benar terpasang end-to-end: UI untuk mengubah nilai dengan izin terbatas, penyimpanan nilai, dan job terjadwal yang menegakkan pemusnahan sesuai batas kebijakan audit, dengan setiap perubahan konfigurasi tercatat dalam audit. Adanya kolom atau nilai retensi di basis data saja **tidak boleh dianggap** fitur retensi audit sudah selesai. Catatan akunta: kolom `audit_retention_days` sudah ada pada tenant (default 1095) tetapi belum punya UI pengaturan maupun job pemusnahan, sehingga statusnya PERLU KEPUTUSAN/DALAM PROSES, bukan TERVERIFIKASI.

**Selesai bila:** test membuktikan mutasi bernilai ekonomis menghasilkan audit yang benar, kegagalan audit membatalkan mutasi, akses/ekspor data privat tercatat sesuai klasifikasi, pengguna di luar scope tidak dapat membaca riwayat, dan metadata tidak memuat nilai rahasia.

## 11. Development lokal yang ringan, tanpa Docker

- Utamakan menjalankan backend dan frontend langsung dari source dengan launcher/perintah lokal yang terdokumentasi. Bila aplikasi memakai server database, jalankan sebagai layanan lokal, misalnya PostgreSQL di Windows/Laragon, dan akses melalui `127.0.0.1` serta port yang sesuai. Database embedded seperti SQLite dapat dipakai bila cocok dengan kebutuhan domain. Docker bukan prasyarat development lokal; gunakan hanya bila kebutuhan aplikasi atau deployment memang membenarkannya.
- Sediakan petunjuk singkat untuk memasang dependency, menyalakan layanan database, menyiapkan database dan kredensial lokal, menjalankan migrasi/seed, memulai aplikasi, login, melihat log, dan menghentikan proses. Tentukan juga queue worker dan scheduler lokal jika fitur memerlukannya.
- Gunakan database development tersendiri dan kredensial lokal. Jangan memakai credential production, menjalankan migration production, atau otomatis mengubah `.env` dan database pengguna. Seeder harus idempotent dan jelas targetnya.
- Jika aplikasi mendukung mode offline/mandiri, login lokal memakai user/role biasa dengan pemeriksaan autentikasi dan permission yang sama. Layanan identitas atau aplikasi eksternal tidak menjadi prasyarat kecuali fitur yang sedang diuji memang bergantung padanya.
- Sediakan data latihan yang jelas asal-usulnya bila diperlukan untuk mencoba alur produk. Pada aplikasi multi-entity, siapkan entity demo aktif yang berisi fixture domain. Semua record dummy memiliki marker/provenance yang dapat diverifikasi; pemulihan/pembersihan hanya menyentuh record bertanda pada scope yang sama dan mempertahankan input manual.
- Dokumentasikan setup production secara terpisah. Pilihan Docker untuk production tidak memaksa development lokal memakai Docker; PostgreSQL production pun dapat berjalan di luar container sesuai arsitektur deployment.

**Selesai bila:** dari checkout bersih, developer dapat menyalakan database lokal dan aplikasi tanpa Docker, menjalankan alur yang relevan sesuai izin, lalu menghentikan proses tanpa memengaruhi data production atau data lokal lain.

## 12. Entity Management, aktivasi, dan pemilih entity (jika multi-entity)

- Sediakan pengelolaan entity pada alur Settings/workspace yang sudah ada: daftar entity aktif dan arsip, pencarian, profil, pembuatan bila diizinkan, pengubahan identitas/konfigurasi, aktivasi/nonaktivasi, serta arsip/pemulihan menurut aturan aplikasi. Gunakan form dan endpoint utama yang sama; jangan membuat pengelolaan entity paralel hanya untuk mode dummy.
- Entity dummy tersedia dan terisi setelah seed lokal bila aplikasi memerlukan data latihan. Tampilkan label **Demo/Dummy** dan metadata dataset yang relevan agar tidak tertukar dengan entity operasional. Perlindungan terhadap hapus permanen dan pemulihan fixture mengikuti provenance.
- Admin yang berwenang dapat mengaktifkan atau menonaktifkan entity. Entity nonaktif/arsip tidak dapat dipilih untuk pekerjaan aktif dan mutasi domainnya ditolak backend, tetapi histori tetap dapat diakses sesuai permission dan kebijakan arsip. Jangan menonaktifkan entity aktif terakhir bila hal itu membuat instalasi tidak dapat digunakan tanpa jalur pemulihan. Setiap perubahan status diaudit.
- Tampilkan **dropdown pemilih entity di toolbar kanan atas** pada seluruh halaman utama aplikasi multi-entity. Dropdown memperlihatkan entity aktif yang dapat diakses user, menandai pilihan saat ini dan entity dummy bila ada, serta memberi jalan ke Entity Management bagi user yang berwenang. Entity nonaktif/arsip tidak muncul sebagai pilihan aktif.
- Saat entity dipilih, perbarui konteks tenant/entity untuk API, permission, periode atau filter domain, serta tab dan riwayat kerja. Muat ulang data milik entity tujuan dan jangan tampilkan state entity sebelumnya. Pilihan terakhir boleh dipulihkan saat login berikutnya hanya jika entity masih aktif dan user masih berhak mengaksesnya; jika tidak, pilih entity valid atau tampilkan keadaan tanpa akses yang jelas.
- Dalam mode terintegrasi, hormati assignment entity dari penyedia identitas dan mirror yang berlaku; atribut identitas milik penyedia tidak diubah sepihak oleh aplikasi. Dalam mode lokal, pengelolaan dan assignment entity dilakukan oleh mekanisme lokal yang sama ketatnya. Perubahan mode tidak boleh membuat entity dummy memperoleh akses lintas workspace.

**Selesai bila:** entity demo, bila disediakan, terisi dan dapat dipilih dari dropdown kanan atas; aktivasi/nonaktivasi langsung memengaruhi daftar pilihan dan otorisasi backend; perpindahan entity tidak membocorkan data atau tab entity lain.

## Jika app adalah child app Akunta

Bagian ini **hanya** berlaku untuk aplikasi yang menjadi bagian ekosistem Akunta. Aplikasi mandiri di luar Akunta tidak wajib memakai Ecopa, Accounting API, `modules/api-client`, role Inspector Fiskal, buku Intern/Fiskal, atau stack dan pola deployment Akunta. Tentukan status integrasi sejak awal agar requirement khusus ini tidak terbawa ke produk yang tidak memerlukannya.

- **Identitas dan registrasi:** mode terintegrasi memakai registrasi/approval dan SSO Ecopa. Wizard awal meminta Ecopa URL dan Registration Token; backend mengirim nama, slug, dan base URL kanonis. Setelah approval, simpan credential terenkripsi. Detail callback, signature, status, dan retry mengikuti `docs/CHILD_APP_REGISTRATION_GUIDE.md`.
- **User delivery:** terima callback di `/webhooks/ecopa`, verifikasi HMAC atas raw body dengan secret yang sesuai fase, dan validasi event, app slug, serta subject. Tangani `user.assigned`, `user.updated`, `user.revoked`, `user.deleted`, `app.registration.approved`, dan `app.registration.rejected` secara idempotent berdasarkan `event_id`. Event assignment membuat shadow user dengan role domain lokal kosong; admin child app yang menetapkan role pada entity/workspace yang diizinkan. Pencabutan akses menonaktifkan sesi/token aktif; histori domain tetap tersimpan.
- **Keandalan delivery:** balas 2xx hanya setelah event diterapkan atau sudah pernah diterapkan. Simpan receipt sukses terpisah dari log percobaan delivery. Jalankan retry dan rekonsiliasi terhadap Ecopa untuk event yang terlewat, tanpa menghidupkan lagi assignment yang dicabut tanpa bukti otoritatif. Batasi tampilan log operasional bagi admin yang berwenang.
- **Permission dan data:** child app memiliki katalog permission dan role domain sendiri, dengan isolasi tenant/entity, assignment, dan audit. Gunakan pola Settings Accounting sebagai referensi UI bila membantu. Batas Inspector Fiskal dan buku Intern/Fiskal milik Accounting tidak otomatis menjadi role atau ledger child app; bila child app membaca atau membuat jurnal, kontrak dan pembatasan Accounting tetap berlaku pada API-nya.
- **Integrasi jurnal:** aplikasi domain yang membuat jurnal memakai Accounting melalui `modules/api-client`, bukan impor model/service internal Accounting. Kirim autentikasi service, tenant/entity context, dan idempotency key; tangani kegagalan serta rekonsiliasi lintas aplikasi. Pada mode lokal, sediakan Accounting lokal atau endpoint integrasi yang terdokumentasi bila alur tersebut perlu diuji.
- **Stack dan deployment:** Laravel/PHP dan SvelteKit/TypeScript (Svelte 5 dengan runes, autentikasi Sanctum SPA, UI default Bahasa Indonesia — **bukan React**) adalah baseline Accounting saat ini, bukan kewajiban otomatis bagi child app baru. Pertimbangkan `packages/akunta-ui` untuk pengalaman UI yang serupa, serta modul reusable Accounting agar fitur umum **dipakai ulang, bukan ditulis ulang**: `modules/rbac` (tenant, role, dan permission), `modules/audit` (audit log append-only), `modules/ecopa-client` (integrasi identitas/webhook), `modules/api-client`, dan `modules/ui`. Verifikasi versi dan kontrak tiap modul sebelum mengandalkannya; jangan impor model/service internal Accounting secara langsung. Development lokal dapat memakai database PostgreSQL sebagai layanan Windows/Laragon dan proses aplikasi native tanpa Docker. Baseline production Accounting saat ini memakai Docker Compose dengan PostgreSQL eksternal; keputusan production child app dicatat tersendiri.
- **Mode latihan:** mode lokal boleh berjalan tanpa Ecopa dengan login, role, dan permission normal. Jika memakai fixture Accounting **PT. Fake Data**, ikuti kontrak provenance, reset, dan sandbox pada `docs/spec.md` serta `docs/decisions.md`. Child app lain membuat fixture domainnya sendiri; jangan menyalin data dummy tanpa marker atau menghapus input manual.

## Poin tambahan untuk review pemilik produk

Poin berikut melengkapi area di atas dan **belum menjadi keputusan produk final**. Poin yang menyebut Ecopa/Accounting hanya berlaku untuk child app Akunta:

1. **Kontrak registrasi dan lifecycle child app:** siapa dapat memasang, menonaktifkan, dan mencabut aplikasi; bagaimana statusnya terlihat di Ecopa dan Accounting.
2. **Onboarding workspace/entity:** data minimum saat pertama masuk, migrasi data awal, serta perilaku saat entity dinonaktifkan atau dipulihkan.
3. **Integrasi ke Accounting:** aksi domain mana yang menghasilkan jurnal, kapan dikirim, mekanisme retry/idempotensi, dan rekonsiliasi bila child app berhasil tetapi posting Accounting gagal.
4. **Observability dan pemulihan:** health check, queue monitoring, backup/restore, target waktu pemulihan, serta siapa menerima alert eksternal.
5. **Kualitas rilis:** test kontrak antar aplikasi, migrasi forward-compatible, accessibility, performa daftar besar, dan versi API/kompatibilitas saat child app diperbarui terpisah.
6. **Kebijakan data:** klasifikasi data pribadi, retensi per jenis data, ekspor, arsip, dan penghapusan yang tidak merusak audit atau referensi akuntansi.

## Checklist penerimaan aplikasi baru

- [ ] Role/permission UI, assignment, dan otorisasi backend diuji sesuai model akses aplikasi; isolasi workspace diuji bila ada.
- [ ] Scheduler, alert, riwayat, dan retensi log diuji bila aplikasi memiliki tugas terjadwal.
- [ ] SPA/tab, akses read-only, loading/error state, dan navigasi kembali bekerja bila UI memakai pola tersebut.
- [ ] Preferensi tema dan tab terbuka pulih sesuai desain; perubahan izin dan pergantian user/scope tidak membocorkan data.
- [ ] Review keamanan, audit trail, pemeriksaan statis, dan test regresi relevan selesai sebelum rilis.
- [ ] Data ekonomis/privat dan event auditnya diidentifikasi; mutasi, akses sensitif, ekspor, retensi, dan isolasi riwayat audit diuji bila relevan.
- [ ] Aplikasi dan database lokal bila ada dapat dijalankan tanpa Docker; migrasi/seed hanya menyentuh database development yang dituju.
- [ ] Entity Management dan pemilih entity mendukung izin, status, dan pergantian scope bila aplikasi multi-entity.
- [ ] **Khusus child app Akunta:** registrasi/SSO Ecopa, webhook terverifikasi, receipt idempotensi, dan rekonsiliasi diuji bila mode terintegrasi dipakai.
- [ ] **Khusus child app Akunta:** kontrak Accounting, idempotensi, dan kegagalan lintas aplikasi diuji bila domain membuat jurnal.
