<?php
/**
 * GET /api/scan?kode=BARCODE
 * Cari barang berdasarkan kode barcode/QR.
 * Juga mendukung pencarian kode dokumen perpindahan.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../_lib.php';   // label_kondisi()
only('GET');
$payload = auth_required();

$kode = trim($_GET['kode'] ?? '');
if ($kode === '') { err('Parameter kode wajib diisi.'); }

/* Lingkup — hasil pindai dulu memuat stok total lembaga beserta rak dan
   gudang setiap batch, untuk token apa pun. Kini stok dihitung dalam
   lingkup akun, batch disaring, dan dokumen hanya ketemu bila akun ini
   memang boleh melihatnya. */
require_once __DIR__ . '/_lingkup.php';
$subStok  = api_sub_stok($pdo, $payload, 'b.id');
$syaratBb = api_syarat_batch($pdo, $payload, 'bb');
$batasDok = '(' . api_syarat_perpindahan($pdo, $payload, 'p')
          . ' OR ' . api_syarat_persetujuan_saya($pdo, $payload, 'p') . ')';

$hasil = ['tipe' => null, 'data' => null];

// 1. Cari sebagai kode barang
try {
    /* Dicari di `barcode` DAN `kode`. Keduanya kolom yang berbeda:
       `kode` itu SKU internal lembaga (BBY-0011), `barcode` adalah angka
       EAN yang benar-benar tercetak di kemasan pabrik. Yang ditembak
       pemindai hampir selalu yang kedua — mencarinya hanya di `kode`
       membuat produk bermerek tidak pernah ketemu saat dipindai.
       Barcode didahulukan karena itu yang datang dari alat. */
    $st = $pdo->prepare(
        "SELECT b.id, b.kode, b.barcode, b.nama, b.satuan, b.harga, b.stok_minimum,
                ($subStok) AS stok
         FROM barang b
         WHERE (b.barcode = ? OR b.kode = ?) AND b.deleted_at IS NULL
         ORDER BY (b.barcode = ?) DESC
         LIMIT 1"
    );
    $st->execute([$kode, $kode, $kode]);
    $barang = $st->fetch();
} catch (Throwable $e) {
    error_log('[AMANAH API] scan barang: ' . $e->getMessage());
    $barang = null;
}

if ($barang) {
    // Ambil batch aktif
    try {
        $stBatch = $pdo->prepare(
            "SELECT bb.nomor_batch, bb.tanggal_expired, bb.qty_tersisa, bb.kondisi,
                    DATEDIFF(bb.tanggal_expired, CURDATE()) AS sisa_hari,
                    COALESCE(lr.kode,'') AS rak, COALESCE(g.nama,'') AS gudang,
                    COALESCE(tk.nama,'') AS toko
             FROM batch_barang bb
             LEFT JOIN lokasi_rak lr ON lr.id = bb.lokasi_rak_id
             LEFT JOIN gudang g      ON g.id  = lr.gudang_id
             LEFT JOIN toko tk       ON tk.id = bb.toko_id
             WHERE bb.barang_id = ? AND bb.qty_tersisa > 0 AND $syaratBb
             ORDER BY (bb.tanggal_expired IS NULL), bb.tanggal_expired ASC LIMIT 10"
        );
        $stBatch->execute([$barang['id']]);
        $barang['batch'] = $stBatch->fetchAll();
    } catch (Throwable $e) {
        error_log('[AMANAH API] scan batch produk: ' . $e->getMessage());
        $barang['batch'] = [];
    }

    $hasil = ['tipe' => 'barang', 'data' => $barang];
    ok($hasil);
}

// 2. Cari sebagai kode dokumen perpindahan
try {
    $st = $pdo->prepare(
        "SELECT p.id, p.no_perpindahan, p.jenis, p.status,
                p.tanggal, p.catatan,
                p.asal_tipe, p.asal_id,
                p.tujuan_tipe, p.tujuan_id
         FROM perpindahan p
         WHERE p.no_perpindahan = ? AND $batasDok
         LIMIT 1"
    );
    $st->execute([$kode]);
    $perp = $st->fetch();
} catch (Throwable $e) { $perp = null; }

if ($perp) {
    $hasil = ['tipe' => 'perpindahan', 'data' => $perp];
    ok($hasil);
}

/* 3. Cari sebagai NOMOR BATCH.

   Kolomnya nomor_batch dan tanggal_expired — sebelumnya di sini tertulis
   no_batch dan tgl_kadaluarsa, keduanya tidak ada. Kueri melempar, catch
   mengubahnya jadi null, lalu berkas ini menjawab "kode tidak ditemukan".
   Padahal nomor batch itulah yang tercetak di kardus dan paling sering
   ditembak petugas — jadi jalur scan yang paling dipakai adalah jalur
   yang tidak pernah berhasil, dengan pesan yang menyalahkan kodenya. */
try {
    $st = $pdo->prepare(
        "SELECT bb.id, bb.nomor_batch, bb.tanggal_expired, bb.qty_tersisa,
                bb.kondisi, bb.sumber,
                DATEDIFF(bb.tanggal_expired, CURDATE()) AS sisa_hari,
                b.id AS barang_id, b.nama AS barang_nama, b.kode AS barang_kode, b.satuan,
                COALESCE(lr.kode,'')  AS rak,
                COALESCE(g.nama,'')   AS gudang,
                COALESCE(tk.nama,'')  AS toko
         FROM batch_barang bb
         JOIN barang b           ON b.id  = bb.barang_id
         LEFT JOIN lokasi_rak lr ON lr.id = bb.lokasi_rak_id
         LEFT JOIN gudang g      ON g.id  = lr.gudang_id
         LEFT JOIN toko tk       ON tk.id = bb.toko_id
         WHERE bb.nomor_batch = ? AND bb.qty_tersisa > 0 AND $syaratBb
         ORDER BY (bb.tanggal_expired IS NULL), bb.tanggal_expired ASC
         LIMIT 1"
    );
    $st->execute([$kode]);
    $batch = $st->fetch();
} catch (Throwable $e) {
    error_log('[AMANAH API] scan batch: ' . $e->getMessage());
    $batch = null;
}

if ($batch) {
    [$lblK] = label_kondisi($batch['kondisi'] ?? 'baru');
    $batch['kondisi_label'] = $lblK;
    ok(['tipe' => 'batch', 'data' => $batch]);
}

err("Kode '$kode' tidak ditemukan sebagai produk, nomor batch, maupun nomor dokumen.", 404);
