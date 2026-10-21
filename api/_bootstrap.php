<?php
/**
 * AMANAH REST API — Bootstrap
 * Dimuat pertama oleh setiap endpoint API.
 */

// CORS — izinkan Flutter app dari mana saja
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Matikan output HTML error — API harus selalu JSON
ini_set('display_errors', '0');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/_jwt.php';

/** Kembalikan respons JSON dan hentikan eksekusi. */
function resp(int $kode, array $data): void {
    http_response_code($kode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ok(array $data = []): void   { resp(200, array_merge(['ok' => true],  $data)); }
function err(string $pesan, int $kode = 400): void { resp($kode, ['ok' => false, 'error' => $pesan]); }

/** Baca body JSON dari request. */
function body(): array {
    $raw = file_get_contents('php://input');
    return $raw ? (json_decode($raw, true) ?? []) : [];
}

/** Verifikasi JWT dari header Authorization dan kembalikan payload. */
function auth_required(): array {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!$h && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $h = $headers['Authorization'] ?? '';
    }
    if (!preg_match('/^Bearer\s+(.+)$/i', $h, $m)) {
        err('Token tidak ditemukan. Harap login terlebih dahulu.', 401);
    }
    $payload = jwt_decode($m[1]);
    if (!$payload) {
        err('Token tidak valid atau sudah kedaluwarsa.', 401);
    }

    /* -----------------------------------------------------------------
       PERAN & STATUS AKUN DIBACA ULANG DARI BASIS DATA
       -----------------------------------------------------------------
       Token berlaku tujuh hari dan memuat peran, tingkat, gudang, serta
       supplier pemiliknya PADA SAAT MASUK. Sebelumnya semua endpoint
       mempercayai isi itu apa adanya: akun yang dinonaktifkan Super Admin
       pagi ini tetap bekerja sampai tokennya habis, dan admin yang
       diturunkan jadi staff tetap membawa wewenang lamanya di ponsel.

       Satu kueri berkunci primer per permintaan menutup itu. Yang
       dibawa token kini hanya IDENTITAS (user_id); wewenangnya selalu
       keadaan hari ini. Pola yang sama dengan penjaga sesi di _lib.php
       untuk web.
       ----------------------------------------------------------------- */
    global $pdo;
    $uid = (int) ($payload['user_id'] ?? 0);
    if ($uid <= 0) { err('Token tidak valid.', 401); }
    try {
        $st = $pdo->prepare(
            "SELECT u.is_active, u.deleted_at, u.gudang_id, u.supplier_id, u.tingkat,
                    u.email, r.nama AS role
               FROM users u LEFT JOIN roles r ON r.id = u.role_id
              WHERE u.id = ? LIMIT 1"
        );
        $st->execute([$uid]);
        $akun = $st->fetch();
    } catch (Throwable $e) {
        error_log('[AMANAH API] auth segarkan akun: ' . $e->getMessage());
        err('Terjadi kesalahan server.', 500);
    }
    if (!$akun || $akun['deleted_at'] !== null) {
        err('Akun ini sudah tidak ada. Silakan masuk kembali.', 401);
    }
    if ((int) $akun['is_active'] !== 1) {
        err('Akun ini sedang dinonaktifkan. Hubungi Super Admin.', 401);
    }
    $payload['email']       = $akun['email'];
    $payload['role']        = (string) $akun['role'];
    $payload['tingkat']     = ($akun['tingkat'] ?? '') === 'manager' ? 'manager' : 'staff';
    $payload['gudang_id']   = $akun['gudang_id']   !== null ? (int) $akun['gudang_id']   : null;
    $payload['supplier_id'] = $akun['supplier_id'] !== null ? (int) $akun['supplier_id'] : null;
    return $payload;
}

/** Hanya izinkan method tertentu; kembalikan 405 bila tidak cocok. */
function only(string ...$methods): void {
    if (!in_array($_SERVER['REQUEST_METHOD'], $methods, true)) {
        err('Method tidak diizinkan.', 405);
    }
}

/** Pagination helper — kembalikan [limit, offset]. */
function paginate(int $defaultLimit = 30): array {
    $limit  = max(1, min(100, (int) ($_GET['limit'] ?? $defaultLimit)));
    $page   = max(1, (int) ($_GET['page'] ?? 1));
    $offset = ($page - 1) * $limit;
    return [$limit, $offset, $page];
}

/**
 * URL PENUH sebuah foto di folder unggahan, atau null bila berkasnya tidak ada.
 *
 * Aplikasi Android tidak punya "halaman sekarang" untuk acuan jalur relatif,
 * dan aplikasi ini dipasang di /Gudang secara lokal tapi di akar domain di
 * hosting — jadi alamatnya disusun dari SCRIPT_NAME endpoint yang memanggil:
 * /Gudang/api/barang.php → /Gudang. Cap waktu berkas jadi pemecah cache.
 *
 * Dulu disalin di barang.php, gudang.php, dan rak.php (tiga salinan yang
 * sudah mulai berbeda: satu di antaranya tidak sadar proksi TLS). Sekarang
 * satu, di sini, dan sadar X-Forwarded-Proto seperti url_penuh() di web.
 */
function foto_url_penuh(?string $nama): ?string {
    $nama = basename(trim((string) $nama));
    if ($nama === '') { return null; }
    $dirUnggah = defined('DIR_UNGGAHAN') ? DIR_UNGGAHAN : 'unggahan';
    $berkas = dirname(__DIR__) . '/' . $dirUnggah . '/' . $nama;
    if (!is_file($berkas)) { return null; }

    $proto = 'http';
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $proto = strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0])) === 'https' ? 'https' : 'http';
    } elseif (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
        $proto = 'https';
    }
    $host = preg_replace('/[^A-Za-z0-9.\-:]/', '', (string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost'));
    $dirApi = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    $dasar  = rtrim(str_replace('\\', '/', dirname($dirApi)), '/');
    if ($dasar === '.' || $dasar === '/') { $dasar = ''; }
    return $proto . '://' . $host . $dasar . '/' . $dirUnggah . '/' . rawurlencode($nama) . '?v=' . filemtime($berkas);
}

/**
 * Padanan pesan_runtime() di _lib.php untuk API: PDOException adalah turunan
 * RuntimeException, jadi `catch (RuntimeException $e) { err($e->getMessage()); }`
 * ikut mengirim galat SQL mentah ke klien. Galat basis data dicatat dan
 * dibalas dengan kalimat umum; pesan untuk pengguna diteruskan apa adanya.
 */
function pesan_api(Throwable $e): string {
    if ($e instanceof PDOException) {
        $di = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        error_log('[AMANAH API] ' . $di . ': ' . $e->getMessage());
        return 'Terjadi kesalahan server. Coba lagi; bila berulang, hubungi admin.';
    }
    return $e->getMessage();
}
