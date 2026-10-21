<?php
/**
 * STOCK OPNAME — hitung fisik lalu koreksi selisihnya.
 *
 * GET  /api/opname.php?rak_id=N     daftar batch di rak itu untuk dihitung
 * GET  /api/opname.php?riwayat=1    penyesuaian yang pernah dicatat
 * POST /api/opname.php
 *      { rak_id, catatan?, hitung: [ { batch_id, qty_fisik, alasan? } ] }
 *
 * ---------------------------------------------------------------------
 * MENGAPA PER RAK, BUKAN PER BARANG
 * ---------------------------------------------------------------------
 * Opname dikerjakan sambil berdiri di depan rak, menghitung apa yang ada
 * di sana. Menyusunnya per barang memaksa petugas berpindah-pindah rak
 * mengikuti urutan daftar, dan itulah cara paling mudah melewatkan satu
 * kardus. Per rak, urutan di layar sama dengan urutan di tangan.
 *
 * SELISIH SELALU BUTUH ALASAN
 * Barang yang hilang dari hitungan adalah titipan orang. Menuliskan
 * "kurang 3" tanpa keterangan membuat pertanyaan "ke mana perginya" tidak
 * bisa dijawab tiga bulan kemudian. Baris yang cocok tidak perlu alasan —
 * yang perlu dijelaskan hanya yang berubah.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_lingkup.php';
require_once __DIR__ . '/../_lib.php';

$p = auth_required();

if (!in_array($p['role'] ?? '', ['super_admin', 'admin_gudang', 'admin_terpadu'], true)) {
    err('Stock opname adalah pekerjaan sisi gudang.', 403);
}

$_SESSION['user_id'] = (int) $p['user_id'];
$method = $_SERVER['REQUEST_METHOD'];

/** Rak yang boleh disentuh akun ini. */
function rak_opname(PDO $pdo, array $p): ?array {
    $in = api_in_rak($pdo, $p);
    if ($in === '') { return null; }                       // tak dibatasi
    return array_filter(array_map('intval', explode(',', $in)));
}

// ====================================================================== GET
if ($method === 'GET') {
    try {
        $rakBoleh = rak_opname($pdo, $p);

        // ---- riwayat penyesuaian
        if (!empty($_GET['riwayat'])) {
            $syarat = $rakBoleh === null
                ? '1=1'
                : ('ps.lokasi_rak_id IN (' . (implode(',', $rakBoleh) ?: '0') . ')');
            $st = $pdo->query(
                "SELECT ps.id, ps.no_penyesuaian, ps.tanggal, ps.jenis, ps.qty, ps.alasan,
                        b.nama AS barang, b.satuan,
                        bb.nomor_batch,
                        lr.kode AS rak_kode, g.nama AS gudang,
                        u.nama AS petugas
                 FROM penyesuaian_stok ps
                 JOIN barang b            ON b.id  = ps.barang_id
                 LEFT JOIN batch_barang bb ON bb.id = ps.batch_id
                 LEFT JOIN lokasi_rak lr  ON lr.id = ps.lokasi_rak_id
                 LEFT JOIN gudang g       ON g.id  = lr.gudang_id
                 LEFT JOIN users u        ON u.id  = ps.user_id
                 WHERE ps.lokasi_tipe = 'gudang' AND $syarat
                 ORDER BY ps.id DESC LIMIT 60"
            );
            ok(['data' => $st->fetchAll()]);
        }

        // ---- daftar rak yang bisa diopname
        $rakId = (int) ($_GET['rak_id'] ?? 0);
        if ($rakId <= 0) {
            $syarat = $rakBoleh === null
                ? 'lr.deleted_at IS NULL'
                : ('lr.deleted_at IS NULL AND lr.id IN (' . (implode(',', $rakBoleh) ?: '0') . ')');
            $st = $pdo->query(
                "SELECT lr.id, lr.kode, lr.nama, g.nama AS gudang_nama,
                        COALESCE(x.jml_batch,0) AS jml_batch,
                        COALESCE(x.qty,0)       AS total_qty,
                        (SELECT MAX(ps.tanggal) FROM penyesuaian_stok ps
                          WHERE ps.lokasi_rak_id = lr.id) AS opname_terakhir
                 FROM lokasi_rak lr
                 LEFT JOIN gudang g ON g.id = lr.gudang_id
                 LEFT JOIN (SELECT lokasi_rak_id, COUNT(*) jml_batch, SUM(qty_tersisa) qty
                            FROM batch_barang WHERE qty_tersisa > 0
                            GROUP BY lokasi_rak_id) x ON x.lokasi_rak_id = lr.id
                 WHERE $syarat
                 ORDER BY lr.kode"
            );
            ok(['rak' => $st->fetchAll()]);
        }

        // ---- isi satu rak, untuk dihitung
        if ($rakBoleh !== null && !in_array($rakId, $rakBoleh, true)) {
            err('Rak itu bukan bagian dari gudang Anda.', 403);
        }

        $stR = $pdo->prepare(
            "SELECT lr.id, lr.kode, lr.nama, g.nama AS gudang_nama
             FROM lokasi_rak lr LEFT JOIN gudang g ON g.id = lr.gudang_id
             WHERE lr.id = ? AND lr.deleted_at IS NULL");
        $stR->execute([$rakId]);
        $rak = $stR->fetch();
        if (!$rak) { err('Rak tidak ditemukan.', 404); }

        $st = $pdo->prepare(
            "SELECT bb.id AS batch_id, bb.nomor_batch, bb.qty_tersisa, bb.kondisi,
                    bb.tanggal_expired,
                    DATEDIFF(bb.tanggal_expired, CURDATE()) AS sisa_hari,
                    b.id AS barang_id, b.nama AS barang, b.kode AS kode_barang, b.satuan,
                    d.nama AS donatur_nama, d.anonim AS donatur_anonim, d.wilayah AS donatur_wilayah
             FROM batch_barang bb
             JOIN barang b        ON b.id = bb.barang_id
             LEFT JOIN donatur d  ON d.id = bb.donatur_id
             WHERE bb.lokasi_rak_id = ? AND bb.qty_tersisa > 0
             ORDER BY b.nama, (bb.tanggal_expired IS NULL), bb.tanggal_expired"
        );
        $st->execute([$rakId]);

        $isi = [];
        foreach ($st->fetchAll() as $r) {
            [$lblK] = label_kondisi($r['kondisi'] ?? 'baru');
            $isi[] = [
                'batch_id'      => (int) $r['batch_id'],
                'nomor_batch'   => $r['nomor_batch'],
                'barang_id'     => (int) $r['barang_id'],
                'barang'        => $r['barang'],
                'kode_barang'   => $r['kode_barang'],
                'satuan'        => $r['satuan'],
                'qty_sistem'    => (int) $r['qty_tersisa'],
                'kondisi'       => $r['kondisi'],
                'kondisi_label' => $lblK,
                'tanggal_expired' => $r['tanggal_expired'],
                'sisa_hari'     => $r['sisa_hari'] !== null ? (int) $r['sisa_hari'] : null,
                'donatur'       => $r['donatur_nama']
                    ? nama_donatur_tampil(['nama' => $r['donatur_nama'],
                                           'anonim' => $r['donatur_anonim'],
                                           'wilayah' => $r['donatur_wilayah']])
                    : null,
            ];
        }

        ok(['rak' => $rak, 'isi' => $isi]);
    } catch (Throwable $e) {
        error_log('[AMANAH API] opname baca: ' . $e->getMessage());
        err('Gagal mengambil data opname.', 500);
    }
}

// ===================================================================== POST
if ($method === 'POST') {
    $b       = body();
    $rakId   = (int) ($b['rak_id'] ?? 0);
    $catatan = trim($b['catatan'] ?? '');
    if ($rakId <= 0) { err('rak_id wajib diisi.'); }

    $rakBoleh = rak_opname($pdo, $p);
    if ($rakBoleh !== null && !in_array($rakId, $rakBoleh, true)) {
        err('Rak itu bukan bagian dari gudang Anda.', 403);
    }

    $hitung = [];
    foreach (($b['hitung'] ?? []) as $h) {
        $bid = (int) ($h['batch_id'] ?? 0);
        if ($bid > 0 && isset($h['qty_fisik'])) {
            $hitung[$bid] = [
                'qty'    => max(0, (int) $h['qty_fisik']),
                'alasan' => trim($h['alasan'] ?? ''),
            ];
        }
    }
    if (!$hitung) { err('Tidak ada hasil hitung yang dikirim.'); }

    try {
        $pdo->beginTransaction();

        // Batch dikunci lebih dulu, supaya angka sistem yang dibandingkan
        // tidak berubah oleh transaksi lain di tengah penyesuaian.
        $in = implode(',', array_map('intval', array_keys($hitung)));
        $stB = $pdo->query(
            "SELECT bb.id, bb.barang_id, bb.qty_tersisa, bb.nomor_batch, b.nama AS barang
             FROM batch_barang bb JOIN barang b ON b.id = bb.barang_id
             WHERE bb.id IN ($in) AND bb.lokasi_rak_id = $rakId FOR UPDATE");
        $batch = $stB->fetchAll();
        if (!$batch) { throw new RuntimeException('Batch yang dihitung tidak ada di rak itu.'); }

        $selisih = [];
        $cocok   = 0;
        $barangDisentuh = [];

        foreach ($batch as $bb) {
            $bid    = (int) $bb['id'];
            $sistem = (int) $bb['qty_tersisa'];
            $fisik  = $hitung[$bid]['qty'];

            /* Penyesuaiannya lewat sesuaikan_batch() di _lib.php — fungsi
               yang sama dipakai penyuntingan stok di halaman Produk. Ia
               yang menegakkan "selisih wajib beralasan" dan menulis
               penyesuaian_stok, jadi kedua jalur tidak bisa menyimpang. */
            $beda = sesuaikan_batch($pdo, $bid, $fisik, $hitung[$bid]['alasan'],
                                    (int) $p['user_id'], $catatan);

            if ($beda === 0) { $cocok++; continue; }

            $barangDisentuh[(int) $bb['barang_id']] = true;
            $selisih[] = [
                'batch'  => $bb['nomor_batch'],
                'barang' => $bb['barang'],
                'sistem' => $sistem,
                'fisik'  => $fisik,
                'beda'   => $beda,
            ];
        }

        foreach (array_keys($barangDisentuh) as $bid) { recalc_stok($pdo, (int) $bid); }
        $pdo->commit();

        catat_aktivitas($pdo, 'create', 'penyesuaian_stok', $rakId,
            'Opname rak #' . $rakId . ': ' . count($selisih) . ' selisih, ' . $cocok . ' cocok');

        ok([
            'cocok'       => $cocok,
            'jml_selisih' => count($selisih),
            'selisih'     => $selisih,
            'pesan'       => count($selisih) === 0
                ? "Opname selesai — seluruh $cocok batch cocok dengan catatan sistem."
                : 'Opname selesai — ' . count($selisih) . ' batch disesuaikan, '
                  . "$cocok batch cocok.",
        ]);

    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        err(pesan_api($e));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('[AMANAH API] opname simpan: ' . $e->getMessage());
        err('Gagal menyimpan hasil opname.', 500);
    }
}

err('Method tidak diizinkan.', 405);
