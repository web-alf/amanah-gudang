<?php
/**
 * AMANAH — Lihat isi satu lokasi (gudang / toko / supplier)
 *
 * ---------------------------------------------------------------------------
 * ALAT INI HANYA MEMBACA. Tidak ada satu pun perintah tulis di dalamnya.
 * ---------------------------------------------------------------------------
 *
 * Dibuat untuk dijalankan SEBELUM mengosongkan sebuah lokasi, supaya yang
 * memutuskan tahu persis apa yang akan tersentuh. Mengosongkan gudang adalah
 * tindakan yang tidak bisa dibatalkan; menebak isinya dari ingatan bukan
 * dasar yang cukup.
 *
 * Yang dilaporkan ada dua lapis, dan bedanya menentukan cara mengosongkannya:
 *
 *   ISI          batch yang masih bersisa — inilah "stok" yang terlihat di
 *                dasbor dan laporan.
 *   KETERKAITAN  berapa baris RIWAYAT yang menunjuk batch-batch itu —
 *                transaksi masuk, keluar, opname, penjualan, perpindahan.
 *                Angka inilah yang menentukan apakah batchnya boleh
 *                dihapus atau hanya boleh dinolkan.
 *
 * Pemakaian (dari command prompt, di folder aplikasi):
 *
 *   php alat_isi_lokasi.php                     daftar semua lokasi
 *   php alat_isi_lokasi.php gudang 6            isi Gudang id 6
 *   php alat_isi_lokasi.php toko 4              isi Toko id 4
 *   php alat_isi_lokasi.php supplier 4          isi Supplier id 4
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Alat ini hanya untuk command line.\n");
}

require_once __DIR__ . '/config.php';

function judul(string $t): void { echo "\n", $t, "\n", str_repeat('-', strlen($t)), "\n"; }
function n($v): string { return number_format((int) $v, 0, ',', '.'); }

$tipe = $argv[1] ?? '';
$id   = (int) ($argv[2] ?? 0);

/* ----------------------------------------------------- daftar semua lokasi */
if ($tipe === '') {
    judul('GUDANG');
    foreach ($pdo->query(
        "SELECT g.id, g.kode, g.nama, g.jenis,
                (SELECT COUNT(*) FROM lokasi_rak lr
                  WHERE lr.gudang_id = g.id AND lr.deleted_at IS NULL) AS rak,
                COALESCE((SELECT SUM(bb.qty_tersisa) FROM batch_barang bb
                   JOIN lokasi_rak lr2 ON lr2.id = bb.lokasi_rak_id
                  WHERE lr2.gudang_id = g.id),0) AS qty
         FROM gudang g WHERE g.deleted_at IS NULL ORDER BY g.kode") as $r) {
        printf("  id=%-4s %-9s %-32s %-9s %2s rak  %8s unit\n",
            $r['id'], $r['kode'], $r['nama'], $r['jenis'], $r['rak'], n($r['qty']));
    }

    judul('TOKO / OUTLET');
    foreach ($pdo->query(
        "SELECT tk.id, tk.kode, tk.nama,
                COALESCE((SELECT SUM(bb.qty_tersisa) FROM batch_barang bb
                  WHERE bb.toko_id = tk.id),0) AS qty
         FROM toko tk WHERE tk.deleted_at IS NULL ORDER BY tk.kode") as $r) {
        printf("  id=%-4s %-9s %-32s %18s unit\n",
            $r['id'], $r['kode'], $r['nama'], n($r['qty']));
    }

    judul('SUPPLIER / MITRA');
    foreach ($pdo->query(
        "SELECT sp.id, sp.kode, sp.nama,
                COALESCE((SELECT SUM(ss.qty) FROM stok_supplier ss
                  WHERE ss.supplier_id = sp.id),0) AS qty
         FROM supplier sp WHERE sp.deleted_at IS NULL ORDER BY sp.kode") as $r) {
        printf("  id=%-4s %-9s %-32s %18s unit\n",
            $r['id'], $r['kode'], $r['nama'], n($r['qty']));
    }

    echo "\nJalankan lagi dengan tipe dan id, misal:  php alat_isi_lokasi.php gudang 6\n";
    exit(0);
}

if (!in_array($tipe, ['gudang', 'toko', 'supplier'], true) || $id <= 0) {
    exit("Tipe harus gudang / toko / supplier, dan id harus angka.\n");
}

/* ------------------------------------------------- tentukan lingkup batch */
$nama = '';
if ($tipe === 'gudang') {
    $nama = $pdo->query("SELECT nama FROM gudang WHERE id=$id")->fetchColumn();
    $syarat = "bb.lokasi_rak_id IN (SELECT id FROM lokasi_rak WHERE gudang_id = $id)";
} elseif ($tipe === 'toko') {
    $nama = $pdo->query("SELECT nama FROM toko WHERE id=$id")->fetchColumn();
    $syarat = "bb.toko_id = $id";
} else {
    $nama = $pdo->query("SELECT nama FROM supplier WHERE id=$id")->fetchColumn();
    $syarat = null;                       // mitra memakai tabel lain
}
if ($nama === false || $nama === null) { exit("Lokasi tidak ditemukan.\n"); }

judul(strtoupper($tipe) . " #$id — $nama");

/* --------------------------------------------------------------- supplier */
if ($tipe === 'supplier') {
    $r = $pdo->query(
        "SELECT COUNT(*) baris, COUNT(DISTINCT barang_id) jenis,
                COALESCE(SUM(qty),0) qty
         FROM stok_supplier WHERE supplier_id = $id")->fetch(PDO::FETCH_ASSOC);
    printf("  Stok siap kirim : %s baris · %s jenis barang · %s unit\n",
        n($r['baris']), n($r['jenis']), n($r['qty']));

    judul('KETERKAITAN — riwayat yang menyangkut mitra ini');
    foreach ([
        'pengiriman_supplier' => "SELECT COUNT(*) FROM pengiriman_supplier WHERE supplier_id=$id",
        'perpindahan (asal)'  => "SELECT COUNT(*) FROM perpindahan WHERE asal_tipe='supplier' AND asal_id=$id",
        'transaksi_masuk'     => "SELECT COUNT(*) FROM transaksi_masuk WHERE supplier_id=$id",
        'retur'               => "SELECT COUNT(*) FROM retur WHERE supplier_id=$id",
        'batch di gudang'     => "SELECT COUNT(*) FROM batch_barang WHERE supplier_id=$id",
    ] as $label => $sql) {
        printf("  %-22s %s baris\n", $label, n($pdo->query($sql)->fetchColumn()));
    }
    echo "\nCatatan: stok mitra ada di tabel `stok_supplier`, TERPISAH dari\n"
       . "batch_barang milik gudang. Mengosongkan mitra tidak menyentuh\n"
       . "barang yang sudah terlanjur dikirim dan diterima gudang.\n";
    exit(0);
}

/* --------------------------------------------------- gudang & toko: batch */
$r = $pdo->query(
    "SELECT COUNT(*) batch, COUNT(DISTINCT bb.barang_id) jenis,
            COALESCE(SUM(bb.qty_tersisa),0) qty,
            SUM(bb.qty_tersisa > 0) aktif
     FROM batch_barang bb WHERE $syarat")->fetch(PDO::FETCH_ASSOC);

printf("  Batch tercatat  : %s (%s masih bersisa)\n", n($r['batch']), n($r['aktif']));
printf("  Jenis barang    : %s\n", n($r['jenis']));
printf("  Total unit      : %s\n", n($r['qty']));

if ((int) $r['batch'] === 0) {
    echo "\n  Lokasi ini sudah kosong — tidak ada batch sama sekali.\n";
    exit(0);
}

judul('ISI PER BARANG');
$q = $pdo->query(
    "SELECT b.kode, b.nama, COUNT(*) batch, COALESCE(SUM(bb.qty_tersisa),0) qty
     FROM batch_barang bb JOIN barang b ON b.id = bb.barang_id
     WHERE $syarat GROUP BY b.id, b.kode, b.nama
     ORDER BY qty DESC, b.nama LIMIT 30");
foreach ($q as $x) {
    printf("  %-12s %-38s %3s batch %10s unit\n",
        $x['kode'], mb_strimwidth($x['nama'], 0, 38, '…'), $x['batch'], n($x['qty']));
}

judul('KETERKAITAN — baris riwayat yang menunjuk batch di lokasi ini');
$sub = "SELECT bb.id FROM batch_barang bb WHERE $syarat";
$terkait = 0;
foreach ([
    'transaksi_masuk_detail'  => "SELECT COUNT(*) FROM transaksi_masuk_detail  WHERE batch_id IN ($sub)",
    'transaksi_keluar_detail' => "SELECT COUNT(*) FROM transaksi_keluar_detail WHERE batch_id IN ($sub)",
    'perpindahan_detail'      => "SELECT COUNT(*) FROM perpindahan_detail      WHERE batch_id IN ($sub)",
    'penjualan_detail'        => "SELECT COUNT(*) FROM penjualan_detail        WHERE batch_id IN ($sub)",
    'stock_opname_detail'     => "SELECT COUNT(*) FROM stock_opname_detail     WHERE batch_id IN ($sub)",
    'penyesuaian_stok'        => "SELECT COUNT(*) FROM penyesuaian_stok        WHERE batch_id IN ($sub)",
    'mutasi_barang'           => "SELECT COUNT(*) FROM mutasi_barang           WHERE batch_id IN ($sub)",
    'pelepasan_stok'          => "SELECT COUNT(*) FROM pelepasan_stok          WHERE batch_id IN ($sub)",
    'retur'                   => "SELECT COUNT(*) FROM retur                   WHERE batch_id IN ($sub)",
    'notifikasi'              => "SELECT COUNT(*) FROM notifikasi              WHERE batch_id IN ($sub)",
] as $label => $sql) {
    $c = (int) $pdo->query($sql)->fetchColumn();
    $terkait += $c;
    printf("  %-26s %s baris%s\n", $label, n($c), $c ? '' : '');
}

judul('ARTINYA');
if ($terkait === 0) {
    echo "  Tidak ada satu pun riwayat yang menunjuk batch di sini.\n"
       . "  Batchnya BOLEH dihapus tuntas tanpa merusak apa pun — ini keadaan\n"
       . "  khas data uji yang belum pernah dipakai bertransaksi.\n";
} else {
    printf("  Ada %s baris riwayat yang menunjuk batch di lokasi ini.\n", n($terkait));
    echo "  Menghapus batchnya akan MEMUTUS riwayat itu: laporan bulan lalu,\n"
       . "  surat jalan yang sudah dicetak, dan hasil opname akan kehilangan\n"
       . "  rujukannya. Untuk lokasi seperti ini, yang benar adalah MENOLKAN\n"
       . "  lewat penyesuaian stok — angkanya jadi nol, riwayatnya utuh.\n";
}
echo "\n";
