<?php
/**
 * =============================================================================
 * RESET DATA OPERASIONAL — kosongkan stok dan transaksi, pertahankan master
 * =============================================================================
 *
 * YANG DIPERTAHANKAN
 *   kategori, barang (produk), gudang, lokasi_rak, supplier, toko,
 *   penerima, roles, users, user_toko, user_gudang, pengaturan,
 *   sesi_perangkat, activity_log
 *
 * YANG DIKOSONGKAN
 *   seluruh stok (batch_barang, stok, stok_supplier), seluruh transaksi
 *   (masuk, keluar, perpindahan, pengiriman, penyesuaian, pelepasan,
 *   penjualan, opname), donatur beserta donasinya, log pemindaian resi,
 *   snapshot stok harian, dan penomoran dokumen.
 *
 * -----------------------------------------------------------------------------
 * MENGAPA CLI-ONLY
 * -----------------------------------------------------------------------------
 * Berkas ini menghapus data yang tidak bisa dikembalikan. Sebagai halaman
 * web, ia hanya berjarak satu URL dari siapa pun yang tahu namanya —
 * termasuk mesin perayap. Dijalankan dari command line, ia menuntut akses
 * ke server itu sendiri.
 *
 * Ditambah SATU pagar lagi: tanpa argumen --ya, ia hanya MENGHITUNG dan
 * tidak menghapus apa pun. Melihat dulu apa yang akan hilang, baru
 * memutuskan.
 *
 *   php reset_data.php          → hanya menampilkan rencana
 *   php reset_data.php --ya     → benar-benar menghapus
 *
 * -----------------------------------------------------------------------------
 * CADANGKAN LEBIH DULU
 * -----------------------------------------------------------------------------
 *   mysqldump -u root gudang_bayi > db/arsip_sql/cadangan_YYYYMMDD.sql
 *
 * Skrip ini menolak jalan bila folder db/arsip_sql tidak ada — bukan karena ia
 * memeriksa isinya, melainkan supaya tidak ada instalasi yang bisa direset
 * tanpa tempat menaruh cadangan.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("403 — berkas ini hanya dijalankan dari command line.\n");
}

require_once __DIR__ . '/config.php';

function keluar(string $s): void { fwrite(STDOUT, $s . PHP_EOL); }
function judul(string $s): void { keluar(''); keluar('--- ' . $s); }

$sungguhan = in_array('--ya', $argv, true);

if (!is_dir(__DIR__ . '/db/arsip_sql')) {
    exit("Folder db/arsip_sql tidak ada. Buat dulu dan simpan cadangan di sana.\n");
}

/* Urutan penghapusan mengikuti ketergantungan: anak lebih dulu, induk
   kemudian. FOREIGN_KEY_CHECKS TIDAK dimatikan — kalau ada tabel yang
   terlewat, kunci asing akan menolak dan skrip berhenti dengan pesan yang
   menyebut tabelnya. Mematikan pemeriksaan akan membuat kesalahan seperti
   itu lewat diam-diam dan meninggalkan baris yatim. */
$urutan = [
    // rincian dulu, induknya menyusul
    'donasi_detail',
    'donasi',
    'transaksi_masuk_detail',
    'transaksi_masuk',
    'transaksi_keluar_detail',
    'transaksi_keluar',
    'pengiriman_supplier_detail',
    'pengiriman_supplier',
    'penjualan_detail',
    'penjualan',
    'stock_opname_detail',
    'stock_opname',
    'retur',
    'marketplace_tarikan',
    'marketplace_sync_log',

    /* Ketiganya menunjuk batch_barang, jadi HARUS sebelum batch_barang.
       perpindahan_detail juga menyimpan batch_id. */
    'pelepasan_stok',
    'penyesuaian_stok',
    'perpindahan_detail',
    'perpindahan',
    'mutasi_barang',
    'resi_scan_log',

    // baru stoknya
    'batch_barang',
    'stok_supplier',
    'stok',

    // donatur setelah donasi, karena donasi menunjuk donatur
    'donatur',

    // pelengkap
    'notifikasi',
    'stok_harian',
    'dokumen_counter',
];

$dipertahankan = [
    'kategori', 'barang', 'gudang', 'lokasi_rak', 'supplier', 'toko',
    'penerima', 'roles', 'users', 'user_toko', 'user_gudang', 'pengaturan',
    'sesi_perangkat', 'activity_log', 'marketplace_akun', 'marketplace_sku',
];

// Tabel yang benar-benar ada di basis data ini.
$ada = [];
foreach ($pdo->query('SHOW TABLES') as $r) { $ada[array_values($r)[0]] = true; }

judul($sungguhan ? 'MENGHAPUS' : 'RENCANA (belum ada yang dihapus)');
$totalHapus = 0;
$rencana = [];
foreach ($urutan as $t) {
    if (!isset($ada[$t])) { continue; }
    $n = (int) $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    $rencana[$t] = $n;
    $totalHapus += $n;
    keluar(sprintf('   %-28s %6d baris', $t, $n));
}

judul('DIPERTAHANKAN');
foreach ($dipertahankan as $t) {
    if (!isset($ada[$t])) { continue; }
    $n = (int) $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    keluar(sprintf('   %-28s %6d baris', $t, $n));
}

// Tabel yang tidak disebut di kedua daftar — dilaporkan, bukan diam-diam
// ikut terhapus atau ikut selamat tanpa disadari.
$disebut = array_flip(array_merge($urutan, $dipertahankan));
$tidakDisebut = array_diff(array_keys($ada), array_keys($disebut));
$tidakDisebut = array_filter($tidakDisebut, fn($t) => strpos($t, 'v_') !== 0);
if ($tidakDisebut) {
    judul('TIDAK TERSENTUH (tidak ada di kedua daftar)');
    foreach ($tidakDisebut as $t) {
        $n = (int) $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        keluar(sprintf('   %-28s %6d baris', $t, $n));
    }
}

if (!$sungguhan) {
    judul('TIDAK ADA YANG DIHAPUS');
    keluar("   $totalHapus baris akan dihapus bila dijalankan dengan --ya.");
    keluar('   Pastikan cadangan sudah dibuat lebih dulu.');
    exit(0);
}

judul('MENJALANKAN');
try {
    $pdo->beginTransaction();
    foreach ($urutan as $t) {
        if (!isset($ada[$t])) { continue; }
        $n = $pdo->exec("DELETE FROM `$t`");
        if ($rencana[$t] > 0) { keluar(sprintf('   %-28s %6d dihapus', $t, $n)); }
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    keluar('');
    keluar('GAGAL — tidak ada yang berubah (seluruhnya dibatalkan).');
    keluar('   ' . $e->getMessage());
    exit(1);
}

/* AUTO_INCREMENT dikembalikan ke 1 supaya nomor dokumen berikutnya mulai
   dari awal. Tanpa ini, transaksi pertama setelah reset bernomor lanjutan
   dari data yang sudah tidak ada — dan itu terbaca seperti ada riwayat
   yang hilang. Dijalankan DI LUAR transaksi: ALTER TABLE tidak bisa
   dibatalkan, dan menempatkannya di dalam justru menutup transaksinya
   diam-diam. */
foreach ($urutan as $t) {
    if (!isset($ada[$t])) { continue; }
    try { $pdo->exec("ALTER TABLE `$t` AUTO_INCREMENT = 1"); }
    catch (Throwable $e) { /* tabel tanpa AUTO_INCREMENT — wajar, dilewati */ }
}

judul('SELESAI');
keluar("   $totalHapus baris dihapus.");
keluar('   Produk, kategori, gudang, rak, mitra, toko, penerima, dan');
keluar('   pengguna tetap utuh. Stok seluruh produk kini 0.');
