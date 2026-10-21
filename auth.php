<?php
/**
 * AMANAH — Aplikasi Manajemen Amanah & Hibah
 * auth.php — helper autentikasi & sesi.
 */

/* =====================================================================
   UMUR SESI — mengapa dulu "keluar sendiri"
   =====================================================================
   Batas menganggur per akun sudah lama benar, dan penjaganya di bawah
   berjalan sebagaimana mestinya. Yang membuat orang tetap terlempar
   keluar bukan logika itu, melainkan TIGA setelan PHP di luarnya —
   masing-masing cukup untuk membatalkannya sendiri:

     1. session.gc_maxlifetime bawaannya 1440 detik (24 menit).
        Pemungut sampah PHP membuang BERKAS sesinya sesudah itu. Akun
        yang disetel menganggur 30 hari tetap kehilangan datanya dalam
        24 menit, dan halaman berikutnya membacanya sebagai belum login.
        Inilah penyebab utama "baru ditinggal makan, sudah keluar".

     2. lifetime cookie 0 berarti cookie mati saat browser ditutup.
        Sekali tutup tab, sesinya hilang berapa pun ambang menganggurnya.

     3. save_path bersama. Di hosting bersama, satu direktori sesi
        dipakai banyak situs, dan pemungut sampah situs LAIN memindai
        direktori yang sama memakai gc_maxlifetime-nya sendiri — yang
        biasanya pendek. Berkas sesi kita ikut terhapus meski setelan
        kita sudah benar. Ini yang paling sulit ditebak, karena mustahil
        direproduksi di komputer sendiri.

   Ketiganya diperbaiki di bawah. Yang MENENTUKAN kapan orang keluar
   tetap satu: last_activity + idle_menit milik akunnya. Setelan di sini
   hanya memastikan datanya masih ada saat penjaga itu memeriksanya.
   ===================================================================== */

/** Ambang menganggur terpanjang yang bisa dipilih — 30 hari. */
const SESI_UMUR_MAKS = 43200 * 60;

/**
 * Apakah permintaan ini datang lewat HTTPS — termasuk lewat proksi TLS.
 *
 * Hosting memakai LiteSpeed di belakang proksi: di sana $_SERVER['HTTPS']
 * sering kosong walaupun pengunjung datang lewat https, dan yang benar ada
 * di X-Forwarded-Proto. url_penuh() di _lib.php sudah lama tahu ini; cookie
 * sesi belum — sehingga flag Secure-nya tidak pernah terpasang di produksi
 * dan cookie login ikut terkirim bila ada satu saja tautan http.
 */
function koneksi_https(): bool {
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        return strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0])) === 'https';
    }
    if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') { return true; }
    return (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

/* =====================================================================
   HEADER KEAMANAN — dikirim dari PHP, bukan hanya .htaccess
   =====================================================================
   .htaccess sudah memasangnya lewat mod_headers, tapi modul itu tidak
   selalu ada dan bila tidak ada, arahannya dilewati DIAM-DIAM. Dari sini
   header terkirim di setiap hosting tanpa syarat.

   CSP-nya sengaja masih mengizinkan skrip dan gaya sebaris ('unsafe-inline'):
   aplikasi ini memakai onclick= dan <style> di banyak halaman, dan
   melarangnya sekarang berarti separuh tombol berhenti bekerja. Yang
   sudah ditutup: skrip dari domain lain, <object>/<embed>, <base>
   yang dibelokkan, formulir yang mengirim ke luar, dan penyematan
   halaman ini di situs lain. Itu menutup kelas serangan yang paling umum
   (memuat skrip pencuri sesi dari luar) tanpa menyentuh satu halaman pun.
   ===================================================================== */
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(self), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; "
         . "style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; "
         . "connect-src 'self'; media-src 'self' blob:; worker-src 'self' blob:; "
         . "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
    if (koneksi_https()) {
        // Setahun, tanpa includeSubDomains: subdomain hosting (cpanel., mail.)
        // bukan urusan aplikasi ini dan belum tentu punya sertifikat.
        header('Strict-Transport-Security: max-age=31536000');
    }
    header_remove('X-Powered-By');
}

if (session_status() === PHP_SESSION_NONE) {

    /* ---------------------------------------------------------------
       (3) Direktori sesi milik aplikasi ini sendiri.

       Dicoba, TIDAK dipaksa. Kalau direktorinya gagal dibuat atau tidak
       bisa ditulis, kita tetap memakai direktori bawaan server —
       session_start() yang gagal berarti seluruh aplikasi mati, dan itu
       jauh lebih buruk daripada sesi yang kadang terhapus lebih cepat.
       --------------------------------------------------------------- */
    $dirSesi = __DIR__ . '/sesi';
    if (!is_dir($dirSesi)) { @mkdir($dirSesi, 0700, true); }
    if (is_dir($dirSesi) && is_writable($dirSesi)) {
        /* Direktorinya berada di dalam akar web, jadi harus ditutup dari
           peramban. Berkas sesi memuat isi $_SESSION apa adanya — id
           pengguna, peran, dan token CSRF. */
        $penjaga = $dirSesi . '/.htaccess';
        if (!is_file($penjaga)) {
            @file_put_contents($penjaga,
                "# Berkas sesi memuat data login. Jangan pernah bisa diminta lewat HTTP.\n"
              . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
              . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
        }
        session_save_path($dirSesi);
    }

    // (1) Jangan biarkan PHP membuang berkas sesi lebih cepat daripada
    //     ambang menganggur terpanjang yang bisa dipilih pengguna.
    ini_set('session.gc_maxlifetime', (string) SESI_UMUR_MAKS);

    /* ---------------------------------------------------------------
       (2) Cookie berumur, bukan cookie sesi peramban.

       Umurnya panjang DENGAN SENGAJA, dan itu bukan pelonggaran
       keamanan: yang memutuskan sesi masih sah tetap last_activity +
       idle_menit di server. Cookie yang mati lebih dulu hanya membuat
       orang keluar sebelum ambangnya tercapai — tanpa satu pun
       penjelasan yang bisa mereka lihat.

       Yang TIDAK mencentang "Ingat saya" mendapat cookie tanpa umur
       (mati saat peramban ditutup); itu disetel ulang di login.php,
       karena pilihannya baru diketahui di sana.
       --------------------------------------------------------------- */
    session_set_cookie_params([
        'lifetime' => SESI_UMUR_MAKS,
        'path'     => '/',
        'domain'   => '',
        'secure'   => koneksi_https(),             // sadar proksi — lihat koneksi_https()
        'httponly' => true,                        // JavaScript tidak bisa membaca cookie sesi
        'samesite' => 'Lax',                       // meredam CSRF lintas situs
    ]);
    session_name('AMANAHSESS');
    session_start();
}

// ---------------------------------------------------------------------
// Auto-logout setelah sesi menganggur.
//
// Batasnya kini MILIK MASING-MASING AKUN, bukan satu angka untuk semua:
// petugas gudang yang bekerja dengan HP di tangan tidak perlu masuk
// berulang kali, sementara akun yang memegang data sensitif tetap bisa
// dibuat pendek. Nilainya disimpan di sesi saat login dan disegarkan
// dari basis data pada setiap pemuatan halaman (lihat penjaga sesi di
// _lib.php), jadi perubahan oleh Super Admin berlaku hampir seketika.
//
// 30 menit tetap jadi bawaan bila tidak diatur — yang panjang harus
// dipilih dengan sengaja, bukan kebetulan.
// ---------------------------------------------------------------------
const IDLE_TIMEOUT      = 1800;   // detik — dipakai bila akun belum punya setelan
const IDLE_MENIT_BAWAAN = 30;

/** Ambang menganggur akun yang sedang login, dalam detik. */
function idle_detik(): int {
    $menit = (int) ($_SESSION['idle_menit'] ?? IDLE_MENIT_BAWAAN);
    if ($menit < 1) { $menit = IDLE_MENIT_BAWAAN; }
    return $menit * 60;
}

/**
 * Perpanjang cookie sesi pada setiap permintaan.
 *
 * Tanpa ini, cookie berumur 30 hari yang diterbitkan saat login akan
 * mati 30 hari SESUDAH LOGIN — bukan 30 hari sesudah aktivitas terakhir.
 * Petugas yang memakai aplikasi setiap hari tetap terlempar keluar di
 * hari ke-31, dan itu terbaca sebagai keluar sendiri tanpa sebab.
 *
 * Yang TIDAK mencentang "Ingat saya" mendapat umur 0 — cookie peramban,
 * mati saat peramban ditutup. Pilihannya disimpan di sesi supaya
 * bertahan di seluruh permintaan berikutnya.
 */
function segarkan_cookie_sesi(): void {
    if (headers_sent()) { return; }   // sudah ada keluaran; tidak bisa dikirim ulang
    $ingat = !empty($_SESSION['ingat']);
    $p = session_get_cookie_params();
    setcookie(session_name(), session_id(), [
        'expires'  => $ingat ? time() + SESI_UMUR_MAKS : 0,
        'path'     => $p['path'] ?: '/',
        'domain'   => $p['domain'] ?? '',
        'secure'   => koneksi_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

if (!empty($_SESSION['user_id'])) {
    $now = time();
    if (isset($_SESSION['last_activity']) && ($now - $_SESSION['last_activity']) > idle_detik()) {
        $_SESSION = [];
        session_destroy();
        header('Location: login.php?timeout=1');
        exit;
    }
    $_SESSION['last_activity'] = $now;
    segarkan_cookie_sesi();
}

/** Wajib login; jika belum, lempar ke halaman login. */
function require_login(): void {
    if (empty($_SESSION['user_id'])) {
        /* Alamat yang diminta ikut dibawa (?ke=) supaya sesudah login orang
           kembali ke halaman itu — mis. detail kardus hasil pindai QR. Hanya
           permintaan GET dan hanya jalur relatif (login.php memeriksanya lagi). */
        $ke = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
            $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
            $ke = (string) (basename(parse_url($uri, PHP_URL_PATH) ?: '') . (($q = parse_url($uri, PHP_URL_QUERY)) ? '?' . $q : ''));
            if ($ke === '' || $ke === 'login.php' || strpos($ke, 'login') === 0) { $ke = ''; }
        }
        header('Location: login.php' . ($ke !== '' ? '?ke=' . rawurlencode($ke) : ''));
        exit;
    }
}

/** Jalur kembali sesudah login yang aman: nama berkas .php relatif + query, tanpa skema/host. */
function login_kembali_aman(?string $ke): string {
    $ke = trim((string) $ke);
    if ($ke === '' || !preg_match('~^[A-Za-z0-9_]+\.php(\?[A-Za-z0-9_=&%.\-+]*)?$~', $ke) || strpos($ke, 'login') === 0) { return ''; }
    return $ke;
}

/** Batasi akses berdasarkan role (mis. ['super_admin','admin_gudang']). */
function require_role(array $roles): void {
    require_login();
    if (!in_array($_SESSION['role'] ?? '', $roles, true)) {
        http_response_code(403);
        die('<p style="font-family:sans-serif;padding:30px">403 — Anda tidak memiliki hak akses untuk halaman ini. '
          . '<a href="dashboard.php">Kembali</a></p>');
    }
}

function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

/** Token CSRF untuk form. */
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function check_csrf(?string $token): bool {
    return !empty($_SESSION['csrf']) && is_string($token) && hash_equals($_SESSION['csrf'], $token);
}

/** Hentikan eksekusi bila token CSRF tidak valid. */
function wajib_csrf(?string $token): void {
    if (!check_csrf($token)) {
        http_response_code(400);
        die('<p style="font-family:sans-serif;padding:30px">Sesi tidak valid atau sudah kedaluwarsa. '
          . 'Silakan <a href="javascript:history.back()">kembali</a> dan muat ulang halaman.</p>');
    }
}

/** Escape output HTML. */
function e(?string $s): string {
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}
