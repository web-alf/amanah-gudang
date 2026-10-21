<?php
/**
 * AMANAH — Penarikan pesanan marketplace terjadwal
 *
 * =====================================================================
 * CARA MEMASANG
 * =====================================================================
 * Berkas ini HANYA boleh dijalankan dari command line, bukan dari
 * peramban — lihat penjagaan di bawah. Di cPanel, buka Cron Jobs lalu
 * pasang (setiap 15 menit):
 *
 *   *_/15 * * * * /usr/local/bin/php /home/USER/public_html/cron_marketplace.php
 *   (hapus garis bawah pada *_/15 — ia hanya ada supaya komentar PHP ini
 *    tidak tertutup lebih awal)
 *
 * Di Windows/XAMPP, pakai Task Scheduler:
 *   C:\xampp\php\php.exe C:\xampp\htdocs\Gudang\cron_marketplace.php
 *
 * KENAPA 15 MENIT, BUKAN 1 MENIT:
 * Shopee membatasi jumlah panggilan per satuan waktu. Menarik terlalu
 * sering akan membuat panggilan ditolak, dan yang tertolak itu bisa jadi
 * panggilan yang benar-benar dibutuhkan. Lima belas menit cukup cepat
 * untuk operasional gudang, dan jauh dari batas.
 *
 * KENAPA TIDAK PAKAI WEBHOOK:
 * Shopee bisa mengirim pemberitahuan langsung, dan itu lebih cepat.
 * Tetapi webhook menuntut alamat HTTPS publik yang selalu hidup dan
 * memverifikasi tanda tangan tiap kiriman masuk. Penarikan terjadwal
 * bekerja tanpa syarat itu, jadi ia dipasang lebih dulu. Keduanya bisa
 * berdampingan nanti.
 * =====================================================================
 */

/* Hanya CLI. Kalau berkas ini bisa dipanggil lewat peramban, siapa pun
   yang tahu namanya dapat memicu penarikan berulang-ulang sampai kuota
   API Shopee habis — tanpa perlu login. */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("403 — berkas ini hanya dijalankan dari command line.\n");
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/_marketplace.php';

$mulai = microtime(true);
$hari  = 7;

// Argumen opsional: jumlah hari ke belakang. Penarikan pertama biasanya
// butuh rentang lebih panjang daripada penarikan rutin.
foreach ($argv as $a) {
    if (preg_match('/^--hari=(\d+)$/', $a, $m)) { $hari = max(1, min(60, (int) $m[1])); }
}

function keluar(string $s): void { fwrite(STDOUT, $s . PHP_EOL); }

keluar('[' . date('Y-m-d H:i:s') . '] Penarikan marketplace dimulai (rentang ' . $hari . ' hari)');

foreach (['json', 'curl'] as $ext) {
    if (!extension_loaded($ext)) {
        keluar("  BERHENTI: ekstensi PHP '$ext' tidak aktif. Integrasi tidak mungkin jalan.");
        exit(1);
    }
}

/* Semua platform sekaligus. mp_akun_aktif() menggabungkan Shopee dan
   Tokopedia, dan mp_tarik() memilih kliennya menurut kolom platform —
   jadi menambah platform ketiga nanti tidak menyentuh berkas ini. */
$akunList = mp_akun_aktif($pdo);
if (!$akunList) {
    keluar('  Tidak ada sambungan marketplace yang aktif. Selesai.');
    exit(0);
}

$total = ['baru' => 0, 'diperbarui' => 0, 'gagal' => 0];

foreach ($akunList as $akun) {
    $nama = $pdo->query("SELECT nama FROM toko WHERE id = " . (int) $akun['toko_id'])->fetchColumn();
    keluar('  → ' . $nama . ' [' . $akun['platform'] . ']');

    /* Tiap toko dibungkus try sendiri. Satu toko yang tokennya dicabut
       tidak boleh menghentikan penarikan toko-toko lain — dan justru toko
       yang bermasalah itulah yang paling sering menjadi alasan cron
       dijalankan. */
    try {
        $r = mp_tarik($pdo, $akun, $hari);
        $total['baru']       += $r['baru'];
        $total['diperbarui'] += $r['diperbarui'];
        $total['gagal']      += $r['gagal'];

        keluar(sprintf('     %d baru, %d diperbarui, %d gagal',
            $r['baru'], $r['diperbarui'], $r['gagal']));
        foreach (array_slice($r['pesan'], 0, 3) as $p) { keluar('     · ' . $p); }

        // Ringkasan per toko masuk ke jejak yang sama dengan impor CSV,
        // supaya riwayatnya bisa dibaca berurutan di satu tempat.
        $pdo->prepare(
            "INSERT INTO marketplace_sync_log
               (toko_id, platform, jenis, jml_baris, jml_sukses, jml_gagal, status, pesan)
             VALUES (?, ?, 'tarik_pesanan', ?, ?, ?, ?, ?)"
        )->execute([
            $akun['toko_id'], $akun['platform'],
            $r['baru'] + $r['diperbarui'] + $r['gagal'],
            $r['baru'] + $r['diperbarui'],
            $r['gagal'],
            $r['gagal'] === 0 ? 'sukses' : (($r['baru'] + $r['diperbarui']) > 0 ? 'sebagian' : 'gagal'),
            mb_substr(implode(' | ', $r['pesan']), 0, 2000) ?: null,
        ]);
    } catch (Throwable $e) {
        $total['gagal']++;
        keluar('     GAGAL: ' . $e->getMessage());
        error_log('[AMANAH] cron marketplace ' . $akun['toko_id'] . ': ' . $e->getMessage());
    }
}

keluar(sprintf('[%s] Selesai dalam %.1f detik — %d baru, %d diperbarui, %d gagal',
    date('Y-m-d H:i:s'), microtime(true) - $mulai,
    $total['baru'], $total['diperbarui'], $total['gagal']));

exit($total['gagal'] > 0 ? 1 : 0);
