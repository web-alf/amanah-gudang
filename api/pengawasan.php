<?php
/**
 * GET /api/pengawasan.php  — ringkasan semua mitra & lokasi (super_admin, pimpinan)
 * GET /api/pengawasan.php?entity=supplier|toko   — detail per entitas
 */
require_once __DIR__ . '/_bootstrap.php';
only('GET');
$payload = auth_required();
if (!in_array($payload['role'], ['super_admin', 'pimpinan'], true)) {
    err('Hanya Super Admin dan Pimpinan yang dapat mengakses halaman ini.', 403);
}

$entity = $_GET['entity'] ?? 'semua';

if ($entity === 'supplier' || $entity === 'semua') {
    try {
        $suppliers = $pdo->query(
            "SELECT sp.id, sp.kode, sp.nama, sp.kontak_pic, sp.telepon, sp.is_active,
                    COUNT(DISTINCT ss.barang_id) AS jenis_barang,
                    COALESCE(SUM(ss.qty),0) AS total_stok,
                    (SELECT COUNT(*) FROM pengiriman_supplier WHERE supplier_id=sp.id AND status='dikirim') AS sedang_dikirim
             FROM supplier sp
             LEFT JOIN stok_supplier ss ON ss.supplier_id=sp.id
             GROUP BY sp.id ORDER BY sp.nama"
        )->fetchAll();
    } catch (Throwable $e) { $suppliers = []; }
}

if ($entity === 'toko' || $entity === 'semua') {
    try {
        $toko = $pdo->query(
            "SELECT tk.id, tk.kode, tk.nama, tk.marketplace, tk.is_active,
                    COUNT(DISTINCT bb.barang_id) AS jenis_barang,
                    COALESCE(SUM(bb.qty_tersisa),0) AS total_stok,
                    (SELECT COUNT(*) FROM perpindahan WHERE tujuan_tipe='toko' AND tujuan_id=tk.id AND status='dikirim') AS kiriman_pending
             FROM toko tk
             LEFT JOIN batch_barang bb ON bb.toko_id=tk.id AND bb.qty_tersisa>0
             WHERE tk.deleted_at IS NULL
             GROUP BY tk.id ORDER BY tk.kode"
        )->fetchAll();
    } catch (Throwable $e) { $toko = []; }
}

if ($entity === 'gudang' || $entity === 'semua') {
    try {
        $gudang = $pdo->query(
            "SELECT g.id, g.kode, g.nama, g.jenis, g.penanggung_jawab, g.is_active,
                    COUNT(DISTINCT lr.id) AS jml_rak,
                    COUNT(DISTINCT bb.barang_id) AS jenis_barang,
                    COALESCE(SUM(bb.qty_tersisa),0) AS total_stok
             FROM gudang g
             LEFT JOIN lokasi_rak lr   ON lr.gudang_id=g.id AND lr.deleted_at IS NULL
             LEFT JOIN batch_barang bb ON bb.lokasi_rak_id=lr.id AND bb.qty_tersisa>0
             WHERE g.deleted_at IS NULL
             GROUP BY g.id ORDER BY g.kode"
        )->fetchAll();
    } catch (Throwable $e) { $gudang = []; }
}

$resp = [];
if (isset($suppliers)) { $resp['supplier'] = $suppliers; }
if (isset($toko))      { $resp['toko']     = $toko; }
if (isset($gudang))    { $resp['gudang']   = $gudang; }

ok($resp);
