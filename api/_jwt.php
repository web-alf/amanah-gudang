<?php
/**
 * AMANAH REST API — JWT sederhana tanpa library eksternal.
 * Menggunakan HMAC-SHA256. Tidak butuh Composer.
 */

/* =====================================================================
   KUNCI TANDA TANGAN
   =====================================================================
   Dibaca berurutan dari:
     1. variabel lingkungan AMANAH_JWT_SECRET
     2. $JWT_SECRET di config.local.php (dimuat config.php)
     3. berkas sesi/.kunci_jwt — DIBUAT OTOMATIS dengan 32 byte acak bila
        keduanya kosong, lalu dipakai terus

   Versi sebelumnya jatuh ke sebuah string tetap yang tertulis di berkas
   ini. Berkas ini ikut terunggah ke setiap hosting, jadi siapa pun yang
   pernah membaca kodenya bisa menempa token super_admin untuk instalasi
   mana pun yang lupa mengisi variabel lingkungannya — dan hampir semua
   instalasi lupa. Kunci acak per server yang dibuat sendiri menutup itu
   tanpa menuntut satu langkah pun dari pemasangnya.

   Folder sesi/ dipilih karena sudah tertutup dari peramban (auth.php
   menulis .htaccess-nya) dan sudah pasti bisa ditulis PHP. Bila berkasnya
   terhapus, kunci baru dibuat dan semua ponsel harus masuk ulang — itu
   akibat yang terlihat, bukan lubang yang tersembunyi.
   ===================================================================== */
function _jwt_kunci(): string {
    $k = (string) getenv('AMANAH_JWT_SECRET');
    if ($k === '' && isset($GLOBALS['JWT_SECRET'])) { $k = (string) $GLOBALS['JWT_SECRET']; }
    if ($k !== '') { return $k; }

    $dir    = dirname(__DIR__) . '/sesi';
    $berkas = $dir . '/.kunci_jwt';
    if (is_file($berkas)) {
        $k = trim((string) @file_get_contents($berkas));
        if (strlen($k) >= 32) { return $k; }
    }
    if (!is_dir($dir)) { @mkdir($dir, 0700, true); }
    $penjaga = $dir . '/.htaccess';
    if (is_dir($dir) && !is_file($penjaga)) {
        @file_put_contents($penjaga,
            "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
          . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }
    $k = bin2hex(random_bytes(32));
    if (@file_put_contents($berkas, $k, LOCK_EX) === false) {
        error_log('[AMANAH API] tidak bisa menulis sesi/.kunci_jwt — isi $JWT_SECRET di config.local.php');
        http_response_code(503);
        echo json_encode(['ok' => false, 'error' => 'Kunci token belum dikonfigurasi di server.']);
        exit;
    }
    @chmod($berkas, 0600);
    return $k;
}

define('JWT_SECRET', _jwt_kunci());
define('JWT_TTL',    60 * 60 * 24 * 7); // 7 hari — peran & status akun tetap diperiksa ulang tiap permintaan (lihat auth_required)

function _base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function _base64url_decode(string $data): string {
    return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
}

/** Buat JWT token dari payload array. */
function jwt_encode(array $payload): string {
    $payload['iat'] = time();
    $payload['exp'] = time() + JWT_TTL;

    $header  = _base64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $body    = _base64url_encode(json_encode($payload));
    $sig     = _base64url_encode(hash_hmac('sha256', "$header.$body", JWT_SECRET, true));

    return "$header.$body.$sig";
}

/**
 * Decode dan verifikasi JWT. Kembalikan payload array, atau null bila tidak valid.
 */
function jwt_decode(string $token): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) { return null; }

    [$header, $body, $sig] = $parts;
    $validSig = _base64url_encode(hash_hmac('sha256', "$header.$body", JWT_SECRET, true));

    // Perbandingan timing-safe
    if (!hash_equals($validSig, $sig)) { return null; }

    $payload = json_decode(_base64url_decode($body), true);
    if (!is_array($payload)) { return null; }

    // Periksa kedaluwarsa
    if (isset($payload['exp']) && $payload['exp'] < time()) { return null; }

    return $payload;
}
