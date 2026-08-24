export type DocumentationCategoryId =
  | 'start'
  | 'master'
  | 'journal'
  | 'reporting'
  | 'fiscal'
  | 'automation'
  | 'administration'
  | 'help';

export interface DocumentationCategory {
  id: DocumentationCategoryId;
  label: string;
  description: string;
  icon: string;
}

export interface DocumentationStep {
  title: string;
  description: string;
  bullets?: string[];
  href?: string;
  actionLabel?: string;
}

export interface DocumentationSection {
  title: string;
  paragraphs?: string[];
  bullets?: string[];
  tone?: 'default' | 'info' | 'warning' | 'success';
}

export interface DocumentationArticle {
  id: string;
  category: DocumentationCategoryId;
  title: string;
  summary: string;
  icon: string;
  minutes: number;
  audience: string;
  keywords: string[];
  outcomes?: string[];
  prerequisites?: string[];
  steps?: DocumentationStep[];
  sections?: DocumentationSection[];
  faq?: Array<{ question: string; answer: string }>;
  related?: string[];
}

export const documentationCategories: DocumentationCategory[] = [
  {
    id: 'start',
    label: 'Mulai Menggunakan',
    description: 'Kenali Akunta dan siapkan perusahaan',
    icon: '▶',
  },
  { id: 'master', label: 'Data Master', description: 'Bagan akun dan periode', icon: '▦' },
  { id: 'journal', label: 'Jurnal', description: 'Input, review, lampiran, dan audit', icon: '✎' },
  {
    id: 'reporting',
    label: 'Laporan',
    description: 'Membaca dan membandingkan laporan',
    icon: '∑',
  },
  { id: 'fiscal', label: 'Fiskal', description: 'Buku Fiskal dan koreksi pajak', icon: 'F' },
  {
    id: 'automation',
    label: 'Otomatisasi',
    description: 'Template, recurring, dan integrasi',
    icon: '↻',
  },
  {
    id: 'administration',
    label: 'Administrasi',
    description: 'Pengguna, izin, dan pengaturan',
    icon: '⚙',
  },
  { id: 'help', label: 'Bantuan', description: 'Istilah, FAQ, dan solusi masalah', icon: '?' },
];

export const documentationArticles: DocumentationArticle[] = [
  {
    id: 'overview',
    category: 'start',
    title: 'Mengenal Akunta',
    summary: 'Gambaran sederhana tentang cara kerja Akunta dan urutan penggunaan yang disarankan.',
    icon: '⌂',
    minutes: 5,
    audience: 'Semua pengguna baru',
    keywords: ['awal', 'pengenalan', 'dashboard', 'alur', 'pemula'],
    outcomes: [
      'Memahami hubungan data master, jurnal, dan laporan.',
      'Mengetahui menu yang digunakan untuk pekerjaan harian.',
      'Mengetahui kapan harus meminta bantuan admin atau supervisor.',
    ],
    steps: [
      {
        title: 'Siapkan fondasi pembukuan',
        description:
          'Admin memilih mode buku, memasang Bagan Akun, dan membuat periode terbuka. Tanpa periode terbuka, jurnal tidak dapat disimpan atau diposting.',
        href: '/settings?section=workspace',
        actionLabel: 'Buka pengaturan workspace',
      },
      {
        title: 'Catat transaksi sebagai jurnal',
        description:
          'Masukkan tanggal, akun debit, akun kredit, nominal, catatan, dan lampiran. Akunta memastikan total debit dan kredit sama serta lebih dari nol.',
        href: '/journals/new',
        actionLabel: 'Buat jurnal',
      },
      {
        title: 'Jalankan proses review',
        description:
          'Operator menyimpan draft lalu mengajukan review. Supervisor dapat menyetujui dan memposting, atau menolak untuk diperbaiki.',
        href: '/journals',
        actionLabel: 'Lihat daftar jurnal',
      },
      {
        title: 'Baca hasil pada laporan',
        description:
          'Jurnal yang sudah diposting akan masuk ke Neraca Saldo, Laba Rugi, Neraca, Buku Besar, dan Buku Pembantu.',
        href: '/laporan/neraca-saldo',
        actionLabel: 'Buka Neraca Saldo',
      },
    ],
    sections: [
      {
        title: 'Prinsip yang perlu diingat',
        bullets: [
          'Draft dan jurnal yang masih direview belum memengaruhi laporan keuangan.',
          'Setiap jurnal wajib balance: total Debit sama dengan total Kredit.',
          'Tanggal jurnal harus berada dalam periode yang statusnya terbuka.',
          'Hak akses menentukan tombol dan data yang dapat dilihat setiap pengguna.',
        ],
        tone: 'info',
      },
    ],
    related: ['first-setup', 'daily-workflow', 'glossary'],
  },
  {
    id: 'first-setup',
    category: 'start',
    title: 'Setup Awal Perusahaan',
    summary: 'Panduan admin menyiapkan mode pembukuan, Bagan Akun, dan periode pertama.',
    icon: '1',
    minutes: 8,
    audience: 'Admin atau pemilik workspace',
    keywords: ['onboarding', 'setup', 'coa', 'periode', 'mode buku', 'perusahaan'],
    outcomes: [
      'Workspace siap menerima jurnal pertama.',
      'Mode Intern/Fiskal dipilih dengan benar.',
    ],
    steps: [
      {
        title: 'Pilih mode pembukuan',
        description:
          'Pilih Intern Saja bila perusahaan hanya memerlukan laporan manajemen. Pilih Intern dan Fiskal Independen bila transaksi dan laporan kedua buku perlu dipisahkan.',
        bullets: [
          'Mode independen berarti Intern dan Fiskal adalah dua ledger lengkap yang terpisah.',
          'Jangan memilih Intern Saja jika perusahaan sudah memiliki kebutuhan jurnal atau koreksi Fiskal.',
        ],
      },
      {
        title: 'Pilih template Bagan Akun',
        description:
          'Gunakan template industri yang paling mendekati bisnis. Akun dapat disunting setelah onboarding selesai.',
      },
      {
        title: 'Buat periode pertama',
        description:
          'Isi nama, tanggal mulai, dan tanggal selesai. Pastikan tanggal transaksi pertama berada di dalam rentang tersebut.',
        href: '/periode',
        actionLabel: 'Kelola periode',
      },
      {
        title: 'Periksa profil dan format',
        description:
          'Lengkapi profil entitas, format tanggal, format nomor jurnal, dan preferensi tampilan pada menu Setting.',
        href: '/settings',
        actionLabel: 'Buka Setting',
      },
    ],
    sections: [
      {
        title: 'Sebelum transaksi pertama',
        bullets: [
          'Periksa saldo awal dan akun kas/bank.',
          'Pastikan akun yang akan digunakan berstatus aktif dan postable.',
          'Undang pengguna dan berikan role sesuai tanggung jawab.',
        ],
        tone: 'success',
      },
    ],
    faq: [
      {
        question: 'Apakah mode buku dapat diganti nanti?',
        answer:
          'Dapat dikelola oleh admin, tetapi perpindahan ke Intern Saja dibatasi bila sudah ada jurnal atau koreksi Fiskal agar data tidak kehilangan konteks.',
      },
      {
        question: 'Apakah template Bagan Akun bersifat permanen?',
        answer:
          'Tidak. Template adalah titik awal. Admin dapat menambah dan memperbarui akun sesuai kebutuhan bisnis.',
      },
    ],
    related: ['chart-of-accounts', 'periods', 'users-permissions'],
  },
  {
    id: 'daily-workflow',
    category: 'start',
    title: 'Alur Kerja Harian',
    summary: 'Rutinitas yang disarankan untuk operator, supervisor, dan petugas pajak.',
    icon: '✓',
    minutes: 6,
    audience: 'Operator, supervisor, dan tax officer',
    keywords: ['harian', 'operator', 'supervisor', 'review', 'rutinitas'],
    sections: [
      {
        title: 'Operator',
        bullets: [
          'Periksa periode aktif.',
          'Buat atau lengkapi jurnal Draft.',
          'Tambahkan bukti transaksi.',
          'Pastikan warning bar hilang.',
          'Klik Ajukan Review.',
        ],
      },
      {
        title: 'Supervisor atau Admin',
        bullets: [
          'Buka tab In Review.',
          'Cocokkan akun, nominal, catatan, dan lampiran.',
          'Pilih Setujui & Posting jika benar.',
          'Pilih Tolak bila perlu diperbaiki.',
          'Tinjau Audit Trail jika jurnal Saved diubah.',
        ],
      },
      {
        title: 'Tax Officer',
        bullets: [
          'Gunakan buku Fiskal.',
          'Tinjau jurnal Fiskal yang sudah diposting.',
          'Buat koreksi Fiskal dengan alasan dan dasar hukum.',
          'Lampirkan bukti sebelum approval.',
          'Periksa rekonsiliasi pajak.',
          'Jika perlu pengakuan ke laporan keuangan, buat dan proses jurnal provisi pajak Intern.',
        ],
      },
      {
        title: 'Penutupan hari atau bulan',
        bullets: [
          'Pastikan tidak ada transaksi penting tertinggal di Draft.',
          'Periksa Neraca Saldo harus balance.',
          'Bandingkan Intern dan Fiskal.',
          'Tutup periode hanya setelah review selesai.',
        ],
        tone: 'info',
      },
    ],
    related: ['create-journal', 'journal-status', 'reports', 'fiscal-corrections'],
  },
  {
    id: 'chart-of-accounts',
    category: 'master',
    title: 'Mengelola Bagan Akun',
    summary: 'Cara memahami, mencari, menambah, dan mengatur ketersediaan akun.',
    icon: '⊞',
    minutes: 8,
    audience: 'Admin dan accountant',
    keywords: ['akun', 'coa', 'kode akun', 'postable', 'availability', 'legal basis'],
    prerequisites: [
      'Pahami jenis akun dasar: Aset, Liabilitas, Ekuitas, Pendapatan, HPP, dan Beban.',
    ],
    steps: [
      {
        title: 'Buka Bagan Akun',
        description:
          'Gunakan tampilan List untuk pencarian cepat, Tree untuk melihat hierarki, atau T-view untuk hubungan akun.',
        href: '/akun',
        actionLabel: 'Buka Bagan Akun',
      },
      {
        title: 'Cari akun yang sudah ada',
        description:
          'Cari berdasarkan kode, nama, atau deskripsi sebelum membuat akun baru agar tidak terjadi duplikasi.',
      },
      {
        title: 'Isi identitas akun',
        description:
          'Tentukan kode unik, nama jelas, jenis akun, normal balance, parent, status postable, dan status aktif.',
      },
      {
        title: 'Atur ketersediaan buku',
        description:
          'Pilih Intern, Fiskal, atau Keduanya. Akun Fiskal memerlukan dasar hukum yang relevan.',
      },
      {
        title: 'Tulis deskripsi operasional',
        description:
          'Jelaskan definisi akun, kapan digunakan, dan contoh transaksi agar operator tidak salah memilih akun.',
      },
    ],
    sections: [
      {
        title: 'Parent dan postable',
        paragraphs: [
          'Parent digunakan untuk pengelompokan dan biasanya tidak menerima posting. Akun postable adalah akun detail yang dapat dipilih pada baris jurnal.',
        ],
        tone: 'info',
      },
      {
        title: 'Jangan mengubah akun sembarangan',
        bullets: [
          'Jangan mengubah jenis atau normal balance akun yang sudah banyak dipakai tanpa review accountant.',
          'Nonaktifkan akun lama bila tidak digunakan; hindari membuat ulang akun dengan arti yang sama.',
          'Periksa dampak perubahan pada laporan.',
        ],
        tone: 'warning',
      },
    ],
    related: ['first-setup', 'create-journal', 'dual-books'],
  },
  {
    id: 'periods',
    category: 'master',
    title: 'Mengelola Periode',
    summary: 'Membuat, menutup, membuka kembali, dan menangani error periode.',
    icon: '◷',
    minutes: 6,
    audience: 'Admin dan supervisor',
    keywords: ['periode', 'tanggal', 'open period', 'tutup buku', 'tidak ada periode terbuka'],
    steps: [
      {
        title: 'Buat periode',
        description:
          'Isi nama dan rentang tanggal. Hindari periode tumpang tindih untuk pekerjaan rutin.',
        href: '/periode',
        actionLabel: 'Buka Periode',
      },
      {
        title: 'Gunakan periode terbuka',
        description:
          'Jurnal hanya dapat disimpan atau diposting bila tanggalnya tercakup periode yang terbuka.',
      },
      {
        title: 'Review sebelum menutup',
        description:
          'Periksa Draft, In Review, Neraca Saldo, dan lampiran transaksi sebelum menutup periode.',
      },
      {
        title: 'Tutup periode',
        description:
          'Pilih periode lalu klik Tutup. Setelah ditutup, transaksi pada rentang tersebut tidak dapat diposting.',
      },
      {
        title: 'Buka kembali bila diperlukan',
        description:
          'Gunakan Buka Lagi hanya dengan otorisasi dan alasan yang jelas, kemudian selesaikan koreksi dan tutup kembali.',
      },
    ],
    faq: [
      {
        question: 'Mengapa muncul “Tidak ada periode terbuka untuk tanggal …”?',
        answer:
          'Tanggal jurnal tidak tercakup periode berstatus terbuka. Ubah tanggal jurnal atau minta admin membuat/membuka periode yang benar.',
      },
      {
        question: 'Apakah periode boleh dihapus?',
        answer:
          'Hapus hanya periode yang belum memiliki transaksi dan memang dibuat keliru. Untuk periode historis, gunakan status tutup.',
      },
    ],
    related: ['create-journal', 'troubleshooting', 'reports'],
  },
  {
    id: 'create-journal',
    category: 'journal',
    title: 'Membuat Jurnal Manual',
    summary: 'Tutorial lengkap mencatat transaksi debit dan kredit sampai siap direview.',
    icon: '✎',
    minutes: 14,
    audience: 'Operator dan accountant',
    keywords: ['jurnal', 'debit', 'kredit', 'balance', 'catatan', 'lampiran', 'posting'],
    prerequisites: [
      'Periode untuk tanggal transaksi sudah terbuka.',
      'Akun yang diperlukan sudah aktif dan postable.',
      'Bukti transaksi tersedia bila diwajibkan SOP.',
    ],
    steps: [
      {
        title: 'Klik Buat Jurnal',
        description: 'Buka menu Jurnal lalu pilih Buat Jurnal.',
        href: '/journals/new',
        actionLabel: 'Buat jurnal sekarang',
      },
      {
        title: 'Pilih buku',
        description:
          'Pilih Intern, Fiskal, atau Intern & Fiskal. Mode gabungan membuat dua draft independen dengan kode transaksi yang sama.',
      },
      {
        title: 'Isi informasi transaksi',
        description:
          'Pilih jenis jurnal, tanggal, nomor atau kode transaksi, memo utama, dan referensi bila ada.',
      },
      {
        title: 'Isi baris Debit',
        description:
          'Klik Tambah baris Debit, cari akun, lalu masukkan nominal. Kursor otomatis menuju pencarian akun.',
      },
      {
        title: 'Isi baris Kredit',
        description:
          'Tambahkan akun lawan dan nominal. Total Debit wajib sama dengan total Kredit dan lebih besar dari nol.',
      },
      {
        title: 'Tambahkan catatan baris',
        description:
          'Klik ikon Note di samping baris. Ikon menjadi kuning bila catatan sudah terisi.',
      },
      {
        title: 'Tambahkan lampiran',
        description:
          'Unggah invoice, kuitansi, PDF, atau gambar yang mendukung transaksi. Preview file sebelum menyimpan.',
      },
      {
        title: 'Periksa warning bar',
        description:
          'Toolbar warning di atas tombol aksi menampilkan error secara langsung. Selesaikan seluruh tag error sampai bar menghilang.',
      },
      {
        title: 'Simpan atau ajukan',
        description:
          'Pilih Simpan Draft bila belum selesai. Operator memilih Ajukan Review; pengguna berwenang dapat memilih Posting Jurnal.',
      },
    ],
    sections: [
      {
        title: 'Contoh sederhana',
        paragraphs: [
          'Pembelian perlengkapan tunai Rp500.000: Debit Perlengkapan Rp500.000 dan Kredit Kas Rp500.000.',
        ],
        tone: 'success',
      },
      {
        title: 'Mode Intern & Fiskal',
        bullets: [
          'Hanya akun yang tersedia di kedua buku yang dapat dipilih.',
          'Akun, lampiran, nomor, status, dan saldo pada kedua jurnal tetap independen setelah dibuat.',
          'Perubahan satu jurnal tidak otomatis mengubah pasangannya.',
        ],
        tone: 'warning',
      },
    ],
    related: ['journal-status', 'attachments-audit', 'dual-books', 'journal-templates'],
  },
  {
    id: 'journal-status',
    category: 'journal',
    title: 'Status dan Proses Review Jurnal',
    summary: 'Arti Draft, In Review, Saved, dan Need Revision serta tindakan pada setiap tahap.',
    icon: '⇢',
    minutes: 7,
    audience: 'Operator, supervisor, dan admin',
    keywords: ['draft', 'in review', 'saved', 'need revision', 'approve', 'tolak', 'posting'],
    sections: [
      {
        title: 'Draft',
        paragraphs: [
          'Jurnal masih dapat dilengkapi oleh pembuat dan belum memengaruhi laporan. Simpan Draft digunakan untuk pekerjaan yang belum final.',
        ],
      },
      {
        title: 'In Review',
        paragraphs: [
          'Jurnal telah diajukan operator. Supervisor memeriksa akun, nominal, lampiran, dan memo, lalu memilih Setujui & Posting atau Tolak.',
        ],
      },
      {
        title: 'Saved',
        paragraphs: [
          'Jurnal sudah diposting dan memengaruhi saldo serta laporan. Operator hanya dapat membaca; supervisor/admin dapat mengubah melalui kewenangan khusus dan setiap perubahan masuk Audit Trail.',
        ],
        tone: 'success',
      },
      {
        title: 'Need Revision',
        paragraphs: [
          'Reviewer menolak jurnal untuk diperbaiki. Baca alasan penolakan, perbaiki data, lalu ajukan kembali.',
        ],
        tone: 'warning',
      },
    ],
    steps: [
      {
        title: 'Operator mengajukan',
        description: 'Pastikan warning bar bersih lalu klik Ajukan Review.',
      },
      {
        title: 'Reviewer memeriksa',
        description: 'Buka tab In Review dan cocokkan jurnal dengan bukti transaksi.',
      },
      {
        title: 'Reviewer mengambil keputusan',
        description: 'Setujui & Posting bila benar, atau Tolak disertai alasan perbaikan.',
      },
      {
        title: 'Pantau hasil',
        description: 'Jurnal yang disetujui masuk Saved; jurnal yang ditolak masuk Need Revision.',
        href: '/journals',
        actionLabel: 'Buka daftar jurnal',
      },
    ],
    related: ['create-journal', 'attachments-audit', 'reports'],
  },
  {
    id: 'dual-books',
    category: 'journal',
    title: 'Memahami Buku Intern dan Fiskal',
    summary: 'Perbedaan dua buku independen, mode input gabungan, dan aturan aksesnya.',
    icon: '⇄',
    minutes: 8,
    audience: 'Accountant, supervisor, tax officer, dan admin',
    keywords: ['intern', 'fiskal', 'both', 'independent books', 'ledger', 'inspector'],
    sections: [
      {
        title: 'Buku Intern',
        paragraphs: [
          'Digunakan untuk kebutuhan manajemen dan operasional perusahaan. Angka dapat mengikuti kebijakan internal selama tetap memenuhi aturan pencatatan perusahaan.',
        ],
      },
      {
        title: 'Buku Fiskal',
        paragraphs: [
          'Digunakan untuk pencatatan yang menjadi dasar rekonsiliasi fiskal. Akun Fiskal memerlukan metadata dasar hukum dan dapat dilihat oleh role yang diizinkan.',
        ],
      },
      {
        title: 'Intern & Fiskal bukan buku ketiga',
        paragraphs: [
          'Pilihan gabungan adalah cara input untuk membuat dua jurnal draft sekaligus. Hasilnya tetap dua jurnal, dua status, dua lampiran, dan dua saldo yang independen.',
        ],
        tone: 'warning',
      },
      {
        title: 'Inspector',
        paragraphs: [
          'Inspector hanya dapat membaca buku dan laporan Fiskal. Pembatasan diterapkan oleh server, bukan sekadar menyembunyikan menu.',
        ],
        tone: 'info',
      },
    ],
    faq: [
      {
        question: 'Mengapa nilai Intern dan Fiskal berbeda?',
        answer:
          'Karena keduanya ledger independen. Perbedaan dapat berasal dari kebijakan pengakuan, jurnal yang hanya dibuat pada satu buku, atau koreksi yang belum dicatat.',
      },
      {
        question: 'Apakah mengedit jurnal Intern mengubah jurnal Fiskal pasangannya?',
        answer: 'Tidak. Setelah dibuat, setiap jurnal dikelola sendiri.',
      },
      {
        question: 'Bagaimana membandingkan kedua buku?',
        answer:
          'Pada setiap laporan pilih tab Intern & Fiskal. Nilai kedua buku ditampilkan berdampingan.',
      },
    ],
    related: ['create-journal', 'reports', 'fiscal-corrections'],
  },
  {
    id: 'attachments-audit',
    category: 'journal',
    title: 'Lampiran dan Audit Trail',
    summary: 'Mengelola bukti transaksi dan menelusuri perubahan jurnal Saved.',
    icon: '◴',
    minutes: 7,
    audience: 'Operator, reviewer, auditor, dan admin',
    keywords: ['lampiran', 'attachment', 'audit trail', 'restore', 'snapshot', 'hapus', 'reupload'],
    steps: [
      {
        title: 'Unggah bukti',
        description:
          'Pada form jurnal klik area Lampiran, pilih file, lalu gunakan preview untuk memastikan dokumen benar.',
      },
      {
        title: 'Simpan jurnal',
        description:
          'Lampiran baru tersimpan bersama jurnal. Dalam mode input gabungan, file menjadi lampiran pada masing-masing jurnal.',
      },
      {
        title: 'Buka Audit Trail',
        description:
          'Pada jurnal Saved, buka panel Audit Trail di sisi kanan. Badge menunjukkan jumlah riwayat.',
      },
      {
        title: 'Pilih snapshot',
        description:
          'Klik titik pada timeline untuk melihat versi sebelumnya. Titik aktif berwarna biru dan tombol Restore muncul saat hover atau dipilih.',
      },
      {
        title: 'Pulihkan bila diperlukan',
        description:
          'Restore mengisi form berdasarkan snapshot terpilih. Periksa ulang sebelum Simpan Perubahan.',
      },
    ],
    sections: [
      {
        title: 'Aturan lampiran',
        bullets: [
          'Menghapus atau mengunggah ulang lampiran dicatat pada Audit Trail.',
          'Lampiran lama tidak dihapus hanya karena jurnal diedit.',
          'Penghapusan permanen dilakukan secara sengaja oleh admin sesuai kewenangan.',
        ],
        tone: 'warning',
      },
      {
        title: 'Apa yang dicatat?',
        bullets: [
          'Tanggal dan waktu perubahan.',
          'Pengguna yang melakukan perubahan.',
          'Snapshot isi jurnal.',
          'Informasi perubahan atau penghapusan lampiran.',
        ],
        tone: 'info',
      },
    ],
    related: ['create-journal', 'journal-status', 'users-permissions'],
  },
  {
    id: 'journal-templates',
    category: 'automation',
    title: 'Template dan Jurnal Berulang',
    summary: 'Mempercepat transaksi rutin dengan pola jurnal yang dapat digunakan ulang.',
    icon: '↻',
    minutes: 8,
    audience: 'Accountant dan admin',
    keywords: ['template', 'recurring', 'jurnal berulang', 'jadwal', 'bookmark'],
    steps: [
      {
        title: 'Buat template',
        description:
          'Buka Journal Template, isi kode, nama, deskripsi, jenis jurnal, mode buku, serta baris debit/kredit.',
        href: '/template-jurnal',
        actionLabel: 'Kelola template',
      },
      {
        title: 'Gunakan template',
        description:
          'Pada form jurnal pilih Mulai dari Template. Akun dan pola baris akan diisikan; Anda tetap perlu memeriksa nominal dan tanggal.',
      },
      {
        title: 'Buat jadwal berulang',
        description:
          'Buka Jurnal Berulang, pilih template, tentukan frekuensi dan tanggal eksekusi.',
        href: '/jurnal-berulang',
        actionLabel: 'Kelola jurnal berulang',
      },
      {
        title: 'Tinjau hasil otomatis',
        description:
          'Periksa jurnal yang dihasilkan pada daftar jurnal dan pastikan periode serta akun tetap valid.',
      },
    ],
    sections: [
      {
        title: 'Kapan menggunakan template?',
        bullets: [
          'Biaya langganan bulanan.',
          'Gaji dan tunjangan dengan pola tetap.',
          'Penyusutan berkala.',
          'Transaksi bank rutin dengan akun lawan yang konsisten.',
        ],
        tone: 'success',
      },
      {
        title: 'Tetap lakukan review',
        paragraphs: [
          'Template mempercepat input, tetapi tidak menggantikan pemeriksaan. Pastikan nominal, tanggal, buku, dan bukti transaksi sesuai periode berjalan.',
        ],
        tone: 'warning',
      },
    ],
    related: ['create-journal', 'periods', 'auto-mapping'],
  },
  {
    id: 'reports',
    category: 'reporting',
    title: 'Membaca Laporan Keuangan',
    summary: 'Fungsi setiap laporan, penggunaan filter, dan perbandingan Intern/Fiskal.',
    icon: '∑',
    minutes: 12,
    audience: 'Owner, manager, accountant, dan tax officer',
    keywords: [
      'laporan',
      'neraca saldo',
      'laba rugi',
      'neraca',
      'buku besar',
      'buku pembantu',
      'compare',
    ],
    steps: [
      {
        title: 'Pilih jenis laporan',
        description:
          'Gunakan Neraca Saldo untuk validasi saldo, Laba Rugi untuk kinerja, Neraca untuk posisi keuangan, Buku Besar untuk detail akun, dan Buku Pembantu untuk rekap berdasarkan sumber.',
      },
      {
        title: 'Pilih buku',
        description:
          'Pilih Intern, Fiskal, atau Intern & Fiskal. Tab gabungan menampilkan kedua nilai berdampingan dan tidak menjumlahkannya.',
      },
      {
        title: 'Atur tanggal atau periode',
        description: 'Isi tanggal akhir atau rentang tanggal sesuai kebutuhan analisis.',
      },
      {
        title: 'Klik Tampilkan',
        description:
          'Perubahan tab buku dimuat otomatis; perubahan tanggal diterapkan saat Anda menekan Tampilkan.',
      },
      {
        title: 'Lakukan drill-down',
        description:
          'Klik baris akun pada Neraca Saldo untuk membuka Buku Besar dan melihat transaksi penyusunnya.',
      },
    ],
    sections: [
      {
        title: 'Neraca Saldo',
        paragraphs: [
          'Menampilkan total Debit, Kredit, dan Saldo per akun. Total Debit dan Kredit harus balance.',
        ],
        bullets: [
          'Debit diberi judul hijau.',
          'Kredit diberi judul merah lembut.',
          'Nilai nol ditampilkan grey dengan opacity rendah.',
        ],
      },
      {
        title: 'Laba Rugi',
        paragraphs: [
          'Menampilkan Pendapatan, Harga Pokok Penjualan, Beban Operasional, Laba Kotor, dan Laba Bersih untuk suatu periode.',
        ],
      },
      {
        title: 'Neraca',
        paragraphs: [
          'Menampilkan Aset, Liabilitas, dan Ekuitas pada tanggal tertentu. Laba berjalan otomatis masuk ke Ekuitas agar posisi keuangan tetap seimbang.',
        ],
      },
      {
        title: 'Buku Besar',
        paragraphs: [
          'Menampilkan saldo awal, transaksi kronologis, total Debit/Kredit, dan saldo akhir untuk satu akun. Gunakan filter sumber untuk menelusuri transaksi dari aplikasi lain.',
        ],
      },
      {
        title: 'Buku Pembantu',
        paragraphs: [
          'Mengelompokkan transaksi berdasarkan source-ref seperti pelanggan, vendor, payroll, atau objek lain yang dikirim aplikasi terintegrasi.',
        ],
      },
      {
        title: 'Hanya jurnal Saved',
        paragraphs: [
          'Laporan hanya menghitung jurnal yang sudah diposting/Saved. Draft, In Review, dan Need Revision tidak mengubah angka laporan.',
        ],
        tone: 'info',
      },
    ],
    related: ['dual-books', 'journal-status', 'fiscal-corrections', 'troubleshooting'],
  },
  {
    id: 'fiscal-corrections',
    category: 'fiscal',
    title: 'Koreksi Fiskal, Rekonsiliasi, dan Provisi Pajak',
    summary:
      'Membuat koreksi, membaca rekonsiliasi, dan mencatat dampak pajak melalui jurnal Intern terpisah.',
    icon: 'F',
    minutes: 10,
    audience: 'Tax officer, supervisor, dan admin',
    keywords: [
      'koreksi fiskal',
      'rekonsiliasi pajak',
      'provisi pajak',
      'pajak kini',
      'positif',
      'negatif',
      'bukti',
      'approval',
      'pendapatan',
      'biaya',
      'hpp',
      'kredit pajak',
      'jurnal fiskal sumber',
      'akun wajib pajak',
      'utang pph badan provisi',
      'utang pph badan definitif',
    ],
    outcomes: [
      'Memahami bahwa koreksi Fiskal adalah rekonsiliasi dan bukan mutasi jurnal.',
      'Menentukan kapan jurnal Fiskal sumber perlu dipilih dan kapan boleh dikosongkan.',
      'Membedakan akun provisi Intern dari akun pajak definitif Intern & Fiskal.',
      'Membaca perhitungan pajak dan membuat jurnal provisi melalui workflow jurnal biasa.',
    ],
    prerequisites: [
      'Entitas menggunakan mode Intern dan Fiskal Independen.',
      'Akun Fiskal dan dasar hukum sudah disiapkan.',
      'Jurnal Fiskal terkait sudah Saved bila koreksi merujuk jurnal.',
    ],
    steps: [
      {
        title: 'Buka Koreksi & Provisi Pajak',
        description: 'Pilih periode yang ingin direkonsiliasi.',
        href: '/fiskal/koreksi',
        actionLabel: 'Buka Koreksi Fiskal',
      },
      {
        title: 'Buat koreksi',
        description:
          'Pilih akun laporan laba rugi, tanggal, arah positif/negatif, nominal, alasan, dasar hukum, dan jurnal Fiskal sumber bila koreksi dapat ditelusuri ke satu jurnal tertentu.',
      },
      {
        title: 'Simpan Draft',
        description: 'Periksa kembali data. Draft koreksi belum memengaruhi laporan.',
      },
      {
        title: 'Unggah bukti',
        description: 'Lampirkan dokumen pendukung. Koreksi tidak dapat disetujui tanpa bukti.',
      },
      {
        title: 'Approve',
        description:
          'Pengguna berwenang menyetujui koreksi. Setelah approved, data dan bukti menjadi immutable.',
      },
      {
        title: 'Baca rekonsiliasi',
        description:
          'Pada tab Daftar Akun, baca nilai sebelum koreksi, koreksi positif, koreksi negatif, dan nilai setelah koreksi. Ringkasan selalu dibatasi oleh entitas serta periode aktif.',
      },
      {
        title: 'Hitung pajak kini',
        description:
          'Pada tab Perhitungan Pajak dan Jurnal Provisi, baca rincian penghasilan kena pajak, tarif simulasi atau tersimpan, beban pajak kini, kredit pajak, dan utang pajak.',
      },
      {
        title: 'Buat jurnal provisi',
        description:
          'Pada tabel jurnal yang perlu dibuat, pilih Tambahkan jurnal. Form jurnal Intern terbuka dengan akun, deskripsi, tanggal, dan nominal yang sudah terisi untuk direview sebelum disimpan.',
      },
    ],
    sections: [
      {
        title: 'Koreksi tidak mengubah jurnal',
        paragraphs: [
          'Koreksi Fiskal disimpan terpisah dan tidak menambah Debit/Kredit pada buku Intern maupun Fiskal. Koreksi approved hanya memengaruhi rekonsiliasi pajak.',
        ],
        tone: 'warning',
      },
      {
        title: 'Fungsi Jurnal Fiskal sumber (opsional)',
        paragraphs: [
          'Jurnal Fiskal sumber adalah referensi audit trail ke satu jurnal Fiskal Saved/posted yang menjadi dasar ditemukannya koreksi. Pemilihannya membantu reviewer memeriksa akun, nominal, uraian, dan bukti transaksi asal serta mengurangi risiko koreksi ganda.',
          'Referensi ini tidak mengubah, membalik, atau menambahkan Debit/Kredit pada jurnal sumber. Posting, saldo, lampiran, dan status jurnal sumber tetap independen dari koreksi.',
        ],
        bullets: [
          'Pilih jurnal sumber jika koreksi berasal dari satu jurnal Fiskal yang jelas.',
          'Kosongkan jika koreksi berasal dari rekap banyak jurnal, perhitungan eksternal, kebijakan, atau temuan yang tidak dapat dipetakan ke satu jurnal.',
          'Jika sumbernya lebih dari satu, jelaskan cakupan transaksi pada alasan koreksi dan lampirkan rekap atau bukti pendukung.',
          'Jurnal Intern serta jurnal Fiskal yang belum Saved/posted tidak dapat dipilih.',
        ],
        tone: 'info',
      },
      {
        title: 'Dampak laporan keuangan memakai jurnal terpisah',
        paragraphs: [
          'Rekomendasi jurnal provisi menggunakan buku Intern: Debit Beban Pajak Penghasilan Kini, Kredit Pajak Dibayar di Muka untuk kredit yang digunakan, dan Kredit Utang PPh Badan - Provisi untuk sisanya. Tambahkan jurnal hanya membuka dan mengisi form jurnal dengan akun, deskripsi “Koreksi fiskal & provisi”, tanggal, serta nominal yang disarankan; jurnal belum tersimpan sebelum form dikonfirmasi. Neraca Saldo dan laporan Intern baru berubah setelah jurnal diposting.',
          'Jika suatu perhitungan memerlukan lebih dari satu jurnal, setiap jurnal ditampilkan sebagai baris rekomendasi terpisah dengan tombol Tambahkan jurnal masing-masing.',
        ],
        tone: 'info',
      },
      {
        title: 'Empat akun pajak wajib pada setiap entitas',
        paragraphs: [
          'Akunta menyediakan empat akun sistem agar koreksi Fiskal dan jurnal provisi selalu mempunyai akun yang tepat. Akun dibuat saat entitas dibuat dan dipastikan kembali saat template COA atau seeder dijalankan.',
          'Kode, nama, tipe, saldo normal, parent, status aktif/postable, dan ketersediaannya dikunci. Akun wajib tidak dapat dihapus atau dinonaktifkan, tetapi deskripsi dan dasar hukumnya tetap dapat diperbarui.',
        ],
        bullets: [
          '1498 — Pajak Dibayar di Muka: Intern & Fiskal (both), karena kredit pajak yang valid dapat menjadi bagian posisi pajak kedua buku.',
          '2197 — Utang PPh Badan - Provisi: Intern, karena merupakan estimasi akuntansi sebelum kewajiban definitif.',
          '2198 — Utang PPh Badan Definitif: Intern & Fiskal (both), karena kewajiban sudah didasarkan pada perhitungan dan dokumen pajak definitif.',
          '6998 — Beban Pajak Penghasilan Kini: Intern, karena merupakan pengakuan beban pada laporan keuangan komersial.',
        ],
        tone: 'success',
      },
      {
        title: 'Cara membaca ringkasan perhitungan',
        bullets: [
          'Baris Sebelum Koreksi menunjukkan nilai buku Fiskal sebelum koreksi approved diterapkan.',
          'Koreksi positif menambah dasar pajak dan koreksi negatif menguranginya.',
          'Tanggal periode pada subjudul mengikuti format tanggal yang dipilih pada Settings.',
          'Perhitungan pajak dan daftar jurnal bersifat baca-saja; perubahan pencatatan dilakukan pada form jurnal standar.',
          'Hasil merupakan estimasi atau snapshot rekonsiliasi, bukan SPT final yang telah dilaporkan.',
        ],
        tone: 'default',
      },
      {
        title: 'Pajak tangguhan tidak dihitung otomatis',
        paragraphs: [
          'Tanda koreksi positif atau negatif belum cukup untuk menentukan pajak tangguhan. Analisis memerlukan jumlah tercatat dan dasar pajak aset/liabilitas, waktu pembalikan, serta tarif yang berlaku. Gunakan jurnal terpisah yang telah direview untuk pencatatan pajak tangguhan.',
        ],
        tone: 'warning',
      },
      {
        title: 'Akun yang dapat dipilih untuk koreksi',
        paragraphs: [
          'Koreksi Fiskal hanya menggunakan akun laporan laba rugi yang tersedia pada buku Fiskal. Karena itu, tidak semua akun pada Bagan Akun ditampilkan di formulir koreksi.',
        ],
        bullets: [
          'Akun yang dapat dipilih adalah Pendapatan, Harga Pokok Penjualan (HPP), dan Beban/Biaya.',
          'Akun neraca seperti Bank (Aset) dan Modal (Ekuitas) tidak ditampilkan karena bukan akun yang dikoreksi melalui rekonsiliasi laba fiskal.',
          'Setoran modal tetap dicatat sebagai jurnal Debit Bank dan Kredit Modal; transaksi tersebut bukan pendapatan usaha dan tidak perlu dimasukkan sebagai Koreksi Fiskal.',
          'Jurnal sumber bersifat opsional dan hanya menampilkan jurnal Fiskal yang sudah Saved/posted. Jurnal Intern atau jurnal yang belum Saved tidak akan muncul.',
        ],
        tone: 'info',
      },
      {
        title: 'Arti arah koreksi',
        bullets: [
          'Koreksi positif menambah dasar penghasilan kena pajak.',
          'Koreksi negatif mengurangi dasar penghasilan kena pajak.',
          'Gunakan alasan dan dasar hukum yang dapat diaudit.',
        ],
        tone: 'info',
      },
    ],
    faq: [
      {
        question: 'Mengapa akun Bank atau Modal tidak muncul di Koreksi Fiskal?',
        answer:
          'Bank adalah akun Aset dan Modal adalah akun Ekuitas. Formulir Koreksi Fiskal hanya menampilkan akun laba rugi—Pendapatan, HPP, dan Beban/Biaya—yang tersedia di buku Fiskal. Setoran modal dicatat langsung sebagai jurnal Debit Bank dan Kredit Modal, bukan sebagai koreksi fiskal.',
      },
      {
        question: 'Apakah koreksi Fiskal mengubah Neraca Saldo?',
        answer:
          'Tidak secara langsung. Koreksi approved mengubah rekonsiliasi pajak. Jika dampak pajak kini perlu diakui pada laporan keuangan, buat jurnal provisi Intern dan posting melalui workflow jurnal biasa.',
      },
      {
        question: 'Apakah hasil perhitungan ini sama dengan SPT final?',
        answer:
          'Tidak. Hasilnya adalah snapshot perhitungan dan provisi akuntansi berdasarkan data yang dimasukkan. Validasi ketentuan, fasilitas tarif, kredit, serta pelaporan SPT tetap dilakukan dalam proses pajak perusahaan.',
      },
      {
        question: 'Apa manfaat memilih Jurnal Fiskal sumber?',
        answer:
          'Jurnal sumber membuat koreksi dapat ditelusuri ke transaksi asal, memudahkan review akun, nominal, uraian, dan bukti, serta membantu mencegah koreksi ganda. Field ini boleh dikosongkan jika koreksi berasal dari banyak jurnal atau rekap eksternal. Memilihnya tidak mengubah jurnal maupun saldo ledger.',
      },
      {
        question:
          'Mengapa Beban Pajak Penghasilan Kini dan Utang PPh Badan - Provisi hanya Intern?',
        answer:
          'Keduanya mencatat estimasi akuntansi pajak kini pada laporan keuangan komersial. Buku Fiskal digunakan sebagai dasar rekonsiliasi, sedangkan koreksi approved tetap disimpan di luar jurnal. Setelah kewajiban pajak menjadi definitif, gunakan Utang PPh Badan Definitif yang tersedia pada Intern & Fiskal.',
      },
      {
        question: 'Mengapa akun pajak wajib tidak dapat dihapus?',
        answer:
          'Perhitungan dan pembuatan jurnal provisi bergantung pada akun tersebut. Penguncian mencegah form menampilkan Akun belum tersedia, menjaga scope Intern/Fiskal tetap benar, dan memastikan setiap entitas selalu siap menjalankan rekonsiliasi pajak.',
      },
    ],
    related: ['dual-books', 'attachments-audit', 'reports'],
  },
  {
    id: 'auto-mapping',
    category: 'automation',
    title: 'Auto Mapping Data Transaksi',
    summary:
      'Mengubah data mentah dari sistem lain menjadi jurnal dengan rule yang dapat digunakan ulang.',
    icon: '⇄',
    minutes: 10,
    audience: 'Admin integrasi dan accountant',
    keywords: ['auto mapping', 'json', 'raw data', 'mapping rule', 'generate jurnal', 'reprocess'],
    steps: [
      {
        title: 'Buka Raw Data',
        description: 'Pilih transaksi berstatus belum dimapping pada menu Auto Mapping.',
        href: '/auto-mapping',
        actionLabel: 'Buka Auto Mapping',
      },
      {
        title: 'Periksa payload',
        description:
          'Pastikan tanggal, deskripsi, nominal, dan identitas sumber tersedia pada JSON.',
      },
      {
        title: 'Petakan field',
        description:
          'Tarik field JSON ke tanggal, deskripsi, akun, nominal, dan memo. Tentukan baris Debit/Kredit.',
      },
      {
        title: 'Validasi hasil',
        description:
          'Pastikan akun ada, periode terbuka, nominal valid, serta Debit dan Kredit balance.',
      },
      {
        title: 'Simpan rule dan generate',
        description:
          'Rule akan dipakai kembali untuk data dengan source type dan struktur yang sama.',
      },
      {
        title: 'Pantau dan reprocess',
        description:
          'Jika struktur sumber berubah, data kembali ke Raw Data. Perbarui mapping lalu jalankan reprocess.',
      },
    ],
    sections: [
      {
        title: 'Pencegahan duplikasi',
        paragraphs: [
          'Sistem sumber harus mengirim idempotency key unik. Data dengan identitas yang sama tidak boleh menghasilkan jurnal ganda.',
        ],
        tone: 'warning',
      },
      {
        title: 'Untuk tim teknis',
        paragraphs: [
          'Detail endpoint, token, contoh JSON, dan cara pattern matching tersedia pada dokumentasi teknis Auto Mapping.',
        ],
        tone: 'info',
      },
    ],
    faq: [
      {
        question: 'Mengapa rule lama tidak terpakai?',
        answer:
          'Source type atau struktur key JSON kemungkinan berubah. Buka Raw Data, bandingkan payload, lalu buat atau perbarui rule.',
      },
      {
        question: 'Mengapa jurnal gagal dibuat?',
        answer:
          'Periksa akun, periode, nilai nominal, keseimbangan Debit/Kredit, dan permission token integrasi.',
      },
    ],
    related: ['integrations', 'create-journal', 'journal-templates'],
  },
  {
    id: 'integrations',
    category: 'automation',
    title: 'Integrasi Aplikasi dan Webhook',
    summary: 'Menghubungkan aplikasi ekosistem, membuat webhook, dan memantau pengiriman event.',
    icon: '↔',
    minutes: 9,
    audience: 'Admin dan tim integrasi',
    keywords: ['integrasi', 'webhook', 'ecopa', 'event', 'delivery log', 'url'],
    steps: [
      {
        title: 'Periksa aplikasi terhubung',
        description:
          'Buka menu Integrasi untuk melihat status aplikasi ekosistem seperti POSO, Payroll, dan aplikasi lain.',
        href: '/integrasi',
        actionLabel: 'Buka Integrasi',
      },
      {
        title: 'Pilih Tambah integrasi',
        description:
          'Gunakan Sister App via Ecopa untuk aplikasi ekosistem atau Webhook Custom untuk penerima eksternal.',
      },
      {
        title: 'Pilih event',
        description:
          'Tentukan event yang boleh diterima, misalnya journal.posted, lalu isi deskripsi.',
      },
      {
        title: 'Simpan URL dengan aman',
        description:
          'URL lengkap ditampilkan saat dibuat atau diregenerasi. Salin dan simpan pada sistem penerima.',
      },
      {
        title: 'Aktifkan dan pantau',
        description:
          'Gunakan toggle status dan buka delivery log untuk memeriksa respons, retry, atau kegagalan.',
      },
    ],
    sections: [
      {
        title: 'Keamanan URL webhook',
        bullets: [
          'Perlakukan URL sebagai kredensial.',
          'Regenerasi URL bila dicurigai bocor.',
          'Hapus subscription yang tidak lagi digunakan.',
          'Jangan menempelkan URL webhook ke catatan publik.',
        ],
        tone: 'warning',
      },
    ],
    related: ['auto-mapping', 'users-permissions', 'troubleshooting'],
  },
  {
    id: 'users-permissions',
    category: 'administration',
    title: 'Pengguna, Role, dan Permission',
    summary: 'Memberikan akses sesuai tanggung jawab tanpa membuka data yang tidak diperlukan.',
    icon: '♙',
    minutes: 9,
    audience: 'Admin',
    keywords: ['user', 'role', 'permission', 'operator', 'supervisor', 'inspector', 'tax officer'],
    steps: [
      {
        title: 'Buka Setting',
        description:
          'Pilih bagian User & Roles untuk pengguna atau Permission Management untuk rincian akses.',
        href: '/settings',
        actionLabel: 'Buka Setting',
      },
      {
        title: 'Tentukan tanggung jawab',
        description:
          'Pisahkan pembuat jurnal, reviewer, pengelola pajak, dan pembaca laporan sesuai SOP perusahaan.',
      },
      {
        title: 'Pilih role',
        description:
          'Gunakan role preset yang paling sesuai lalu tambahkan permission hanya bila diperlukan.',
      },
      {
        title: 'Uji akses',
        description:
          'Pastikan pengguna dapat membuka tugasnya, tetapi tidak dapat melakukan tindakan di luar kewenangan.',
      },
      {
        title: 'Review berkala',
        description:
          'Cabut akses pengguna yang pindah tugas atau keluar dan tinjau role secara berkala.',
      },
    ],
    sections: [
      {
        title: 'Panduan pembagian akses',
        bullets: [
          'Operator: membuat draft dan mengajukan review.',
          'Supervisor/Admin: review, posting, dan perubahan terotorisasi.',
          'Tax Officer: koreksi dan laporan Fiskal.',
          'Inspector: hanya membaca jurnal/laporan Fiskal.',
          'Owner/Manager: membaca laporan sesuai cakupan entitas.',
        ],
        tone: 'info',
      },
      {
        title: 'Prinsip akses minimum',
        paragraphs: [
          'Berikan akses paling sedikit yang masih memungkinkan pengguna menyelesaikan pekerjaannya. Jangan memakai akun bersama.',
        ],
        tone: 'warning',
      },
    ],
    related: ['journal-status', 'dual-books', 'attachments-audit'],
  },
  {
    id: 'settings-demo-data',
    category: 'administration',
    title: 'Pengaturan dan Data Demo',
    summary: 'Mengatur preferensi aplikasi serta menggunakan data simulasi dengan aman.',
    icon: '⚙',
    minutes: 7,
    audience: 'Admin',
    keywords: ['setting', 'format tanggal', 'workspace', 'fake data', 'demo', 'theme'],
    sections: [
      {
        title: 'General',
        bullets: [
          'Atur format tanggal agar tanggal pada form, pesan error, dan laporan konsisten.',
          'Pilih preferensi tampilan yang berlaku pada pengguna/workspace.',
        ],
      },
      {
        title: 'Workspace dan Entity Profile',
        bullets: [
          'Kelola identitas workspace.',
          'Lengkapi nama legal, kontak, dan profil entitas.',
          'Periksa mode pembukuan sebelum transaksi Fiskal dibuat.',
        ],
      },
      {
        title: 'Fake Data',
        paragraphs: [
          'PT. Fake Data menyediakan dataset lengkap dan berversi pada periode Demo 2026. Pada entitas biasa, import dibatasi ke COA dan akun impersonation.',
        ],
        bullets: [
          'Saat berpindah ke PT. Fake Data, periode aktif otomatis menggunakan Demo 2026.',
          'Jurnal berulang PT. Fake Data hanya contoh dan tidak dijalankan scheduler.',
          'Periode serta jurnal Tersimpan/dibalik bersifat read-only di backend dan UI.',
          'Gunakan Tinjau Reset Dataset untuk melihat data bertanda yang akan dibangun ulang dan data manual yang tetap dipertahankan.',
          'Reset memerlukan frasa konfirmasi, menolak preview yang sudah berubah, dan dicatat di audit log.',
          'Clear Fake Data hanya menghapus record yang terbukti dibuat importer fake pada entitas yang sama.',
          'Data manual pengguna tidak boleh ikut terhapus.',
        ],
        tone: 'warning',
      },
      {
        title: 'Format nomor jurnal',
        paragraphs: [
          'Atur format nomor sesuai SOP, lalu periksa preview sebelum digunakan pada transaksi baru.',
        ],
        tone: 'info',
      },
    ],
    steps: [
      {
        title: 'Buka Setting',
        description: 'Pilih bagian yang ingin diatur dari menu kiri halaman Setting.',
        href: '/settings',
        actionLabel: 'Buka Setting',
      },
    ],
    related: ['first-setup', 'users-permissions', 'troubleshooting'],
  },
  {
    id: 'troubleshooting',
    category: 'help',
    title: 'Mengatasi Masalah Umum',
    summary: 'Solusi cepat untuk error jurnal, periode, akun, laporan, lampiran, dan integrasi.',
    icon: '!',
    minutes: 10,
    audience: 'Semua pengguna',
    keywords: ['error', 'masalah', 'balance', 'periode', 'akun', 'laporan kosong', 'upload gagal'],
    sections: [
      {
        title: 'Bagian Debit / Credit belum balance',
        paragraphs: [
          'Jumlahkan kembali seluruh baris. Pastikan tidak ada nominal kosong, salah sisi, atau angka nol. Debit harus sama dengan Kredit dan keduanya lebih besar dari nol.',
        ],
        tone: 'warning',
      },
      {
        title: 'Tidak ada periode terbuka untuk tanggal tertentu',
        paragraphs: [
          'Ubah tanggal jurnal ke periode terbuka atau minta admin membuat/membuka periode yang mencakup tanggal tersebut.',
        ],
        tone: 'warning',
      },
      {
        title: 'Akun tidak dapat dipilih',
        bullets: [
          'Pastikan akun aktif dan postable.',
          'Periksa ketersediaan buku akun.',
          'Dalam mode Intern & Fiskal, hanya akun availability Keduanya yang dapat dipakai.',
          'Untuk akun Fiskal, lengkapi dasar hukum.',
        ],
      },
      {
        title: 'Laporan kosong atau tidak berubah',
        bullets: [
          'Pastikan jurnal sudah Saved, bukan Draft/In Review.',
          'Periksa filter tanggal.',
          'Periksa tab buku Intern/Fiskal.',
          'Klik Tampilkan setelah mengubah tanggal.',
          'Pastikan jurnal memakai akun dan entitas yang benar.',
        ],
      },
      {
        title: 'Lampiran gagal',
        bullets: [
          'Periksa tipe dan ukuran file.',
          'Pastikan koneksi stabil.',
          'Coba preview file lokal.',
          'Jangan berpindah mode buku saat upload belum selesai.',
        ],
      },
      {
        title: 'Auto Mapping gagal generate',
        bullets: [
          'Periksa periode terbuka.',
          'Pastikan kode akun tersedia.',
          'Pastikan field nominal numerik.',
          'Pastikan Debit/Kredit balance.',
          'Periksa permission integrasi dan idempotency key.',
        ],
      },
    ],
    faq: [
      {
        question: 'Saya sudah memperbaiki form tetapi warning masih ada.',
        answer:
          'Pindah fokus dari input agar nilai terbaru terbaca. Periksa setiap tag pada warning bar; tag akan hilang otomatis saat kondisinya valid.',
      },
      {
        question: 'Mengapa saya tidak melihat tombol tertentu?',
        answer:
          'Tombol mengikuti status jurnal dan role pengguna. Hubungi admin bila tugas Anda memang memerlukan permission tambahan.',
      },
      {
        question: 'Mengapa tanggal pada pesan berbeda format?',
        answer:
          'Periksa Setting General dan simpan format tanggal. Muat ulang halaman bila pengaturan baru belum terlihat.',
      },
    ],
    related: ['periods', 'create-journal', 'reports', 'auto-mapping'],
  },
  {
    id: 'glossary',
    category: 'help',
    title: 'Kamus Istilah Akunta',
    summary: 'Arti istilah akuntansi dan istilah aplikasi dalam bahasa sederhana.',
    icon: 'A',
    minutes: 7,
    audience: 'Pengguna awam',
    keywords: ['kamus', 'istilah', 'debit', 'kredit', 'ledger', 'coa', 'posting', 'source ref'],
    sections: [
      {
        title: 'Debit dan Kredit',
        paragraphs: [
          'Dua sisi pencatatan transaksi. Setiap jurnal harus memiliki jumlah Debit dan Kredit yang sama. Debit tidak selalu berarti uang masuk, dan Kredit tidak selalu berarti uang keluar; maknanya bergantung pada jenis akun.',
        ],
      },
      {
        title: 'Bagan Akun / Chart of Accounts (COA)',
        paragraphs: ['Daftar seluruh akun yang dipakai perusahaan untuk mengelompokkan transaksi.'],
      },
      {
        title: 'Ledger / Buku',
        paragraphs: [
          'Kumpulan pencatatan yang menghasilkan saldo. Akunta dapat memiliki ledger Intern dan Fiskal yang independen.',
        ],
      },
      {
        title: 'Posting / Saved',
        paragraphs: ['Proses mengesahkan jurnal agar memengaruhi saldo dan laporan.'],
      },
      {
        title: 'Normal balance',
        paragraphs: [
          'Sisi alami saldo akun. Aset dan Beban umumnya Debit; Liabilitas, Ekuitas, dan Pendapatan umumnya Kredit.',
        ],
      },
      {
        title: 'Postable',
        paragraphs: [
          'Akun detail yang boleh dipilih pada baris jurnal. Akun parent biasanya hanya untuk pengelompokan.',
        ],
      },
      {
        title: 'Source-ref',
        paragraphs: [
          'Identitas objek dari aplikasi sumber, misalnya pelanggan, vendor, invoice, atau payroll. Digunakan untuk Buku Pembantu dan filter Buku Besar.',
        ],
      },
      {
        title: 'Idempotency key',
        paragraphs: [
          'Kunci unik dari sistem sumber untuk mencegah transaksi yang sama diproses lebih dari sekali.',
        ],
      },
      {
        title: 'Koreksi Fiskal',
        paragraphs: [
          'Penyesuaian untuk kebutuhan pajak yang disimpan terpisah dari jurnal dan tidak mengubah Debit/Kredit kedua buku.',
        ],
      },
      {
        title: 'Audit Trail',
        paragraphs: [
          'Riwayat siapa mengubah apa dan kapan, termasuk snapshot jurnal serta perubahan lampiran.',
        ],
      },
    ],
    related: ['overview', 'create-journal', 'dual-books', 'reports'],
  },
];

export function searchableArticleText(article: DocumentationArticle): string {
  return [
    article.title,
    article.summary,
    article.audience,
    ...article.keywords,
    ...(article.outcomes ?? []),
    ...(article.prerequisites ?? []),
    ...(article.steps ?? []).flatMap((step) => [
      step.title,
      step.description,
      ...(step.bullets ?? []),
    ]),
    ...(article.sections ?? []).flatMap((section) => [
      section.title,
      ...(section.paragraphs ?? []),
      ...(section.bullets ?? []),
    ]),
    ...(article.faq ?? []).flatMap((item) => [item.question, item.answer]),
  ]
    .join(' ')
    .toLocaleLowerCase('id-ID');
}
