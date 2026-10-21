<?php
/**
 * GET    /api/perpindahan.php        — daftar perpindahan (semua jenis)
 * GET    /api/perpindahan.php?id=N   — detail + item
 * POST   /api/perpindahan.php        — buat dokumen perpindahan baru (draft)
 * PUT    /api/perpindahan.php?id=N   — update (hanya draft)
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_lingkup.php';
require_once __DIR__ . '/../_lib.php';

$payload = auth_required();
$method  = $_SERVER['REQUEST_METHOD'];
$id      = isset($_GET['id']) ? (int) $_GET['id'] : null;

if ($method === 'GET') {
    if ($id) {
        /* Dibatasi lingkup. Sebelumnya syaratnya hanya "WHERE p.id = ?" —
           daftar di bawah memang sudah disaring per gudang, tapi jalur ?id=
           tidak, sehingga isi dokumen gudang lain (termasuk daftar barang,
           harga, dan nomor batchnya) terbuka hanya dengan menebak angka. */
        $batas = api_syarat_perpindahan($pdo, $payload, 'p');
        try {
            $st = $pdo->prepare(
                "SELECT p.*, u.nama AS dibuat_nama, ua.nama AS disetujui_nama
                 FROM perpindahan p
                 LEFT JOIN users u  ON u.id  = p.user_id
                 LEFT JOIN users ua ON ua.id = p.approved_by
                 WHERE p.id = ? AND $batas"
            );
            $st->execute([$id]);
            $perp = $st->fetch();
            if (!$perp) { err('Dokumen itu tidak ada, atau bukan untuk gudang Anda.', 404); }

            $stDet = $pdo->prepare(
                "SELECT pd.*, b.nama AS barang_nama, b.satuan, b.kode AS barang_kode
                 FROM perpindahan_detail pd
                 JOIN barang b ON b.id = pd.barang_id
                 WHERE pd.perpindahan_id = ?"
            );
            $stDet->execute([$id]);
            $detail = $stDet->fetchAll();
        } catch (Throwable $e) {
            error_log('[AMANAH API] perpindahan detail: ' . $e->getMessage());
            err('Gagal mengambil data.', 500);
        }

        ok(['perpindahan' => $perp, 'detail' => $detail]);
    }

    [$limit, $offset] = paginate(20);
    $status = $_GET['status'] ?? '';
    $jenis  = $_GET['jenis']  ?? '';
    $dari   = $_GET['dari']   ?? '';
    $ke     = $_GET['ke']     ?? '';

    $where  = ['1=1'];
    $params = [];
    if ($status) { $where[] = 'p.status = ?'; $params[] = $status; }
    if ($jenis)  { $where[] = 'p.jenis = ?';  $params[] = $jenis; }
    if ($dari && $ke) { $where[] = 'DATE(p.tanggal) BETWEEN ? AND ?'; $params[] = $dari; $params[] = $ke; }

    // Scope filter
    $role = $payload['role'];
    if (in_array($role, ['admin_gudang','admin_terpadu'], true) && $payload['gudang_id']) {
        $gid = (int) $payload['gudang_id'];
        $where[] = "((p.asal_tipe='gudang' AND p.asal_id=$gid)
            OR (p.tujuan_tipe='gudang' AND p.tujuan_id=$gid)
            OR (p.asal_tipe='rak' AND p.asal_id IN (SELECT id FROM lokasi_rak WHERE gudang_id=$gid AND deleted_at IS NULL))
            OR (p.tujuan_tipe='rak' AND p.tujuan_id IN (SELECT id FROM lokasi_rak WHERE gudang_id=$gid AND deleted_at IS NULL)))";
    } elseif ($role === 'supplier' && $payload['supplier_id']) {
        $sid = (int) $payload['supplier_id'];
        $where[] = "((p.asal_tipe='supplier' AND p.asal_id=$sid) OR (p.tujuan_tipe='supplier' AND p.tujuan_id=$sid))";
    } elseif ($role === 'toko' && $payload['user_id']) {
        $uid = (int) $payload['user_id'];
        $where[] = "(p.tujuan_tipe='toko' OR p.asal_tipe='toko')
            AND EXISTS(SELECT 1 FROM user_toko ut WHERE ut.user_id=$uid
                AND (ut.toko_id=p.asal_id OR ut.toko_id=p.tujuan_id))";
    }

    $wSql = implode(' AND ', $where);
    try {
        $stCount = $pdo->prepare("SELECT COUNT(*) FROM perpindahan p WHERE $wSql");
        $stCount->execute($params);
        $total = (int) $stCount->fetchColumn();

        $st = $pdo->prepare(
            "SELECT p.id, p.no_perpindahan, p.jenis, p.status, p.tanggal, p.catatan,
                    p.asal_tipe, p.asal_id, p.tujuan_tipe, p.tujuan_id,
                    u.nama AS dibuat_nama,
                    (SELECT COUNT(*) FROM perpindahan_detail WHERE perpindahan_id=p.id) AS jml_item,
                    (SELECT COALESCE(SUM(qty),0) FROM perpindahan_detail WHERE perpindahan_id=p.id) AS total_qty
             FROM perpindahan p
             LEFT JOIN users u ON u.id = p.user_id
             WHERE $wSql
             ORDER BY p.tanggal DESC, p.id DESC
             LIMIT $limit OFFSET $offset"
        );
        $st->execute($params);
        ok(['data' => $st->fetchAll(), 'total' => $total, 'limit' => $limit]);
    } catch (Throwable $e) { error_log("[AMANAH API] " . basename(__FILE__) . ": " . $e->getMessage()); err("Terjadi kesalahan server.", 500); }
}

if ($method === 'POST') {
    $b = body();
    $jenis     = $b['jenis'] ?? '';
    $asalTipe  = $b['asal_tipe'] ?? '';
    $asalId    = (int) ($b['asal_id'] ?? 0);
    $tujuanTipe = $b['tujuan_tipe'] ?? '';
    $tujuanId  = (int) ($b['tujuan_id'] ?? 0);
    $catatan   = trim($b['catatan'] ?? '');
    $items     = $b['items'] ?? [];

    if (!$jenis || !$asalTipe || !$asalId || !$tujuanTipe || !$tujuanId) {
        err('jenis, asal_tipe, asal_id, tujuan_tipe, tujuan_id wajib diisi.');
    }
    if (!$items || !is_array($items)) { err('Minimal satu item wajib diisi.'); }

    /* Jenis yang boleh dibuat dibatasi peran. Padanan jenis_boleh_peran()
       di _lib.php, yang membacanya dari sesi. Tanpa ini akun toko bisa
       membuat dokumen penyaluran ke penerima amanah — alur sisi gudang
       lembaga, bukan wewenangnya. */
    $jenisBoleh = ($payload['role'] ?? '') === 'toko'
        ? ['mutasi_rak', 'transfer_gudang']
        : ['penerimaan', 'kirim_toko', 'retur_toko', 'antar_toko',
           'transfer_gudang', 'mutasi_rak', 'penyaluran', 'retur'];
    if (!in_array($jenis, $jenisBoleh, true)) {
        err("Jenis '$jenis' tidak bisa dibuat oleh akun Anda. Yang tersedia: "
          . implode(', ', $jenisBoleh) . '.', 403);
    }

    // Baris kosong dilewati diam-diam; yang kosong seluruhnya baru ditolak.
    $baris = [];
    foreach ($items as $item) {
        $barangId = (int) ($item['barang_id'] ?? 0);
        $qty      = (int) ($item['qty'] ?? 0);
        if ($barangId <= 0 || $qty <= 0) { continue; }
        $baris[] = ['barang_id' => $barangId, 'qty' => $qty,
                    'batch' => trim($item['nomor_batch'] ?? '') ?: null];
    }
    if (!$baris) { err('Keranjang masih kosong.'); }

    /* Mutasi rak langsung sah — barangnya tidak berpindah pemilik, tidak
       keluar gudang, dan tidak berubah jumlah. Menunggu persetujuan untuk
       memindahkan kardus antar rak membuat letak barang di layar menyimpang
       dari letak sebenarnya sampai ada yang menyetujui. Keputusan yang sama
       dengan formulir web; langsung_sah() yang memutuskan, bukan daftar
       nama jenis yang ditulis ulang di sini. */
    $langsung = langsung_sah($jenis);

    // Dipakai sahkan_perpindahan() dan catat_aktivitas() untuk mengisi pelaku.
    $_SESSION['user_id'] = (int) $payload['user_id'];

    try {
        $hasil = dengan_nomor_dokumen($pdo, 'perpindahan', 'no_perpindahan', 'PP',
            function (string $noPP) use ($pdo, $jenis, $asalTipe, $asalId, $tujuanTipe,
                                         $tujuanId, $catatan, $baris, $payload) {
                $pdo->beginTransaction();
                $pdo->prepare(
                    "INSERT INTO perpindahan
                       (no_perpindahan, jenis, status, tanggal, asal_tipe, asal_id,
                        tujuan_tipe, tujuan_id, catatan, user_id)
                     VALUES (?, ?, 'draft', NOW(), ?, ?, ?, ?, ?, ?)"
                )->execute([$noPP, $jenis, $asalTipe, $asalId, $tujuanTipe, $tujuanId,
                            $catatan ?: null, (int) $payload['user_id']]);
                $pid = (int) $pdo->lastInsertId();

                $stD = $pdo->prepare(
                    "INSERT INTO perpindahan_detail (perpindahan_id, barang_id, nomor_batch, qty)
                     VALUES (?,?,?,?)"
                );
                foreach ($baris as $r) {
                    $stD->execute([$pid, $r['barang_id'], $r['batch'], $r['qty']]);
                }

                $pdo->commit();
                return ['id' => $pid, 'no' => $noPP];
            });

        $pid = $hasil['id'];

        if ($langsung) {
            // Transaksinya sendiri, di dalam sahkan_perpindahan().
            $sah = sahkan_perpindahan($pdo, $pid, (int) $payload['user_id']);
            catat_aktivitas($pdo, 'create', 'perpindahan', $pid,
                "Pindah antar rak {$hasil['no']} — langsung diterapkan");
            ok(['id' => $pid, 'no_perpindahan' => $hasil['no'],
                'no_surat_jalan' => $sah['no_sj'], 'status' => $sah['status'],
                'pesan' => "Barang dipindahkan. Surat jalan {$sah['no_sj']} terbit dan "
                         . "letak barang sudah diperbarui."]);
        }

        catat_aktivitas($pdo, 'create', 'perpindahan', $pid,
            "Buat dokumen perpindahan {$hasil['no']} ($jenis)");
        ok(['id' => $pid, 'no_perpindahan' => $hasil['no'], 'status' => 'draft',
            'pesan' => "Dokumen {$hasil['no']} tersimpan sebagai draft. "
                     . "Kirim untuk persetujuan bila isinya sudah benar."]);

    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        err(pesan_api($e));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('[AMANAH API] perpindahan simpan: ' . $e->getMessage());
        err('Gagal menyimpan dokumen perpindahan.', 500);
    }
}

err('Method tidak diizinkan.', 405);
