<?php
/**
 * AMANAH — Beranda
 *
 * Halaman depan sebelum masuk: menjelaskan apa yang dikerjakan sistem ini
 * dan apa saja yang ada di dalamnya.
 *
 * Dua hal yang dijaga di berkas ini:
 *
 *   1. TIDAK ADA DATA. Halaman ini terbuka untuk siapa pun yang bisa
 *      menjangkau alamatnya, jadi tidak ada satu pun query ke basis data.
 *      Jumlah stok, nama mitra, dan nama penerima bantuan tidak boleh
 *      bocor lewat halaman depan — dan cara paling aman memastikannya
 *      adalah dengan tidak mengambilnya sama sekali.
 *
 *   2. TIDAK ADA KLAIM PALSU. Setiap fitur yang disebut di bawah benar
 *      ada dan berjalan. Halaman depan yang menjanjikan lebih dari isinya
 *      hanya memindahkan kekecewaan ke hari pertama pemakaian.
 *
 * Yang sudah masuk diarahkan ke dasbornya, bukan disuruh melihat halaman
 * perkenalan lagi — tapi tetap boleh membukanya lewat tautan.
 */
require __DIR__ . '/config.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/_lib.php';

$sudahMasuk = !empty($_SESSION['user_id']);
$tujuanMasuk = $sudahMasuk ? beranda_peran() : 'login.php';
/* Tombol unduh aplikasi Android — hanya bila APK-nya memang ada di server
   atau alamat unduh khusus sudah diisi (lihat aplikasi.php). */
$adaApk   = is_file(__DIR__ . '/unduhan/Amanah.apk') || trim(pengaturan($pdo, 'apk_url', '')) !== '';
$versiApk = pengaturan($pdo, 'apk_versi_nama', '');
/* Seksi "Aplikasi Android" di halaman depan: tautan unduh LANGSUNG ke APK,
   kode QR untuk dipindai ponsel, dan catatan rilisnya. Sumber datanya sama
   dengan api/versi_aplikasi.php dan halaman Pengaturan › Pembaruan Aplikasi. */
require_once __DIR__ . '/_qr.php';
$berkasApk  = __DIR__ . '/unduhan/Amanah.apk';
$urlApkK    = trim(pengaturan($pdo, 'apk_url', ''));
$urlApk     = $urlApkK !== '' ? $urlApkK
            : (is_file($berkasApk) ? url_penuh('unduhan/Amanah.apk') . '?v=' . filemtime($berkasApk) : '');
$ukuranApk  = ($urlApkK === '' && is_file($berkasApk)) ? number_format(filesize($berkasApk) / 1048576, 1, ',', '.') . ' MB' : '';
$catatanApk = pengaturan($pdo, 'apk_catatan', '');
$svgQrApk   = $urlApk !== '' ? qr_svg($urlApk, 0, '#111') : null;

// Tema mengikuti pilihan yang tersimpan, sama seperti di dalam aplikasi.
$tema = ($_COOKIE['amanah_theme'] ?? 'light') === 'dark' ? 'dark' : 'light';   // terang adalah bawaan

/** Penanda versi berkas — supaya perbaikan CSS tidak tertahan cache. */
function aset_br(string $berkas): string {
    $penuh = __DIR__ . '/' . $berkas;
    return $berkas . '?v=' . (is_file($penuh) ? filemtime($penuh) : APP_VERSI);
}

/* ---------------------------------------------------------------------
   Isi halaman disusun sebagai data, bukan HTML berulang — menambah satu
   fitur berarti menambah satu baris, bukan menyalin satu blok.
   --------------------------------------------------------------------- */

$RANTAI = [
    ['i-truck',  'Supplier',        'Mitra pemasok mencatat kiriman & nomor resi',  'var(--accent)'],
    ['i-gudang', 'Gudang',          'Barang diterima, dihitung fisik, masuk rak',   'var(--primary-2)'],
    ['i-toko',   'Gudang Toko',     'Penampung sebelum dipecah ke tiap toko',       'var(--info)'],
];
$UJUNG = [
    ['i-toko',  'Toko / Outlet',    'Dijual lewat marketplace atau gerai',          'var(--green)'],
    ['i-users', 'Penerima',         'Disalurkan kepada yang berhak',                'var(--amber)'],
];

$FITUR = [
    ['i-masuk', 'var(--green)', 'Barang Masuk & Keluar',
     'Layar bergaya kasir: tembak barcode berulang, semuanya masuk keranjang, lalu satu kali simpan.',
     ['Scanner USB & kamera HP', 'Nomor batch & tanggal kedaluwarsa', 'Barang baru dan bekas dibedakan']],

    ['i-cal', 'var(--amber)', 'FEFO & Batch',
     'Barang yang paling dekat kedaluwarsa keluar lebih dulu — dipilih sistem, bukan diserahkan pada '
     . 'siapa yang paling mudah dijangkau tangan.',
     ['Peringatan 90 / 30 / 7 hari', 'Riwayat batch per rak & toko', 'Tidak ada stok kedaluwarsa yang lolos']],

    ['i-doc', 'var(--primary-2)', 'Surat Jalan & Persetujuan',
     'Setiap perpindahan punya dokumennya sendiri, melewati persetujuan, dan bisa dicetak rapi di kertas A4.',
     ['Tujuh status dari draf sampai selesai', 'Nomor dokumen anti-tabrakan', 'Resi otomatis bila tanpa kurir']],

    ['i-paket', 'var(--info)', 'Penerimaan Hitung Fisik',
     'Yang masuk ke stok adalah hasil hitungan petugas, bukan angka di surat jalan. Selisihnya dicatat '
     . 'supaya bisa ditanyakan ke pengirim.',
     ['Kekurangan wajib dijelaskan', 'Tidak bisa melebihi jumlah kiriman', 'Jejaknya tersimpan permanen']],

    ['i-toko', 'var(--accent)', 'Toko & Marketplace',
     'Satu dasbor untuk semua toko sekaligus — Shopee, Tokopedia, TikTok, Lazada, dan gerai fisik.',
     ['Impor pesanan dari CSV', 'Stok & omzet lintas toko', 'Distribusi dari gudang toko']],

    ['i-users', 'var(--amber)', 'Penerima & Penyaluran',
     'Penerima bantuan tercatat sebagai data, bukan teks bebas — sehingga "berapa yang sudah sampai ke '
     . 'keluarga ini?" punya jawaban.',
     ['Sebaran per wilayah', 'Menandai yang belum pernah menerima', 'Anonim saat dilaporkan ke luar']],

    ['i-grafik', 'var(--green)', 'Laporan & Ekspor',
     'Angka yang sama dengan yang dipakai bekerja — bukan laporan yang disusun ulang dan bisa berbeda.',
     ['Stok per gudang, rak, dan toko', 'Ekspor CSV', 'Grafik kategori & status dokumen']],

    ['i-impor', 'var(--info)', 'Impor Data CSV',
     'Mengisi master data sekaligus banyak: produk, kategori, supplier, gudang, rak, dan toko.',
     ['Pratinjau sebelum disimpan', 'Unduh berkas contoh', 'Baris bermasalah ditunjukkan, bukan didiamkan']],

    ['i-scan', 'var(--primary-2)', 'Scan Resi & Barcode',
     'Satu resi paket bisa memuat banyak barang. Scan resinya, lalu cocokkan isinya satu per satu.',
     ['Kamera HP atau scanner USB', 'Selisih langsung terlihat', 'Bekerja tanpa internet']],
];

$PERAN = [
    ['i-gear',       'var(--primary-2)', 'Super Admin',   'Seluruh sistem, kelola akun, dan setelan keamanan.'],
    ['i-gudang',     'var(--green)',     'Admin Gudang',  'Barang masuk & keluar, stok, batch, surat jalan.'],
    ['i-cek-bulat',  'var(--info)',      'Pimpinan',      'Menyetujui dokumen dan melihat laporan — tanpa mengubah data.'],
    ['i-truck',      'var(--accent)',    'Supplier',      'Mencatat rencana kiriman & resi. Hanya melihat datanya sendiri.'],
    ['i-toko',       'var(--amber)',     'Admin Toko',    'Menerima kiriman, mencatat penjualan, mengelola stok tokonya.'],
];

$LANGKAH = [
    ['Supplier mencatat kiriman',
     'Nomor resi dan daftar barang diisi dari portal supplier. Kalau tidak lewat kurir, resinya dibuat otomatis.'],
    ['Gudang menerima & menghitung',
     'Petugas menembak barcode, mengisi nomor batch dan tanggal kedaluwarsa. Angka hasil hitung fisiklah yang masuk stok.'],
    ['Dokumen melewati persetujuan',
     'Perpindahan tidak langsung mengubah stok. Ia menunggu disetujui, lalu dikirim, lalu diterima.'],
    ['Barang dipecah ke toko atau disalurkan',
     'Dari gudang, barang berangkat ke toko untuk dijual, atau kepada penerima yang berhak. Keduanya tercatat.'],
    ['Setiap langkah meninggalkan jejak',
     'Siapa, kapan, berapa, dari mana ke mana. Bukan untuk saling curiga — untuk bisa menjawab bila ditanya.'],
];

$AMAN = [
    ['i-kunci', 'var(--red)', 'Peran & kepemilikan ditegakkan di server',
     'Supplier tidak bisa membuka data supplier lain, toko tidak bisa membuka toko lain — dan itu diperiksa '
     . 'di server, bukan cuma disembunyikan dari tampilan.'],
    ['i-users', 'var(--info)', 'Maksimal 3 perangkat per akun',
     'Login keempat ditolak. Halaman Perangkat memperlihatkan di mana saja sebuah akun sedang masuk, dan '
     . 'sesinya bisa diakhiri dari jauh.'],
    ['i-clock', 'var(--amber)', 'Batas waktu menganggur per akun',
     'Dari 30 menit sampai satu bulan, disetel Super Admin per akun — panjang untuk petugas lapangan, '
     . 'pendek untuk yang memegang data sensitif.'],
    ['i-clipboard', 'var(--green)', 'Jejak aktivitas & hapus lunak',
     'Membuat, mengubah, menghapus, mengimpor, mengekspor — semuanya tercatat. Data yang dihapus tidak '
     . 'benar-benar hilang, jadi laporan lama tidak ikut putus.'],
];
?>
<!DOCTYPE html>
<html lang="id" data-theme="<?= $tema ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="dark light">
<meta name="theme-color" content="#070B17">
<title><?= e(ident(APP_NAMA, APP_KEPANJANGAN)) ?></title>
<?php require __DIR__ . '/_merek.php'; ?>
<meta name="description" content="Sistem manajemen gudang & rantai pasok: penerimaan, batch &amp; kedaluwarsa (FEFO), surat jalan, toko &amp; marketplace, hingga penyaluran.">
<link rel="stylesheet" href="<?= e(aset_br('assets/amanah.css')) ?>">
</head>
<body>
<?php require __DIR__ . '/_ikon.php'; ?>

<header class="br-nav">
  <div class="br isi">
    <div class="merek">
      <?php if ($MEREK['logo']): ?>
        <span class="logo logo-gambar"><img src="<?= e($MEREK['logo']) ?>" alt="<?= e(APP_NAMA) ?>"></span>
      <?php else: ?>
        <span class="logo" aria-hidden="true"><svg><use href="#i-box"></use></svg></span>
      <?php endif; ?>
      <div><b><?= APP_NAMA ?></b><span>Gudang &amp; Rantai Pasok</span></div>
    </div>
    <nav class="tautan">
      <a href="#fitur" class="sembunyi-hp">Fitur</a>
      <a href="#alur" class="sembunyi-hp">Cara Kerja</a>
      <a href="alur.php" class="sembunyi-hp">Panduan Lengkap</a>
      <a href="#aman" class="sembunyi-hp">Keamanan</a>
      <a class="btn btn-primary btn-sm" href="<?= e($tujuanMasuk) ?>">
        <?= $sudahMasuk ? 'Buka Dashboard' : 'Masuk' ?>
      </a>
    </nav>
  </div>
</header>

<section class="br br-hero">
  <span class="br-lencana">
    <svg aria-hidden="true"><use href="#i-cek-bulat"></use></svg>
    Berjalan penuh tanpa internet
  </span>
  <h1>Setiap titipan tercatat,<br><em>setiap penyaluran terlacak</em></h1>
  <p class="utama">
    Dari supplier sampai ke tangan penerima — penerimaan, nomor batch, tanggal kedaluwarsa,
    surat jalan, penjualan toko, dan penyaluran. Satu sistem, satu catatan, satu jawaban
    ketika ada yang bertanya.
  </p>
  <div class="br-aksi">
    <a class="btn btn-primary" href="<?= e($tujuanMasuk) ?>">
      <svg aria-hidden="true"><use href="#i-kunci"></use></svg>
      <?= $sudahMasuk ? 'Buka Dashboard' : 'Masuk ke Sistem' ?>
    </a>
    <a class="btn btn-ghost" href="#fitur">
      <svg aria-hidden="true"><use href="#i-bawah"></use></svg> Lihat Fiturnya
    </a>
    <?php if ($adaApk): ?>
    <a class="btn btn-ghost" href="#aplikasi">
      <svg aria-hidden="true"><use href="#i-unduh"></use></svg> Unduh Aplikasi Android<?= $versiApk !== '' ? ' ' . e($versiApk) : '' ?>
    </a>
    <?php endif; ?>
  </div>

  <?php /* Rantai barang: identitas sistem ini, jadi ia yang pertama terlihat. */ ?>
  <div class="br-rantai">
    <?php foreach ($RANTAI as $i => [$ik, $nm, $ket, $wr]): ?>
      <div class="br-simpul" style="--sc:<?= $wr ?>">
        <div class="ic"><svg aria-hidden="true"><use href="#<?= $ik ?>"></use></svg></div>
        <b><?= e($nm) ?></b><small><?= e($ket) ?></small>
      </div>
      <div class="br-panah" aria-hidden="true">
        <svg><use href="#i-panah-kanan"></use></svg>
      </div>
    <?php endforeach; ?>
    <div class="br-cabang">
      <?php foreach ($UJUNG as [$ik, $nm, $ket, $wr]): ?>
        <div class="br-simpul" style="--sc:<?= $wr ?>">
          <div class="ic"><svg aria-hidden="true"><use href="#<?= $ik ?>"></use></svg></div>
          <b><?= e($nm) ?></b><small><?= e($ket) ?></small>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<?php if ($adaApk): ?>
<section class="br-seksi" id="aplikasi">
  <div class="br">
    <div class="br-kepala">
      <h2>Aplikasi Android</h2>
      <p>Pemindai barcode, surat jalan, penerimaan, dan stock opname dari ponsel petugas. Unduh di sini, pasang, masuk dengan akun yang sama.</p>
    </div>
    <div class="apk-seksi">
      <div class="apk-utama">
        <div class="apk-versi">
          <span class="apk-ikon"><svg aria-hidden="true"><use href="#i-unduh"></use></svg></span>
          <div>
            <b>AMANAH <?= e($versiApk !== '' ? $versiApk : 'terbaru') ?></b>
            <small><?= e($ukuranApk !== '' ? $ukuranApk . ' · ' : '') ?>Android 7.0 ke atas</small>
          </div>
        </div>
        <?php if ($catatanApk !== ''): ?>
          <div class="apk-catatan"><b>Apa yang baru</b><pre><?= e($catatanApk) ?></pre></div>
        <?php endif; ?>
        <a class="btn btn-primary apk-tombol" href="<?= e($urlApk) ?>">
          <svg aria-hidden="true"><use href="#i-unduh"></use></svg> Unduh Amanah.apk</a>
        <p class="apk-ket">Setelah diunduh di ponsel, buka berkasnya dan pilih <b>Pasang</b>. Bila Android menolak,
          izinkan pemasangan dari peramban yang dipakai, lalu ulangi. Memasang di atas versi lama tidak menghapus data.</p>
      </div>
      <?php if ($svgQrApk): ?>
      <div class="apk-qr">
        <div class="apk-qr-kotak"><?= $svgQrApk ?></div>
        <small>Pindai dengan kamera ponsel — tautan unduhnya langsung terbuka</small>
      </div>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="br-seksi" id="fitur">
  <div class="br">
    <div class="br-kepala">
      <h2>Yang ada di dalamnya</h2>
      <p>Bukan daftar keinginan — semuanya sudah berjalan dan dipakai dari layar yang sama.</p>
    </div>
    <div class="br-grid">
      <?php foreach ($FITUR as [$ik, $wr, $jd, $ket, $poin]): ?>
        <article class="br-kartu" style="--kc:<?= $wr ?>">
          <div class="ic"><svg aria-hidden="true"><use href="#<?= $ik ?>"></use></svg></div>
          <h3><?= e($jd) ?></h3>
          <p><?= e($ket) ?></p>
          <ul>
            <?php foreach ($poin as $p): ?>
              <li><svg aria-hidden="true"><use href="#i-cek"></use></svg><span><?= e($p) ?></span></li>
            <?php endforeach; ?>
          </ul>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="br-seksi" id="peran">
  <div class="br">
    <div class="br-kepala">
      <h2>Lima peran, lima tampilan berbeda</h2>
      <p>Yang dilihat seseorang ditentukan perannya — bukan disembunyikan dengan CSS,
        melainkan diperiksa ulang di server pada setiap permintaan.</p>
    </div>
    <div class="br-peran">
      <?php foreach ($PERAN as [$ik, $wr, $nm, $ket]): ?>
        <div class="p" style="--pc:<?= $wr ?>">
          <div class="atas">
            <span class="av"><svg aria-hidden="true"><use href="#<?= $ik ?>"></use></svg></span>
            <b><?= e($nm) ?></b>
          </div>
          <small><?= e($ket) ?></small>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="br-seksi" id="alur">
  <div class="br">
    <div class="br-kepala">
      <h2>Cara kerjanya</h2>
      <p>Satu perjalanan barang, dari supplier sampai tercatat tuntas.
        <br><a href="alur.php" style="color:var(--accent)">Lihat panduan lengkap &rsaquo;</a>
        — tujuh tahap persiapan dari nol, tujuh langkah harian, beserta apa
        yang rusak bila sebuah tahap dilewati.</p>
    </div>
    <div class="card">
      <ol class="br-langkah">
        <?php foreach ($LANGKAH as [$jd, $ket]): ?>
          <li><div><b><?= e($jd) ?></b><small><?= e($ket) ?></small></div></li>
        <?php endforeach; ?>
      </ol>
    </div>
  </div>
</section>

<section class="br-seksi" id="aman">
  <div class="br">
    <div class="br-kepala">
      <h2>Keamanan &amp; keandalan</h2>
      <p>Sistem yang memegang catatan titipan orang lain harus bisa dipertanggungjawabkan,
        bukan hanya enak dipakai.</p>
    </div>
    <div class="br-grid">
      <?php foreach ($AMAN as [$ik, $wr, $jd, $ket]): ?>
        <article class="br-kartu" style="--kc:<?= $wr ?>">
          <div class="ic"><svg aria-hidden="true"><use href="#<?= $ik ?>"></use></svg></div>
          <h3><?= e($jd) ?></h3>
          <p><?= e($ket) ?></p>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="br-seksi" id="luring">
  <div class="br">
    <div class="br-grid">
      <article class="br-kartu" style="--kc:var(--green)">
        <div class="ic"><svg aria-hidden="true"><use href="#i-putar"></use></svg></div>
        <h3>Bekerja saat internet mati</h3>
        <p>Ikon, grafik, dan pemindai barcode semuanya tersimpan di dalam sistem — bukan diambil
          dari jaringan. Gudang sering berada di tempat dengan sinyal buruk, dan pekerjaan tidak
          boleh berhenti karenanya.</p>
      </article>
      <article class="br-kartu" style="--kc:var(--info)">
        <div class="ic"><svg aria-hidden="true"><use href="#i-scan"></use></svg></div>
        <h3>Dipakai sambil berdiri</h3>
        <p>Tabel berubah menjadi kartu di layar ponsel, dan setiap tombol dibuat cukup besar untuk
          dikenai jari — termasuk saat petugas memakai sarung tangan dan memegang barang.</p>
      </article>
      <article class="br-kartu" style="--kc:var(--amber)">
        <div class="ic"><svg aria-hidden="true"><use href="#i-matahari"></use></svg></div>
        <h3>Terang &amp; gelap</h3>
        <p>Tema gelap sebagai bawaan, terang tersedia sekali klik — layar ponsel murah jauh lebih
          terbaca dengan latar putih di ruang yang sangat terang.</p>
      </article>
    </div>
  </div>
</section>

<section class="br-seksi">
  <div class="br">
    <div class="br-tutup">
      <h2><?= $sudahMasuk ? 'Selamat bekerja' : 'Siap mulai?' ?></h2>
      <p><?= $sudahMasuk
            ? 'Anda sudah masuk. Lanjutkan dari dasbor sesuai peran Anda.'
            : 'Akun dibuat oleh Super Admin. Hubungi pengelola sistem bila Anda belum memilikinya.' ?></p>
      <div class="br-aksi">
        <a class="btn btn-primary" href="<?= e($tujuanMasuk) ?>">
          <svg aria-hidden="true"><use href="#i-kunci"></use></svg>
          <?= $sudahMasuk ? 'Buka Dashboard' : 'Masuk ke Sistem' ?>
        </a>
      </div>
    </div>
  </div>
</section>

<footer class="br-footer">
  <div class="br">
    <?= e(ident(APP_NAMA . ' v' . APP_VERSI, APP_KEPANJANGAN, APP_LEMBAGA)) ?><br>
    <?= e(APP_TAGLINE) ?>
  </div>
</footer>

</body>
</html>
