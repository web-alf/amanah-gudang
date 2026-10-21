<?php
/**
 * GET  /api/keluar.php         — daftar transaksi keluar
 * GET  /api/keluar.php?id=N    — detail + item
 * POST /api/keluar.php         — catat transaksi keluar baru (FEFO otomatis)
 *
 * ---------------------------------------------------------------------
 * DUA HAL YANG DIPERBAIKI DARI VERSI SEBELUMNYA
 * ---------------------------------------------------------------------
 * 1. Kolom yang tidak ada. INSERT-nya memakai `nomor_dokumen` dan
 *    `dibuat_oleh` pada transaksi_keluar (aslinya no_transaksi dan
 *    user_id), dan SELECT detailnya memakai bb.no_batch / bb.tgl_kadaluarsa
 *    (aslinya nomor_batch / tanggal_expired). Setiap POST pasti gagal.
 *
 * 2. FEFO tanpa batas lokasi. Kueri batch-nya hanya
 *    "WHERE barang_id = ? AND qty_tersisa > 0" — tanpa penyaring rak
 *    maupun gudang, dan tanpa mengecualikan stok toko. Akibatnya petugas
 *    gudang A bisa memotong stok gudang B, atau bahkan barang yang sudah
 *    diserahkan ke toko. Sekarang pengambilannya lewat ambil_fefo() dengan
 *    gudang_id dari token, sehingga yang terpotong hanya yang benar-benar
 *    ada di raknya sendiri.
 *
 * Barang berkondisi bekas_tidak_layak tidak ikut tersalur: barang yang
 * sudah dinilai tidak layak tidak boleh sampai ke penerima amanah.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../_lib.php';

$payload = auth_required();
if (!in_array($payload['role'], ['super_admin', 'admin_gudang', 'admin_terpadu'], true)) {
    err('Hak akses tidak cukup.', 403);
}

$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int) $_GET['id'] : null;

/* Gudang yang mengikat akun ini, atau null bila melihat seluruh rantai.
   Dipakai dua kali: menyaring daftar, dan membatasi FEFO saat menyimpan. */
$gudangSaya = in_array($payload['role'], ['admin_gudang', 'admin_terpadu'], true)
            && $payload['gudang_id'] !== null
            ? (int) $payload['gudang_id'] : null;

// ====================================================================== GET
if ($method === 'GET') {
    if ($id) {
        try {
            $st = $pdo->prepare(
                "SELECT h.*, u.nama AS dibuat_nama
                 FROM transaksi_keluar h
                 LEFT JOIN users u ON u.id = h.user_id
                 WHERE h.id = ?"
            );
            $st->execute([$id]);
            $header = $st->fetch();
            if (!$header) { err('Transaksi tidak ditemukan.', 404); }

            $stDet = $pdo->prepare(
                "SELECT d.id, d.barang_id, d.qty, d.batch_id,
                        b.nama AS barang_nama, b.satuan, b.kode AS barang_kode,
                        bb.nomor_batch, bb.tanggal_expired, bb.kondisi, bb.sumber,
                        lr.kode AS rak_kode, g.nama AS gudang_nama,
                        dn.nama AS donatur_nama, dn.anonim AS donatur_anonim,
                        dn.wilayah AS donatur_wilayah
                 FROM transaksi_keluar_detail d
                 JOIN barang b             ON b.id  = d.barang_id
                 LEFT JOIN batch_barang bb ON bb.id = d.batch_id
                 LEFT JOIN lokasi_rak lr   ON lr.id = bb.lokasi_rak_id
                 LEFT JOIN gudang g        ON g.id  = lr.gudang_id
                 LEFT JOIN donatur dn      ON dn.id = bb.donatur_id
                 WHERE d.transaksi_keluar_id = ?"
            );
            $stDet->execute([$id]);

            $detail = [];
            foreach ($stDet->fetchAll() as $r) {
                [$lblK] = label_kondisi($r['kondisi'] ?? 'baru');
                $detail[] = [
                    'id'              => (int) $r['id'],
                    'barang_id'       => (int) $r['barang_id'],
                    'barang_nama'     => $r['barang_nama'],
                    'barang_kode'     => $r['barang_kode'],
                    'satuan'          => $r['satuan'],
                    'qty'             => (int) $r['qty'],
                    'nomor_batch'     => $r['nomor_batch'],
                    'tanggal_expired' => $r['tanggal_expired'],
                    'kondisi'         => $r['kondisi'],
                    'kondisi_label'   => $lblK,
                    'sumber'          => $r['sumber'],
                    'rak_kode'        => $r['rak_kode'],
                    'gudang_nama'     => $r['gudang_nama'],
                    'donatur'         => $r['donatur_nama']
                        ? nama_donatur_tampil(['nama' => $r['donatur_nama'],
                                               'anonim' => $r['donatur_anonim'],
                                               'wilayah' => $r['donatur_wilayah']])
                        : null,
                ];
            }
            ok(['header' => $header, 'detail' => $detail]);
        } catch (Throwable $e) {
            error_log('[AMANAH API] keluar detail: ' . $e->getMessage());
            err('Gagal mengambil data.', 500);
        }
    }

    [$limit, $offset, $page] = paginate(20);
    $dari = $_GET['dari'] ?? date('Y-m-01');
    $ke   = $_GET['ke']   ?? date('Y-m-d');

    $where = ['DATE(h.tanggal) BETWEEN ? AND ?'];
    $par   = [$dari, $ke];

    /* Petugas gudang melihat pengeluaran yang menyentuh raknya. Tanpa ini
       ia membaca ke mana saja barang gudang lain disalurkan. */
    if ($gudangSaya !== null) {
        $where[] = "EXISTS(SELECT 1 FROM transaksi_keluar_detail d2
                           JOIN batch_barang bb2 ON bb2.id = d2.batch_id
                           WHERE d2.transaksi_keluar_id = h.id
                             AND bb2.lokasi_rak_id IN (SELECT lr.id FROM lokasi_rak lr
                                                       WHERE lr.gudang_id = ? AND lr.deleted_at IS NULL))";
        $par[] = $gudangSaya;
    }
    $q = trim($_GET['q'] ?? '');
    if ($q !== '') {
        $where[] = '(h.no_transaksi LIKE ? OR h.penerima LIKE ? OR h.untuk_bayi LIKE ?)';
        $like = '%' . like_escape($q) . '%';
        array_push($par, $like, $like, $like);
    }
    $sqlWhere = 'WHERE ' . implode(' AND ', $where);

    try {
        $stCount = $pdo->prepare("SELECT COUNT(*) FROM transaksi_keluar h $sqlWhere");
        $stCount->execute($par);
        $total = (int) $stCount->fetchColumn();

        $st = $pdo->prepare(
            "SELECT h.id, h.no_transaksi, h.tanggal, h.penerima, h.untuk_bayi,
                    h.divisi_cabang, h.catatan,
                    COALESCE(SUM(d.qty),0) AS total_qty, COUNT(d.id) AS jml_item,
                    u.nama AS dibuat_nama
             FROM transaksi_keluar h
             LEFT JOIN transaksi_keluar_detail d ON d.transaksi_keluar_id = h.id
             LEFT JOIN users u ON u.id = h.user_id
             $sqlWhere
             GROUP BY h.id ORDER BY h.tanggal DESC, h.id DESC
             LIMIT $limit OFFSET $offset"
        );
        $st->execute($par);
        ok(['data' => $st->fetchAll(), 'total' => $total, 'limit' => $limit, 'page' => $page]);
    } catch (Throwable $e) {
        error_log('[AMANAH API] keluar daftar: ' . $e->getMessage());
        err('Gagal mengambil data.', 500);
    }
}

// ===================================================================== POST
if ($method === 'POST') {
    $b         = body();
    $penerima  = trim($b['penerima'] ?? '');
    $untukBayi = trim($b['untuk_bayi'] ?? '');
    $divisi    = trim($b['divisi_cabang'] ?? '');
    $catatan   = trim($b['catatan'] ?? '');

    if ($penerima === '') { err('Nama penerima wajib diisi.'); }

    /* Barang yang sama muncul dua kali digabung dulu. Tanpa ini,
       pemeriksaan stok per baris bisa lolos padahal totalnya melebihi. */
    $perBarang = [];
    foreach (($b['items'] ?? []) as $it) {
        $bid = (int) ($it['barang_id'] ?? 0);
        $qty = (int) ($it['qty'] ?? 0);
        if ($bid <= 0 || $qty <= 0) { continue; }
        $perBarang[$bid] = ($perBarang[$bid] ?? 0) + $qty;
    }
    if (!$perBarang) { err('Keranjang masih kosong.'); }

    /* Periksa kecukupan SEBELUM menulis apa pun, dan sebut semua barang
       yang kurang sekaligus — bukan satu per satu tiap kali dicoba. */
    $kurang = [];
    $inRak = $gudangSaya !== null
        ? "AND bb.lokasi_rak_id IN (SELECT lr.id FROM lokasi_rak lr
                                    WHERE lr.gudang_id = " . $gudangSaya . " AND lr.deleted_at IS NULL)"
        : "AND bb.toko_id IS NULL";
    // Sama persis dengan penyaring di ambil_fefo($hanyaLayak): kalau
    // pemeriksaan awal menghitung barang tidak layak sedangkan pengambilannya
    // melewatinya, pemeriksaan lolos lalu penyimpanan gagal.
    $layak    = kondisi_layak_salur();
    $inLayak  = "AND bb.kondisi IN (" . implode(',', array_fill(0, count($layak), '?')) . ")";
    $stAda = $pdo->prepare(
        "SELECT COALESCE(SUM(bb.qty_tersisa),0) FROM batch_barang bb
         WHERE bb.barang_id = ? AND bb.qty_tersisa > 0 $inRak $inLayak" . sql_tanpa_kardus($pdo) . "");
    $stNama = $pdo->prepare("SELECT nama, satuan FROM barang WHERE id = ?");
    foreach ($perBarang as $bid => $qty) {
        $stAda->execute(array_merge([$bid], $layak));
        $ada = (int) $stAda->fetchColumn();
        if ($qty > $ada) {
            $stNama->execute([$bid]);
            $nb = $stNama->fetch();
            $kurang[] = ($nb['nama'] ?? "Barang #$bid") . ": diminta $qty "
                      . ($nb['satuan'] ?? 'unit') . ', yang layak disalurkan hanya ' . $ada . '.';
        }
    }
    if ($kurang) { err(implode(' ', $kurang)); }

    try {
        $hasil = dengan_nomor_dokumen($pdo, 'transaksi_keluar', 'no_transaksi', 'TK',
            function (string $noTrx) use ($pdo, $perBarang, $penerima, $untukBayi,
                                          $divisi, $catatan, $payload, $gudangSaya) {
                $pdo->beginTransaction();
                kunci_stok($pdo, array_keys($perBarang));

                $pdo->prepare(
                    "INSERT INTO transaksi_keluar
                       (no_transaksi, tanggal, user_id, penerima, untuk_bayi, divisi_cabang, catatan)
                     VALUES (?, NOW(), ?, ?, ?, ?, ?)"
                )->execute([$noTrx, (int) $payload['user_id'], $penerima,
                            $untukBayi ?: null, $divisi ?: null, $catatan ?: null]);
                $trxId = (int) $pdo->lastInsertId();

                $potong = $pdo->prepare("UPDATE batch_barang SET qty_tersisa = qty_tersisa - ? WHERE id = ?");
                $stD    = $pdo->prepare(
                    "INSERT INTO transaksi_keluar_detail (transaksi_keluar_id, barang_id, batch_id, qty)
                     VALUES (?,?,?,?)"
                );

                $jejak = [];
                foreach ($perBarang as $bid => $q) {
                    // Dibatasi gudang sendiri; melempar bila stok kurang,
                    // seluruh transaksi batal.
                    $diambil = ambil_fefo($pdo, (int) $bid, $q, null, $gudangSaya, null, true);
                    foreach ($diambil as $a) {
                        $potong->execute([$a['ambil'], $a['batch_id']]);
                        $stD->execute([$trxId, $bid, $a['batch_id'], $a['ambil']]);
                        $jejak[] = $a['nomor_batch'] . ' (' . $a['ambil'] . ')';
                    }
                    recalc_stok($pdo, (int) $bid);
                }

                $pdo->commit();
                return ['id' => $trxId, 'no' => $noTrx, 'jejak' => $jejak];
            });

        $totQty = array_sum($perBarang);
        catat_aktivitas($pdo, 'create', 'transaksi_keluar', $hasil['id'],
            "Barang keluar {$hasil['no']}: " . count($perBarang) . " jenis, $totQty unit ke $penerima");

        ok([
            'id'           => $hasil['id'],
            'no_transaksi' => $hasil['no'],
            'batch_terpakai' => $hasil['jejak'],
            'pesan' => "Barang keluar {$hasil['no']} tersimpan — " . count($perBarang)
                     . " jenis, $totQty unit. Batch terpakai: " . implode(', ', $hasil['jejak']) . '.',
        ]);

    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        err(pesan_api($e));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('[AMANAH API] keluar simpan: ' . $e->getMessage());
        err('Gagal menyimpan barang keluar.', 500);
    }
}

err('Method tidak diizinkan.', 405);
