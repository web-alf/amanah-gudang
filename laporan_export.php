<?php
/**
 * AMANAH — Export laporan stok ke CSV
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_login();

/* Gerbang yang SAMA dengan laporan.php. Berkas ini dulu hanya memeriksa
   login: staff yang di layar tidak diperlihatkan harga bisa mengunduh
   harga dan nilai persediaan lewat tombol Export — pintu belakang untuk
   angka yang halamannya sendiri sembunyikan. */
if (!boleh_lihat_laporan($pdo)) {
    http_response_code(403);
    die('<p style="font-family:sans-serif;padding:30px">403 — Akun Anda tidak diberi izin membuka laporan. '
      . '<a href="' . e(beranda_peran()) . '">Kembali</a></p>');
}
$lihatNilai = boleh_lihat_nilai($pdo);

/* Tombol Export ada di Laporan Stok, dan Laporan Stok kini dibuka semua
   peran — jadi export-nya harus ikut terbuka DAN ikut terbatasi. Kalau
   tidak, tombolnya terlihat lalu menolak, atau lebih buruk: mengunduh stok
   seluruh lembaga kepada satu supplier. */
$L       = lingkup_stok($pdo);
$syaratV = syarat_lingkup($pdo, 'v');

$rows = [];
try {
    /* Angkanya dijumlahkan dari v_stok_rantai, bukan dari tabel `stok`.
       Tabel `stok` adalah total lembaga tanpa lokasi — tidak bisa disaring,
       dan di berkas berjudul "stok Anda" itu angka yang salah.
       Kolom Gudang/Rak diambil dari lokasi stok yang sebenarnya, bukan dari
       barang.lokasi_rak_id yang cuma lokasi bawaan. */
    $rows = $pdo->query(
        "SELECT b.kode, b.nama, k.nama AS kategori, b.merek, b.ukuran, b.satuan,
                COALESCE(SUM(v.qty),0) AS stok, b.stok_minimum, b.harga,
                COALESCE(SUM(v.qty),0) * b.harga AS nilai,
                sup.nama AS supplier,
                GROUP_CONCAT(DISTINCT COALESCE(t.nama, lr.nama) ORDER BY COALESCE(t.nama, lr.nama)
                             SEPARATOR ', ') AS rak,
                GROUP_CONCAT(DISTINCT COALESCE(g.nama, CONCAT('Toko: ', t.nama))
                             ORDER BY g.nama SEPARATOR ', ') AS gudang,
                MIN(ve.tanggal_expired) AS exp_terdekat
         FROM barang b
         JOIN v_stok_rantai v    ON v.barang_id = b.id AND $syaratV
         LEFT JOIN kategori k    ON k.id  = b.kategori_id
         LEFT JOIN supplier sup  ON sup.id = b.supplier_id
         LEFT JOIN lokasi_rak lr ON lr.id = v.lokasi_rak_id
         LEFT JOIN gudang g      ON g.id  = lr.gudang_id
         LEFT JOIN toko t        ON t.id  = v.toko_id
         LEFT JOIN v_expired_rantai ve ON ve.barang_id = b.id
                                      AND ve.qty_tersisa > 0
                                      AND " . syarat_lingkup($pdo, 've') . "
         WHERE b.deleted_at IS NULL
         GROUP BY b.id
         ORDER BY b.kode"
    )->fetchAll();
} catch (Throwable $e) {
    error_log('[AMANAH] export laporan: ' . $e->getMessage());
}

catat_aktivitas($pdo, 'export', 'laporan', null, 'Export laporan stok ke CSV (' . count($rows) . ' baris)');

// Bersihkan buffer supaya tidak ada spasi/HTML yang ikut ke dalam file CSV.
while (ob_get_level() > 0) { ob_end_clean(); }

$fn = 'AMANAH_laporan_stok_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fn . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8 agar Excel membaca huruf beraksen dengan benar

fputcsv($out, [ident('Laporan Stok — ' . APP_NAMA, APP_LEMBAGA)]);
fputcsv($out, ['Dicetak', tgl_id(date('Y-m-d H:i:s'), 'lengkap')]);
// Lingkupnya ikut tertulis di berkasnya. Berkas CSV berpindah tangan lepas
// dari halamannya, jadi tanpa baris ini tidak ada cara tahu stok siapa.
fputcsv($out, ['Lingkup', $L['label'] !== '' ? $L['label'] : 'Seluruh rantai']);
fputcsv($out, []);
/* Kolom rupiah hanya ikut bila pembacanya berhak — keputusan yang sama
   dengan yang menyembunyikannya di layar laporan. */
$kepala = ['Kode', 'Nama Barang', 'Kategori', 'Merek', 'Ukuran', 'Satuan', 'Stok', 'Stok Min'];
if ($lihatNilai) { array_push($kepala, 'Harga', 'Nilai'); }
array_push($kepala, 'Supplier', 'Gudang', 'Rak', 'Expired Terdekat');
fputcsv($out, $kepala);

$totalNilai = 0;
foreach ($rows as $r) {
    $totalNilai += (float) $r['nilai'];
    $baris = [$r['kode'], $r['nama'], $r['kategori'], $r['merek'], $r['ukuran'], $r['satuan'],
              $r['stok'], $r['stok_minimum']];
    if ($lihatNilai) { array_push($baris, $r['harga'], $r['nilai']); }
    array_push($baris, $r['supplier'], $r['gudang'], $r['rak'], $r['exp_terdekat'] ?: '');
    fputcsv($out, $baris);
}

if ($lihatNilai) {
    fputcsv($out, []);
    fputcsv($out, ['', '', '', '', '', '', '', '', 'TOTAL NILAI', $totalNilai]);
}

fclose($out);
exit;
