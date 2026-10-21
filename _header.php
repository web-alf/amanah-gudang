<?php
/**
 * AMANAH — Kerangka tampilan tunggal untuk SELURUH peran.
 *
 * Supplier, Gudang, Toko, Pimpinan, dan Super Admin memakai kerangka yang
 * sama persis; yang berbeda hanya isi menunya.
 *
 * Seluruh gaya tinggal di assets/amanah.css — berkas ini hanya menyusun
 * struktur. Kalau sebuah halaman butuh bentuk baru, tambahkan komponennya
 * di berkas CSS itu, JANGAN menulis <style> di halaman.
 *
 * Variabel yang dibaca halaman pemanggil:
 *   $PAGE_TITLE     judul halaman
 *   $ACTIVE         kunci menu yang sedang aktif
 *   $MENU_OVERRIDE  menu khusus (dipakai dasbor supplier & toko)
 *   $EKSTRA_TOPBAR  HTML tambahan di topbar (mis. pemilih toko)
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
/* Alamat berakhiran garis miring (hosting, 12 Okt 2026). Hosting membuang
   .php dari alamat; bila pernah ada FOLDER bernama sama (dulu cadangan/),
   peramban menyimpan pengalihan 301 ke /cadangan/ dan halaman lalu
   dijalankan dari alamat itu — semua berkas tampilan dicari di
   /cadangan/assets/… dan halamannya tampil polos. Diarahkan ke bentuk
   tanpa garis miring, yang oleh hosting dipetakan ke skrip ini juga. */
if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    $uriTanpaQuery = (string) strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?');
    $skrip = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
    if ($uriTanpaQuery !== '' && substr($uriTanpaQuery, -1) === '/' && substr($skrip, -4) === '.php'
        && rtrim($uriTanpaQuery, '/') === substr($skrip, 0, -4)) {
        $q = (string) ($_SERVER['QUERY_STRING'] ?? '');
        header('Location: ' . substr($skrip, 0, -4) . ($q !== '' ? '?' . $q : ''), true, 302);
        exit;
    }
}

require_login();


$u          = current_user();
$ACTIVE     = $ACTIVE ?? '';
$PAGE_TITLE = $PAGE_TITLE ?? APP_NAMA;
/* Nilai yang SUDAH disetel halaman tidak ditimpa.
 *
 * Dulu baris ini menyetelnya tanpa syarat. Akibatnya halaman master data
 * yang mengetatkan izinnya di atas — mis. $canEdit = boleh_kelola() —
 * kehilangan nilai itu begitu berkas ini di-require, karena penanganan POST
 * berjalan lebih dulu tapi penggambaran tombolnya sesudah. Yang terlihat:
 * staff tetap disuguhi tombol Ubah dan Hapus, lalu ditolak 403 saat
 * menekannya. Halaman yang tidak menyetelnya sendiri tetap dapat bawaannya. */
$canEdit    = isset($canEdit) ? $canEdit : boleh_edit();
$canApprove = isset($canApprove) ? $canApprove : boleh_setujui($pdo);

$namaUser = (string) ($u['nama'] ?? 'Pengguna');
$pecah    = preg_split('/\s+/', trim($namaUser)) ?: [];
$inisial  = strtoupper(mb_substr($pecah[0] ?? 'U', 0, 1) . (isset($pecah[1]) ? mb_substr($pecah[1], 0, 1) : '')) ?: 'U';

$flash = ambil_flash();

// Tema GELAP jadi bawaan — sesuai arahan desain. Tombol terang tetap ada
// karena petugas kadang bekerja di ruang bercahaya sangat terang tempat
// layar HP murah lebih terbaca dengan latar putih.
$tema = ($_COOKIE['amanah_theme'] ?? 'light') === 'dark' ? 'dark' : 'light';   // terang adalah bawaan — sama dengan aplikasi Android

// Sidebar lebar atau rel ikon. Dibaca dari cookie DI SERVER supaya
// halaman langsung tergambar pada lebar yang benar — kalau diatur oleh
// JavaScript setelah halaman tampil, sidebar berkedip lebar lalu
// menyempit setiap kali pindah halaman.
$navRapat = ($_COOKIE['amanah_nav'] ?? 'lebar') === 'rapat';

// --------------------------------------------------------------- BADGE
function badge_hitung(PDO $pdo, string $sql): int {
    try { return (int) $pdo->query($sql)->fetchColumn(); }
    catch (Throwable $e) { return 0; }   // tabel belum dimigrasi
}

$menu = $MENU_OVERRIDE ?? null;

// Grup navigasi yang memuat halaman ini — menentukan sorotan sidebar dan
// deretan tab. Dicocokkan per NAMA BERKAS, bukan per $ACTIVE.
$FILE_INI = basename($_SERVER['SCRIPT_NAME'] ?? '');
$GRUP_INI = null;
$TAB_GRUP = [];
$BADGE    = ['persetujuan' => 0, 'terima' => 0, 'kiriman' => 0];

/* Menu ditentukan DI SINI, berdasarkan peran — bukan diserahkan ke tiap
   halaman lewat $MENU_OVERRIDE.
   Dulu tiap halaman wajib ingat memasangnya sendiri, dan yang lupa akan
   jatuh ke menu gudang internal. Itu bukan kemungkinan teoretis:
   perangkat.php lupa, sehingga akun Supplier dan Toko melihat menu gudang
   penuh — Kategori, Penerima Amanah, Transfer Antar Gudang — hanya dengan
   membuka halaman perangkatnya sendiri. Sekarang halaman baru otomatis
   benar, dan $MENU_OVERRIDE hanya dipakai bila sebuah halaman memang perlu
   menu yang berbeda dari bawaan perannya (mis. mode pengawasan). */
if ($menu === null && is_supplier()) {
    $menu = menu_supplier(0, $pdo);
} elseif ($menu === null && is_toko()) {
    $tokoSaya = toko_saya($pdo);
    $menu = menu_toko(badge_hitung($pdo,
        "SELECT COUNT(*) FROM perpindahan
         WHERE tujuan_tipe = 'toko'
           AND tujuan_id IN (" . (implode(',', array_map('intval', $tokoSaya)) ?: '0') . ")
           AND status IN ('dikirim','disetujui')"), $pdo);
} elseif ($menu === null) {
    $BADGE = [
        'persetujuan' => badge_hitung($pdo, "SELECT COUNT(*) FROM perpindahan WHERE status = 'menunggu_persetujuan'"),
        // Lencana ikut lingkup gudang: petugas GD-003 tidak perlu ikut
        // memikul angka kiriman yang menuju gudang orang lain.
        'terima'      => badge_hitung($pdo, "SELECT COUNT(*) FROM perpindahan
                                             WHERE status = 'dikirim'
                                               AND (tujuan_tipe = 'gudang' AND tujuan_id IN (" . in_gudang_saya($pdo) . ")
                                                 OR tujuan_tipe = 'rak'    AND tujuan_id IN (" . in_rak_saya($pdo) . "))"),
        'kiriman'     => badge_hitung($pdo, "SELECT COUNT(*) FROM pengiriman_supplier
                                             WHERE status IN ('dikirim','tiba')"),
    ];
    $menu     = menu_internal(role_saat_ini(), $BADGE, $pdo);
    $GRUP_INI = grup_berisi($FILE_INI, role_saat_ini(), $BADGE, null, $pdo);

    if ($GRUP_INI) {
        $ACTIVE = $GRUP_INI['k'];
        // Satu anggota tidak perlu tab — tidak ada pilihan untuk dipilih.
        if (count($GRUP_INI['anggota']) > 1) { $TAB_GRUP = $GRUP_INI['anggota']; }
    }
}

// Halaman di bawah Pengaturan memakai jejak navigasi, bukan tab.
$DI_PENGATURAN = ($GRUP_INI['k'] ?? '') === 'pengaturan' && $FILE_INI !== 'pengaturan.php';

// Jumlah lencana untuk ikon notifikasi di topbar.
$NOTIF = $BADGE['persetujuan'] + $BADGE['terima'] + $BADGE['kiriman'];

$LABEL_DASBOR = label_dasbor();
$PERAN_SLUG   = is_supplier() ? 'supplier' : (is_toko() ? 'toko' : 'internal');
?>
<!DOCTYPE html>
<html lang="id" data-theme="<?= $tema ?>"<?= $navRapat ? ' data-nav="rapat"' : '' ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="dark light">
<meta name="theme-color" content="<?= $tema === 'dark' ? '#0B1020' : '#F7F8FC' ?>">
<title><?= e($PAGE_TITLE) ?> · <?= APP_NAMA ?></title>
<?php require __DIR__ . '/_merek.php'; ?>
<?php
/* Penanda cache diambil dari waktu ubah berkas, bukan angka yang disetel
   tangan. Dengan nomor manual, setiap perbaikan CSS/JS berisiko tidak
   pernah sampai ke pengguna karena browser memakai salinan lamanya —
   dan itu sudah terjadi sekali pada berkas scan. */
function aset(string $berkas): string {
    $penuh = __DIR__ . '/' . $berkas;
    $cap   = is_file($penuh) ? filemtime($penuh) : APP_VERSI;
    return $berkas . '?v=' . $cap;
}
?>
<link rel="stylesheet" href="<?= e(aset('assets/amanah.css')) ?>">
</head>
<body>
<?php require __DIR__ . '/_ikon.php'; ?>

<div class="app">
  <div class="overlay" id="overlay" onclick="toggleSidebar()"></div>

  <aside class="sidebar" id="sidebar" data-peran="<?= e($PERAN_SLUG) ?>">
    <div class="brand">
      <?php /* Logo unggahan bila ada; ikon bawaan bila belum. Tampilan
                  tidak boleh bergantung pada operator sudah mengunggah. */ ?>
      <?php if ($MEREK['logo']): ?>
        <span class="logo logo-gambar"><img src="<?= e($MEREK['logo']) ?>" alt="<?= e(APP_NAMA) ?>"></span>
      <?php else: ?>
        <span class="logo" aria-hidden="true"><svg><use href="#i-box"></use></svg></span>
      <?php endif; ?>
      <div><b><?= APP_NAMA ?></b><span><?= e($LABEL_DASBOR) ?></span></div>
    </div>

    <nav class="nav" aria-label="Menu utama">
      <?php foreach ($menu as $m): ?>
        <?php if (isset($m['sec'])): ?>
          <div class="sec"><?= e($m['sec']) ?></div>
        <?php else:
          if (isset($m['role']) && !in_array(role_saat_ini(), $m['role'], true)) continue; ?>
          <?php
          /* data-tip menjadi tooltip saat sidebar terlipat. Lencana ikut
             disebut di situ karena angkanya menyusut jadi titik. */
          $tip = $m['t'] . (!empty($m['badge']) ? ' · ' . (int) $m['badge'] . ' menunggu' : '');
          ?>
          <a href="<?= e($m['h']) ?>" class="<?= $ACTIVE === $m['k'] ? 'active' : '' ?>"
             data-tip="<?= e($tip) ?>"
             <?= $ACTIVE === $m['k'] ? 'aria-current="page"' : '' ?>>
            <svg class="ic" aria-hidden="true"><use href="#<?= e($m['i']) ?>"></use></svg>
            <span><?= e($m['t']) ?></span>
            <?php if (!empty($m['badge'])): ?>
              <span class="bdg"><?= (int) $m['badge'] ?><span class="sr"> menunggu</span></span>
            <?php endif; ?>
          </a>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>

    <a class="sb-help" href="https://wa.me/" target="_blank" rel="noopener">
      <svg aria-hidden="true"><use href="#i-bantuan"></use></svg>
      <div><b>Butuh Bantuan?</b><small>Hubungi tim support</small></div>
    </a>
  </aside>

  <div class="main">
    <header class="topbar">
      <button class="menu-btn" onclick="toggleSidebar()" aria-label="Buka menu">
        <svg aria-hidden="true"><use href="#i-menu"></use></svg>
      </button>

      <button class="rail-btn" onclick="toggleRail()" id="railBtn"
              title="Lipat / lebarkan menu (Ctrl+B)"
              aria-label="Lipat atau lebarkan menu" aria-pressed="<?= $navRapat ? 'true' : 'false' ?>">
        <svg aria-hidden="true"><use href="#i-lipat"></use></svg>
      </button>

      <?php if (!is_eksternal()): ?>
        <form class="search" method="get" action="barang.php" role="search">
          <svg aria-hidden="true"><use href="#i-cari"></use></svg>
          <input name="q" id="cariGlobal" placeholder="Cari produk, kode, barcode, resi, surat jalan…"
                 value="<?= e($_GET['q'] ?? '') ?>" aria-label="Pencarian">
          <kbd>Ctrl /</kbd>
        </form>
      <?php else: ?>
        <div style="font-size:14.5px;font-weight:650"><?= e($PAGE_TITLE) ?></div>
      <?php endif; ?>

      <div class="spacer"></div>
      <?= $EKSTRA_TOPBAR ?? '' ?>

      <?php if (!is_eksternal() || is_toko()): ?>
        <a class="icon-btn scan" href="scan_resi.php" title="Scan resi paket" aria-label="Scan resi paket">
          <svg aria-hidden="true"><use href="#i-scan"></use></svg>
        </a>
      <?php endif; ?>

      <button class="icon-btn" onclick="toggleTheme()" id="themeBtn"
              title="Mode terang / gelap" aria-label="Ganti tema">
        <svg aria-hidden="true"><use href="#i-bulan" id="themeIcon"></use></svg>
      </button>

      <?php /* Lonceng selalu ada dan membuka Pusat Notifikasi — bukan hanya
               muncul saat ada angka lalu melompat ke satu halaman. Angkanya
               tetap dari lencana yang murah dihitung. */ ?>
        <a class="icon-btn" href="notifikasi.php"
           title="<?= $NOTIF > 0 ? (int) $NOTIF . ' hal menunggu tindakan' : 'Notifikasi' ?>" aria-label="Notifikasi">
          <svg aria-hidden="true"><use href="#i-bel"></use></svg>
          <?php if ($NOTIF > 0): ?><span class="dot"><?= $NOTIF > 9 ? '9+' : (int) $NOTIF ?></span><?php endif; ?>
        </a>

      <!-- Menu pengguna. Keluar ada di sini, bukan di sidebar: sidebar
           memetakan TEMPAT dalam aplikasi, keluar adalah tindakan akun. -->
      <div class="usermenu" id="usermenu" data-buka="0">
        <div class="userchip" onclick="toggleUserMenu()" role="button" tabindex="0"
             aria-haspopup="true" aria-expanded="false" aria-controls="umPanel"
             onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();toggleUserMenu()}">
          <div class="av"><?= e($inisial) ?></div>
          <div class="who">
            <b><?= e($namaUser) ?></b>
            <small><?= e($u['role_label'] ?? '') ?></small>
          </div>
        </div>

        <div class="um-panel" id="umPanel" role="menu">
          <div class="um-head">
            <b><?= e($namaUser) ?></b>
            <small><?= e($u['email'] ?? '') ?></small>
            <span class="tag t-purple"><?= e($u['role_label'] ?? '') ?></span>
          </div>
          <a href="profil.php" role="menuitem">
            <svg aria-hidden="true"><use href="#i-users"></use></svg> Profil Saya
          </a>
          <a href="ganti_password.php" role="menuitem">
            <svg aria-hidden="true"><use href="#i-gear"></use></svg> Ganti Password
          </a>
          <?php if (!is_eksternal()): ?>
            <a href="pengaturan.php" role="menuitem">
              <svg aria-hidden="true"><use href="#i-gear"></use></svg> Pengaturan
            </a>
          <?php endif; ?>
          <a href="profil.php#jejak" role="menuitem">
            <svg aria-hidden="true"><use href="#i-clipboard"></use></svg> Riwayat Aktivitas
          </a>
          <hr>
          <a href="logout.php" class="bahaya" role="menuitem">
            <svg aria-hidden="true"><use href="#i-keluar-pintu"></use></svg> Keluar
          </a>
        </div>
      </div>
    </header>

    <div class="content">
      <?php if ($TAB_GRUP): ?>
        <div class="grup-tab">
          <?php foreach ($TAB_GRUP as $t): ?>
            <a href="<?= e($t['f']) ?>" class="<?= $FILE_INI === $t['f'] ? 'aktif' : '' ?>">
              <?= e($t['t']) ?>
              <?php if (!empty($t['badge'])): ?><span class="bdg"><?= (int) $t['badge'] ?></span><?php endif; ?>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ($DI_PENGATURAN): ?>
        <a class="jejak" href="pengaturan.php">
          <svg class="ikon ikon-sm" aria-hidden="true"><use href="#i-gear"></use></svg>
          Pengaturan <span aria-hidden="true">›</span> <b><?= e($PAGE_TITLE) ?></b>
        </a>
      <?php endif; ?>

      <?php if ($flash): ?>
        <div class="flash flash-<?= $flash['type'] === 'ok' ? 'ok' : 'err' ?>"><?= e($flash['msg']) ?></div>
      <?php endif; ?>

      <?php /* Admin Gudang yang belum ditugaskan ke gudang mana pun akan
               melihat semua daftar kosong. Tanpa keterangan ini, halaman
               yang benar tampak seperti halaman yang rusak. */
             echo peringatan_tanpa_gudang($pdo); ?>
