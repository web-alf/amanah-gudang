<?php
/**
 * GET /api/versi_aplikasi.php — versi aplikasi Android terbaru yang tersedia.
 *
 * Dipanggil aplikasi setiap kali dibuka. Bila `versi_kode` di sini lebih
 * besar dari versionCode yang terpasang, aplikasi menampilkan dialog
 * "Versi baru tersedia" dengan tombol Unduh. APK-nya dibagikan lewat
 * server ini sendiri (folder unduhan/), bukan Play Store — jadi tanpa
 * pengecekan ini tidak ada cara memberi tahu ponsel bahwa ada versi baru.
 *
 * TANPA autentikasi, dan itu disengaja: pengecekan harus bisa berjalan
 * sebelum masuk, dan yang dijawab hanya nomor versi, catatan, serta
 * alamat unduh — tidak ada data lembaga di dalamnya.
 *
 * Nilainya diatur Super Admin lewat Pengaturan › Pembaruan Aplikasi
 * (pengaturan_aplikasi.php) dan disimpan di tabel `pengaturan`:
 *   apk_versi_kode   versionCode terbaru (angka; 0 = belum diatur)
 *   apk_versi_nama   versionName untuk ditampilkan, mis. 1.5.0
 *   apk_catatan      apa yang baru, beberapa baris
 *   apk_wajib        1 = tidak bisa ditunda (aplikasi lama tidak boleh dipakai)
 *   apk_url          alamat unduh khusus; kosong = unduhan/Amanah.apk di server ini
 *
 * Respons:
 *   { ok, versi_kode, versi_nama, catatan, wajib, url, ukuran, diperbarui }
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../_lib.php';
only('GET');

$kode  = (int) pengaturan($pdo, 'apk_versi_kode', '0');
$nama  = pengaturan($pdo, 'apk_versi_nama', '');
$cat   = pengaturan($pdo, 'apk_catatan', '');
$wajib = pengaturan($pdo, 'apk_wajib', '0') === '1';
$url   = trim(pengaturan($pdo, 'apk_url', ''));

$berkas  = dirname(__DIR__) . '/unduhan/Amanah.apk';
$ukuran  = null;
$mtime   = null;
if ($url === '' && is_file($berkas)) {
    /* Alamat absolut, disusun seperti foto_url_penuh(): /Gudang/api/... di
       lokal, akar domain di hosting. */
    $proto = 'http';
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $proto = strtolower(trim(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0])) === 'https' ? 'https' : 'http';
    } elseif (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
        $proto = 'https';
    }
    $host   = preg_replace('/[^A-Za-z0-9.\-:]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $dirApi = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    $dasar  = rtrim(str_replace('\\', '/', dirname($dirApi)), '/');
    if ($dasar === '.' || $dasar === '/') { $dasar = ''; }
    $ukuran = filesize($berkas);
    $mtime  = filemtime($berkas);
    $url    = $proto . '://' . $host . $dasar . '/unduhan/Amanah.apk?v=' . $mtime;
}

ok([
    'versi_kode' => $kode,
    'versi_nama' => $nama,
    'catatan'    => $cat,
    'wajib'      => $wajib,
    'url'        => $url,
    'ukuran'     => $ukuran,
    'diperbarui' => $mtime ? date('Y-m-d H:i:s', $mtime) : null,
]);
