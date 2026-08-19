<?php

declare(strict_types=1);

/**
 * SOP descriptions for legacy/generic accounts that already exist in Akunta.
 * Each item: [description, recommended availability for independent books].
 */
return [
    'Aktiva' => ['Kelompok seluruh sumber daya ekonomi yang dikuasai perusahaan. Digunakan sebagai akun induk dan tidak untuk posting langsung.

Contoh: kas, piutang, persediaan, dan aset tetap disajikan dalam kelompok aktiva.', 'both'],
    'Aktiva Lancar' => ['Kelompok aset yang diperkirakan direalisasi, dijual, atau digunakan dalam siklus operasi normal. Digunakan sebagai akun induk dan tidak untuk posting langsung.

Contoh: kas, piutang usaha, dan persediaan dikelompokkan sebagai aktiva lancar.', 'both'],
    'Aktiva Tetap' => ['Kelompok aset berwujud yang digunakan lebih dari satu periode untuk operasi perusahaan. Digunakan sebagai akun induk dan tidak untuk posting langsung.

Contoh: tanah, bangunan, kendaraan, dan peralatan dikelompokkan sebagai aktiva tetap.', 'both'],
    'Akumulasi Penyusutan' => ['Akun kontra aset intern yang menghimpun penyusutan komersial aset tetap. Digunakan ketika beban penyusutan periodik diakui berdasarkan masa manfaat akuntansi.

Contoh: akumulasi penyusutan bulanan peralatan kantor.', 'intern'],
    'Akumulasi Penyusutan Bangunan' => ['Akun kontra aset intern yang menghimpun penyusutan komersial bangunan. Digunakan untuk mengurangi nilai tercatat bangunan tanpa mengubah harga perolehannya.

Contoh: penyusutan bulanan gedung kantor.', 'intern'],
    'Akumulasi Penyusutan Kendaraan' => ['Akun kontra aset intern yang menghimpun penyusutan komersial kendaraan. Digunakan untuk mengurangi nilai tercatat kendaraan selama masa manfaatnya.

Contoh: penyusutan bulanan kendaraan operasional.', 'intern'],
    'Akumulasi Penyusutan Peralatan' => ['Akun kontra aset intern yang menghimpun penyusutan komersial peralatan. Digunakan untuk mengurangi nilai tercatat peralatan selama masa manfaatnya.

Contoh: penyusutan bulanan komputer dan perangkat kantor.', 'intern'],
    'Bangunan' => ['Harga perolehan bangunan yang dimiliki dan digunakan perusahaan lebih dari satu periode. Digunakan saat bangunan memenuhi kebijakan pengakuan aset tetap; tanah dicatat terpisah.

Contoh: pembelian gedung kantor milik perusahaan.', 'both'],
    'Bank' => ['Dana perusahaan yang tersimpan pada rekening bank. Digunakan untuk setiap penerimaan, pembayaran, atau pemindahan dana melalui rekening tersebut.

Contoh: pelanggan melunasi invoice melalui transfer bank.', 'both'],
    'Beban Air' => ['Biaya pemakaian air untuk kegiatan operasional. Digunakan pada periode layanan air dikonsumsi.

Contoh: tagihan air kantor bulan berjalan.', 'both'],
    'Beban ATK' => ['Biaya alat tulis dan kebutuhan administrasi yang habis dipakai atau tidak memenuhi kebijakan kapitalisasi. Digunakan saat barang diterima atau dipakai sesuai kebijakan perusahaan.

Contoh: pembelian kertas, tinta, dan alat tulis kantor.', 'both'],
    'Beban Bank/Adm' => ['Biaya layanan dan administrasi perbankan yang tidak dapat ditelusuri langsung ke penjualan tertentu. Digunakan saat bank membebankan fee.

Contoh: biaya administrasi rekening dan biaya transfer.', 'both'],
    'Beban Internet/Telpon' => ['Biaya konektivitas internet dan komunikasi telepon untuk operasi umum. Digunakan pada periode layanan dikonsumsi.

Contoh: tagihan internet kantor dan nomor telepon operasional.', 'both'],
    'Beban Lain-lain' => ['Biaya nonutama yang sah tetapi belum memiliki akun khusus. Digunakan secara terbatas setelah operator memastikan tidak ada akun yang lebih tepat dan menyimpan bukti serta penjelasan.

Contoh: pengeluaran operasional insidental bernilai tidak material.', 'both'],
    'Beban Listrik' => ['Biaya pemakaian energi listrik untuk operasi perusahaan. Digunakan pada periode listrik dikonsumsi.

Contoh: tagihan listrik kantor dan ruang server bulan berjalan.', 'both'],
    'Beban Pemasaran' => ['Biaya aktivitas untuk memperoleh atau mempertahankan pelanggan. Digunakan ketika jasa kampanye, promosi, atau materi pemasaran telah diterima.

Contoh: biaya kampanye produk dan pembuatan materi promosi.', 'both'],
    'Beban Penyusutan' => ['Beban intern atas alokasi harga perolehan aset tetap selama masa manfaat komersialnya. Digunakan sesuai jadwal aset dan kebijakan penyusutan perusahaan.

Contoh: penyusutan bulanan peralatan kantor.', 'intern'],
    'Beban Sewa' => ['Biaya penggunaan kantor, perangkat, atau fasilitas yang tidak dikapitalisasi sebagai aset hak-guna menurut kebijakan perusahaan. Digunakan sepanjang periode pemakaian.

Contoh: sewa kantor bulan berjalan.', 'both'],
    'Beban Transportasi' => ['Biaya perjalanan lokal dan transportasi untuk kegiatan usaha. Digunakan jika tujuan bisnis dan bukti pengeluaran dapat diidentifikasi.

Contoh: transportasi engineer ke lokasi pelanggan.', 'both'],
    'Biaya Administrasi' => ['Biaya administrasi umum perusahaan yang tidak mempunyai akun khusus. Digunakan secara terbatas dengan dokumen pendukung dan penjelasan yang memadai.

Contoh: biaya pengurusan dokumen operasional.', 'both'],
    'Biaya Angkut Pembelian' => ['Biaya membawa barang yang dibeli sampai lokasi dan kondisi yang diperlukan. Digunakan jika biaya berkaitan langsung dengan perolehan barang; perlakuannya mengikuti kebijakan persediaan atau HPP.

Contoh: ongkos pengiriman perangkat dari vendor.', 'both'],
    'Biaya Bunga' => ['Biaya pendanaan atas pinjaman atau kewajiban berbunga. Digunakan berdasarkan periode berjalannya bunga dan dokumen fasilitas pembiayaan.

Contoh: bunga pinjaman bank bulan berjalan.', 'both'],
    'Biaya Dibayar di Muka' => ['Pembayaran yang manfaatnya belum seluruhnya diterima pada periode berjalan. Digunakan sampai manfaat dikonsumsi dan dialokasikan menjadi beban.

Contoh: pembayaran asuransi atau software satu tahun di muka.', 'both'],
    'Biaya Gaji' => ['Imbalan kerja berupa gaji yang menjadi beban perusahaan. Digunakan ketika karyawan telah memberikan jasa pada periode terkait.

Contoh: payroll bulanan karyawan.', 'both'],
    'Biaya Listrik, Air & Telepon' => ['Biaya utilitas dan komunikasi untuk kegiatan operasional. Digunakan pada periode layanan listrik, air, atau telepon dikonsumsi.

Contoh: tagihan utilitas kantor bulan berjalan.', 'both'],
    'Biaya Operasional' => ['Kelompok biaya untuk menjalankan kegiatan perusahaan yang tidak langsung membentuk harga pokok satu produk atau proyek. Digunakan sebagai akun induk dan tidak untuk posting langsung.

Contoh: gaji administrasi, sewa, dan pemasaran dikelompokkan sebagai biaya operasional.', 'both'],
    'Biaya Pemasaran' => ['Biaya kegiatan promosi dan penjualan untuk memperoleh pelanggan. Digunakan saat aktivitas pemasaran telah dilaksanakan.

Contoh: biaya iklan digital dan materi promosi.', 'both'],
    'Biaya Penyusutan' => ['Beban intern atas alokasi harga perolehan aset tetap selama masa manfaat komersialnya. Digunakan berdasarkan register aset dan kebijakan penyusutan perusahaan.

Contoh: penyusutan bulanan kendaraan operasional.', 'intern'],
    'Biaya Perlengkapan' => ['Biaya barang penunjang operasi yang habis dipakai atau tidak memenuhi kebijakan kapitalisasi. Digunakan saat barang diterima atau dikonsumsi.

Contoh: kabel, adaptor, dan perlengkapan kantor bernilai kecil.', 'both'],
    'Biaya Sewa' => ['Biaya penggunaan aset atau fasilitas milik pihak lain yang dibebankan pada periode berjalan. Digunakan sepanjang periode pemakaian sesuai kontrak.

Contoh: sewa ruang kantor bulanan.', 'both'],
    'Harga Pokok Penjualan' => ['Kelompok biaya langsung barang atau jasa yang pendapatannya telah diakui. Digunakan sebagai akun induk dan tidak untuk posting langsung.

Contoh: harga perolehan hardware dan biaya langsung layanan dikelompokkan sebagai HPP.', 'both'],
    'Hutang Bank' => ['Kewajiban pokok pinjaman kepada bank. Digunakan saat dana pinjaman diterima dan ketika pokok dilunasi; bunga dicatat terpisah.

Contoh: pinjaman bank untuk pengadaan server.', 'both'],
    'Hutang Gaji' => ['Kewajiban kepada karyawan atas gaji yang telah menjadi hak tetapi belum dibayar. Digunakan pada cut-off payroll.

Contoh: gaji akhir bulan yang dibayarkan awal bulan berikutnya.', 'both'],
    'Hutang PPh' => ['Kewajiban pajak penghasilan yang telah dipotong atau terutang tetapi belum disetor. Digunakan berdasarkan jenis transaksi dan dokumen pemotongan yang relevan.

Contoh: PPh atas pembayaran jasa yang menunggu penyetoran.', 'both'],
    'Hutang PPh 23' => ['PPh Pasal 23 yang telah dipotong perusahaan dari pembayaran kepada pihak lain dan belum disetor. Digunakan hanya pada transaksi yang termasuk objek pemotongan.

Contoh: pemotongan PPh 23 atas jasa vendor.', 'both'],
    'Hutang Usaha' => ['Kewajiban membayar pemasok atas barang atau jasa yang telah diterima dan ditagihkan. Digunakan ketika invoice vendor diakui tetapi belum dibayar.

Contoh: invoice penyedia cloud yang jatuh tempo kemudian.', 'both'],
    'Kas Kecil Fiskal' => ['Akun kas khusus pada buku Fiskal untuk transaksi tunai yang memang dicatat terpisah dari buku Intern. Digunakan hanya jika perusahaan mempertahankan saldo dan bukti kas fiskal independen.

Contoh: pembayaran tunai fiskal dengan bukti yang memenuhi kebijakan pajak.', 'fiskal'],
    'Kendaraan' => ['Harga perolehan kendaraan yang dimiliki dan digunakan untuk operasi lebih dari satu periode. Digunakan jika memenuhi kebijakan kapitalisasi aset tetap.

Contoh: pembelian kendaraan operasional perusahaan.', 'both'],
    'Kewajiban' => ['Kelompok kewajiban kini perusahaan kepada pihak lain akibat peristiwa masa lalu. Digunakan sebagai akun induk dan tidak untuk posting langsung.

Contoh: utang vendor, pajak, dan pinjaman dikelompokkan sebagai kewajiban.', 'both'],
    'Kewajiban Jangka Panjang' => ['Kelompok kewajiban yang penyelesaiannya secara substansial melewati jangka pendek. Digunakan sebagai akun induk dan tidak untuk posting langsung.

Contoh: pinjaman investasi jangka panjang dikelompokkan di sini.', 'both'],
    'Kewajiban Lancar' => ['Kelompok kewajiban yang diperkirakan diselesaikan dalam siklus operasi atau jangka pendek. Digunakan sebagai akun induk dan tidak untuk posting langsung.

Contoh: utang usaha, utang pajak, dan utang gaji dikelompokkan di sini.', 'both'],
    'Laba Tahun Berjalan' => ['Hasil bersih pendapatan dikurangi beban pada periode berjalan sebelum ditutup ke laba ditahan. Digunakan untuk penyajian atau proses penutupan sesuai kebijakan sistem.

Contoh: laba bersih tahun berjalan disajikan dalam ekuitas.', 'both'],
    'Laba/Rugi Selisih Kurs' => ['Hasil bersih perubahan nilai transaksi atau saldo moneter akibat perubahan kurs. Digunakan saat selisih kurs direalisasi atau diukur kembali sesuai kebijakan akuntansi.

Contoh: selisih nilai rupiah ketika invoice vendor valuta asing dilunasi.', 'both'],
    'Modal Pemilik' => ['Kontribusi modal yang telah disetor pemilik kepada perusahaan. Digunakan ketika setoran modal benar-benar diterima dan didukung dokumen.

Contoh: pemilik menyetor modal ke rekening perusahaan.', 'both'],
    'Pembelian' => ['Nilai pembelian barang yang berkaitan dengan barang untuk dijual atau digunakan dalam penyerahan kepada pelanggan sesuai sistem persediaan perusahaan. Digunakan berdasarkan invoice dan penerimaan barang.

Contoh: pembelian perangkat untuk dijual kembali.', 'both'],
    'Pendapatan & Biaya Lain-lain' => ['Kelompok hasil dan biaya yang berada di luar kegiatan utama perusahaan. Digunakan sebagai akun induk dan tidak untuk posting langsung.

Contoh: pendapatan bunga dan biaya bunga dikelompokkan di sini.', 'both'],
    'Pendapatan Bunga' => ['Imbal hasil atas saldo bank, deposito, atau pemberian dana yang sah. Digunakan ketika hak atas bunga timbul berdasarkan rekening koran atau perjanjian.

Contoh: bunga rekening bank bulan berjalan.', 'both'],
    'Pendapatan Lain' => ['Pendapatan yang tidak berasal dari lini usaha utama dan belum memerlukan akun khusus. Digunakan secara terbatas setelah operator memastikan klasifikasinya.

Contoh: keuntungan insidental bernilai tidak material yang didukung dokumen.', 'both'],
    'Penjualan' => ['Nilai penyerahan barang atau jasa utama perusahaan sebelum retur dan potongan. Digunakan ketika kewajiban penyerahan kepada pelanggan telah dipenuhi.

Contoh: invoice penjualan barang atau jasa kepada pelanggan.', 'both'],
    'Peralatan' => ['Harga perolehan peralatan yang digunakan perusahaan lebih dari satu periode dan memenuhi kebijakan kapitalisasi. Digunakan untuk aset operasional yang bukan persediaan.

Contoh: pembelian komputer atau peralatan kantor untuk dipakai sendiri.', 'both'],
    'Persediaan Barang' => ['Barang yang dimiliki untuk dijual kembali atau digunakan dalam penyerahan kepada pelanggan. Digunakan sejak barang diterima sampai dijual atau dikeluarkan dari stok.

Contoh: perangkat jaringan yang tersedia untuk pesanan pelanggan.', 'both'],
    'Petty Cash' => ['Dana kas kecil untuk pembayaran operasional bernilai terbatas. Digunakan sesuai batas dan prosedur pertanggungjawaban kas kecil.

Contoh: pembelian kebutuhan kantor yang disertai struk.', 'both'],
    'Potongan Penjualan' => ['Pengurang pendapatan karena diskon yang diberikan setelah atau pada saat penjualan sesuai kebijakan. Digunakan jika potongan dapat dikaitkan dengan transaksi penjualan tertentu.

Contoh: potongan pembayaran lebih awal kepada pelanggan.', 'both'],
    'Prive' => ['Pengurang ekuitas atas pengambilan aset perusahaan oleh pemilik pada entitas yang menggunakan konsep prive. Digunakan setelah transaksi dan otorisasinya dapat dibuktikan.

Contoh: penarikan dana oleh pemilik untuk kepentingan pribadi.', 'both'],
    'Retur Pembelian' => ['Pengurang pembelian atau kewajiban karena barang dikembalikan kepada pemasok. Digunakan ketika retur telah disetujui dan dapat dikaitkan dengan pembelian asal.

Contoh: pengembalian perangkat rusak kepada vendor.', 'both'],
    'Retur Penjualan' => ['Pengurang pendapatan karena barang atau jasa yang telah ditagihkan dibatalkan atau dikembalikan sesuai kesepakatan. Digunakan ketika retur atau kredit pelanggan disetujui.

Contoh: nota kredit atas perangkat yang dikembalikan pelanggan.', 'both'],
    'Tanah' => ['Harga perolehan tanah yang dimiliki dan digunakan perusahaan. Digunakan saat hak dan penguasaan tanah memenuhi kebijakan pengakuan aset.

Contoh: pembelian tanah untuk kantor perusahaan.', 'both'],
];
