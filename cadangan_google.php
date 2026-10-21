<?php
/**
 * AMANAH — Hubungkan akun Google untuk Cadangan Data (OAuth)
 *
 *   ?aksi=hubungkan  → ke halaman persetujuan Google (izin drive.file + email)
 *   ?code=…&state=…  → kembali dari Google: tukar kode jadi refresh token
 *
 * Redirect URI yang harus terdaftar di Google Cloud = alamat berkas ini,
 * persis seperti yang ditampilkan di halaman Cadangan Data.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/_cadangan.php';
require_role(['super_admin']);

$aksi = (string) ($_GET['aksi'] ?? '');
if ($aksi === 'hubungkan') {
    if (!gdrive_terkonfigurasi($pdo)) {
        set_flash('err', 'Isi dan simpan Client ID & Client Secret Google dulu.'); header('Location: cadangan.php'); exit;
    }
    $state = bin2hex(random_bytes(16));
    $_SESSION['google_state'] = $state;
    header('Location: ' . gdrive_url_otorisasi($pdo, $state)); exit;
}

if (isset($_GET['error'])) {
    set_flash('err', 'Google membatalkan penghubungan: ' . (string) $_GET['error'] . (isset($_GET['error_description']) ? ' — ' . (string) $_GET['error_description'] : ''));
    header('Location: cadangan.php'); exit;
}

$kode  = (string) ($_GET['code'] ?? '');
$state = (string) ($_GET['state'] ?? '');
if ($kode === '' || $state === '' || empty($_SESSION['google_state']) || !hash_equals($_SESSION['google_state'], $state)) {
    set_flash('err', 'Tautan kembali dari Google tidak sah atau kedaluwarsa. Tekan "Hubungkan akun Google" lagi.');
    header('Location: cadangan.php'); exit;
}
unset($_SESSION['google_state']);
try {
    $email = gdrive_tukar_kode($pdo, $kode);
    catat_aktivitas($pdo, 'update', 'cadangan', null, "Akun Google $email dihubungkan untuk cadangan");
    set_flash('ok', "Akun Google $email terhubung. Sekarang tekan \"Buat folder di Drive\" bila folder tujuan belum ada.");
} catch (Throwable $e) {
    set_flash('err', $e->getMessage());
}
header('Location: cadangan.php'); exit;
