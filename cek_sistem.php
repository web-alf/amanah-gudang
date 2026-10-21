<?php
/**
 * AMANAH — Pemeriksa Kesiapan Server
 *
 * Dipakai SEKALI saat memasang di hosting, lalu HAPUS.
 *
 * Gunanya menjawab satu pertanyaan: kenapa aplikasinya balas 500?
 * Halaman galat 500 tidak menyebut penyebabnya, jadi berkas ini
 * memeriksanya sendiri dan menyebutkannya dengan jelas.
 *
 * Berkas ini SENGAJA berdiri sendiri: ia tidak memuat config.php,
 * auth.php, atau _lib.php lewat require di awal. Kalau ia ikut memuat
 * berkas yang justru sedang bermasalah, ia akan mati bersama-sama dan
 * tidak memberi tahu apa pun. Pemeriksa harus hidup ketika yang
 * diperiksa sedang mati.
 *
 * ---------------------------------------------------------------------
 * KEAMANAN
 * ---------------------------------------------------------------------
 * Halaman ini menyebut versi PHP, nama basis data, dan jalur berkas —
 * keterangan yang berguna bagi orang yang ingin menyerang. Karena itu:
 *
 *   1. Ia meminta KUNCI di alamatnya:  cek_sistem.php?kunci=...
 *   2. Kuncinya TIDAK tertulis di berkas ini, melainkan di
 *      config.local.php sebagai $KUNCI_CEK. Kosong = halaman ini mati.
 *   3. Kosongkan lagi $KUNCI_CEK setelah aplikasi berjalan.
 *
 * Tanpa kunci yang benar, halaman ini membalas 404 — bukan pesan
 * "kunci salah", supaya keberadaannya pun tidak terkonfirmasi.
 *
 * Kunci yang dulu tertulis di sini ("ganti-kunci-ini-2026") tidak pernah
 * diganti di hosting, jadi halaman ini terbuka bagi siapa pun yang
 * membaca kodenya. Membacanya dari config.local.php — berkas yang tidak
 * ikut terunggah — membuat kunci bawaan tidak mungkin ada.
 */

$KUNCI_CEK = '';
if (is_file(__DIR__ . '/config.local.php')) {
    /* Dimuat tanpa config.php: berkas ini harus tetap hidup ketika
       koneksi basis datanya justru yang sedang diperiksa. */
    require __DIR__ . '/config.local.php';
}
if ($KUNCI_CEK === '' || strlen($KUNCI_CEK) < 8
    || !isset($_GET['kunci']) || !hash_equals($KUNCI_CEK, (string) $_GET['kunci'])) {
    http_response_code(404);
    exit('Not Found');
}

// Tampilkan galat HANYA di halaman ini, supaya penyebabnya terlihat.
ini_set('display_errors', '1');
error_reporting(E_ALL);

$hasil = [];
/** @param string $status 'ok' | 'gagal' | 'awas' */
function cek(string $nama, string $status, string $ket, string $saran = ''): void {
    global $hasil;
    $hasil[] = compact('nama', 'status', 'ket', 'saran');
}

// ============================================================ 1. PHP
/* Batas sebenarnya 7.4, BUKAN 8.0.
 *
 * Baris ini dulu menuntut PHP 8.0 dengan alasan "aplikasi memakai match()
 * dan str_contains()". Keduanya tidak benar lagi, dan salah satunya tidak
 * pernah benar:
 *
 *   - match() TIDAK dipakai di mana pun. Justru sebaliknya: setiap
 *     percabangan sengaja ditulis if/elseif karena match() gagal saat
 *     PARSE — bukan saat dijalankan — sehingga satu pemakaian akan
 *     mematikan seluruh berkas di hosting PHP 7.4.
 *   - str_contains(), str_starts_with(), str_ends_with() sudah
 *     dipolyfill di config.php tepat untuk keperluan ini.
 *
 * Yang benar-benar dibutuhkan adalah arrow function fn() — fitur PHP 7.4,
 * dipakai di dua puluh lima berkas.
 *
 * Kekeliruan seperti ini bukan sekadar teks yang salah: ia menyuruh orang
 * menaikkan versi PHP di hosting produksi tanpa alasan, dan kenaikan versi
 * adalah tindakan yang bisa mematahkan hal lain. */
$vPHP = PHP_VERSION;
if (version_compare($vPHP, '8.0.0', '>=')) {
    cek('Versi PHP', 'ok', $vPHP);
} elseif (version_compare($vPHP, '7.4.0', '>=')) {
    cek('Versi PHP', 'ok', $vPHP . ' — didukung (batas minimum 7.4)');
} else {
    cek('Versi PHP', 'gagal', $vPHP . ' — TERLALU LAMA',
        'Aplikasi ini memakai arrow function fn() yang baru ada di PHP 7.4. '
      . 'Di versi sebelumnya, berkas yang memakainya gagal diurai dan halamannya '
      . 'membalas 500. Naikkan ke PHP 7.4 atau lebih baru — di cPanel: MultiPHP Manager.');
}

// ==================================================== 2. Ekstensi PHP
foreach ([
    'pdo_mysql' => 'menghubungkan ke basis data',
    'mbstring'  => 'memotong teks Indonesia dengan benar',
    'json'      => 'menyimpan data marketplace',
] as $ext => $untuk) {
    extension_loaded($ext)
        ? cek("Ekstensi $ext", 'ok', 'terpasang')
        : cek("Ekstensi $ext", 'gagal', 'TIDAK ADA', "Dibutuhkan untuk $untuk. Aktifkan lewat cPanel > Select PHP Version > Extensions.");
}

// ================================================ 3. Berkas yang wajib ada
$wajib = ['config.php', 'auth.php', '_lib.php', '_header.php', '_footer.php', '_ikon.php',
          'index.php', 'login.php', 'dashboard.php', 'assets/amanah.css', 'assets/scan.js',
          'assets/chart.umd.js'];
$hilang = [];
foreach ($wajib as $f) { if (!is_file(__DIR__ . '/' . $f)) { $hilang[] = $f; } }
$hilang
    ? cek('Berkas aplikasi', 'gagal', count($hilang) . ' berkas hilang: ' . implode(', ', $hilang),
          'Unggah ulang seluruh berkas aplikasi. Mengunggah SQL saja tidak cukup — '
        . 'basis data baru dengan kode lama menyebabkan galat "undefined function".')
    : cek('Berkas aplikasi', 'ok', count($wajib) . ' berkas inti lengkap');

// =============================================== 4. Kode & basis data sepadan
// Fungsi & konstanta yang HANYA ada di versi terbaru. Kalau salah satunya
// tidak ada, berarti kode di server lebih lama daripada basis datanya.
$sepadan = true;
if (is_file(__DIR__ . '/_lib.php')) {
    $isi = (string) file_get_contents(__DIR__ . '/_lib.php');
    $penanda = ['function ident(' => 'de-branding',
                'MAKS_PERANGKAT'  => 'batas perangkat per akun',
                'IDLE_MENIT_BAWAAN' => 'batas menganggur per akun',
                'function gudang_toko(' => 'gudang sisi toko'];
    $kurang = [];
    foreach ($penanda as $p => $fitur) { if (strpos($isi, $p) === false) { $kurang[] = $fitur; } }
    if ($kurang) {
        $sepadan = false;
        cek('Kode vs basis data', 'gagal', 'kode di server lebih lama',
            'Fitur ini ada di basis data tapi belum ada di kode: ' . implode(', ', $kurang)
          . '. Unggah ulang seluruh berkas PHP, bukan hanya SQL-nya.');
    } else {
        cek('Kode vs basis data', 'ok', 'kode di server sudah versi terbaru');
    }
}

// ================================================== 5. config.local.php
$adaLokal = is_file(__DIR__ . '/config.local.php');
$adaLokal
    ? cek('config.local.php', 'ok', 'ada')
    : cek('config.local.php', 'awas', 'TIDAK ADA',
          'Tanpa berkas ini, aplikasi memakai kredensial XAMPP (root tanpa password) '
        . 'yang tidak berlaku di hosting. Buat config.local.php berisi $DB_HOST, $DB_NAME, '
        . '$DB_USER, dan $DB_PASS milik hosting Anda.');

// ====================================================== 6. Basis data
$pdoCek = null;
if (is_file(__DIR__ . '/config.php')) {
    // Dimuat di dalam try: kalau config.php sendiri yang bermasalah,
    // pesannya harus muncul di sini, bukan menjatuhkan halaman.
    try {
        ob_start();
        require __DIR__ . '/config.php';
        ob_end_clean();
        $pdoCek = $pdo ?? null;
        if ($pdoCek instanceof PDO) {
            $nama = $pdoCek->query('SELECT DATABASE()')->fetchColumn();
            cek('Koneksi basis data', 'ok', 'tersambung ke "' . $nama . '"');
        } else {
            cek('Koneksi basis data', 'gagal', 'config.php tidak menghasilkan koneksi');
        }
    } catch (Throwable $e) {
        ob_end_clean();
        cek('Koneksi basis data', 'gagal', $e->getMessage(),
            'Periksa nama basis data, pengguna, dan password di config.local.php. '
          . 'Di hosting, nama basis data biasanya diawali nama akun cPanel.');
    }
}

// ================================================ 7. Isi basis data
if ($pdoCek instanceof PDO) {
    try {
        $t = (int) $pdoCek->query(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'")->fetchColumn();
        $v = (int) $pdoCek->query(
            "SELECT COUNT(*) FROM information_schema.VIEWS
             WHERE TABLE_SCHEMA = DATABASE()")->fetchColumn();
        if ($t >= 36 && $v >= 9) {
            cek('Struktur basis data', 'ok', "$t tabel, $v view");
        } else {
            cek('Struktur basis data', 'gagal', "$t tabel, $v view (seharusnya 36 tabel, 9 view)",
                'Impor instal_database.sql belum lengkap atau gagal sebagian. '
              . 'View yang gagal biasanya karena DEFINER — pakai instal_database.sql '
              . 'versi terbaru yang DEFINER-nya sudah dibuang.');
        }

        // Setiap view harus benar-benar bisa dijalankan. View yang rusak
        // baru terlihat saat halaman dibuka, bukan saat impor.
        $gagalView = [];
        foreach ($pdoCek->query(
            "SELECT TABLE_NAME FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE()"
        ) as $r) {
            try { $pdoCek->query('SELECT 1 FROM `' . $r['TABLE_NAME'] . '` LIMIT 1')->fetchColumn(); }
            catch (Throwable $e) { $gagalView[] = $r['TABLE_NAME']; }
        }
        $gagalView
            ? cek('View bisa dijalankan', 'gagal', implode(', ', $gagalView),
                  'View ini ada tapi gagal saat dipakai — hampir selalu karena DEFINER '
                . 'menunjuk pengguna yang tidak ada di hosting.')
            : cek('View bisa dijalankan', 'ok', 'semua view berjalan');

        $peran = (int) $pdoCek->query('SELECT COUNT(*) FROM roles')->fetchColumn();
        $akun  = (int) $pdoCek->query('SELECT COUNT(*) FROM users WHERE deleted_at IS NULL')->fetchColumn();
        ($peran > 0 && $akun > 0)
            ? cek('Data acuan', 'ok', "$peran peran, $akun akun")
            : cek('Data acuan', 'gagal', "$peran peran, $akun akun",
                  'Tanpa baris di tabel roles dan users, tidak ada yang bisa masuk.');

        // Kolom yang ditambahkan migrasi terakhir.
        $kolom = [];
        foreach ([['users', 'idle_menit'], ['gudang', 'jenis'], ['batch_barang', 'kondisi']] as [$tb, $kl]) {
            $ada = $pdoCek->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
            $ada->execute([$tb, $kl]);
            if (!(int) $ada->fetchColumn()) { $kolom[] = "$tb.$kl"; }
        }
        $kolom
            ? cek('Kolom terbaru', 'gagal', 'hilang: ' . implode(', ', $kolom),
                  'Basis data di hosting lebih lama daripada kodenya. Impor instal_database.sql terbaru.')
            : cek('Kolom terbaru', 'ok', 'lengkap');

    } catch (Throwable $e) {
        cek('Isi basis data', 'gagal', $e->getMessage());
    }
}

// ================================================ 8. Bisa menulis sesi
/* Jalurnya ikut disebut, bukan hanya "bisa ditulis".
 *
 * Aplikasi ini berusaha memakai folder sesi MILIKNYA SENDIRI (Gudang/sesi)
 * justru karena folder bersama adalah penyebab paling sering dari "keluar
 * sendiri" di hosting bersama: pemungut sampah situs lain memindai
 * direktori yang sama dengan ambangnya sendiri, dan berkas sesi kita ikut
 * terhapus meski setelan kita benar.
 *
 * Kalau baris ini menyebut jalur di luar folder aplikasi, artinya foldernya
 * gagal dibuat atau tidak bisa ditulis — dan gejalanya akan kembali. */
/* Yang diperiksa adalah PRASYARATNYA, bukan session_save_path().
 *
 * Berkas ini sengaja tidak memuat auth.php — itulah yang membuatnya tetap
 * hidup saat aplikasinya mati. Akibatnya session_save_path() di sini
 * mengembalikan bawaan server, bukan folder yang akan dipakai aplikasi,
 * dan membacanya justru melaporkan "folder bersama" pada pemasangan yang
 * sebenarnya sudah benar.
 *
 * Yang bisa diperiksa tanpa memuat apa pun: apakah folder yang dituju
 * auth.php ada dan bisa ditulis. Itu tepat prasyaratnya. */
$dirSesiApp = __DIR__ . '/sesi';
$bawaanSesi = session_save_path() ?: sys_get_temp_dir();

if (is_dir($dirSesiApp) && is_writable($dirSesiApp)) {
    cek('Folder sesi', 'ok', 'folder sendiri siap: ' . $dirSesiApp);
} elseif (!is_dir($dirSesiApp) && is_writable(__DIR__)) {
    /* Belum ada, tapi induknya bisa ditulis — auth.php akan membuatnya
       sendiri pada permintaan pertama. Bukan masalah. */
    cek('Folder sesi', 'ok', 'akan dibuat otomatis di ' . $dirSesiApp);
} elseif (is_dir($bawaanSesi) && is_writable($bawaanSesi)) {
    cek('Folder sesi', 'awas', 'akan jatuh ke folder bersama: ' . $bawaanSesi,
        'Folder ' . $dirSesiApp . ' tidak ada dan tidak bisa dibuat. Aplikasi tetap '
      . 'jalan memakai folder sesi bersama, tetapi di hosting bersama itu bisa '
      . 'membuat orang keluar sendiri — pemungut sampah situs lain memindai folder '
      . 'yang sama dengan ambangnya sendiri dan ikut menghapus berkas sesi kita. '
      . 'Buat folder "sesi" di dalam folder aplikasi, izin 0700 atau 0755.');
} else {
    cek('Folder sesi', 'gagal', 'tidak ada folder sesi yang bisa ditulis',
        'Login tidak akan pernah bertahan. Hubungi dukungan hosting.');
}

// =================================================== 9. .htaccess aktif
is_file(__DIR__ . '/.htaccess')
    ? cek('.htaccess', 'ok', 'ada — pastikan instal_database.sql membalas 403 dari peramban')
    : cek('.htaccess', 'gagal', 'TIDAK ADA',
          'Tanpa ini, instal_database.sql bisa diunduh siapa pun — beserta seluruh hash password.');

$jmlGagal = count(array_filter($hasil, fn($h) => $h['status'] === 'gagal'));
$jmlAwas  = count(array_filter($hasil, fn($h) => $h['status'] === 'awas'));
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Pemeriksa Kesiapan Server</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:"Segoe UI",system-ui,sans-serif;background:#070B17;color:#F8FAFC;
    line-height:1.6;padding:28px 18px;font-size:14.5px}
  .w{max-width:860px;margin:0 auto}
  h1{font-size:24px;font-weight:700;letter-spacing:-.03em;margin-bottom:6px}
  .sub{color:#94A3B8;font-size:13.5px;margin-bottom:24px}
  .ringkas{border-radius:14px;padding:16px 18px;margin-bottom:22px;font-weight:600;
    border:1px solid}
  .r-ok{background:rgba(16,185,129,.12);border-color:rgba(16,185,129,.35);color:#34D399}
  .r-bad{background:rgba(239,68,68,.12);border-color:rgba(239,68,68,.35);color:#F87171}
  .baris{background:#101829;border:1px solid rgba(150,170,220,.13);border-radius:12px;
    padding:14px 16px;margin-bottom:10px;display:flex;gap:14px;align-items:flex-start}
  .tanda{flex-shrink:0;width:22px;height:22px;border-radius:50%;display:grid;place-items:center;
    font-size:12px;font-weight:700;margin-top:1px}
  .t-ok{background:rgba(16,185,129,.18);color:#34D399}
  .t-bad{background:rgba(239,68,68,.18);color:#F87171}
  .t-warn{background:rgba(245,158,11,.18);color:#FBBF24}
  .isi{min-width:0;flex:1}
  .nama{font-weight:650;font-size:14.5px}
  .ket{color:#CBD5E1;font-size:13.2px;word-break:break-word}
  .saran{margin-top:7px;padding:10px 12px;background:#0B1120;border-radius:9px;
    border-left:3px solid #2563EB;color:#94A3B8;font-size:12.8px}
  .akhir{margin-top:26px;padding:16px 18px;background:rgba(239,68,68,.1);
    border:1px solid rgba(239,68,68,.3);border-radius:12px;color:#F87171;font-size:13.2px}
  code{background:#0B1120;padding:2px 6px;border-radius:5px;font-size:.92em}
</style>
</head>
<body>
<div class="w">
  <h1>Pemeriksa Kesiapan Server</h1>
  <p class="sub">PHP <?= htmlspecialchars(PHP_VERSION) ?> ·
    <?= htmlspecialchars(PHP_OS_FAMILY) ?> ·
    <?= htmlspecialchars($_SERVER['SERVER_SOFTWARE'] ?? 'server tidak diketahui') ?></p>

  <?php if ($jmlGagal === 0): ?>
    <div class="ringkas r-ok">Semua pemeriksaan lolos<?= $jmlAwas ? " ($jmlAwas peringatan)" : '' ?>.
      Kalau halaman aplikasi masih membalas 500, penyebabnya ada di luar daftar ini — lihat
      catatan di bawah.</div>
  <?php else: ?>
    <div class="ringkas r-bad"><?= $jmlGagal ?> pemeriksaan GAGAL. Perbaiki yang bertanda merah
      lebih dulu — itu penyebab paling mungkin dari galat 500.</div>
  <?php endif; ?>

  <?php foreach ($hasil as $h): ?>
    <div class="baris">
      <span class="tanda <?= $h['status'] === 'ok' ? 't-ok' : ($h['status'] === 'awas' ? 't-warn' : 't-bad') ?>">
        <?= $h['status'] === 'ok' ? '✓' : ($h['status'] === 'awas' ? '!' : '✕') ?>
      </span>
      <div class="isi">
        <div class="nama"><?= htmlspecialchars($h['nama']) ?></div>
        <div class="ket"><?= htmlspecialchars($h['ket']) ?></div>
        <?php if ($h['saran'] !== ''): ?>
          <div class="saran"><?= htmlspecialchars($h['saran']) ?></div>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>

  <div class="akhir">
    <b>Kalau semua di atas lolos tapi aplikasi tetap 500:</b><br>
    1. Ganti nama <code>.htaccess</code> jadi <code>htaccess-mati</code>, lalu muat ulang.
       Kalau jadi jalan, penyebabnya ada di berkas itu.<br>
    2. Buka <b>cPanel › Metrics › Errors</b>, atau berkas <code>error_log</code> di folder
       aplikasi. Di sanalah pesan aslinya tercatat.<br><br>
    <b>HAPUS berkas cek_sistem.php ini setelah aplikasi berjalan.</b> Ia menyebut versi PHP,
    nama basis data, dan jalur berkas — keterangan yang berguna bagi orang yang ingin menyerang.
  </div>
</div>
</body>
</html>
