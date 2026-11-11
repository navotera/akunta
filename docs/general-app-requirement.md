# General App Requirements untuk Child App Akunta

**Status:** Draft untuk review produk dan arsitektur

**Cakupan:** Aplikasi baru dalam ekosistem Akunta, misalnya Manajemen Aset, Payroll, dan Manajemen Kas

**Acuan:** `docs/CHILD_APP_REGISTRATION_GUIDE.md`, `docs/cross-app-rbac.md`, `docs/permission-management.md`, `docs/spec.md`, dan `docs/decisions.md`

Dokumen ini adalah checklist kemampuan umum child app, bukan spesifikasi domain Manajemen Aset. Kontrak yang sudah dikunci dalam dokumen acuan tetap berlaku. Jika ada perbedaan antara dokumen historis dan implementasi terbaru, verifikasi kontrak dan test sebelum mengunci keputusan baru. Setiap child app memiliki data dan aturan domain sendiri. Mode terintegrasi mengikuti identitas Ecopa; mode uji lokal memakai autentikasi lokal tanpa Ecopa. Keduanya mempertahankan batas tenant/entity, role, permission, dan aturan domain.

## 1. Role Management dan Permission Management (UI dan logic)

- Child app memiliki katalog permission domain sendiri. Definisikan permission per aksi yang berbeda wewenang: baca daftar/detail, buat, ubah, hapus, persetujuan, impor/ekspor, dan aksi otomatis bila ada. Kode permission stabil, berlabel jelas, dikelompokkan menurut fitur, dan memiliki dependency bila suatu aksi memerlukan izin baca.
- Sediakan UI untuk mencari role, membuat role custom, mengubah nama/deskripsi, melihat anggota, mengatur matriks izin, dan memulihkan izin bawaan. Gunakan pola **Settings → Role Management** Accounting sebagai referensi interaksi; child app boleh menyesuaikan istilah domain.
- Role bawaan dan hak awalnya ditetapkan per child app. Role custom dapat dimulai kosong atau menyalin snapshot izin role lain. Pemulihan role custom kembali ke snapshot awalnya; pemulihan role bawaan kembali ke default child app. Perubahan satu workspace tidak mengubah workspace lain atau preset global.
- Hak efektif dihitung dari seluruh assignment aktif pada workspace yang benar, dengan allowlist eksplisit dan batas domain yang tidak boleh dilampaui. Permission baru tidak otomatis diberikan kepada role custom atau override yang belum mencantumkannya.
- UI mengikuti hak efektif, tetapi setiap endpoint dan job tetap melakukan otorisasi backend terhadap aksi, record induk, tenant/entity, status, dan kondisi domain. Tidak ada akses hanya karena tombol tersembunyi atau nama role tampak cocok.
- Perubahan role, permission, dan penetapan anggota memiliki audit trail. Perubahan bersamaan memakai mekanisme revisi/konflik agar penyimpanan lama tidak menimpa perubahan admin lain. Batasi self-demotion dan hilangnya administrator terakhir sesuai aturan child app.
- Untuk fitur baru, tinjau permission, default role, grant/revoke role custom, akses request langsung, dan isolasi workspace. Accounting memiliki batas khusus Inspector Fiskal dan buku Intern/Fiskal; child app lain menentukan batas domainnya sendiri tanpa menyalin role tersebut secara otomatis.

**Selesai bila:** pengguna tanpa izin ditolak oleh backend, grant/revoke role custom berlaku pada workspace yang tepat, dan UI menampilkan keadaan read-only untuk pengguna yang hanya boleh membaca.

## 2. User List (UI)

- Sediakan daftar pengguna yang berhak masuk child app pada workspace aktif, serta pengguna Ecopa yang tersedia untuk ditetapkan bila kontrak Ecopa mengizinkannya. Bedakan dengan jelas **ditetapkan**, **belum memiliki role lokal**, **dicabut**, dan **dinonaktifkan**.
- Tampilkan nama, email, status akses, role lokal, serta cakupan entity/workspace yang relevan. Sediakan pencarian, filter status/role, pagination, empty state, loading state, dan pesan kegagalan yang dapat ditindaklanjuti.
- Daftar tidak menjadi sumber utama perubahan identitas. Nama/email dalam mode terintegrasi berasal dari Ecopa dan disinkronkan sebagai shadow user. Aksi yang tersedia di daftar tetap tunduk pada permission dan batas workspace.
- Gunakan halaman **Settings → User & Roles** Accounting sebagai referensi; detail/edit role mengarah ke alur utama yang sama, bukan form tandingan.

**Selesai bila:** user dari workspace lain tidak terlihat, status sinkronisasi/assignment tidak disamakan dengan role lokal, dan daftar tetap dapat dipakai saat kosong atau saat sebagian data gagal dimuat.

## 3. User Assignment to Role (UI dan logic)

- Pisahkan dua keputusan: Ecopa memberi atau mencabut akses user ke child app; admin child app memilih role domain lokal pada entity/workspace yang diizinkan. Payload Ecopa tidak boleh menentukan role domain rinci.
- Event `user.assigned` membuat atau mengaktifkan shadow user dan assignment dengan role lokal kosong. Sampai role lokal dipilih, akses domain mengikuti aturan deny-by-default child app. Pilihan role dibatasi pada role yang tersedia di workspace tersebut.
- UI dapat menetapkan, mengganti, dan mencabut role lokal sesuai wewenang. Bila operasi massal disediakan, batasi pilihan pada assignment aktif di workspace yang sama, tampilkan dampaknya sebelum simpan, dan terapkan secara atomik.
- Perubahan role mengubah hak efektif pada request berikutnya; pencabutan akses Ecopa atau penonaktifan user mencabut sesi/token aktif. Pertahankan record historis dan attribution audit.
- Lindungi penetapan diri sendiri, admin, serta role yang sedang dipakai sesuai kebijakan aplikasi. Catat pelaku, target, role lama/baru, workspace, dan waktu dalam audit.

**Selesai bila:** assignment lintas workspace ditolak, role kosong tidak memperoleh izin domain, dan perubahan dapat diuji melalui UI maupun request API langsung.

## 4. Ecopa User Delivery dan registrasi aplikasi

Bagian ini berlaku saat **mode terintegrasi Ecopa** aktif. Pada mode uji lokal bagian 11, Ecopa tidak dijalankan dan tidak menjadi prasyarat login atau pemakaian entity dummy.

- Sebelum login terintegrasi, child app mendaftar melalui alur approval Ecopa. Wizard awal hanya meminta Ecopa URL dan Registration Token; backend mengirim nama, slug aplikasi, dan base URL kanonis. Setelah approval, child app memakai SSO Ecopa. Detail callback, signature, status, dan retry mengikuti `docs/CHILD_APP_REGISTRATION_GUIDE.md`.
- Terima callback pada satu endpoint standar `/webhooks/ecopa`. Verifikasi HMAC atas raw body memakai secret yang sesuai fase registrasi atau fase operasional; validasi event, app slug, dan subject sebelum mengubah data. Simpan credential terenkripsi dan jangan kirim secret ke browser/log.
- Tangani `user.assigned`, `user.updated`, `user.revoked`, dan `user.deleted` secara idempotent berdasarkan `event_id`. `user.updated` memperbarui mirror nama/email; `user.revoked` mencabut akses; `user.deleted` menonaktifkan user sambil mempertahankan histori domain. Event registrasi `app.registration.approved` dan `app.registration.rejected` ditangani sesuai fase bootstrap.
- Balas 2xx hanya setelah event diterapkan atau sudah pernah diterapkan. Dependency yang belum siap menghasilkan respons retryable; payload/signature salah tidak mengubah data. Simpan receipt sukses terpisah dari log setiap percobaan delivery, dan sediakan tampilan log operasional terbatas bagi admin aplikasi.
- Jalankan rekonsiliasi berkala terhadap Ecopa untuk memperbaiki webhook yang terlewat. Reconcile tidak boleh menghidupkan lagi assignment yang sudah dicabut tanpa bukti dari sumber otoritatif. Definisikan perilaku saat Ecopa sementara tidak tersedia.

**Selesai bila:** replay event aman, urutan event yang tertunda dapat dipulihkan, secret tidak muncul pada respons/log, dan pencabutan akses langsung berlaku.

## 5. Cron Management

### 5.1 Setup dan alerting

- Dokumentasikan cara memasang pemanggil Laravel Scheduler pada OS/container, jadwal tiap tugas, timezone, serta kebutuhan queue worker bila job berjalan asinkron. Halaman Settings menampilkan instruksi setup yang sesuai deployment.
- Jalankan heartbeat terjadwal dan tampilkan waktu terakhir, sehat/terlambat/tidak pernah berjalan. Untuk tugas penting, tampilkan status terakhir dan perkiraan waktu eksekusi berikutnya. Accounting saat ini memakai heartbeat tiap menit dan menganggapnya stale setelah 180 detik; child app menetapkan ambang berdasar jadwalnya.
- Tampilkan peringatan in-app untuk scheduler mati, job gagal, atau job overdue. **Usulan untuk review:** notifikasi eksternal dan eskalasi insiden ditambahkan bila ada kanal operasional yang disepakati.
- Cegah eksekusi tumpang tindih dan duplikasi pada deployment multi-server. Job harus aman diulang dan tidak boleh memperoleh hak domain lebih luas daripada aksi yang diwakilinya.

### 5.2 History

- Catat nama tugas, waktu mulai/selesai, durasi, hasil, exit code, serta pesan error yang disanitasi. Sediakan riwayat terbatas dengan filter tugas/status dan akses hanya bagi pengelola operasional yang berwenang.
- Pisahkan kegagalan tugas dari masalah scheduler global; keduanya perlu diagnosa yang berbeda. Riwayat tidak menyimpan token, secret, data pribadi berlebihan, atau payload penuh.

### 5.3 Auto trash history

- Hapus **hanya log operasional cron** yang melewati masa retensi terkonfigurasi. Jangan menghapus data bisnis, audit, receipt idempotensi, atau bukti transaksi melalui pemangkasan riwayat cron.
- Accounting saat ini memberi default retensi log cron **30 hari**, dapat diatur **30–120 hari**, dan menjalankan pemangkasan setiap jam. Child app dapat memakai baseline ini, kecuali kebijakan retensi domain atau regulasi memerlukan masa lain.
- Job pemangkasan sendiri perlu dapat dipantau tanpa menghasilkan pertumbuhan log yang tidak terbatas. Uji batas tanggal, isolasi data, serta kegagalan pemangkasan.

## 6. SPA dan tabbed page

- Halaman interaktif memakai shell aplikasi bersama: autentikasi, workspace/entity aktif, navigasi, state loading/error, dan permission efektif. Saat workspace berganti, kosongkan state yang terikat pada workspace lama dan muat ulang data serta hak akses untuk workspace baru.
- Settings dapat menggunakan section/tab dalam satu halaman seperti Accounting. Tab User & Roles, Role Management, Integration, dan Cron hanya ditampilkan jika relevan dan berizin. Tab aktif boleh dicerminkan dalam URL agar deep link, refresh, back/forward browser, dan kembali dari halaman detail tetap masuk ke konteks yang tepat.
- Perubahan tab tidak boleh mencampur data dua workspace atau meninggalkan form yang dapat disimpan terhadap workspace lama. Definisikan perilaku untuk draft belum disimpan, pemuatan lambat, serta tab yang aksesnya dicabut saat sesi berjalan.
- Detail dan edit objek yang sama menggunakan satu form utama dengan mode edit/read-only berdasar permission dan status record. Endpoint tetap memeriksa setiap mutasi. Modal dipakai untuk konfirmasi atau pilihan terbatas, bukan editor paralel.
- Sediakan state tab yang aksesibel: label, fokus keyboard, penanda aktif, dan relasi tab-panel yang benar. Navigasi tetap dapat digunakan ketika JavaScript lambat atau API sedang gagal, sejauh arsitektur aplikasi mengizinkan.

### 6.1 Pemulihan preferensi dan riwayat kerja setelah browser ditutup

- Simpan pilihan tampilan yang memang merupakan preferensi user, termasuk light/dark mode dan tema/warna workspace bila tersedia. Perubahan berlaku segera; saat browser dibuka lagi pada hari berikutnya, pilihan terakhir diterapkan sebelum atau saat shell aplikasi tampil agar tidak berkedip ke tema default. Accounting Web saat ini menyimpan color mode di `localStorage` dan tema warna berdasarkan workspace.
- Simpan daftar tab aplikasi yang masih terbuka, urutannya, dan tab aktif saat user membuka, berpindah, menutup, atau mengubah tab. Setelah browser ditutup dan dibuka lagi pada perangkat/browser yang sama, pulihkan tab yang masih terbuka beserta tab aktif terakhir. Tab yang sengaja ditutup tidak muncul kembali. Riwayat ini adalah state navigasi, bukan salinan isi halaman.
- Pisahkan riwayat tab menurut child app, user, dan workspace/entity. Saat login sebagai user lain atau berpindah workspace, jangan tampilkan tab maupun data milik scope sebelumnya. Preferensi global seperti dark mode boleh tetap berlaku pada browser yang sama; preferensi khusus workspace mengikuti workspace terkait.
- Setelah login dan sebelum memulihkan tiap tab, validasi ulang sesi, assignment, permission efektif, keberadaan route/record, serta scope entity. Buang tab yang sudah tidak boleh diakses atau tidak valid. Jika tab aktif terakhir tidak dapat dipulihkan, buka tab lain yang valid atau halaman awal yang diizinkan. Jangan memulihkan data rahasia, respons API, token, isi form sensitif, atau draft transaksi dari penyimpanan browser hanya untuk membuka tab.
- Settings dapat mengingat section/tab terakhir bila user kembali ke Settings, selama izin section tersebut masih berlaku. Deep link yang secara eksplisit dipilih user tetap diutamakan atas riwayat lokal. Untuk form dengan perubahan belum disimpan, tampilkan peringatan saat menutup tab/navigasi; penyimpanan draft lintas sesi memerlukan aturan domain dan keamanan tersendiri.
- Jika penyimpanan browser tidak tersedia, penuh, atau korup, aplikasi tetap dapat digunakan dengan preferensi default dan tab awal yang aman. Jangan menganggap `localStorage` sebagai sumber otorisasi ataupun sinkronisasi antar perangkat; kebutuhan lintas perangkat harus diputuskan dan dirancang terpisah.

**Selesai bila:** dark mode dan tab terbuka bertahan setelah browser ditutup lalu dibuka kembali pada browser yang sama; tab yang ditutup tidak kembali; deep link, pergantian user/workspace, dan pencabutan izin tidak menampilkan data yang tidak berhak diakses; pengguna read-only tidak dapat menyimpan melalui UI ataupun API.

## 7. Bahasa pemrograman, framework, dan bahasa antarmuka

- Baseline implementasi yang benar-benar dipakai Accounting saat ini: **Laravel 11/PHP** untuk API, **SvelteKit/Svelte 5/TypeScript** untuk Accounting Web, dengan Bun/Vite untuk tooling frontend. Ecopa memakai Laravel/Filament sesuai komponen masing-masing. **React bukan standar otomatis** hanya karena child app baru.
- Pilih stack child app melalui keputusan arsitektur yang mencatat kebutuhan domain, kemampuan tim, reuse komponen, integrasi SSO, deployment, dan biaya pemeliharaan. Untuk pengalaman SPA yang sejalan dengan Accounting, prioritaskan pola SvelteKit dan `packages/akunta-ui`; jika memakai stack lain, kontrak API, auth, permission, dan UX bersama tetap harus terpenuhi.
- UI untuk pengguna Indonesia memakai **Bahasa Indonesia** sebagai default, format tanggal/angka/mata uang sesuai locale dan timezone workspace. Kode, kontrak API, dan nama permission memakai penamaan teknis yang konsisten. **Usulan untuk review:** dukungan UI multi-bahasa sejak versi pertama atau disiapkan untuk fase berikutnya.
- Aplikasi domain yang membuat jurnal harus memakai API Accounting melalui `modules/api-client`, tidak mengimpor model/service internal Accounting. Sertakan autentikasi service, tenant/entity context, idempotency key, dan penanganan kegagalan lintas aplikasi.

## 8. Error handling

- API mengembalikan status HTTP dan bentuk error yang konsisten: kode mesin yang stabil, pesan aman bagi pengguna, dan detail validasi per field bila relevan. Bedakan 401 (belum login), 403 (tidak berizin), 404 (tidak ada/tidak terlihat dalam scope), 409 (konflik), 422 (validasi), 429 (rate limit), dan 5xx (gangguan layanan).
- UI menampilkan error dekat tindakan yang gagal; pertahankan input/draft yang belum tersimpan. Gunakan toast untuk pemberitahuan singkat, pesan field untuk validasi, serta halaman/state khusus untuk akses ditolak dan layanan tidak tersedia. Jangan menampilkan stack trace atau secret.
- Request Ecopa/Accounting yang timeout atau gagal sementara memakai retry terbatas dengan backoff dan idempotensi. Kegagalan permanen masuk antrean penanganan atau status yang terlihat operator; jangan diam-diam menganggap sinkronisasi berhasil.
- Log server memakai correlation/request ID dan konteks aman untuk diagnosis, tanpa credential atau payload sensitif. Kesalahan domain tidak boleh meninggalkan perubahan parsial; gunakan transaksi pada operasi yang harus atomik.

## 9. Security

- Wajib ada pemeriksaan autentikasi, assignment aplikasi, tenant/entity, permission, dan akses record pada backend. Terapkan deny-by-default, least privilege, perlindungan CSRF untuk sesi, validasi input, escaping output, batas upload, rate limiting, dan proteksi terhadap IDOR/mass assignment.
- Secret SSO, webhook, API, dan database disimpan melalui konfigurasi aman serta dienkripsi bila persisten. Sediakan rotasi/revokasi credential. Verifikasi signature webhook pada raw body, lindungi replay, dan jangan mengekspos detail internal lewat error.
- Audit aksi sensitif mengikuti persyaratan khusus pada bagian 10. Audit bisnis serta data historis mengikuti kebijakan retensi masing-masing, terpisah dari cron/webhook log operasional.
- Uji akses negatif, lintas tenant/entity, role custom, webhook palsu/replay, pencabutan sesi, upload, serta jalur otomatis. Jalankan review dependency dan threat modeling pada boundary Ecopa ↔ child app ↔ Accounting sebelum rilis.
- Repository [UnitOneAI/SecuritySkills](https://github.com/UnitOneAI/SecuritySkills) dapat dipakai sebagai **bahan bantu review keamanan** (misalnya threat modeling dan secure code review). Skill eksternal harus ditinjau sebelum dijalankan; temuan harus dibuktikan terhadap kode, test, dan kontrak Akunta. Dokumen ini tidak menganggap repository tersebut sudah terpasang atau audit keamanan sudah dilakukan.

## 10. Audit trail data ekonomis dan privat perusahaan

- Setiap child app mengidentifikasi data yang **berdampak ekonomis** (misalnya nilai aset, penyusutan, gaji, pembayaran, harga, kewajiban, dan transaksi) dan data **privat perusahaan** (misalnya identitas pegawai, dokumen internal, kontrak, rekening, dan lampiran). Daftar field/objek yang diaudit ditetapkan pada spesifikasi domain child app.
- Catat pembuatan, perubahan, penghapusan/pemulihan, perubahan status, approval/penolakan, serta pengiriman atau sinkronisasi yang mengubah state ekonomi. Catat juga pembacaan detail, unduhan lampiran, dan ekspor data sensitif sesuai klasifikasi data dan risiko; setiap ekspor massal wajib diaudit. Percobaan akses yang ditolak dicatat sebagai security event tanpa membocorkan isi record.
- Setiap event memuat pelaku (atau identitas service/job), aksi, waktu, tenant/entity, jenis dan ID resource, hasil, sumber permintaan (UI/API/job), serta request/correlation ID. Untuk mutasi, simpan perubahan yang relevan sebagai nilai lama/baru atau ringkasan perubahan yang dapat ditelusuri. Catat alasan keputusan bila workflow mewajibkannya dan tautkan ke jurnal Accounting bila transaksi menghasilkan jurnal.
- Metadata audit memakai minimisasi data: jangan simpan password, token, secret, nomor rekening lengkap, dokumen penuh, payload webhook penuh, atau isi pribadi yang tidak diperlukan untuk pembuktian. Masking/redaction harus berlaku juga pada error dan log operasional. Jika nilai penuh memang diperlukan sebagai bukti, simpan di penyimpanan bukti yang akses dan retensinya diatur tersendiri; audit menyimpan referensinya.
- Audit bersifat append-only dan tahan terhadap perubahan/penghapusan oleh alur aplikasi biasa. Mutasi bisnis kritis dan pencatatan audit keberhasilannya harus atomik; kegagalan mencatat audit membatalkan mutasi. Event pembacaan/akses ditolak dapat memakai jalur terpisah yang tetap andal dan tidak mengubah data bisnis.
- UI riwayat audit hanya dapat dibuka oleh role dengan izin audit yang sesuai, dibatasi tenant/entity, dapat difilter berdasarkan waktu, pelaku, aksi, dan objek, serta dapat menunjukkan urutan perubahan tanpa mengungkap data privat yang tidak berhak dilihat pengguna. Akses terhadap audit itu sendiri juga tercatat bila menyangkut data sensitif.
- Retensi audit terpisah dari retensi riwayat cron. Ikuti kebijakan tenant di `docs/spec.md` (default tiga tahun dan aturan yang lebih panjang untuk kebutuhan tax compliance); jangan memangkas audit bersama job auto trash cron. Pemusnahan setelah masa retensi mengikuti kebijakan data dan kebutuhan bukti yang berlaku.

**Selesai bila:** test membuktikan mutasi bernilai ekonomis menghasilkan audit yang benar, kegagalan audit membatalkan mutasi, akses/ekspor data privat tercatat sesuai klasifikasi, pengguna lintas entity tidak dapat membaca riwayat, dan metadata tidak memuat nilai rahasia.

## 11. Docker untuk uji lokal tanpa Ecopa

- Sediakan konfigurasi Docker Compose **khusus development/uji lokal** yang dapat dijalankan dari checkout repository tanpa Ecopa dan tanpa jaringan PostgreSQL eksternal. Layanan minimum: database lokal disposable, backend child app, frontend bila terpisah, serta Redis/queue/scheduler bila fitur child app memerlukannya. Port, healthcheck, volume, dan urutan startup didokumentasikan; konfigurasi production tetap terpisah.
- Mode lokal memakai login dan user/role lokal yang dibuat oleh bootstrap/seed yang terdokumentasi. Jangan membuat bypass autentikasi atau permission hanya karena Ecopa tidak aktif. Wizard registrasi, redirect SSO, webhook, dan reconcile Ecopa tidak menjadi prasyarat atau sumber error pada mode ini.
- Sediakan langkah singkat untuk build, menjalankan, migrasi, seed data dummy, login, melihat log, dan menghentikan stack. Perintah seed harus idempotent dan hanya menargetkan database lokal yang dimaksud. Jangan memakai credential production atau otomatis mengubah `.env`/database development pengguna.
- Saat boot pertama, tersedia setidaknya satu **entity dummy yang aktif** dan sudah berisi data latihan domain child app, lengkap dengan user lokal yang dapat mengaksesnya. Untuk Accounting, gunakan kontrak **PT. Fake Data** yang sudah ada; child app lain membuat fixture domainnya sendiri dan dapat menghubungkannya ke entity latihan sesuai kontrak integrasi yang berlaku.
- Semua record dummy memiliki marker/provenance yang dapat diverifikasi. Pemulihan atau pembersihan fixture hanya menyentuh record bertanda milik entity/tenant yang sama dan mempertahankan input manual pengguna. Entity dummy tetap dapat diedit untuk latihan menurut permission dan aturan bisnis normal; tidak ada kunci tulis atau grant khusus demo.
- Bila child app memerlukan jurnal Accounting, mode lokal menyediakan endpoint Accounting lokal atau konfigurasi integrasi lokal yang terdokumentasi. Tidak adanya Ecopa tidak berarti melewati kontrak API Accounting, idempotensi, atau pemeriksaan tenant/entity.
- `docker/docker-compose.yml` saat ini memakai jaringan PostgreSQL eksternal, sehingga kebutuhan Compose lokal mandiri di atas adalah **requirement implementasi**, bukan klaim bahwa stack yang ada sudah memenuhi syarat.

**Selesai bila:** dari checkout bersih, pengguna dapat menjalankan stack lokal, login tanpa Ecopa, memilih entity dummy yang berisi data, melakukan alur domain sesuai izin, lalu menghentikan stack tanpa memengaruhi data production atau data lokal lain.

## 12. Entity Management, aktivasi, dan pemilih entity

- Sediakan pengelolaan entity pada alur Settings/workspace yang sudah ada: daftar entity aktif dan arsip, pencarian, profil, pembuatan bila diizinkan, pengubahan identitas/konfigurasi, aktivasi/nonaktivasi, serta arsip/pemulihan menurut aturan child app. Gunakan form dan endpoint utama yang sama; jangan membuat pengelolaan entity paralel hanya untuk mode dummy.
- Entity dummy sudah ada dan terisi setelah seed lokal. Tampilkan label **Demo/Dummy** dan metadata dataset yang relevan agar tidak tertukar dengan entity operasional. Perlindungan terhadap hapus permanen dan pemulihan fixture mengikuti provenance serta kontrak PT. Fake Data bila memakai fixture Accounting tersebut.
- Admin yang berwenang dapat mengaktifkan atau menonaktifkan entity. Entity nonaktif/arsip tidak dapat dipilih untuk pekerjaan aktif dan mutasi domainnya ditolak backend, tetapi histori tetap dapat diakses sesuai permission dan kebijakan arsip. Jangan menonaktifkan entity aktif terakhir bila hal itu membuat instalasi tidak dapat digunakan tanpa jalur pemulihan. Setiap perubahan status diaudit.
- Tampilkan **dropdown pemilih entity di toolbar kanan atas** pada seluruh halaman utama child app. Dropdown memperlihatkan entity aktif yang dapat diakses user, menandai pilihan saat ini dan entity dummy, serta memberi jalan ke Entity Management bagi user yang berwenang. Entity nonaktif/arsip tidak muncul sebagai pilihan aktif.
- Saat entity dipilih, perbarui konteks tenant/entity untuk API, permission, periode atau filter domain, serta tab dan riwayat kerja. Muat ulang data milik entity tujuan dan jangan tampilkan state entity sebelumnya. Pilihan terakhir boleh dipulihkan saat login berikutnya hanya jika entity masih aktif dan user masih berhak mengaksesnya; jika tidak, pilih entity valid atau tampilkan keadaan tanpa akses yang jelas.
- Dalam mode terintegrasi, hormati assignment entity dari Ecopa dan mirror yang berlaku; atribut identitas yang dimiliki Ecopa tidak boleh diubah sepihak oleh child app. Dalam mode lokal, pengelolaan dan assignment entity dilakukan oleh mekanisme lokal yang sama ketatnya. Perubahan mode tidak boleh membuat entity dummy memperoleh akses lintas workspace.

**Selesai bila:** entity dummy terisi dan dapat dipilih dari dropdown kanan atas, aktivasi/nonaktivasi langsung memengaruhi daftar pilihan dan otorisasi backend, serta perpindahan entity tidak membocorkan data atau tab entity lain.

## Poin tambahan untuk review pemilik produk

Poin berikut melengkapi sembilan area di atas dan **belum menjadi keputusan produk final**:

1. **Kontrak registrasi dan lifecycle child app:** siapa dapat memasang, menonaktifkan, dan mencabut aplikasi; bagaimana statusnya terlihat di Ecopa dan Accounting.
2. **Onboarding workspace/entity:** data minimum saat pertama masuk, migrasi data awal, serta perilaku saat entity dinonaktifkan atau dipulihkan.
3. **Integrasi ke Accounting:** aksi domain mana yang menghasilkan jurnal, kapan dikirim, mekanisme retry/idempotensi, dan rekonsiliasi bila child app berhasil tetapi posting Accounting gagal.
4. **Observability dan pemulihan:** health check, queue monitoring, backup/restore, target waktu pemulihan, serta siapa menerima alert eksternal.
5. **Kualitas rilis:** test kontrak antar aplikasi, migrasi forward-compatible, accessibility, performa daftar besar, dan versi API/kompatibilitas saat child app diperbarui terpisah.
6. **Kebijakan data:** klasifikasi data pribadi, retensi per jenis data, ekspor, arsip, dan penghapusan yang tidak merusak audit atau referensi akuntansi.

## Checklist penerimaan child app baru

- [ ] Registrasi dan SSO Ecopa, webhook terverifikasi, receipt idempotensi, dan reconcile diuji.
- [ ] User list, assignment lokal, role/permission UI, serta otorisasi backend diuji pada beberapa workspace.
- [ ] Scheduler, alert in-app, riwayat, dan pemangkasan log terkonfigurasi dan diuji.
- [ ] SPA/tab, akses read-only, loading/error state, serta navigasi kembali bekerja.
- [ ] Preferensi tema dan tab terbuka pulih setelah browser dibuka lagi; tab tertutup, perubahan izin, dan perpindahan user/workspace ditangani dengan benar.
- [ ] Kontrak Accounting, idempotensi, dan penanganan kegagalan lintas aplikasi diuji bila domain membuat jurnal.
- [ ] Review keamanan, audit trail, pemeriksaan statis, dan test regresi relevan selesai sebelum rilis.
- [ ] Matriks data ekonomis/privat dan event auditnya disetujui; mutasi, akses sensitif, ekspor, retensi, serta isolasi riwayat audit diuji.
- [ ] Docker lokal mandiri dapat dijalankan tanpa Ecopa; login lokal, seeding entity dummy, dan alur domain bekerja dengan permission normal.
- [ ] Entity Management dan dropdown kanan atas mendukung aktivasi, nonaktivasi, perpindahan entity, serta pemulihan pilihan yang tetap berizin.
