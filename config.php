<?php
/**
 * AMANAH — Konfigurasi koneksi database
 * Sistem manajemen gudang & rantai pasok
 *
 * Berkas ini TIDAK memuat satu pun kredensial. Semua rahasia — kredensial
 * basis data, kunci JWT, kunci pemeriksa sistem — dibaca dari
 * `config.local.php` di folder yang sama, yang:
 *
 *   - berbeda di tiap server (XAMPP di komputer sendiri, hosting, cadangan)
 *   - TIDAK ikut diunggah, dan TIDAK ikut ke repositori
 *   - diblokir dari peramban lewat .htaccess
 *
 * Contoh isinya ada di `config.local.php.contoh`. Salin, ganti namanya,
 * isi nilainya.
 *
 * ---------------------------------------------------------------------
 * KENAPA TIDAK ADA NILAI BAWAAN
 * ---------------------------------------------------------------------
 * Versi sebelumnya memuat `root` tanpa sandi sebagai bawaan, dengan niat
 * memudahkan XAMPP. Akibatnya: berkas berisi kredensial ikut terunggah ke
 * hosting, dan hosting yang config.local.php-nya hilang atau salah nama
 * diam-diam mencoba masuk sebagai root — bukan gagal dengan pesan yang
 * menyebut sebabnya. Sekarang tanpa config.local.php aplikasi berhenti
 * di sini dan mengatakan persis apa yang kurang.
 */

$DB_HOST = '';
$DB_NAME = '';
$DB_USER = '';
$DB_PASS = '';
$DB_PORT = '3306';

/* Rahasia lain yang boleh diisi config.local.php. Dideklarasikan di sini
   supaya pemakainya cukup memeriksa kosong/tidak, bukan ada/tidak. */
$JWT_SECRET = '';   // kunci tanda tangan token aplikasi Android
$KUNCI_CEK  = '';   // kunci halaman cek_sistem.php; kosong = halaman itu mati

if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}

if ($DB_NAME === '' || $DB_USER === '') {
    error_log('[AMANAH] config.local.php tidak ada atau belum berisi $DB_NAME/$DB_USER');
    http_response_code(503);
    die(
        '<div style="font-family:Segoe UI,system-ui,sans-serif;max-width:620px;margin:60px auto;'
      . 'padding:26px;border:1px solid #fecaca;background:#fef2f2;border-radius:14px;'
      . 'line-height:1.7;color:#7f1d1d">'
      . '<h2 style="margin-bottom:8px">Berkas <code>config.local.php</code> belum ada</h2>'
      . '<p>Aplikasi ini membaca kredensial basis data dari berkas itu, dan berkasnya '
      . 'tidak ditemukan atau belum berisi <code>$DB_NAME</code> dan <code>$DB_USER</code>.</p>'
      . '<ol style="margin:10px 0 10px 20px">'
      . '<li>Salin <code>config.local.php.contoh</code> menjadi <code>config.local.php</code> '
      . 'di folder yang sama dengan <code>config.php</code></li>'
      . '<li>Isi nama basis data, pengguna, dan sandinya</li>'
      . '<li>Muat ulang halaman ini</li>'
      . '</ol>'
      . '<p style="font-size:12px;color:#991b1b">Di komputer sendiri (XAMPP) biasanya '
      . '<code>gudang_bayi</code> / <code>root</code> tanpa sandi.</p>'
      . '</div>'
    );
}

// ---------------------------------------------------------------------
// KOMPATIBILITAS PHP 7
//
// Hosting berjalan di PHP 7, sementara aplikasi ini ditulis dengan
// beberapa fungsi bawaan PHP 8. Fungsi bisa ditambal di sini sekali
// untuk seluruh aplikasi — jauh lebih baik daripada menyunting tiap
// pemanggilannya di belasan berkas, dan otomatis tidak terpakai lagi
// begitu servernya naik ke PHP 8.
//
// Catatan: `match` TIDAK bisa ditambal seperti ini. Ia sintaks, bukan
// fungsi, jadi ia harus benar-benar ditulis ulang di tiap tempatnya.
// ---------------------------------------------------------------------
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool {
        // PHP 8 menganggap string kosong selalu terkandung — perilaku itu
        // ditiru di sini supaya hasilnya tidak berbeda antar versi.
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return $needle === '' || strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool {
        return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
    }
}

// Zona waktu — supaya tanggal transaksi & surat jalan sesuai WIB.
date_default_timezone_set('Asia/Jakarta');

// Error dicatat ke log, tidak ditampilkan ke pengguna.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

/**
 * Ekstensi PDO diperiksa SEBELUM dipakai.
 *
 * Tanpa pemeriksaan ini, `new PDO(...)` pada server yang ekstensinya belum
 * aktif melempar Error — bukan PDOException — sehingga tidak tertangkap
 * oleh catch di bawah. Yang dilihat pengguna cuma HTTP 500 kosong tanpa
 * petunjuk, padahal penyebabnya satu centang di panel hosting.
 *
 * Ini bukan kasus hipotetis: itulah yang terjadi saat aplikasi ini pertama
 * dipasang di hosting bersama.
 */
if (!class_exists('PDO')) {
    error_log('[AMANAH] ekstensi PDO tidak aktif di PHP ' . PHP_VERSION);
    http_response_code(503);
    die(
        '<div style="font-family:Segoe UI,system-ui,sans-serif;max-width:620px;margin:60px auto;'
      . 'padding:26px;border:1px solid #fecaca;background:#fef2f2;border-radius:14px;'
      . 'line-height:1.7;color:#7f1d1d">'
      . '<h2 style="margin-bottom:8px">Ekstensi PHP <code>pdo_mysql</code> belum aktif</h2>'
      . '<p>Aplikasi ini memakai PDO untuk seluruh akses basis data, dan ekstensinya '
      . 'tidak tersedia di PHP ' . htmlspecialchars(PHP_VERSION) . ' pada server ini.</p>'
      . '<p><b>Cara mengaktifkan di cPanel:</b></p>'
      . '<ol style="margin:10px 0 10px 20px">'
      . '<li>Buka <b>Select PHP Version</b></li>'
      . '<li>Pastikan versi yang dipilih adalah yang sedang dipakai domain ini</li>'
      . '<li>Masuk tab <b>Extensions</b>, centang <code>pdo</code>, <code>pdo_mysql</code>, '
      . '<code>mysqlnd</code>, <code>mbstring</code>, dan <code>json</code></li>'
      . '<li>Simpan, lalu muat ulang halaman ini</li>'
      . '</ol>'
      . '<p style="font-size:12px;color:#991b1b">Kode aplikasi tidak perlu diubah — '
      . 'ia berjalan di PHP 7.4 maupun 8.x.</p>'
      . '</div>'
    );
}

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};port={$DB_PORT};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
    // Selaraskan zona waktu MySQL dengan PHP agar NOW() konsisten.
    $pdo->exec("SET time_zone = '+07:00'");
} catch (PDOException $e) {
    error_log('[AMANAH] koneksi database gagal: ' . $e->getMessage());
    http_response_code(503);
    die(
        '<div style="font-family:Segoe UI,sans-serif;max-width:560px;margin:60px auto;padding:26px;'
      . 'border:1px solid #fecaca;background:#fef2f2;border-radius:14px;line-height:1.7;color:#7f1d1d">'
      . '<h2 style="margin-bottom:8px">Tidak dapat terhubung ke database</h2>'
      . '<p>Ekstensi PDO sudah aktif, jadi yang bermasalah kredensial atau nama '
      . 'basis datanya. Periksa hal berikut:</p>'
      . '<ol style="margin:10px 0 10px 20px">'
      . '<li>Berkas <code>config.local.php</code> ada, dan berisi <code>$DB_NAME</code>, '
      . '<code>$DB_USER</code>, serta <code>$DB_PASS</code> milik server ini</li>'
      . '<li>Di hosting, nama basis data &amp; pengguna biasanya diawali nama akun cPanel '
      . '(mis. <code>akun_gudang</code>) — bukan nama yang Anda tulis sendiri</li>'
      . '<li>Pengguna basis data sudah <b>ditambahkan ke</b> basis datanya, dengan hak penuh</li>'
      . '<li><code>instal_database.sql</code> sudah diimpor ke basis data itu</li>'
      . '<li>Di komputer sendiri: MySQL sudah dinyalakan di XAMPP Control Panel</li>'
      . '</ol>'
      . '<p style="font-size:12px;color:#991b1b">Detail teknisnya dicatat di error log server — '
      . 'di hosting: <b>cPanel &rsaquo; Metrics &rsaquo; Errors</b>, atau berkas '
      . '<code>error_log</code> di folder aplikasi.</p>'
      . '</div>'
    );
}
