<?php
/**
 * GET /api/monitoring.php?tipe=expired|stok_kritis|semua
 * Batch & Expired monitoring.
 */
require_once __DIR__ . '/_bootstrap.php';
only('GET');
$payload = auth_required();

$tipe = $_GET['tipe'] ?? 'expired';
[$limit, $offset] = paginate(50);

/* Lingkup — endpoint ini dulu tanpa penyaring: token toko atau supplier
   menerima nomor batch, rak, dan gudang seluruh lembaga. Sekarang batch
   dibatasi api_syarat_batch() dan stok dihitung api_sub_stok(); supplier
   membaca stok_supplier miliknya sendiri. */
require_once __DIR__ . '/_lingkup.php';
$L        = api_lingkup_stok($pdo, $payload);
$syaratBb = api_syarat_batch($pdo, $payload, 'bb');
$subStok  = api_sub_stok($pdo, $payload, 'b.id');

if (($tipe === 'expired' || $tipe === 'semua') && $L['supplier']) {
    /* Cabang supplier: stoknya di stok_supplier. Bentuk kolomnya disamakan
       dengan cabang batch_barang supaya aplikasi tidak perlu tahu bedanya. */
    $hari = (int) ($_GET['hari'] ?? 90);
    try {
        $st = $pdo->prepare(
            "SELECT b.kode, b.nama, b.satuan,
                    ss.id AS batch_id, ss.nomor_batch, ss.tanggal_expired,
                    ss.qty AS qty_tersisa, ss.kondisi,
                    DATEDIFF(ss.tanggal_expired, CURDATE()) AS sisa_hari,
                    CASE
                      WHEN ss.tanggal_expired < CURDATE()                        THEN 'expired'
                      WHEN DATEDIFF(ss.tanggal_expired, CURDATE()) <= 7          THEN 'segera_expired'
                      WHEN DATEDIFF(ss.tanggal_expired, CURDATE()) <= 30         THEN 'waspada'
                      ELSE 'aman'
                    END AS status_expired,
                    COALESCE(lr.kode,'') AS rak_kode,
                    COALESCE(g.nama,'')  AS gudang_nama,
                    '' AS toko_nama
             FROM stok_supplier ss
             JOIN barang b      ON b.id  = ss.barang_id AND b.deleted_at IS NULL
             LEFT JOIN lokasi_rak lr ON lr.id = ss.lokasi_rak_id
             LEFT JOIN gudang g      ON g.id  = lr.gudang_id
             WHERE ss.supplier_id = ? AND ss.qty > 0
               AND ss.tanggal_expired IS NOT NULL
               AND ss.tanggal_expired <= DATE_ADD(CURDATE(), INTERVAL $hari DAY)
             ORDER BY ss.tanggal_expired ASC, ss.qty DESC
             LIMIT $limit OFFSET $offset"
        );
        $st->execute([(int) $L['supplier']]);
        $expired = $st->fetchAll();
    } catch (Throwable $e) {
        error_log('[AMANAH API] monitoring expired supplier: ' . $e->getMessage());
        err('Gagal membaca data kedaluwarsa.', 500);
    }
} elseif ($tipe === 'expired' || $tipe === 'semua') {
    $hari = (int) ($_GET['hari'] ?? 90);   // tampilkan yang expired dalam N hari
    try {
        $st = $pdo->query(
            /* Nama kolomnya nomor_batch dan tanggal_expired.
               Sebelumnya di sini tertulis no_batch dan tgl_kadaluarsa —
               keduanya tidak ada, jadi kueri ini SELALU melempar, dan
               catch di bawah mengubahnya jadi daftar kosong. Akibatnya
               halaman Batch & Expired tidak pernah menampilkan satu pun
               barang yang mendekati kedaluwarsa, dan itu terbaca sebagai
               "aman" padahal artinya "tidak pernah diperiksa". Untuk
               produk bayi, itu kegagalan yang paling mahal. */
            "SELECT b.kode, b.nama, b.satuan,
                    bb.id AS batch_id, bb.nomor_batch, bb.tanggal_expired,
                    bb.qty_tersisa, bb.kondisi,
                    DATEDIFF(bb.tanggal_expired, CURDATE()) AS sisa_hari,
                    CASE
                      WHEN bb.tanggal_expired < CURDATE()                        THEN 'expired'
                      WHEN DATEDIFF(bb.tanggal_expired, CURDATE()) <= 7          THEN 'segera_expired'
                      WHEN DATEDIFF(bb.tanggal_expired, CURDATE()) <= 30         THEN 'waspada'
                      ELSE 'aman'
                    END AS status_expired,
                    COALESCE(lr.kode,'') AS rak_kode,
                    COALESCE(g.nama,'')  AS gudang_nama,
                    COALESCE(tk.nama,'') AS toko_nama
             FROM batch_barang bb
             JOIN barang b      ON b.id  = bb.barang_id AND b.deleted_at IS NULL
             LEFT JOIN lokasi_rak lr ON lr.id = bb.lokasi_rak_id
             LEFT JOIN gudang g      ON g.id  = lr.gudang_id
             LEFT JOIN toko tk       ON tk.id = bb.toko_id
             WHERE bb.qty_tersisa > 0
               AND $syaratBb
               AND bb.tanggal_expired IS NOT NULL
               AND bb.tanggal_expired <= DATE_ADD(CURDATE(), INTERVAL $hari DAY)
             ORDER BY bb.tanggal_expired ASC, bb.qty_tersisa DESC
             LIMIT $limit OFFSET $offset"
        );
        $expired = $st->fetchAll();
    } catch (Throwable $e) {
        /* Kegagalan TIDAK lagi disamarkan jadi daftar kosong. Daftar
           kosong dan kueri gagal terlihat sama persis di layar, dan yang
           satu berarti "tidak ada yang kedaluwarsa" sementara yang lain
           berarti "tidak tahu". */
        error_log('[AMANAH API] monitoring expired: ' . $e->getMessage());
        err('Gagal membaca data kedaluwarsa.', 500);
    }
}

if ($tipe === 'stok_kritis' || $tipe === 'semua') {
    try {
        /* Stok dihitung dalam lingkup akun (api_sub_stok), bukan dari tabel
           `stok` yang memuat total lembaga. */
        $stHabis = $pdo->query(
            "SELECT * FROM (
                SELECT b.id, b.kode, b.nama, b.satuan, b.stok_minimum,
                       ($subStok) AS stok
                  FROM barang b
                 WHERE b.deleted_at IS NULL
             ) x
             CROSS JOIN (SELECT 1) dummy
             WHERE (x.stok = 0 OR (x.stok_minimum > 0 AND x.stok <= x.stok_minimum))
             ORDER BY x.stok ASC, x.nama ASC
             LIMIT $limit"
        );
        $stokKritis = [];
        foreach ($stHabis->fetchAll() as $r) {
            $r['stok'] = (int) $r['stok'];
            $r['status_stok'] = $r['stok'] === 0 ? 'habis' : 'hampir_habis';
            $stokKritis[] = $r;
        }
        $stHabis = null;
    } catch (Throwable $e) {
        error_log('[AMANAH API] monitoring stok kritis: ' . $e->getMessage());
        $stokKritis = [];
    }
}

$resp = ['tipe' => $tipe];
if (isset($expired))    { $resp['expired']     = $expired; }
if (isset($stokKritis)) { $resp['stok_kritis'] = $stokKritis; }

ok($resp);
