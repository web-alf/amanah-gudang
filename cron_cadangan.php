<?php
/**
 * AMANAH — Pemicu cadangan otomatis.
 *
 * Dua pemanggil, keduanya mengikuti jadwal yang sama (cadangan_perlu_otomatis):
 *   1. Cron hosting:  cron_cadangan.php?kunci=KUNCI   (cPanel: Once Per Hour)
 *      Kunci dibuat di halaman Cadangan Data; tanpa kunci yang cocok, ditolak.
 *      Tambahkan &paksa=1 bila ingin membuat cadangan setiap dipanggil.
 *   2. Halaman aplikasi (lihat _footer.php): Super Admin yang sedang masuk
 *      memanggil ?otomatis=1 di latar bila sudah jatuh tempo.
 * Memanggilnya berkali-kali tidak apa-apa: yang belum jatuh tempo dilewati.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/_cadangan.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$kunci = (string) ($_GET['kunci'] ?? '');
$cara  = '';
if ($kunci !== '' && pengaturan($pdo, 'cadangan_kunci') !== '' && hash_equals(pengaturan($pdo, 'cadangan_kunci'), $kunci)) {
    $cara = 'cron';
} elseif (isset($_GET['otomatis']) && !empty($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'super_admin') {
    $cara = 'otomatis';
} else {
    http_response_code(403);
    echo json_encode(['ok' => false, 'pesan' => 'Kunci tidak cocok.']); exit;
}

$paksa = $cara === 'cron' && isset($_GET['paksa']);
if (!$paksa) {
    if (pengaturan($pdo, 'cadangan_otomatis') !== '1') { echo json_encode(['ok' => true, 'dibuat' => false, 'pesan' => 'Cadangan otomatis tidak menyala.']); exit; }
    if (!cadangan_perlu_otomatis($pdo)) {
        $j = cadangan_jadwal($pdo);
        echo json_encode(['ok' => true, 'dibuat' => false, 'pesan' => 'Belum jatuh tempo (' . cadangan_teks_jadwal($j) . '; terakhir ' . pengaturan($pdo, 'cadangan_terakhir') . ').'], JSON_UNESCAPED_UNICODE); exit;
    }
}

try {
    $h = cadangan_buat($pdo, $cara, $cara === 'otomatis' ? (int) $_SESSION['user_id'] : null);
    catat_aktivitas($pdo, 'export', 'cadangan', null, "Cadangan $cara {$h['berkas']}" . ($h['drive_pesan'] ? ' — ' . $h['drive_pesan'] : ''));
    echo json_encode(['ok' => true, 'dibuat' => true, 'berkas' => $h['berkas'], 'ukuran' => $h['ukuran'], 'drive' => $h['drive_pesan']], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[AMANAH] cron cadangan: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'pesan' => 'Cadangan gagal: ' . mb_substr($e->getMessage(), 0, 200)], JSON_UNESCAPED_UNICODE);
}
