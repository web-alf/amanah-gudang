<?php
/**
 * GET /api/alur  — daftar perpindahan / alur distribusi
 * GET /api/alur?id=N — detail satu perpindahan
 */
require_once __DIR__ . '/_bootstrap.php';
only('GET');
$payload = auth_required();

$id     = isset($_GET['id']) ? (int) $_GET['id'] : null;
$status = $_GET['status'] ?? '';   // menunggu_persetujuan | dikirim | selesai | ditolak
$jenis  = $_GET['jenis']  ?? '';   // penerimaan | pengeluaran | transfer_gudang | kirim_toko | dsb.
[$limit, $offset] = paginate(20);

/* Lingkup — aturan yang sama dengan perpindahan.php dan persetujuan.php:
   dokumen terlihat bila asal ATAU tujuannya menyentuh lingkup akun, atau
   akun itu penyetujunya. Dulu detail (?id=) tanpa pagar sama sekali dan
   daftarnya memakai salinan aturan sendiri yang tidak mengenal toko. */
require_once __DIR__ . '/_lingkup.php';
$batas = '(' . api_syarat_perpindahan($pdo, $payload, 'p')
       . ' OR ' . api_syarat_persetujuan_saya($pdo, $payload, 'p') . ')';

if ($id) {
    try {
        $st = $pdo->prepare(
            "SELECT p.*,
                    u.nama AS dibuat_nama,
                    ua.nama AS disetujui_nama
             FROM perpindahan p
             LEFT JOIN users u  ON u.id  = p.user_id
             LEFT JOIN users ua ON ua.id = p.approved_by
             WHERE p.id = ? AND $batas"
        );
        $st->execute([$id]);
        $perp = $st->fetch();
    } catch (Throwable $e) { err('Gagal mengambil data.', 500); }

    if (!$perp) { err('Perpindahan tidak ditemukan.', 404); }

    // Detail item
    try {
        $stDet = $pdo->prepare(
            "SELECT pd.*, b.nama AS barang_nama, b.satuan, b.kode AS barang_kode
             FROM perpindahan_detail pd
             JOIN barang b ON b.id = pd.barang_id
             WHERE pd.perpindahan_id = ?"
        );
        $stDet->execute([$id]);
        $detail = $stDet->fetchAll();
    } catch (Throwable $e) { $detail = []; }

    ok(['perpindahan' => $perp, 'detail' => $detail]);
}

// Daftar
$where  = ['1=1'];
$params = [];
if ($status) {
    $where[]  = 'p.status = ?';
    $params[] = $status;
}
if ($jenis) {
    $where[]  = 'p.jenis = ?';
    $params[] = $jenis;
}

// Batasi berdasarkan lingkup akun — lihat $batas di atas.
$where[] = $batas;

$wSql = implode(' AND ', $where);
try {
    $stCount = $pdo->prepare("SELECT COUNT(*) FROM perpindahan p WHERE $wSql");
    $stCount->execute($params);
    $total = (int) $stCount->fetchColumn();

    $st = $pdo->prepare(
        "SELECT p.id, p.no_perpindahan, p.jenis, p.status,
                p.tanggal, p.catatan,
                p.asal_tipe, p.asal_id,
                p.tujuan_tipe, p.tujuan_id,
                u.nama AS dibuat_nama
         FROM perpindahan p
         LEFT JOIN users u ON u.id = p.user_id
         WHERE $wSql
         ORDER BY p.tanggal DESC, p.id DESC
         LIMIT $limit OFFSET $offset"
    );
    $st->execute($params);
    $list = $st->fetchAll();
} catch (Throwable $e) {
    error_log('[AMANAH API] alur: ' . $e->getMessage()); err('Gagal mengambil data.', 500);
}

ok(['alur' => $list, 'total' => $total, 'limit' => $limit]);
