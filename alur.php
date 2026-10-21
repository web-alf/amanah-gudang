<?php
/**
 * AMANAH — Alur Kerja & Isi Sistem, lengkap
 *
 * Halaman publik. Satu-satunya query ke basis data adalah pengaturan merek
 * (logo/favicon) — halaman ini harus tetap terbuka meskipun basis datanya
 * masih kosong, karena justru orang yang belum punya data yang paling
 * butuh membacanya.
 *
 * CATATAN PENTING soal cara halaman ini dibuat:
 * Daftar menu, jenis perpindahan, status dokumen, pilihan batas idle, dan
 * batas perangkat TIDAK ditulis ulang di sini sebagai teks. Semuanya dibaca
 * dari sumber yang sama dengan yang dipakai aplikasi — grup_navigasi(),
 * menu_supplier(), menu_toko(), rute_sah(), pilihan_idle(), MAKS_PERANGKAT.
 * Dokumentasi yang menyalin daftar akan basi pada perubahan berikutnya, dan
 * dokumentasi yang basi lebih berbahaya daripada tidak ada dokumentasi:
 * orang mempercayainya.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';   // e() ada di sini, bukan di _lib.php
require_once __DIR__ . '/_lib.php';

$sudahMasuk  = !empty($_SESSION['user_id']);
$tujuanMasuk = $sudahMasuk ? beranda_peran() : 'login.php';

/**
 * Tag pembuka sebuah seksi, sekalian menentukan pita latarnya.
 *
 * Latar bergantian dipasang di sini, bukan dengan :nth-of-type di CSS.
 * Alasannya: halaman ini juga punya seksi TANPA id (hero dan penutup),
 * sehingga hitungan nth-of-type akan meleset satu dan polanya bergeser
 * setiap kali seksi tak ber-id ditambah atau dipindah.
 */
function seksi(string $id): string
{
    static $urut = 0;
    $pita = ($urut++ % 2 === 1) ? ' al-pita' : '';
    return '<section class="br-seksi' . $pita . '" id="' . e($id) . '">';
}
function aset_al(string $berkas): string {
    $penuh = __DIR__ . '/' . $berkas;
    return $berkas . '?v=' . (is_file($penuh) ? filemtime($penuh) : APP_VERSI);
}

/* Menu tiap peran diambil dari fungsi yang sama dengan yang membangun
   sidebar sungguhan.
   grup_navigasi() menerima peran sebagai PARAMETER dan tidak membaca sesi
   sama sekali — sudah diperiksa. Jadi halaman publik ini tidak perlu, dan
   tidak boleh, menyentuh $_SESSION untuk menampilkan menu peran lain:
   halaman yang mengubah peran pengunjungnya, walau sesaat, adalah cacat
   yang sulit dilacak justru karena tampak berhasil. */
function menu_peran(string $role): array {
    $keluar = [];
    $sec = null;
    foreach (grup_navigasi($role) as $g) {
        if (($g['sidebar'] ?? true) === false) { continue; }
        if (($g['sec'] ?? null) !== $sec) { $sec = $g['sec'] ?? null; $keluar[] = ['sec' => $sec]; }
        $keluar[] = ['t' => $g['t'], 'anggota' => array_column($g['anggota'] ?? [], 't')];
    }
    return $keluar;
}

$MENU = [
    'super_admin'  => ['Super Admin',  'i-kunci',  menu_peran('super_admin')],
    'admin_gudang' => ['Admin Gudang', 'i-gudang', menu_peran('admin_gudang')],
    'pimpinan'     => ['Pimpinan',     'i-grafik', menu_peran('pimpinan')],
];

// Supplier & Toko memakai menunya sendiri, bukan grup_navigasi().
function menu_datar(array $m): array {
    $k = [];
    foreach ($m as $x) {
        $k[] = isset($x['sec']) ? ['sec' => $x['sec']] : ['t' => $x['t'], 'anggota' => []];
    }
    return $k;
}
$MENU['supplier'] = ['Supplier', 'i-truck', menu_datar(menu_supplier())];
$MENU['toko']     = ['Toko',     'i-toko',  menu_datar(menu_toko())];

/* --------------------------------------------------- rantai beranimasi */
$RANTAI = [
    ['i-truck',  'Supplier',        'Mencatat barang & menerbitkan surat jalan',   'var(--blue)'],
    ['i-gudang', 'Gudang',          'Menghitung fisik, menyimpan per rak & batch', 'var(--accent)'],
    ['i-toko',   'Toko',            'Menerima kiriman, menjual dari stoknya',      'var(--green)'],
    ['i-users',  'Penerima Amanah', 'Ujung penyaluran, bukan penjualan',           'var(--amber)'],
];

/* ------------------------------------------ BAGIAN 1: persiapan awal */
$TAHAP = [
    ['Buat akun & tentukan perannya', 'Super Admin', 'Pengaturan › Pengguna',
     'Setiap orang yang memakai sistem diberi satu akun dengan satu peran. Akun '
   . 'Admin Gudang WAJIB ditautkan ke satu gudang, dan akun Supplier ke satu '
   . 'supplier — tautan itulah yang menentukan data apa yang ia lihat. Batas '
   . 'waktu tidak-aktif juga disetel per akun di sini.',
     'Akun Admin Gudang tanpa gudang akan melihat SEMUA daftarnya kosong. '
   . 'Halamannya benar, tapi terlihat seperti rusak.'],

    ['Isi kategori produk', 'Super Admin', 'Master Data › Kategori',
     'Kategori diisi paling awal karena setiap produk wajib memilikinya. Cukup '
   . 'pengelompokan yang benar-benar Anda pakai saat melihat laporan — bukan '
   . 'daftar sedetail mungkin.',
     'Formulir produk menolak disimpan: kategori wajib dipilih, dan belum ada '
   . 'yang bisa dipilih.'],

    ['Daftarkan gudang, lalu raknya', 'Super Admin', 'Master Data › Gudang, lalu Rak',
     'Gudang lebih dulu, rak menyusul — rak menunjuk ke gudang, bukan sebaliknya. '
   . 'Stok tersimpan PER RAK, jadi rak bukan pelengkap. Ada tiga jenis gudang: '
   . 'pusat (dibuat di sini), toko (dibuat dari Dasbor Toko), dan supplier '
   . '(terbentuk otomatis satu per supplier).',
     'Gudang tanpa rak menolak semua penerimaan barang: tidak ada lokasi yang '
   . 'bisa dituju, sehingga barang tidak akan pernah masuk.'],

    ['Daftarkan supplier, toko, dan penerima amanah', 'Super Admin',
     'Master Data › Supplier / Toko / Penerima Amanah',
     'Ketiganya simpul di luar gudang: dari mana barang datang, ke mana dijual, '
   . 'ke mana disalurkan. Akun login portal supplier dan dasbor toko dibuat '
   . 'terpisah lewat Pengaturan › Akun Supplier / Akun Toko.',
     'Surat jalan tidak bisa dibuat: asal dan tujuannya belum ada dalam daftar, '
   . 'dan sistem tidak menerima nama yang diketik bebas untuk sesuatu yang '
   . 'seharusnya terdaftar.'],

    ['Masukkan daftar produk', 'Super Admin atau Admin Gudang',
     'Master Data › Produk — atau Pengaturan › Impor CSV',
     'Produk merujuk kategori (wajib) serta supplier dan rak (opsional). Untuk '
   . 'ratusan baris pakai Impor CSV: contoh berkasnya bisa diunduh, dan setiap '
   . 'baris diperiksa di pratinjau sebelum benar-benar disimpan.',
     'Tidak ada yang bisa diterima, dipindahkan, atau dijual — seluruh transaksi '
   . 'menunjuk ke produk.'],

    ['Masukkan stok awal', 'Admin Gudang', 'Transaksi › Barang Masuk',
     'Stok TIDAK diisi lewat formulir produk — ia hanya berubah lewat transaksi, '
   . 'supaya setiap perubahan punya dokumen asalnya. Stok awal dicatat sebagai '
   . 'barang masuk, lengkap dengan nomor batch, kondisi (baru/bekas), dan '
   . 'tanggal kedaluwarsa bila ada.',
     'Angka stok tetap nol walau barangnya ada di rak, dan laporan kedaluwarsa '
   . 'tidak punya apa pun untuk diperingatkan.'],

    ['Coba satu siklus penuh sebelum diserahkan', 'Super Admin bersama petugas',
     'Supplier → Penerimaan → Surat Jalan → Toko',
     'Jalankan satu barang melewati seluruh rantai: supplier menerbitkan surat '
   . 'jalan, gudang memindai resinya dan menghitung fisiknya, lalu barangnya '
   . 'didistribusikan ke toko. Pakai jumlah kecil, dan SENGAJA catat selisih '
   . 'hitung — di situlah cara kerja sistem paling terlihat.',
     'Kesalahan pengaturan baru ditemukan saat operasional berjalan, ketika '
   . 'memperbaikinya sudah berarti membetulkan data yang salah.'],
];

/* ------------------------------------------- BAGIAN 2: alur harian */
$HARIAN = [
    ['Supplier mencatat barang & menerbitkan surat jalan', 'Supplier',
     'Dasbor Supplier › Barang Masuk, lalu Barang Keluar',
     'Supplier mencatat barang yang siap kirim di gudangnya sendiri, memilih baris '
   . 'yang akan dikirim, lalu sistem menerbitkan surat jalan beserta kode resi '
   . 'untuk dipindai penerima.'],

    ['QR surat jalan dipindai saat barang tiba', 'Semua peran', 'Transaksi › Cek Surat Jalan',
     'Setiap surat jalan tercetak membawa QR berisi nomornya. Dipindai di gudang '
   . 'atau di toko, daftar barang kiriman itu langsung terbuka — jadi yang dihitung '
   . 'petugas adalah daftar yang sama dengan yang dikirim pengirimnya. Tanpa '
   . 'internet pun tetap bisa: nomornya tercetak di lembar yang sama untuk diketik.'],

    ['Resi paket dipindai saat tiba', 'Toko / Super Admin', 'Transaksi › Scan Resi Paket',
     'Resi dipindai dengan kamera HP, scanner USB, atau diketik, untuk menemukan '
   . 'dokumen kirimannya. Ini langkah PENCARIAN — ia tidak mengubah stok. '
   . 'Setiap pemindaian dicatat di jejak audit.'],

    ['Barang dihitung fisik saat dibongkar', 'Admin Gudang atau Toko',
     'Transaksi › Penerimaan Barang',
     'Yang masuk ke stok adalah HASIL HITUNG FISIK, bukan angka di surat jalan. '
   . 'Selisihnya dicatat beserta keterangannya, sehingga bisa ditanyakan kembali '
   . 'ke pengirim — bukan diam-diam dibulatkan.'],

    ['Barang disimpan per rak dan per batch', 'Admin Gudang',
     'Master Data › Gudang › Rak',
     'Setiap batch tahu raknya, kondisinya (baru/bekas), dan tanggal '
   . 'kedaluwarsanya. Dari sini muncul peringatan berjenjang dan urutan keluar '
   . 'yang kedaluwarsa lebih dulu.'],

    ['Dokumen keluar menunggu persetujuan', 'Diajukan petugas, disetujui Pimpinan / Super Admin',
     'Transaksi › Persetujuan',
     'Petugas menyusun dokumen lalu mengirimkannya untuk persetujuan. Yang '
   . 'menyetujui bukan yang melaksanakan. Penolakan WAJIB menyertakan alasan, '
   . 'supaya pembuatnya tahu apa yang harus diperbaiki.'],

    ['Barang berpindah ke toko atau penerima amanah', 'Toko menerima sendiri',
     'Dasbor Toko › Barang Masuk',
     'Stok berpindah ketika pihak penerima mengonfirmasi hitung fisiknya — BUKAN '
   . 'saat dokumennya disetujui. Selama di jalan, barang tidak dihitung ada di '
   . 'kedua tempat sekaligus.'],

    ['Toko menjual, atau barang disalurkan', 'Toko',
     'Dasbor Toko › Barang Keluar / Impor Pesanan',
     'Penjualan dicatat manual atau diimpor dari berkas pesanan marketplace. '
   . 'Barang yang disalurkan ke Penerima Amanah keluar dari rantai lewat '
   . 'dokumen penyaluran, bukan penjualan.'],

    ['Setiap peran melihat laporan stoknya sendiri', 'Semua peran',
     'Laporan › Laporan Stok, Batch & Expired',
     'Supplier melihat isi gudangnya, gudang melihat raknya, toko melihat tokonya '
   . 'beserta gudang penampungnya. Pengawas melihat seluruh rantai. Angka yang '
   . 'sama tidak pernah diceritakan dua kali dengan cara berbeda.'],
];

/* ----------------------------------------- wewenang per peran
   Diperiksa terhadap perilaku sebenarnya di kode, bukan diniatkan. */
$WEWENANG = [
    ['Melihat dasbor & laporan',            ['ya','lingkup','ya','lingkup','lingkup']],
    ['Master data (produk, kategori, dll)', ['ya','sebagian','baca','tidak','tidak']],
    ['Barang masuk & keluar gudang',        ['ya','ya','tidak','tidak','tidak']],
    ['Membuat surat jalan / perpindahan',   ['ya','ya','tidak','ya','rak saja']],
    ['Mengajukan dokumen untuk persetujuan',['ya','ya','tidak','ya','ya']],
    ['MENYETUJUI / menolak dokumen',        ['ya','tidak','ya','tidak','tidak']],
    ['Konfirmasi penerimaan barang',        ['ya','ya','tidak','tidak','ya']],
    ['Pindah antar rak',                    ['ya','ya','tidak','ya','ya']],
    ['Kelola akun & peran',                 ['ya','tidak','tidak','tidak','tidak']],
    ['Ubah logo & identitas tampilan',      ['ya','tidak','tidak','tidak','tidak']],
    ['Impor CSV master data',               ['ya','ya','tidak','tidak','tidak']],
    ['Lihat & lepas perangkatnya sendiri',  ['ya','ya','ya','ya','ya']],
];
$KOL_PERAN = ['Super Admin', 'Admin Gudang', 'Pimpinan', 'Supplier', 'Toko'];

/* ----------------------------------------------- seluruh halaman */
$HALAMAN = [
    ['Dasbor & pemantauan', [
        ['dashboard.php',       'Ringkasan',        'KPI, sebaran stok per lokasi, antrean tugas, langkah pengaturan yang belum selesai'],
        ['pengawasan.php',      'Mitra & Lokasi',   'Daftar supplier, gudang, toko, penerima beserta ringkasan stoknya'],
        ['dasbor_supplier.php', 'Dasbor Supplier',  'Barang masuk, siap kirim, pindah antar rak, riwayat surat jalan'],
        ['dasbor_toko.php',     'Dasbor Toko',      'Ringkasan, penjualan, stok toko, gudang toko, distribusi ke tiap toko'],
    ]],
    ['Transaksi', [
        ['masuk.php',            'Barang Masuk',       'Pencatatan barang masuk gaya kasir, dengan batch & kedaluwarsa'],
        ['keluar.php',           'Barang Keluar',      'Pengeluaran barang ke penerima, hanya barang yang masih berstok'],
        ['perpindahan.php',      'Surat Jalan',        'Daftar seluruh dokumen perpindahan, bisa disaring per jenis & status'],
        ['perpindahan_form.php', 'Buat Perpindahan',   'Formulir dokumen: jenis, asal, tujuan, rincian barang'],
        ['surat_jalan.php',      'Cetak Surat Jalan',  'Versi cetak dokumen beserta kode resinya'],
        ['penerimaan.php',       'Penerimaan Barang',  'Hitung fisik saat bongkar; selisih dicatat, bukan dibulatkan'],
        ['kiriman_supplier.php', 'Kiriman Supplier',   'Kiriman yang sedang di jalan dari supplier'],
        ['persetujuan.php',      'Persetujuan',        'Ajukan, setujui, atau tolak dokumen (penolakan wajib beralasan)'],
        ['monitoring.php',       'Batch & Expired',    'Peringatan stok dan kedaluwarsa berjenjang, sesuai lingkup peran'],
        ['scan_resi.php',        'Scan Resi Paket',    'Cari dokumen kiriman dari nomor resi: kamera, scanner USB, atau ketik'],
        ['cek_surat.php',        'Cek Surat Jalan',    'Pindai QR di lembar surat jalan; daftar barang kirimannya langsung tampil'],
        ['impor_pesanan.php',    'Impor Pesanan',      'Impor pesanan marketplace ke penjualan toko'],
    ]],
    ['Master data', [
        ['barang.php',      'Produk',           'Daftar produk beserta stok, ambang minimum, dan kedaluwarsa terdekat'],
        ['barang_form.php', 'Tambah/Edit Produk','Halaman penuh untuk menyunting; tambah produk kini lewat popup di daftar. Termasuk kolom barcode yang bisa dipindai kamera HP atau scanner USB'],
        ['barang_hapus.php','Hapus Produk',     'Penghapusan lunak, menahan bila masih ada stok atau riwayat'],
        ['cari_barang.php', 'Cari Produk',      'Pencarian cepat untuk formulir transaksi'],
        ['kategori.php',    'Kategori',         'Pengelompokan produk; wajib ada sebelum produk bisa dibuat'],
        ['supplier.php',    'Supplier',         'Data supplier, dan pembuatan akun portalnya'],
        ['gudang.php',      'Gudang',           'Gudang pusat; jenis toko & supplier tidak dibuat dari sini'],
        ['rak.php',         'Rak',              'Lokasi penyimpanan di dalam gudang — tempat stok sebenarnya berada'],
        ['toko.php',        'Toko / Outlet',    'Data toko & marketplace-nya, dan pembuatan akun dasbornya'],
        ['penerima.php',    'Penerima Amanah',  'Ujung penyaluran: barang keluar dari rantai, bukan dijual'],
    ]],
    ['Laporan', [
        ['laporan.php',        'Laporan Stok',  'Stok per kategori & per lokasi, nilai persediaan, status kedaluwarsa'],
        ['laporan_export.php', 'Export CSV',    'Unduhan laporan stok; lingkup peran ikut tertulis di dalam berkasnya'],
    ]],
    ['Pengaturan & akun', [
        ['pengaturan.php',        'Pengaturan',        'Pintu masuk seluruh pengaturan, dikelompokkan per kebutuhan'],
        ['pengguna.php',          'Pengguna',          'Akun, peran, tautan gudang/supplier, dan batas waktu tidak-aktif'],
        ['pengaturan_merek.php',  'Logo & Identitas',  'Logo utama, favicon, gambar share, judul & teks share'],
        ['impor_data.php',        'Impor CSV',         'Enam entitas master data, dengan pratinjau sebelum simpan'],
        ['perangkat.php',         'Perangkat',         'Sesi aktif dan pelepasannya; batas ' . MAKS_PERANGKAT . ' perangkat per akun'],
        ['profil.php',            'Profil Saya',       'Data diri, foto, dan ringkasan aktivitas akun'],
        ['ganti_password.php',    'Ganti Password',    'Perubahan password akun sendiri'],
        ['login.php / logout.php','Masuk & Keluar',    'Keluar membebaskan slot perangkat seketika'],
    ]],
    ['Halaman publik (tanpa login)', [
        ['index.php', 'Beranda',      'Ringkasan sistem, rantai, fitur, peran, dan keamanan'],
        ['alur.php',  'Alur Kerja',   'Halaman ini — panduan lengkap dari nol sampai berjalan'],
    ]],
];

/* --------------------------------- entitas yang bisa diimpor lewat CSV */
$IMPOR = [
    ['Kategori', 'nama', 'keterangan'],
    ['Gudang',   'kode, nama', 'jenis, alamat, penanggung jawab, telepon, aktif'],
    ['Rak',      'kode, nama, gudang', 'kapasitas, keterangan'],
    ['Supplier', 'kode, nama', 'kontak, telepon, email, alamat, keterangan'],
    ['Toko',     'kode, nama', 'marketplace, URL, penanggung jawab, telepon'],
    ['Produk',   'kode, nama, kategori', 'barcode, merek, ukuran, satuan, harga, stok minimum, supplier, rak'],
];

/* ------------------------------------------- tabel yang belum dipakai
   Disebut apa adanya. Skema yang ada tapi tak berfungsi adalah jebakan
   bagi siapa pun yang nanti melanjutkan sistem ini: ia akan menyangka
   fiturnya ada, lalu mencari halaman yang tidak pernah dibuat. */
$BELUM = [
    ['stock_opname, stock_opname_detail', 'Perhitungan ulang stok fisik menyeluruh — skemanya siap, halamannya belum ada.'],
    ['mutasi_barang',                     'Dimaksudkan sebagai buku besar pergerakan stok; tidak ditulisi maupun dibaca.'],
    ['notifikasi',                        'Hanya disebut dalam komentar. Lencana di topbar dihitung langsung dari dokumen, bukan dari tabel ini.'],
    ['retur',                             'Retur ke supplier berjalan sebagai dokumen perpindahan berjenis retur, jadi tabel ini tak terpakai.'],
    ['marketplace_akun',                  'Tidak ada penyambungan API marketplace; pesanan masuk lewat Impor Pesanan.'],
];

/* --------------------------------------------------- aturan yang dijaga */
$ATURAN = [
    ['i-cek-bulat', 'Yang menyetujui bukan yang melaksanakan',
     'Petugas mengajukan, Pimpinan atau Super Admin menyetujui. Satu orang tidak '
   . 'bisa mengeluarkan barang atas keputusannya sendiri.'],
    ['i-layers', 'Stok hanya berubah lewat transaksi',
     'Tidak ada kolom "ubah stok". Setiap angka punya dokumen asalnya, jadi selisih '
   . 'selalu bisa dilacak ke satu kejadian.'],
    ['i-lokasi', 'Setiap peran hanya melihat lingkupnya',
     'Petugas gudang melihat raknya, toko melihat tokonya, supplier melihat gudangnya. '
   . 'Pembatasannya diperiksa ULANG di server, bukan hanya disembunyikan dari menu.'],
    ['i-kunci', 'Maksimal ' . MAKS_PERANGKAT . ' perangkat per akun',
     'Akun yang beredar tanpa batas tidak lagi menunjukkan siapa yang bekerja. '
   . 'Daftar perangkat bisa dilihat dan dilepas sendiri di Pengaturan › Perangkat.'],
    ['i-doc', 'Setiap tindakan meninggalkan jejak',
     'Pembuatan, perubahan, persetujuan, penolakan, penerimaan, dan pemindaian resi '
   . 'dicatat beserta pelakunya dan waktunya.'],
    ['i-scan', 'Angka fisik mengalahkan angka dokumen',
     'Saat penerimaan, yang masuk stok adalah hasil hitung fisik. Selisihnya '
   . 'disimpan sebagai catatan, bukan dihapus dengan pembulatan.'],
];

/* -------------------------------------------------- daftar isi
   Lima belas seksi dikelompokkan menjadi empat bagian. Pengelompokan ini
   bukan hiasan: daftar lima belas butir dibaca sebagai daftar panjang yang
   harus dipindai satu-satu, sedangkan empat kelompok bisa dipilih sekali
   lihat. Nomor urutnya tetap berlanjut 1..15 supaya orang bisa saling
   menunjuk bagian dengan angka. */
$BAGIAN = [
    'Memahami alurnya' => [
        'rantai'  => ['Arah barang',            'Ke mana barang bergerak, dan di mana ia dihitung'],
        'nol'     => ['Dari nol sampai jalan',  'Tujuh tahap persiapan, sekali kerja'],
        'harian'  => ['Alur harian',            'Delapan langkah yang berulang setiap hari'],
    ],
    'Siapa dan di mana' => [
        'peran'   => ['Peran & wewenang',       'Lima peran, dua belas batas wewenang'],
        'menu'    => ['Menu tiap peran',        'Menu yang benar-benar dilihat masing-masing'],
        'halaman' => ['Semua halaman',          'Setiap halaman dan tugasnya'],
    ],
    'Cara kerja rinci' => [
        'dokumen' => ['Jenis & status dokumen', 'Delapan jenis, tujuh status, rute yang sah'],
        'batch'   => ['Batch & kedaluwarsa',    'Lima tingkat peringatan, kondisi barang'],
        'scan'    => ['Pemindaian',             'Kamera, scanner USB, atau ketik'],
        'impor'   => ['Impor CSV',              'Enam entitas master data'],
    ],
    'Pengelolaan sistem' => [
        'setelan' => ['Pengaturan',             'Seluruh setelan dan siapa yang boleh'],
        'aman'    => ['Keamanan & sesi',        'Batas perangkat, lingkup, jejak audit'],
        'data'    => ['Struktur data',          'Di mana setiap data disimpan'],
        'belum'   => ['Belum dipakai',          'Skema yang ada tapi belum berjalan'],
        'aturan'  => ['Aturan sistem',          'Enam aturan dan alasannya'],
    ],
];

/* Peta rata untuk rel titik dan penomoran seksi. */
$PETA = [];
foreach ($BAGIAN as $isiBagian) {
    foreach ($isiBagian as $id => [$nama, ]) { $PETA[$id] = $nama; }
}
$NO_SEKSI = array_flip(array_keys($PETA));   // id => indeks nol
?><!DOCTYPE html>
<html lang="id" data-theme="<?= (($_COOKIE['amanah_theme'] ?? 'dark') === 'light' ? 'light' : 'dark') ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="dark light">
<meta name="theme-color" content="#070B17">
<title><?= e(ident('Alur Kerja & Isi Sistem — ' . APP_NAMA, APP_KEPANJANGAN)) ?></title>
<?php require __DIR__ . '/_merek.php'; ?>
<link rel="stylesheet" href="<?= e(aset_al('assets/amanah.css')) ?>">
</head>
<body>
<?php require __DIR__ . '/_ikon.php'; ?>

<header class="br-nav">
  <div class="br isi">
    <a class="merek" href="index.php" style="text-decoration:none;color:inherit">
      <?php if ($MEREK['logo']): ?>
        <span class="logo logo-gambar"><img src="<?= e($MEREK['logo']) ?>" alt="<?= e(APP_NAMA) ?>"></span>
      <?php else: ?>
        <span class="logo" aria-hidden="true"><svg><use href="#i-box"></use></svg></span>
      <?php endif; ?>
      <div><b><?= APP_NAMA ?></b><span>Alur Kerja &amp; Isi Sistem</span></div>
    </a>
    <nav class="tautan">
      <a href="index.php" class="sembunyi-hp">Beranda</a>
      <a class="btn btn-primary btn-sm" href="<?= e($tujuanMasuk) ?>">
        <?= $sudahMasuk ? 'Ke Dasbor' : 'Masuk' ?>
      </a>
    </nav>
  </div>
</header>

<?php /* Rel titik penanda posisi. Tanpa teks sama sekali — namanya muncul
         sebagai tooltip saat disentuh. Hanya tampil di layar lebar, tempat
         ruang tepinya memang kosong; di layar sempit ia akan melayang di
         atas bacaan, dan itu lebih buruk daripada tidak ada.
         Jarak antar BAGIAN dibuat lebih lebar, sehingga kelompoknya terbaca
         dari bentuknya saja. */ ?>
<nav class="al-rel" id="rel" aria-label="Posisi bagian">
  <?php $ke = 0; foreach ($BAGIAN as $isiBagian): ?>
    <?php if ($ke++ > 0): ?><span class="pisah" aria-hidden="true"></span><?php endif; ?>
    <?php foreach ($isiBagian as $id => [$nama, ]): ?>
      <a href="#<?= e($id) ?>" data-nama="<?= e(($NO_SEKSI[$id] + 1) . ". " . $nama) ?>"
         aria-label="<?= e($nama) ?>"></a>
    <?php endforeach; ?>
  <?php endforeach; ?>
</nav>

<button class="al-atas" id="keAtas" type="button" aria-label="Kembali ke atas">
  <svg aria-hidden="true"><use href="#i-panah-atas"></use></svg>
</button>

<section class="br-seksi">
  <div class="br">
    <div class="al-masuk">
      <div class="al-hero">
        <div class="al-hero-teks">
          <div class="al-merek-baris">
            <span>Track</span><i aria-hidden="true"></i>
            <span>Manage</span><i aria-hidden="true"></i>
            <span>Grow</span>
          </div>
          <h1>Seluruh isi sistem,<br>dari nol sampai jalan</h1>
          <p>Dua hal yang sering tertukar dan menjadi sumber hampir semua
            kebingungan: <b>persiapan</b> dikerjakan sekali, <b>alur harian</b>
            berulang selamanya. Halaman ini memisahkan keduanya, lalu melanjutkan
            ke setiap peran, setiap halaman, setiap jenis dokumen, dan setiap
            pengaturan — termasuk bagian yang <b>belum</b> dipakai.</p>

          <?php /* Kata-katanya mengikuti materi merek AMANAH sendiri, supaya
                   halaman ini terbaca sebagai bagian dari mereknya, bukan
                   dokumen teknis yang ditempelkan. */ ?>
          <div class="al-nilai">
            <span style="--sc:var(--blue)"><svg aria-hidden="true"><use href="#i-grafik-bar"></use></svg> Lebih Produktif</span>
            <span style="--sc:var(--accent)"><svg aria-hidden="true"><use href="#i-layers"></use></svg> Lebih Teratur</span>
            <span style="--sc:var(--green)"><svg aria-hidden="true"><use href="#i-grafik"></use></svg> Lebih Bertumbuh</span>
          </div>

          <div class="al-catat">
            <b>Isi halaman ini dibaca langsung dari kode.</b> Daftar menu, jenis
            dokumen, pilihan batas waktu, dan batas perangkat tidak ditulis ulang
            sebagai teks — semuanya diambil dari fungsi yang sama dengan yang
            membangun aplikasinya. Jadi kalau sistemnya berubah, halaman ini ikut
            berubah, dan tidak bisa berbohong tanpa sengaja.
          </div>
        </div>

        <?php /* Panel kanan. Bila poster/materi promosi sudah diunggah lewat
                 Pengaturan > Logo & Identitas, itulah yang tampil. Bila belum,
                 panel merek terisi sendiri — bukan kotak kosong menunggu
                 unggahan, karena halaman ini harus sudah layak dilihat sejak
                 hari pertama. */ ?>
        <?php $gbrHero = url_unggahan(pengaturan($pdo, 'gambar_hero')); ?>
        <div class="al-panel al-masuk">
          <?php if ($gbrHero): ?>
            <img class="al-panel-gambar" src="<?= e($gbrHero) ?>"
                 alt="<?= e(APP_NAMA) ?> — <?= e(APP_KEPANJANGAN) ?>" loading="lazy">
          <?php else: ?>
            <div class="al-panel-logo">
              <?php if ($MEREK['logo']): ?>
                <img src="<?= e($MEREK['logo']) ?>" alt="<?= e(APP_NAMA) ?>">
              <?php else: ?>
                <svg aria-hidden="true"><use href="#i-box"></use></svg>
              <?php endif; ?>
            </div>
            <h2><?= APP_NAMA ?></h2>
            <div class="kata">Track · Manage · Grow</div>

            <?php /* Angkanya dihitung dari isi halaman ini, bukan angka
                     pemasaran. Kalau seksi atau menu ditambah, angka di sini
                     ikut berubah — jadi ia tidak bisa jadi klaim kosong. */ ?>
            <div class="al-panel-angka">
              <div><b><?= count($PETA) ?></b><small>bagian panduan</small></div>
              <div><b><?= count($MENU) ?></b><small>peran pengguna</small></div>
              <div><b><?= count(rute_sah()) ?></b><small>jenis dokumen</small></div>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <?php /* Daftar isi ada DI DALAM halaman, dibaca sekali di awal seperti
             daftar isi buku — bukan menempel di layar sepanjang waktu.
             Navigasi yang menempel hanya sepadan bila dipakai berulang;
             daftar isi dipakai sekali, lalu jadi gangguan. */ ?>
    <div class="al-daftar al-masuk">
      <div class="al-daftar-grid">
        <?php foreach ($BAGIAN as $judulBagian => $isiBagian): ?>
          <div class="al-bagian">
            <h3><?= e($judulBagian) ?></h3>
            <ol>
              <?php foreach ($isiBagian as $id => [$nama, $ket]): ?>
                <li>
                  <a href="#<?= e($id) ?>">
                    <span class="n"><?= str_pad((string) ($NO_SEKSI[$id] + 1), 2, "0", STR_PAD_LEFT) ?></span>
                    <span><?= e($nama) ?>
                      <br><small style="color:var(--muted);font-size:11.5px"><?= e($ket) ?></small>
                    </span>
                  </a>
                </li>
              <?php endforeach; ?>
            </ol>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</section>

<?php /* ================================================ ARAH BARANG */ ?>
<?= seksi('rantai') ?>
  <div class="br">
    <div class="br-kepala al-masuk">
      <?php $bagianIni = ''; foreach ($BAGIAN as $jb => $ib) { if (isset($ib['rantai'])) { $bagianIni = $jb; break; } } ?>
      <span class="al-nomor"><?= str_pad((string) ($NO_SEKSI['rantai'] + 1), 2, '0', STR_PAD_LEFT) ?> · <?= e($bagianIni) ?></span>
      <h2>Arah barang, sekali lihat</h2>
      <p>Barang selalu bergerak satu arah melewati simpul yang menghitungnya.
        Perhatikan paketnya <b>berhenti</b> di setiap simpul — di situlah ia
        dihitung dan dicatat, bukan sekadar lewat.</p>
    </div>

    <div class="al-rantai" id="rantaiAnim" data-jalan="ya">
      <div class="al-garis" aria-hidden="true"></div>
      <div class="al-paket" id="paketAnim" aria-hidden="true">
        <svg><use href="#i-box"></use></svg>
      </div>
      <div class="al-jalur">
        <?php foreach ($RANTAI as $i => [$ik, $nm, $ket, $wr]): ?>
          <div class="al-pos" data-simpul="<?= $i ?>" style="--sc:<?= $wr ?>">
            <div class="ic"><svg aria-hidden="true"><use href="#<?= $ik ?>"></use></svg></div>
            <div><b><?= e($nm) ?></b><small><?= e($ket) ?></small></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="al-kendali">
      <button class="btn btn-ghost btn-sm" id="tombolJalan" type="button"
              aria-pressed="true">Jeda animasi</button>
      <span class="ket">Animasi berhenti sendiri bila peramban Anda disetel
        mengurangi gerakan.</span>
    </div>
  </div>
</section>

<?php /* ==================================================== DARI NOL */ ?>
<?= seksi('nol') ?>
  <div class="br">
    <div class="br-kepala al-masuk">
      <span class="br-lencana"><?= str_pad((string) ($NO_SEKSI['nol'] + 1), 2, '0', STR_PAD_LEFT) ?> · Bagian 1 — sekali jalan</span>
      <h2 style="margin-top:14px">Dari nol sampai bisa dipakai</h2>
      <p>Tujuh tahap, dan <b>urutannya tidak bisa ditukar</b>: tiap tahap memakai
        hasil tahap sebelumnya. Kotak kuning menyebut kegagalan yang benar-benar
        muncul bila tahap itu dilompati.</p>
    </div>

    <div class="al-tahap">
      <?php foreach ($TAHAP as $i => [$judul, $siapa, $di, $isi, $jika]): ?>
        <div class="al-kartu al-masuk">
          <div class="al-no"><?= $i + 1 ?></div>
          <div class="al-isi">
            <h3><?= e($judul) ?></h3>
            <p><?= e($isi) ?></p>
            <div class="al-jika"><b>Kalau dilewati:</b> <?= e($jika) ?></div>
            <div class="al-meta">
              <span class="tag t-purple"><?= e($siapa) ?></span>
              <span class="tag t-gray"><?= e($di) ?></span>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<?php /* ================================================ ALUR HARIAN */ ?>
<?= seksi('harian') ?>
  <div class="br">
    <div class="br-kepala al-masuk">
      <span class="br-lencana"><?= str_pad((string) ($NO_SEKSI['harian'] + 1), 2, '0', STR_PAD_LEFT) ?> · Bagian 2 — setiap hari</span>
      <h2 style="margin-top:14px">Alur harian setelah sistemnya jalan</h2>
      <p>Delapan langkah yang berulang. Dua paling sering disalahpahami:
        <b>langkah 3</b> (yang masuk stok adalah hasil hitung fisik, bukan angka
        surat jalan) dan <b>langkah 6</b> (stok berpindah saat diterima, bukan
        saat disetujui).</p>
    </div>

    <div class="al-tahap">
      <?php foreach ($HARIAN as $i => [$judul, $siapa, $di, $isi]): ?>
        <div class="al-kartu al-masuk">
          <div class="al-no"><?= $i + 1 ?></div>
          <div class="al-isi">
            <h3><?= e($judul) ?></h3>
            <p><?= e($isi) ?></p>
            <div class="al-meta">
              <span class="tag t-purple"><?= e($siapa) ?></span>
              <span class="tag t-gray"><?= e($di) ?></span>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<?php /* ============================================ PERAN & WEWENANG */ ?>
<?= seksi('peran') ?>
  <div class="br">
    <div class="br-kepala al-masuk">
      <?php $bagianIni = ''; foreach ($BAGIAN as $jb => $ib) { if (isset($ib['peran'])) { $bagianIni = $jb; break; } } ?>
      <span class="al-nomor"><?= str_pad((string) ($NO_SEKSI['peran'] + 1), 2, '0', STR_PAD_LEFT) ?> · <?= e($bagianIni) ?></span>
      <h2>Lima peran dan batas wewenangnya</h2>
      <p><b>lingkup</b> berarti hanya data yang terhubung dengan akun itu.
        <b>sebagian</b> berarti ada bagian yang dibatasi. Batas ini diperiksa di
        server, jadi mengubah URL atau isi formulir tidak menembusnya.</p>
    </div>
    <div class="al-tabel al-masuk">
      <table>
        <thead><tr><th style="min-width:210px">Wewenang</th>
          <?php foreach ($KOL_PERAN as $p): ?><th><?= e($p) ?></th><?php endforeach; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($WEWENANG as [$nama, $nilai]): ?>
          <tr><td><b><?= e($nama) ?></b></td>
            <?php foreach ($nilai as $v): ?>
              <td class="<?= $v === 'ya' ? 'ya' : ($v === 'tidak' ? 'tidak' : 'separuh') ?>">
                <?= $v === 'ya' ? '✓' : ($v === 'tidak' ? '—' : e($v)) ?>
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<?php /* ============================================== MENU TIAP PERAN */ ?>
<?= seksi('menu') ?>
  <div class="br">
    <div class="br-kepala al-masuk">
      <?php $bagianIni = ''; foreach ($BAGIAN as $jb => $ib) { if (isset($ib['menu'])) { $bagianIni = $jb; break; } } ?>
      <span class="al-nomor"><?= str_pad((string) ($NO_SEKSI['menu'] + 1), 2, '0', STR_PAD_LEFT) ?> · <?= e($bagianIni) ?></span>
      <h2>Menu yang dilihat tiap peran</h2>
      <p>Diambil langsung dari fungsi yang membangun sidebar sungguhan — jadi ini
        bukan salinan, melainkan menu yang sama. Sebuah peran tidak pernah
        melihat menu yang justru akan menolaknya.</p>
    </div>
    <div class="al-menu al-masuk">
      <?php foreach ($MENU as [$label, $ikon, $daftar]): ?>
        <div class="al-menu-kartu">
          <h3><svg style="width:16px;height:16px;stroke:var(--accent);fill:none;stroke-width:2"
                   aria-hidden="true"><use href="#<?= e($ikon) ?>"></use></svg>
            <?= e($label) ?></h3>
          <div class="jml"><?= count(array_filter($daftar, function ($x) { return !isset($x['sec']); })) ?> menu</div>
          <ul>
            <?php foreach ($daftar as $d): ?>
              <?php if (isset($d['sec'])): ?>
                <li class="grup"><?= e($d['sec']) ?></li>
              <?php else: ?>
                <li class="baris">
                  <svg aria-hidden="true"><use href="#i-panah-kanan"></use></svg>
                  <span><?= e($d['t']) ?>
                    <?php if (!empty($d['anggota'])): ?>
                      <br><small style="color:var(--muted)"><?= e(implode(' · ', $d['anggota'])) ?></small>
                    <?php endif; ?>
                  </span>
                </li>
              <?php endif; ?>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="al-catat al-masuk">
      <b>Pengaturan tidak muncul di sidebar</b> untuk peran mana pun. Jalan
      masuknya lewat menu pengguna di kanan atas — sidebar memetakan pekerjaan
      harian, dan mengelola akun bukan pekerjaan harian.
    </div>
  </div>
</section>

<?php /* ============================================= SEMUA HALAMAN */ ?>
<?= seksi('halaman') ?>
  <div class="br">
    <div class="br-kepala al-masuk">
      <?php $bagianIni = ''; foreach ($BAGIAN as $jb => $ib) { if (isset($ib['halaman'])) { $bagianIni = $jb; break; } } ?>
      <span class="al-nomor"><?= str_pad((string) ($NO_SEKSI['halaman'] + 1), 2, '0', STR_PAD_LEFT) ?> · <?= e($bagianIni) ?></span>
      <h2>Setiap halaman dan tugasnya</h2>
      <p>Seluruh halaman yang bisa dibuka, dikelompokkan menurut kegunaannya.
        Berkas berawalan garis bawah adalah potongan tampilan bersama, dan
        peramban ditolak mengaksesnya langsung.</p>
    </div>
    <?php foreach ($HALAMAN as [$grup, $baris]): ?>
      <div class="al-masuk" style="margin-top:22px">
        <h3 style="font-size:15px;margin:0 0 2px"><?= e($grup) ?></h3>
        <div class="al-tabel" style="margin-top:10px">
          <table>
            <thead><tr><th style="min-width:150px">Berkas</th>
              <th style="min-width:130px">Nama</th><th>Isinya</th></tr></thead>
            <tbody>
            <?php foreach ($baris as [$berkas, $nama, $ket]): ?>
              <tr><td><code><?= e($berkas) ?></code></td>
                <td><b><?= e($nama) ?></b></td>
                <td><?= e($ket) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<?php /* ======================================= JENIS & STATUS DOKUMEN */ ?>
<?= seksi('dokumen') ?>
  <div class="br">
    <div class="br-kepala al-masuk">
      <?php $bagianIni = ''; foreach ($BAGIAN as $jb => $ib) { if (isset($ib['dokumen'])) { $bagianIni = $jb; break; } } ?>
      <span class="al-nomor"><?= str_pad((string) ($NO_SEKSI['dokumen'] + 1), 2, '0', STR_PAD_LEFT) ?> · <?= e($bagianIni) ?></span>
      <h2>Delapan jenis dokumen perpindahan</h2>
      <p>Setiap jenis punya <b>rute yang sah</b>: kombinasi asal dan tujuan yang
        diizinkan. Kombinasi di luar daftar ini ditolak sebelum dokumennya
        tersimpan — itulah yang mencegah barang "berpindah" ke tempat yang tidak
        masuk akal.</p>
    </div>
    <div class="al-tabel al-masuk">
      <table>
        <thead><tr><th>Jenis</th><th>Nama</th><th>Asal boleh</th><th>Tujuan boleh</th></tr></thead>
        <tbody>
        <?php foreach (rute_sah() as $kunci => $r): [$lbl] = label_jenis($kunci); ?>
          <tr><td><code><?= e($kunci) ?></code></td>
            <td><b><?= e($lbl) ?></b></td>
            <td><?= e(implode(', ', $r['asal'])) ?></td>
            <td><?= e(implode(', ', $r['tujuan'])) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="br-kepala al-masuk" style="margin-top:34px">
      <h2>Tujuh status, satu arah</h2>
      <p>Dokumen bergerak maju melewati status. Yang menentukan kapan stok
        benar-benar berpindah adalah apakah jenis dokumen itu punya pihak
        penerima yang memverifikasi fisiknya.</p>
    </div>
    <div class="al-tabel al-masuk">
      <table>
        <thead><tr><th style="min-width:170px">Status</th><th>Artinya</th></tr></thead>
        <tbody>
          <tr><td><code>draft</code></td><td>Masih disusun petugas; belum dilihat siapa pun.</td></tr>
          <tr><td><code>menunggu_persetujuan</code></td><td>Sudah diajukan; menunggu Pimpinan atau Super Admin.</td></tr>
          <tr><td><code>ditolak</code></td><td>Dikembalikan beserta <b>alasan wajib</b>, supaya bisa diperbaiki.</td></tr>
          <tr><td><code>disetujui</code></td><td>Disetujui. Untuk jenis tanpa pihak penerima, stok berpindah di titik ini.</td></tr>
          <tr><td><code>dikirim</code></td><td>Surat jalan terbit, barang di jalan. Stok <b>belum</b> berpindah.</td></tr>
          <tr><td><code>diterima</code></td><td>Penerima sudah menghitung fisiknya; stok berpindah sesuai hasil hitung.</td></tr>
          <tr><td><code>selesai</code></td><td>Tuntas dan terkunci sebagai catatan.</td></tr>
        </tbody>
      </table>
    </div>
    <div class="al-catat al-masuk">
      <b>Kenapa stok tidak berpindah saat disetujui.</b> Kalau berpindah di titik
      itu, barang akan tercatat sudah sampai padahal masih di jalan — dan bila
      hitungan penerima berbeda, tidak ada lagi tempat untuk mencatat
      selisihnya. Dengan menunda sampai penerimaan, barang tidak pernah
      terhitung ada di dua tempat sekaligus.
    </div>
  </div>
</section>

<?php /* ======================================= BATCH & KEDALUWARSA */ ?>
<?= seksi('batch') ?>
  <div class="br">
    <div class="br-kepala al-masuk">
      <?php $bagianIni = ''; foreach ($BAGIAN as $jb => $ib) { if (isset($ib['batch'])) { $bagianIni = $jb; break; } } ?>
      <span class="al-nomor"><?= str_pad((string) ($NO_SEKSI['batch'] + 1), 2, '0', STR_PAD_LEFT) ?> · <?= e($bagianIni) ?></span>
      <h2>Batch, kondisi, dan kedaluwarsa</h2>
      <p>Stok tidak disimpan sebagai satu angka per produk. Ia disimpan per
        <b>batch</b>: satu kelompok barang dengan nomor, lokasi, kondisi, dan
        tanggal kedaluwarsa yang sama.</p>
    </div>
    <div class="al-tabel al-masuk">
      <table>
        <thead><tr><th style="min-width:150px">Status</th><th>Ambang</th><th>Artinya</th></tr></thead>
        <tbody>
          <tr><td><span class="tag t-green">Aman</span></td><td>lebih dari 90 hari</td>
            <td>Belum perlu tindakan.</td></tr>
          <tr><td><span class="tag t-amber">Waspada</span></td><td>90 hari atau kurang</td>
            <td>Mulai diprioritaskan keluar lebih dulu.</td></tr>
          <tr><td><span class="tag t-orange">Segera expired</span></td><td>30 hari atau kurang</td>
            <td>Harus dikeluarkan, ditarik, atau disalurkan sekarang.</td></tr>
          <tr><td><span class="tag t-red">Expired</span></td><td>lewat tanggal</td>
            <td>Tidak boleh disalurkan; perlu keputusan pemusnahan.</td></tr>
          <tr><td><span class="tag t-gray">Tanpa expired</span></td><td>tidak berlaku</td>
            <td>Barang yang memang tak punya masa kedaluwarsa.</td></tr>
        </tbody>
      </table>
    </div>
    <div class="al-catat al-masuk">
      <b>Kondisi barang: baru atau bekas.</b> Keduanya disimpan sebagai batch
      terpisah walau produknya sama, karena barang donasi dan barang pembelian
      tidak boleh tercampur dalam satu angka.
      <br><br>
      <b>Peringatan stok memakai stok di lingkup Anda</b>, bukan total lembaga.
      Total lembaga akan membuat petugas gudang melihat "aman" padahal raknya
      sendiri kosong.
    </div>
  </div>
</section>

<?php /* =============================================== PEMINDAIAN */ ?>
<?= seksi('scan') ?>
  <div class="br">
    <div class="br-kepala al-masuk">
      <?php $bagianIni = ''; foreach ($BAGIAN as $jb => $ib) { if (isset($ib['scan'])) { $bagianIni = $jb; break; } } ?>
      <span class="al-nomor"><?= str_pad((string) ($NO_SEKSI['scan'] + 1), 2, '0', STR_PAD_LEFT) ?> · <?= e($bagianIni) ?></span>
      <h2>Pemindaian: tiga cara masuk, dua tempat pakai</h2>
      <p>Mengetik ulang 13 angka barcode sambil memegang barangnya adalah
        pekerjaan yang paling mudah salah di seluruh sistem. Karena itu setiap
        kolom yang biasanya diisi dari fisik barang bisa diisi dengan memindai.</p>
    </div>
    <div class="al-tabel al-masuk">
      <table>
        <thead><tr><th style="min-width:150px">Cara</th><th>Butuh apa</th><th>Catatan</th></tr></thead>
        <tbody>
          <tr><td><b>Kamera HP / laptop</b></td><td>Peramban modern, dan alamat aman (HTTPS atau localhost)</td>
            <td>Memakai pembaca barcode bawaan peramban; tanpa aplikasi tambahan.</td></tr>
          <tr><td><b>Scanner USB</b></td><td>Scanner barcode biasa</td>
            <td>Terdeteksi otomatis — ia bekerja seperti keyboard yang mengetik sangat cepat.</td></tr>
          <tr><td><b>Ketik manual</b></td><td>—</td>
            <td>Selalu tersedia sebagai cadangan, tidak pernah dimatikan.</td></tr>
        </tbody>
      </table>
    </div>
    <div class="al-catat al-masuk">
      <b>Dipakai di tiga tempat.</b> Pertama, kolom <b>Barcode</b> pada formulir
      produk — hasil pindaian langsung masuk ke kolomnya.
      Kedua, <b>Scan Resi Paket</b> — nomor resi dicocokkan ke kiriman supplier
      dan dokumen perpindahan, lalu setiap pemindaian dicatat untuk jejak audit.
      Ketiga, <b>Cek Surat Jalan</b> — QR pada lembar surat jalan dibaca, dan yang
      terbuka adalah daftar barang di dalam kiriman itu. QR-nya dibuat di server
      tanpa pustaka luar dan tanpa layanan daring, jadi surat jalan tetap tercetak
      lengkap saat gudang sedang tanpa internet — dan nomor dokumen kita tidak
      pernah dikirim ke server pihak ketiga.
      <br><br>
      Saat scanner USB dipakai, tombol Enter yang dikirimkannya <b>tidak</b>
      mengirim formulir; fokus berpindah ke kolom berikutnya. Tanpa itu, satu
      pindaian akan menyimpan formulir yang baru terisi separuh.
    </div>
  </div>
</section>

<?php /* ================================================= IMPOR CSV */ ?>
<?= seksi('impor') ?>
  <div class="br">
    <div class="br-kepala al-masuk">
      <?php $bagianIni = ''; foreach ($BAGIAN as $jb => $ib) { if (isset($ib['impor'])) { $bagianIni = $jb; break; } } ?>
      <span class="al-nomor"><?= str_pad((string) ($NO_SEKSI['impor'] + 1), 2, '0', STR_PAD_LEFT) ?> · <?= e($bagianIni) ?></span>
      <h2>Impor CSV: enam entitas</h2>
      <p>Untuk mengisi ratusan baris master data. Setiap berkas diperiksa dan
        ditampilkan sebagai <b>pratinjau</b> lebih dulu — baris bermasalah
        ditunjukkan sebelum apa pun tersimpan, bukan sesudahnya.</p>
    </div>
    <div class="al-tabel al-masuk">
      <table>
        <thead><tr><th>Entitas</th><th>Kolom wajib</th><th>Kolom pilihan</th></tr></thead>
        <tbody>
        <?php foreach ($IMPOR as [$nama, $wajib, $opsi]): ?>
          <tr><td><b><?= e($nama) ?></b></td><td><?= e($wajib) ?></td><td><?= e($opsi) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="al-catat al-masuk">
      <b>Nama kolom tidak harus persis.</b> Sistem mengenali beberapa penamaan
      lain untuk kolom yang sama — misalnya <code>pj</code> untuk penanggung
      jawab, <code>hp</code> untuk telepon. Contoh berkas untuk setiap entitas
      bisa diunduh dari halaman impor.
      <br><br>
      <b>Stok tidak bisa diimpor.</b> Ia hanya berubah lewat transaksi, supaya
      setiap angka punya dokumen asalnya. Stok awal dicatat lewat Barang Masuk.
      <br><br>
      Pesanan marketplace diimpor terpisah lewat <b>Impor Pesanan</b> di dasbor
      toko, bukan dari halaman ini.
    </div>
  </div>
</section>

<?php /* ================================================ PENGATURAN */ ?>
<?= seksi('setelan') ?>
  <div class="br">
    <div class="br-kepala al-masuk">
      <?php $bagianIni = ''; foreach ($BAGIAN as $jb => $ib) { if (isset($ib['setelan'])) { $bagianIni = $jb; break; } } ?>
      <span class="al-nomor"><?= str_pad((string) ($NO_SEKSI['setelan'] + 1), 2, '0', STR_PAD_LEFT) ?> · <?= e($bagianIni) ?></span>
      <h2>Seluruh pengaturan</h2>
      <p>Jalan masuknya dari menu pengguna di kanan atas, bukan dari sidebar.
        Sebagian hanya untuk Super Admin.</p>
    </div>
    <div class="al-tabel al-masuk">
      <table>
        <thead><tr><th style="min-width:160px">Pengaturan</th><th>Siapa</th><th>Isinya</th></tr></thead>
        <tbody>
          <tr><td><b>Pengguna</b></td><td>Super Admin</td>
            <td>Tambah/ubah akun, peran, tautan ke gudang atau supplier, dan
              batas waktu tidak-aktif per akun.</td></tr>
          <tr><td><b>Akun Supplier</b></td><td>Super Admin</td>
            <td>Membuat login portal untuk supplier yang sudah terdaftar.</td></tr>
          <tr><td><b>Akun Toko</b></td><td>Super Admin</td>
            <td>Membuat login dasbor untuk toko; satu akun bisa memegang beberapa toko.</td></tr>
          <tr><td><b>Logo &amp; Identitas</b></td><td>Super Admin</td>
            <td>Logo utama, favicon, gambar saat tautan dibagikan, serta judul &amp;
              teks share. Menerima PNG, JPG, WebP sampai
              <?= round(MAKS_UNGGAHAN / 1048576, 1) ?> MB.</td></tr>
          <tr><td><b>Impor CSV</b></td><td>Super Admin, Admin Gudang</td>
            <td>Enam entitas master data, dengan pratinjau sebelum simpan.</td></tr>
          <tr><td><b>Perangkat</b></td><td>Semua peran</td>
            <td>Daftar sesi aktif dan pelepasannya. Batas
              <?= MAKS_PERANGKAT ?> perangkat per akun.</td></tr>
          <tr><td><b>Ganti Password</b></td><td>Semua peran</td>
            <td>Mengubah password akun sendiri.</td></tr>
          <tr><td><b>Profil Saya</b></td><td>Semua peran</td>
            <td>Data diri, foto, dan ringkasan aktivitas akun.</td></tr>
          <tr><td><b>Tema terang / gelap</b></td><td>Semua peran</td>
            <td>Tombol di kanan atas. Gelap adalah bawaan; pilihannya diingat
              per peramban.</td></tr>
          <tr><td><b>Sidebar lebar / rel ikon</b></td><td>Semua peran</td>
            <td>Diingat per peramban, dan dibaca di server supaya halaman
              langsung tergambar pada lebar yang benar.</td></tr>
        </tbody>
      </table>
    </div>

    <div class="br-kepala al-masuk" style="margin-top:34px">
      <h3 style="font-size:17px">Pilihan batas waktu tidak-aktif</h3>
      <p>Disetel per akun. Sesi berakhir bila tidak ada aktivitas selama waktu
        ini. Petugas lapangan yang memakai HP sepanjang hari biasanya butuh
        batas panjang; akun dengan wewenang besar sebaiknya pendek.</p>
    </div>
    <div class="al-tabel al-masuk">
      <table>
        <thead><tr><th>Batas</th><th>Dalam menit</th></tr></thead>
        <tbody>
        <?php foreach (pilihan_idle() as $menit => $label): ?>
          <tr><td><b><?= e($label) ?></b></td><td><code><?= (int) $menit ?></code></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>

<?php /* ============================================ KEAMANAN & SESI */ ?>
<?= seksi('aman') ?>
  <div class="br">
    <div class="br-kepala al-masuk">
      <?php $bagianIni = ''; foreach ($BAGIAN as $jb => $ib) { if (isset($ib['aman'])) { $bagianIni = $jb; break; } } ?>
      <span class="al-nomor"><?= str_pad((string) ($NO_SEKSI['aman'] + 1), 2, '0', STR_PAD_LEFT) ?> · <?= e($bagianIni) ?></span>
      <h2>Keamanan &amp; sesi</h2>
      <p>Bukan daftar fitur, melainkan daftar cara data bisa menjadi tidak bisa
        dipercaya — beserta apa yang menutupnya.</p>
    </div>
    <div class="al-tabel al-masuk">
      <table>
        <thead><tr><th style="min-width:190px">Yang dijaga</th><th>Caranya</th></tr></thead>
        <tbody>
          <tr><td><b>Batas <?= MAKS_PERANGKAT ?> perangkat per akun</b></td>
            <td>Login keempat ditolak selama tiga sesi sebelumnya masih hidup.
              Keluar lewat tombol Keluar membebaskan slotnya seketika — itulah cara
              berpindah perangkat tanpa meminta bantuan Super Admin.</td></tr>
          <tr><td><b>Sesi berakhir sendiri</b></td>
            <td>Sesuai batas tidak-aktif akun itu. Perubahan batasnya berlaku
              hampir seketika, tidak menunggu login ulang.</td></tr>
          <tr><td><b>Pesan sesi berakhir menyebut sebabnya</b></td>
            <td>Dibedakan tiga: Anda sendiri dari perangkat lain, tindakan Super
              Admin, atau perubahan langsung di basis data. Ketiganya butuh
              penanganan berbeda, jadi pesannya tidak menebak.</td></tr>
          <tr><td><b>Penugasan dibaca dari basis data</b></td>
            <td>Bukan dari sesi. Kalau penugasan gudang <b>dicabut</b>, aksesnya
              tertutup saat itu juga — tidak menunggu orangnya logout.</td></tr>
          <tr><td><b>Setiap formulir bertanda</b></td>
            <td>Token per sesi. Formulir tanpa token yang sah ditolak, sehingga
              situs lain tidak bisa mengirim perintah atas nama Anda.</td></tr>
          <tr><td><b>Lingkup diperiksa di server</b></td>
            <td>Menyaring daftar hanya merapikan tampilan. Setiap penyimpanan
              memeriksa ulang apakah gudang, rak, atau toko yang disebut memang
              milik akun itu.</td></tr>
          <tr><td><b>Unggahan gambar diperiksa isinya</b></td>
            <td>Jenis berkas ditentukan dari isi berkasnya, bukan dari ekstensi
              atau keterangan pengunggah. Nama berkasnya diganti acak, dan folder
              unggahan menolak menjalankan berkas apa pun. SVG tidak diterima
              karena ia bisa memuat skrip.</td></tr>
          <tr><td><b>Password disimpan sebagai hash</b></td>
            <td>Tidak pernah dalam bentuk yang bisa dibaca, termasuk oleh Super Admin.</td></tr>
          <tr><td><b>Jejak aktivitas</b></td>
            <td>Pembuatan, perubahan, persetujuan, penolakan, penerimaan,
              pemindaian resi, ekspor, dan keluar — semuanya tercatat beserta
              pelaku dan waktunya.</td></tr>
          <tr><td><b>Penghapusan bersifat lunak</b></td>
            <td>Data yang dihapus ditandai, bukan dibuang, sehingga dokumen lama
              tidak kehilangan rujukannya.</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</section>

<?php /* ============================================ STRUKTUR DATA */ ?>
<?= seksi('data') ?>
  <div class="br">
    <div class="br-kepala al-masuk">
      <?php $bagianIni = ''; foreach ($BAGIAN as $jb => $ib) { if (isset($ib['data'])) { $bagianIni = $jb; break; } } ?>
      <span class="al-nomor"><?= str_pad((string) ($NO_SEKSI['data'] + 1), 2, '0', STR_PAD_LEFT) ?> · <?= e($bagianIni) ?></span>
      <h2>Tempat data disimpan</h2>
      <p>Bagian ini untuk yang mengurus sistemnya, bukan yang memakainya
        sehari-hari. Yang paling perlu diketahui: <b>stok hidup di dua tempat</b>,
        dan itulah sebabnya laporan menggabungkan keduanya.</p>
    </div>
    <div class="al-tabel al-masuk">
      <table>
        <thead><tr><th style="min-width:180px">Kelompok</th><th>Isinya</th></tr></thead>
        <tbody>
          <tr><td><b>Stok gudang &amp; toko</b></td>
            <td><code>batch_barang</code> — per batch, menunjuk ke rak
              <i>atau</i> ke toko. <code>stok</code> menyimpan total per produk
              untuk pembacaan cepat.</td></tr>
          <tr><td><b>Stok supplier</b></td>
            <td><code>stok_supplier</code> — terpisah, karena alur supplier
              (catat masuk → siap kirim → kirim) berbeda. Sejak rak supplier
              ada, barisnya juga menunjuk ke rak.</td></tr>
          <tr><td><b>Dokumen perpindahan</b></td>
            <td><code>perpindahan</code> + <code>perpindahan_detail</code> —
              satu dokumen, banyak baris barang.</td></tr>
          <tr><td><b>Master data</b></td>
            <td><code>barang</code>, <code>kategori</code>, <code>gudang</code>,
              <code>lokasi_rak</code>, <code>supplier</code>, <code>toko</code>,
              <code>penerima</code></td></tr>
          <tr><td><b>Transaksi gudang</b></td>
            <td><code>transaksi_masuk</code>, <code>transaksi_keluar</code>,
              beserta tabel rinciannya masing-masing.</td></tr>
          <tr><td><b>Sisi toko</b></td>
            <td><code>penjualan</code>, <code>penjualan_detail</code>,
              <code>user_toko</code>, <code>penyesuaian_stok</code>,
              <code>marketplace_sku</code></td></tr>
          <tr><td><b>Sisi supplier</b></td>
            <td><code>pengiriman_supplier</code> beserta rinciannya,
              dan <code>resi_scan_log</code></td></tr>
          <tr><td><b>Akun &amp; keamanan</b></td>
            <td><code>users</code>, <code>roles</code>,
              <code>sesi_perangkat</code>, <code>activity_log</code></td></tr>
          <tr><td><b>Pengaturan &amp; penomoran</b></td>
            <td><code>pengaturan</code> (logo, favicon, share),
              <code>dokumen_counter</code> (nomor dokumen &amp; surat jalan)</td></tr>
        </tbody>
      </table>
    </div>
    <div class="al-catat al-masuk">
      <b>View gabungan.</b> Karena stok hidup di dua tabel, laporan tidak
      membacanya langsung. Ia membaca view yang menggabungkan keduanya, supaya
      dua halaman yang bersebelahan di menu tidak pernah memberi angka berbeda
      untuk barang yang sama.
    </div>
  </div>
</section>

<?php /* ============================================= BELUM DIPAKAI */ ?>
<?= seksi('belum') ?>
  <div class="br">
    <div class="br-kepala al-masuk">
      <?php $bagianIni = ''; foreach ($BAGIAN as $jb => $ib) { if (isset($ib['belum'])) { $bagianIni = $jb; break; } } ?>
      <span class="al-nomor"><?= str_pad((string) ($NO_SEKSI['belum'] + 1), 2, '0', STR_PAD_LEFT) ?> · <?= e($bagianIni) ?></span>
      <h2>Yang ada di struktur data tapi belum berjalan</h2>
      <p>Disebut apa adanya. Skema yang ada tanpa halamannya adalah jebakan bagi
        siapa pun yang melanjutkan sistem ini nanti: ia akan menyangka fiturnya
        ada, lalu mencari halaman yang tidak pernah dibuat.</p>
    </div>
    <div class="al-tabel al-masuk">
      <table>
        <thead><tr><th style="min-width:200px">Tabel</th><th>Keadaannya</th></tr></thead>
        <tbody>
        <?php foreach ($BELUM as [$tabel, $ket]): ?>
          <tr><td><code><?= e($tabel) ?></code></td><td><?= e($ket) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="al-catat al-masuk">
      Selain itu, <b>pengurangan stok pada Barang Keluar masih memakai total
      lembaga</b>, bukan stok per gudang. Tampilannya sudah dibatasi per gudang,
      tetapi angka yang dipotong belum. Ini diketahui dan belum diubah karena
      menyentuh cara stok dihitung.
    </div>
  </div>
</section>

<?php /* ============================================ ATURAN SISTEM */ ?>
<?= seksi('aturan') ?>
  <div class="br">
    <div class="br-kepala al-masuk">
      <?php $bagianIni = ''; foreach ($BAGIAN as $jb => $ib) { if (isset($ib['aturan'])) { $bagianIni = $jb; break; } } ?>
      <span class="al-nomor"><?= str_pad((string) ($NO_SEKSI['aturan'] + 1), 2, '0', STR_PAD_LEFT) ?> · <?= e($bagianIni) ?></span>
      <h2>Enam aturan yang dijaga sistem</h2>
      <p>Bukan pembatasan demi kerumitan — masing-masing menutup satu cara data
        menjadi tidak bisa dipercaya. Orang yang tahu alasan sebuah aturan jauh
        lebih jarang mencari jalan memutarnya.</p>
    </div>
    <div class="br-grid al-masuk">
      <?php foreach ($ATURAN as [$ik, $jd, $ket]): ?>
        <div class="br-kartu">
          <div class="ic"><svg aria-hidden="true"><use href="#<?= $ik ?>"></use></svg></div>
          <b><?= e($jd) ?></b>
          <p><?= e($ket) ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="br-seksi">
  <div class="br">
    <div class="br-tutup al-masuk">
      <h2>Siap mulai?</h2>
      <p>Tahap 1 sampai 4 dikerjakan Super Admin dan biasanya selesai dalam satu
        kali duduk. Sisanya mengikuti sendiri.</p>
      <a class="btn btn-primary al-denyut" href="<?= e($tujuanMasuk) ?>">
        <?= $sudahMasuk ? 'Buka Dasbor' : 'Masuk ke Sistem' ?>
      </a>
      <a class="btn btn-ghost" href="index.php">Lihat daftar fitur</a>
    </div>
  </div>
</section>

<footer class="br-footer">
  <div class="br">
    <?= e(ident(APP_NAMA . ' v' . APP_VERSI, APP_KEPANJANGAN, APP_LEMBAGA)) ?><br>
    <a href="index.php">Beranda</a> ·
    <a href="<?= e($tujuanMasuk) ?>"><?= $sudahMasuk ? 'Dasbor' : 'Masuk' ?></a>
  </div>
</footer>

<script>
/* Semua di bawah ini bersifat PENAMBAH, bukan penentu: halaman sudah terbaca
   utuh tanpa JavaScript. Yang hilang hanya animasi dan penanda bagian. */
(function () {
  'use strict';

  var diamkan = window.matchMedia &&
                window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---------------------------------------------- muncul saat di-scroll
     Isinya terlihat secara bawaan (lihat komentar di amanah.css). Keadaan
     tersembunyi baru DIPASANG di sini, dan hanya setelah yakin bisa dibuka
     kembali. Jadi kegagalan apa pun di bawah menghasilkan "tanpa animasi",
     bukan "halaman kosong". */
  var blok = [].slice.call(document.querySelectorAll('.al-masuk'));
  var bisaAmati = !diamkan && ('IntersectionObserver' in window) && blok.length > 0;

  if (bisaAmati) {
    document.documentElement.classList.add('al-siap');

    var pengamat = new IntersectionObserver(function (baris) {
      baris.forEach(function (r) {
        if (!r.isIntersecting) { return; }
        r.target.classList.add('tampak');
        pengamat.unobserve(r.target);
      });
    }, { rootMargin: '0px 0px -12% 0px', threshold: 0.08 });

    blok.forEach(function (b) { pengamat.observe(b); });

    /* Jaring pengaman waktu. IntersectionObserver menjalankan callback-nya
       setelah frame pertama, dan di sebagian lingkungan frame itu bisa tidak
       pernah datang — sehingga isinya tinggal tersembunyi selamanya. */
    window.setTimeout(function () {
      blok.forEach(function (b) { b.classList.add('tampak'); });
    }, 2000);
  }

  /* --------------------------------------------- penanda posisi di rel titik
     Rel-nya sudah berguna tanpa JavaScript: titiknya tetap tautan yang bisa
     diklik. Yang ditambahkan di sini hanya penanda titik yang sedang dibaca. */
  var rel = document.getElementById('rel');
  if (rel && 'IntersectionObserver' in window) {
    var titik = {};
    [].slice.call(rel.querySelectorAll('a')).forEach(function (a) {
      titik[a.getAttribute('href').slice(1)] = a;
    });

    var pengamatSeksi = new IntersectionObserver(function (baris) {
      baris.forEach(function (r) {
        var a = titik[r.target.id];
        if (!a || !r.isIntersecting) { return; }
        for (var k in titik) { if (titik[k] !== a) { titik[k].classList.remove('kini'); } }
        a.classList.add('kini');
      });
      /* Jendela sempit di sepertiga atas layar: seksi dianggap "sedang
         dibaca" saat kepalanya berada di situ, bukan saat ia sekadar
         tersentuh tepi bawah layar. Tanpa penyempitan ini, dua seksi bisa
         aktif sekaligus dan penandanya berkedip. */
    }, { rootMargin: '-12% 0px -76% 0px' });

    Object.keys(titik).forEach(function (id) {
      var s = document.getElementById(id);
      if (s) { pengamatSeksi.observe(s); }
    });
  }

  /* ----------------------------------------------------- kembali ke atas
     Halaman ini tingginya sekitar 25 ribu piksel. Menggulir kembali ke atas
     dengan tangan adalah pekerjaan yang tidak perlu ada. */
  var keAtas = document.getElementById('keAtas');
  if (keAtas) {
    var pantau = function () {
      keAtas.classList.toggle('tampil', window.scrollY > 700);
    };
    // passive: true — pendengar scroll yang tidak memanggil preventDefault
    // tidak boleh menahan pengguliran di layar sentuh.
    window.addEventListener('scroll', pantau, { passive: true });
    pantau();

    keAtas.addEventListener('click', function () {
      window.scrollTo({ top: 0, behavior: diamkan ? 'auto' : 'smooth' });
    });
  }

  /* ------------------------------------------- sorotan simpul + kendali */
  var rantai = document.getElementById('rantaiAnim');
  var tombol = document.getElementById('tombolJalan');
  var simpul = rantai ? [].slice.call(rantai.querySelectorAll('.al-pos')) : [];

  if (!rantai || !tombol) { return; }

  if (diamkan) {
    // Tidak ada yang bisa dijeda, jadi tombolnya dihilangkan alih-alih
    // dibiarkan sebagai kontrol yang tidak melakukan apa pun.
    tombol.parentNode.removeChild(tombol);
    simpul.forEach(function (s) { s.classList.add('aktif'); });
    return;
  }

  /* Sorotan simpul memakai jadwal yang MENIRU keyframe CSS. Keduanya harus
     dijaga sejalan: 9 detik, empat perhentian pada 10%, 26%, 52%, 78%. */
  var DURASI = 9000;
  var JADWAL = [0.10, 0.26, 0.52, 0.78];
  var mulai = null, rafId = null;

  function langkah(waktu) {
    if (mulai === null) { mulai = waktu; }
    var lewat = ((waktu - mulai) % DURASI) / DURASI;

    var aktif = -1;
    for (var i = 0; i < JADWAL.length; i++) {
      if (lewat >= JADWAL[i] - 0.02 && lewat < JADWAL[i] + 0.10) { aktif = i; }
    }
    simpul.forEach(function (s, i) { s.classList.toggle('aktif', i === aktif); });
    rafId = window.requestAnimationFrame(langkah);
  }

  function jalan(ya) {
    rantai.setAttribute('data-jalan', ya ? 'ya' : 'tidak');
    tombol.setAttribute('aria-pressed', ya ? 'true' : 'false');
    tombol.textContent = ya ? 'Jeda animasi' : 'Jalankan animasi';

    if (ya) {
      if (rafId === null) { mulai = null; rafId = window.requestAnimationFrame(langkah); }
    } else if (rafId !== null) {
      window.cancelAnimationFrame(rafId);
      rafId = null;
    }
  }

  tombol.addEventListener('click', function () {
    jalan(rantai.getAttribute('data-jalan') !== 'ya');
  });

  /* Animasi dihentikan saat tabnya tersembunyi. requestAnimationFrame berhenti
     sendiri, tapi animasi CSS tidak — dan itu memutar terus di latar,
     memakan baterai tanpa ada yang melihat. */
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) {
      if (rafId !== null) { window.cancelAnimationFrame(rafId); rafId = null; }
    } else if (rantai.getAttribute('data-jalan') === 'ya' && rafId === null) {
      mulai = null;
      rafId = window.requestAnimationFrame(langkah);
    }
  });

  jalan(true);
})();
</script>
</body>
</html>
