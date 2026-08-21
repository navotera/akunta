<?php

declare(strict_types=1);

/**
 * Curated descriptions for the Technology & IT CoA.
 *
 * Every entry deliberately states the account boundary and a representative
 * economic event. Tax treatment still follows the entity's supporting
 * documents and the regulations applicable to the reporting period.
 */
return [
    '1000' => 'Kelompok seluruh sumber daya ekonomi yang dikuasai perusahaan. Digunakan sebagai akun induk dan tidak untuk posting langsung.

Contoh: kas, piutang, persediaan, dan perangkat server disajikan sebagai kelompok aset.',
    '1100' => 'Kelompok kas dan instrumen sangat likuid untuk kegiatan perusahaan. Digunakan sebagai akun induk dan tidak untuk posting langsung.

Contoh: saldo kas, rekening bank, dan dana pada payment gateway dikelompokkan di sini.',
    '1101' => 'Uang tunai yang secara fisik dikuasai perusahaan. Digunakan untuk penerimaan atau pembayaran tunai bernilai wajar sesuai kebijakan kas.

Contoh: pengisian kas kecil untuk membeli kebutuhan kantor.',
    '1102' => 'Dana perusahaan pada rekening bank operasional. Digunakan saat uang masuk atau keluar melalui transfer bank.

Contoh: pelanggan melunasi invoice jasa konsultasi ke rekening perusahaan.',
    '1103' => 'Dana hasil transaksi yang masih berada pada penyelenggara payment gateway. Digunakan setelah pembayaran pelanggan berhasil tetapi sebelum dana diselesaikan ke bank.

Contoh: pembayaran langganan SaaS tertahan dua hari sebelum settlement.',
    '1104' => 'Dana perusahaan pada rekening yang dipisahkan untuk pembayaran pajak dan payroll. Digunakan saat perusahaan memindahkan atau membayarkan dana melalui rekening khusus tersebut.

Contoh: pemindahan dana dari bank operasional untuk pembayaran gaji bulanan.',
    '1200' => 'Kelompok hak tagih perusahaan kepada pelanggan atau pihak lain. Digunakan sebagai akun induk dan tidak untuk posting langsung.

Contoh: invoice proyek dan pendapatan proyek yang belum dapat ditagih dikelompokkan di sini.',
    '1201' => 'Hak kontraktual untuk menerima pembayaran dari pelanggan atas barang atau jasa yang telah ditagihkan. Digunakan ketika invoice diterbitkan secara kredit.

Contoh: invoice implementasi software dengan termin pembayaran 30 hari.',
    '1202' => 'Aset intern untuk nilai pekerjaan proyek yang telah diakui manajemen tetapi belum ditagihkan. Digunakan hanya berdasarkan pengukuran progres dan kebijakan akuntansi intern yang terdokumentasi.

Contoh: milestone pengembangan selesai pada akhir bulan sementara invoice baru terbit bulan berikutnya.',
    '1203' => 'Akun kontra aset intern untuk estimasi penurunan nilai piutang menurut penilaian manajemen. Digunakan ketika terdapat bukti risiko gagal bayar dan estimasi dapat dipertanggungjawabkan.

Contoh: sebagian piutang pelanggan yang menunggak lama dicadangkan.',
    '1204' => 'Hak tagih perusahaan kepada karyawan yang bukan merupakan pembayaran gaji. Digunakan untuk pinjaman atau penggantian biaya yang wajib dikembalikan dan telah disetujui.

Contoh: uang muka perjalanan yang tersisa dan harus dikembalikan karyawan.',
    '1300' => 'Kelompok aset yang dimiliki untuk dijual kembali atau digunakan dalam penyerahan barang kepada pelanggan. Digunakan sebagai akun induk dan tidak untuk posting langsung.

Contoh: stok perangkat jaringan dan lisensi resale dikelompokkan di sini.',
    '1301' => 'Perangkat keras IT yang dimiliki untuk dijual kembali kepada pelanggan. Digunakan sejak barang diterima sampai dijual atau dikeluarkan dari stok.

Contoh: pembelian laptop dan router untuk pesanan pelanggan.',
    '1302' => 'Hak lisensi software yang diperoleh untuk dijual kembali dan belum diserahkan kepada pelanggan. Digunakan bila perusahaan bertindak sebagai reseller dan menguasai unit lisensi.

Contoh: pembelian paket lisensi antivirus untuk stok penjualan.',
    '1400' => 'Kelompok aset lancar selain kas, piutang, dan persediaan utama. Digunakan sebagai akun induk dan tidak untuk posting langsung.

Contoh: pembayaran cloud di muka dan pajak dibayar di muka dikelompokkan di sini.',
    '1401' => 'Pembayaran cloud, hosting, atau software yang manfaatnya mencakup periode mendatang. Digunakan saat pembayaran belum seluruhnya menjadi beban periode berjalan.

Contoh: pembayaran tahunan tool DevOps yang diamortisasi setiap bulan.',
    '1402' => 'PPN atas perolehan barang atau jasa yang dicatat sebagai pajak masukan sesuai dokumen perpajakan. Digunakan ketika faktur pajak masukan yang sah diterima.

Contoh: PPN pada invoice vendor server cloud lokal.',
    '1498' => 'Pembayaran atau pemotongan pajak yang dapat diperhitungkan pada kewajiban pajak periode terkait. Digunakan berdasarkan bukti bayar atau bukti potong yang valid.

Contoh: PPh 23 yang dipotong pelanggan atas jasa konsultasi.',
    '1404' => 'Pembayaran kepada vendor sebelum barang atau jasa diterima. Digunakan selama perusahaan masih memiliki hak atas penyerahan atau pengembalian uang dari vendor.

Contoh: uang muka pengadaan server sebelum perangkat dikirim.',
    '1405' => 'Dana yang ditempatkan sebagai jaminan dan dapat diterima kembali setelah syarat kontrak dipenuhi. Digunakan jika pembayaran bukan beban maupun harga perolehan barang.

Contoh: deposit sewa ruang data center yang dapat dikembalikan.',
    '1500' => 'Kelompok aset berwujud yang digunakan lebih dari satu periode untuk operasi perusahaan. Digunakan sebagai akun induk dan tidak untuk posting langsung.

Contoh: komputer kantor dan server milik perusahaan dikelompokkan di sini.',
    '1501' => 'Komputer, laptop, router, switch, dan perangkat jaringan yang dipakai sendiri oleh perusahaan. Digunakan saat perangkat dikapitalisasi, bukan saat dibeli untuk dijual kembali.

Contoh: pembelian laptop kerja untuk developer.',
    '1502' => 'Server dan perangkat data center yang dimiliki dan digunakan untuk memberikan layanan. Digunakan ketika perusahaan menguasai perangkat, bukan sekadar menyewa kapasitas cloud.

Contoh: pembelian server rack untuk layanan private cloud.',
    '1503' => 'Peralatan kantor berumur manfaat lebih dari satu periode yang memenuhi kebijakan kapitalisasi. Digunakan untuk aset operasional selain komputer, server, dan perangkat jaringan.

Contoh: pembelian perangkat konferensi video untuk ruang rapat.',
    '1504' => 'Perangkat milik perusahaan yang digunakan berulang untuk demonstrasi produk, pengujian, atau laboratorium teknis. Digunakan jika perangkat tidak dimaksudkan untuk dijual dan memenuhi kebijakan kapitalisasi.

Contoh: perangkat IoT untuk lab integrasi pelanggan.',
    '1591' => 'Kontra aset intern yang menghimpun penyusutan komersial aset tetap. Digunakan pada setiap pengakuan penyusutan menurut estimasi masa manfaat akuntansi perusahaan.

Contoh: penyusutan bulanan laptop developer.',
    '1592' => 'Kontra aset fiskal yang menghimpun penyusutan menurut klasifikasi dan masa manfaat pajak. Digunakan hanya pada buku fiskal sesuai dasar penyusutan yang berlaku.

Contoh: penyusutan fiskal bulanan server berdasarkan kelompok aset pajaknya.',
    '1600' => 'Kelompok aset nonmoneter tanpa bentuk fisik yang dikendalikan perusahaan. Digunakan sebagai akun induk intern dan tidak untuk posting langsung.

Contoh: biaya pengembangan produk software yang memenuhi kebijakan kapitalisasi dikelompokkan di sini.',
    '1601' => 'Biaya pengembangan produk software milik perusahaan yang dikapitalisasi menurut kebijakan akuntansi intern. Digunakan hanya setelah kriteria kapitalisasi terdokumentasi; riset dan pemeliharaan rutin tetap dibebankan.

Contoh: biaya fase pembangunan modul SaaS yang layak digunakan dan menghasilkan manfaat mendatang.',
    '1691' => 'Kontra aset intern yang menghimpun amortisasi produk software yang dikapitalisasi. Digunakan sejak aset siap digunakan selama masa manfaat yang ditetapkan.

Contoh: amortisasi bulanan platform SaaS milik perusahaan.',

    '2000' => 'Kelompok kewajiban kini perusahaan kepada pihak lain akibat peristiwa masa lalu. Digunakan sebagai akun induk dan tidak untuk posting langsung.

Contoh: utang vendor, pajak, dan pinjaman dikelompokkan sebagai liabilitas.',
    '2100' => 'Kelompok kewajiban yang diperkirakan diselesaikan dalam siklus operasi atau jangka pendek. Digunakan sebagai akun induk dan tidak untuk posting langsung.

Contoh: utang usaha, pendapatan diterima di muka, dan utang gaji dikelompokkan di sini.',
    '2101' => 'Kewajiban membayar pemasok atas barang atau jasa yang telah diterima dan ditagihkan. Digunakan ketika invoice vendor diakui tetapi belum dibayar.

Contoh: invoice bulanan penyedia cloud yang jatuh tempo 14 hari.',
    '2102' => 'Kewajiban memberikan layanan SaaS atau hosting karena pembayaran pelanggan telah diterima sebelum layanan dipenuhi. Digunakan untuk bagian pembayaran yang menjadi pendapatan periode mendatang.

Contoh: pelanggan membayar paket hosting satu tahun di muka.',
    '2103' => 'Kewajiban atas barang atau jasa vendor yang telah diterima tetapi invoice belum diterima atau belum dibukukan. Digunakan jika jumlah dapat diestimasi secara andal.

Contoh: pemakaian cloud bulan berjalan yang invoice-nya terbit bulan berikutnya.',
    '2104' => 'Kewajiban memenuhi proyek atau mengembalikan dana karena pembayaran proyek diterima sebelum kewajiban kontrak dipenuhi. Digunakan sampai pekerjaan yang terkait dengan uang muka telah diserahkan.

Contoh: uang muka proyek integrasi sebelum fase implementasi dimulai.',
    '2105' => 'Dana pelanggan yang dikuasai sementara dan dapat dikembalikan menurut syarat layanan. Digunakan untuk deposit yang bukan pendapatan maupun pembayaran jasa yang sudah diberikan.

Contoh: deposit perangkat pinjaman untuk layanan managed IT.',
    '2110' => 'PPN yang dipungut dari pelanggan atas penyerahan kena pajak sesuai dokumen perpajakan. Digunakan saat timbul kewajiban PPN keluaran.

Contoh: PPN pada invoice penjualan lisensi software.',
    '2111' => 'PPh Pasal 21 yang telah dipotong perusahaan dan belum disetor. Digunakan saat payroll atau pembayaran lain menimbulkan kewajiban pemotongan PPh 21.

Contoh: pemotongan PPh 21 gaji pegawai bulan berjalan.',
    '2112' => 'PPh Pasal 23 yang telah dipotong perusahaan dari pembayaran kepada pihak lain dan belum disetor. Digunakan hanya untuk transaksi yang termasuk objek pemotongan.

Contoh: pemotongan PPh 23 atas honor konsultan berbadan usaha.',
    '2115' => 'PPh final yang dipotong atau dipungut perusahaan dan belum disetor. Digunakan hanya untuk transaksi yang merupakan objek PPh final sesuai ketentuan yang berlaku.

Contoh: PPh Final Pasal 4 ayat (2) yang dipotong atas pembayaran sewa tanah dan bangunan.',
    '2197' => 'Estimasi kewajiban PPh badan periode berjalan. Digunakan pada buku intern setelah rekonsiliasi fiskal dan provisi pajak kini disetujui.

Contoh: pengakuan PPh badan kurang bayar pada penutupan tahun pajak.',
    '2198' => 'Kewajiban PPh badan yang telah dihitung secara definitif berdasarkan rekonsiliasi dan dokumen perpajakan. Digunakan pada buku Intern dan Fiskal sampai kewajiban dibayar atau diselesaikan.

Contoh: pengakuan PPh badan definitif berdasarkan penghitungan pajak tahunan.',
    '2114' => 'Kewajiban kepada karyawan atas gaji atau benefit yang telah menjadi hak tetapi belum dibayar. Digunakan pada cut-off payroll.

Contoh: gaji akhir bulan yang dibayarkan pada awal bulan berikutnya.',
    '2200' => 'Kelompok kewajiban yang penyelesaiannya secara substansial melewati jangka pendek. Digunakan sebagai akun induk dan tidak untuk posting langsung.

Contoh: pinjaman investasi dan liabilitas sewa jangka panjang dikelompokkan di sini.',
    '2201' => 'Pokok pinjaman yang masih terutang kepada bank. Digunakan saat dana pinjaman diterima dan ketika pokoknya dilunasi; bunga dicatat terpisah sebagai beban.

Contoh: pinjaman untuk pengadaan infrastruktur server.',
    '2202' => 'Kewajiban pembayaran sewa peralatan yang diakui sebagai liabilitas sesuai kebijakan akuntansi perusahaan. Digunakan jika kontrak sewa memenuhi kriteria pengakuan liabilitas.

Contoh: kontrak sewa server tiga tahun yang diakui sebagai liabilitas sewa.',

    '3000' => 'Kelompok hak residual pemilik atas aset setelah dikurangi liabilitas. Digunakan sebagai akun induk dan tidak untuk posting langsung.

Contoh: modal disetor dan laba ditahan dikelompokkan sebagai ekuitas.',
    '3101' => 'Kontribusi modal yang telah disetor pemilik atau pemegang saham kepada perusahaan. Digunakan ketika setoran modal benar-benar diterima dan didukung dokumen perusahaan.

Contoh: pemegang saham menyetor modal awal ke rekening perusahaan.',
    '3201' => 'Akumulasi laba atau rugi setelah distribusi kepada pemilik. Digunakan pada pemindahan hasil periode dan penyesuaian ekuitas yang relevan.

Contoh: laba bersih tahun lalu ditutup ke laba ditahan.',
    '3301' => 'Pengurang ekuitas untuk penarikan pemilik atau pembagian dividen, sesuai bentuk badan usaha. Digunakan setelah transaksi dan otorisasinya dapat dibuktikan.

Contoh: pembayaran dividen yang telah diputuskan kepada pemegang saham.',

    '4000' => 'Kelompok kenaikan manfaat ekonomi dari kegiatan penjualan barang dan jasa perusahaan. Digunakan sebagai akun induk dan tidak untuk posting langsung.

Contoh: pendapatan software, SaaS, hosting, dan konsultasi dikelompokkan di sini.',
    '4101' => 'Imbalan atas pengembangan software khusus untuk pelanggan. Digunakan ketika kewajiban pelaksanaan atau milestone proyek telah dipenuhi sesuai kebijakan pengakuan pendapatan.

Contoh: pengakuan nilai milestone modul ERP yang diterima pelanggan.',
    '4102' => 'Imbalan atas akses berlangganan ke produk SaaS. Digunakan sepanjang periode layanan yang telah diberikan, bukan semata-mata saat kas diterima.

Contoh: pengakuan satu bulan dari kontrak SaaS tahunan.',
    '4103' => 'Imbalan atas layanan hosting, domain, atau layanan infrastruktur terkait. Digunakan ketika periode layanan telah diberikan kepada pelanggan.

Contoh: tagihan hosting dan pengelolaan domain bulanan.',
    '4104' => 'Nilai penjualan perangkat keras IT kepada pelanggan sebelum dikurangi harga pokoknya. Digunakan ketika kendali barang telah berpindah sesuai transaksi.

Contoh: penjualan server dan perangkat jaringan kepada pelanggan.',
    '4105' => 'Nilai penjualan atau resale lisensi software kepada pelanggan. Digunakan ketika hak lisensi telah diserahkan sesuai kontrak.

Contoh: penyerahan lisensi antivirus satu tahun kepada pelanggan.',
    '4106' => 'Imbalan atas analisis, advisory, audit, atau konsultasi IT. Digunakan ketika jasa konsultasi telah diberikan sesuai kontrak.

Contoh: penyelesaian asesmen keamanan dan rekomendasi arsitektur.',
    '4107' => 'Imbalan atas layanan operasional IT terkelola yang berkelanjutan. Digunakan selama layanan monitoring, support, atau administrasi sistem telah diberikan.

Contoh: managed network service bulanan.',
    '4108' => 'Imbalan atas konfigurasi, migrasi, dan integrasi sistem yang bukan bagian dari pengembangan software custom. Digunakan ketika pekerjaan implementasi yang dijanjikan telah diselesaikan.

Contoh: implementasi ERP dan integrasi API ke sistem pelanggan.',
    '4109' => 'Imbalan atas dukungan teknis dan pemeliharaan software atau infrastruktur. Digunakan sepanjang periode support atau saat pekerjaan maintenance yang disepakati selesai.

Contoh: kontrak annual maintenance untuk aplikasi pelanggan.',
    '4110' => 'Imbalan atas pengujian keamanan, asesmen, monitoring keamanan, atau audit teknologi informasi. Digunakan ketika ruang lingkup jasa keamanan telah diberikan.

Contoh: penyelesaian penetration test dan laporan temuan untuk pelanggan.',
    '4111' => 'Imbalan atas pelatihan teknologi informasi yang diberikan kepada pelanggan. Digunakan ketika sesi atau paket pelatihan telah dilaksanakan.

Contoh: pelatihan administrator aplikasi untuk tim pelanggan.',
    '4191' => 'Pendapatan intern yang diakru manajemen sebelum penagihan formal. Digunakan untuk evaluasi manajemen jika jasa telah diberikan dan nilai dapat diukur secara andal.

Contoh: pengakuan progres proyek akhir bulan yang belum masuk invoice.',
    '4901' => 'Pendapatan yang tidak berasal dari lini utama software, SaaS, hosting, hardware, lisensi, konsultasi, atau managed service. Digunakan hanya bila sifatnya memang di luar pendapatan utama dan materialitas tidak memerlukan akun khusus.

Contoh: penjualan aset kantor bekas sebesar nilai hasil penjualannya, dengan pelepasan aset dicatat terpisah.',

    '5000' => 'Kelompok biaya yang dapat diatribusikan langsung pada barang atau jasa yang menghasilkan pendapatan. Digunakan sebagai akun induk dan tidak untuk posting langsung.

Contoh: developer proyek, cloud pelanggan, dan harga pokok hardware dikelompokkan di sini.',
    '5101' => 'Biaya tenaga developer yang dapat ditelusuri langsung ke proyek pelanggan. Digunakan berdasarkan alokasi waktu atau dokumen proyek yang konsisten.

Contoh: biaya jam kerja developer untuk implementasi aplikasi pelanggan.',
    '5102' => 'Biaya cloud, server, atau kapasitas infrastruktur yang dapat ditelusuri langsung ke pelanggan atau layanan yang dijual. Digunakan berdasarkan tagihan atau metrik alokasi yang terdokumentasi.

Contoh: biaya instance khusus untuk layanan hosting pelanggan.',
    '5103' => 'Biaya perolehan domain atau lisensi yang dibeli untuk diserahkan atau dijual kembali kepada pelanggan. Digunakan ketika penjualan terkait diakui.

Contoh: biaya domain pelanggan dan lisensi antivirus resale.',
    '5104' => 'Nilai tercatat hardware IT yang telah dijual kepada pelanggan. Digunakan bersamaan dengan pengakuan penjualan dan pengurangan persediaan.

Contoh: harga perolehan router yang diserahkan kepada pelanggan.',
    '5105' => 'Biaya pihak ketiga yang mengerjakan bagian langsung dari proyek IT pelanggan. Digunakan bila pekerjaan subkontraktor dapat dikaitkan dengan kontrak tertentu.

Contoh: honor spesialis keamanan untuk penetration test proyek pelanggan.',
    '5106' => 'Biaya pemrosesan pembayaran yang berkaitan langsung dengan penerimaan transaksi pelanggan. Digunakan ketika penyelenggara payment gateway memotong fee transaksi.

Contoh: merchant discount rate atas pembayaran langganan SaaS.',
    '5107' => 'Biaya lisensi pihak ketiga yang dapat ditelusuri langsung ke layanan tertentu yang diberikan kepada pelanggan. Digunakan sejalan dengan pengakuan pendapatan layanan terkait.

Contoh: lisensi monitoring per-endpoint untuk kontrak managed service pelanggan.',
    '5108' => 'Biaya tenaga support yang dapat ditelusuri langsung ke pelanggan atau kontrak layanan tertentu. Digunakan berdasarkan catatan waktu atau dasar alokasi yang konsisten.

Contoh: biaya engineer onsite untuk memenuhi kontrak support pelanggan.',

    '6000' => 'Kelompok biaya untuk menjalankan perusahaan yang tidak ditelusuri langsung ke satu penjualan atau proyek. Digunakan sebagai akun induk dan tidak untuk posting langsung.

Contoh: gaji administrasi, tools internal, pemasaran, dan sewa kantor dikelompokkan di sini.',
    '6101' => 'Imbalan kerja berupa gaji pokok dan komponen payroll yang diklasifikasikan sebagai beban periode. Digunakan ketika karyawan memberikan jasa kepada perusahaan.

Contoh: payroll bulanan tim administrasi dan produk internal.',
    '6102' => 'Biaya tunjangan karyawan dan kontribusi BPJS yang menjadi beban perusahaan. Digunakan pada periode hak atau kewajiban benefit timbul.

Contoh: kontribusi BPJS Kesehatan dan Ketenagakerjaan bulan berjalan.',
    '6103' => 'Imbalan bagi tenaga ahli atau freelancer yang tidak ditelusuri sebagai biaya langsung proyek tertentu. Digunakan berdasarkan kontrak dan bukti jasa yang diterima.

Contoh: honor konsultan HR untuk penyusunan kebijakan perusahaan.',
    '6201' => 'Biaya cloud dan aktivitas DevOps untuk kebutuhan internal perusahaan atau pengembangan nonpelanggan. Digunakan jika biaya tidak dapat ditelusuri langsung ke satu layanan pelanggan.

Contoh: biaya environment CI/CD dan staging internal.',
    '6202' => 'Biaya langganan software dan tools yang dipakai untuk operasi internal. Digunakan sepanjang periode manfaat langganan.

Contoh: langganan project management, source-code hosting, dan design tool untuk tim internal.',
    '6203' => 'Biaya konektivitas internet dan fasilitas data center yang bersifat operasional umum. Digunakan jika biaya tidak dialokasikan langsung ke pelanggan tertentu.

Contoh: koneksi internet kantor dan biaya colocation untuk sistem internal.',
    '6204' => 'Biaya riset, eksplorasi, dan eksperimen produk yang tidak memenuhi kebijakan kapitalisasi aset software. Digunakan ketika manfaat masa depan belum dapat dibuktikan atau pekerjaan masih berada pada tahap riset.

Contoh: eksperimen proof-of-concept fitur AI yang belum diputuskan untuk dikembangkan.',
    '6205' => 'Biaya menjaga keamanan dan kepatuhan sistem internal yang tidak dapat ditelusuri langsung ke kontrak pelanggan. Digunakan saat layanan atau asesmen internal telah diterima.

Contoh: vulnerability assessment tahunan atas infrastruktur internal.',
    '6301' => 'Biaya kegiatan pemasaran melalui kanal digital untuk memperoleh atau mempertahankan pelanggan. Digunakan saat jasa iklan atau kampanye telah diterima.

Contoh: belanja iklan pencarian untuk promosi produk SaaS.',
    '6302' => 'Imbalan penjualan yang menjadi beban berdasarkan keberhasilan atau aktivitas tim penjualan. Digunakan ketika syarat komisi telah terpenuhi dan jumlahnya dapat diukur.

Contoh: komisi sales atas kontrak SaaS baru yang telah efektif.',
    '6401' => 'Biaya sewa ruang kerja dan utilitas kantor yang digunakan dalam operasi. Digunakan sepanjang periode pemakaian fasilitas.

Contoh: sewa kantor, listrik, dan air bulan berjalan.',
    '6402' => 'Biaya perlengkapan dan peralatan bernilai kecil atau berumur pendek yang tidak memenuhi kebijakan kapitalisasi. Digunakan saat barang diterima atau dipakai sesuai kebijakan perusahaan.

Contoh: kabel, adaptor, dan perangkat periferal kecil untuk kantor.',
    '6403' => 'Biaya perlindungan asuransi untuk periode berjalan. Digunakan sepanjang masa pertanggungan; pembayaran untuk periode mendatang dicatat terlebih dahulu sebagai biaya dibayar di muka.

Contoh: bagian bulanan premi asuransi perangkat server.',
    '6501' => 'Beban intern atas alokasi biaya perolehan aset tetap selama masa manfaat komersialnya. Digunakan sesuai kebijakan penyusutan akuntansi perusahaan.

Contoh: penyusutan bulanan komputer developer.',
    '6502' => 'Beban penyusutan pada buku fiskal menurut kelompok dan masa manfaat perpajakan. Digunakan berdasarkan daftar aset dan ketentuan yang berlaku untuk periode pajak.

Contoh: penyusutan fiskal server pada bulan berjalan.',
    '6601' => 'Biaya peningkatan kompetensi dan sertifikasi personel untuk kepentingan usaha. Digunakan saat pelatihan atau ujian sertifikasi telah diterima dan bukti pesertanya tersedia.

Contoh: biaya sertifikasi cloud engineer.',
    '6602' => 'Biaya perjalanan yang diperlukan untuk implementasi, support, atau kegiatan usaha di lokasi lain. Digunakan berdasarkan tujuan perjalanan dan bukti pengeluaran.

Contoh: tiket dan penginapan engineer untuk go-live di lokasi pelanggan.',
    '6603' => 'Biaya menjamu atau membangun relasi usaha dengan pihak eksternal. Digunakan jika tujuan bisnis, pihak yang terkait, tanggal, dan bukti pengeluaran terdokumentasi; perlakuan fiskalnya dievaluasi terpisah.

Contoh: jamuan rapat negosiasi kontrak dengan calon pelanggan.',
    '6604' => 'Beban intern atas estimasi kerugian piutang yang dinilai tidak tertagih. Digunakan ketika estimasi penurunan nilai diakui berpasangan dengan cadangan kerugian piutang.

Contoh: pembentukan cadangan atas invoice pelanggan yang mengalami kesulitan keuangan.',
    '6605' => 'Beban intern hasil alokasi biaya bersama antar divisi untuk analisis kinerja. Digunakan hanya dengan dasar alokasi yang konsisten dan tidak mewakili transaksi eksternal baru.

Contoh: alokasi biaya tim platform ke divisi SaaS berdasarkan pemakaian sumber daya.',
    '6701' => 'Biaya jasa profesional eksternal yang mendukung perusahaan dan tidak merupakan biaya langsung proyek pelanggan. Digunakan ketika jasa hukum, akuntansi, audit, atau advisory telah diterima.

Contoh: biaya audit laporan keuangan tahunan.',
    '6702' => 'Biaya administrasi rekening dan layanan perbankan selain fee payment gateway yang ditelusuri langsung ke transaksi pelanggan. Digunakan saat bank membebankan biaya.

Contoh: biaya administrasi rekening dan transfer bank.',
    '6703' => 'Biaya jasa hukum, perizinan, dan administrasi legal perusahaan yang tidak langsung terkait satu proyek pelanggan. Digunakan ketika jasa atau hak perizinan telah diterima.

Contoh: biaya konsultan hukum untuk meninjau perjanjian standar pelanggan.',
    '6704' => 'Biaya mencari dan menyeleksi tenaga kerja baru yang tidak menjadi bagian payroll. Digunakan ketika jasa rekrutmen, iklan lowongan, atau asesmen kandidat telah diterima.

Contoh: fee recruitment agency untuk mencari DevOps engineer.',
    '6801' => 'Denda atau sanksi yang timbul karena ketidakpatuhan perpajakan. Digunakan pada buku intern ketika surat tagihan atau kewajiban sanksi diakui; evaluasi fiskal dilakukan melalui rekonsiliasi.

Contoh: sanksi administrasi keterlambatan penyetoran pajak.',
    '6998' => 'Beban pajak penghasilan kini berdasarkan penghasilan kena pajak setelah rekonsiliasi fiskal. Digunakan pada buku intern ketika provisi pajak periode berjalan telah dihitung dan akan dipasangkan dengan kredit pajak serta utang PPh Badan.

Contoh: pengakuan beban PPh badan tahun berjalan setelah memperhitungkan kompensasi rugi dan kredit pajak.',

    '8000' => 'Kelompok akun penyajian penyesuaian dari hasil komersial menuju dasar fiskal. Digunakan sebagai akun induk fiskal dan tidak untuk posting transaksi operasional langsung.

Contoh: koreksi positif dan negatif dikelompokkan untuk rekonsiliasi fiskal.',
    '8101' => 'Akun penyajian fiskal untuk koreksi yang menambah penghasilan kena pajak atau mengurangi biaya yang diakui fiskal. Digunakan hanya berdasarkan analisis koreksi dan bukti pendukung yang disetujui.

Contoh: bagian beban representasi yang tidak memenuhi persyaratan fiskal dikoreksi positif.',
    '8102' => 'Akun penyajian fiskal untuk koreksi yang mengurangi penghasilan kena pajak atau menambah pengurang fiskal. Digunakan hanya berdasarkan analisis koreksi dan dasar pendukung yang disetujui.

Contoh: penyesuaian beda waktu ketika pengurang fiskal periode berjalan lebih besar daripada beban komersial.',
];
