<?php
/**
 * Perbaikan, pembuangan, dan penjualan barang — pasangan API dari
 * pelepasan.php di web.
 *
 * GET  /api/pelepasan.php                daftar lengkap: proses, kandidat, riwayat
 * POST /api/pelepasan.php
 *      { aksi: "lepas",   batch_id, qty, jenis, alasan,
 *        nilai_jual?, no_bukti_setor? }
 *      { aksi: "selesai", id, hasil, biaya?, nilai_jual?, no_bukti_setor? }
 *
 * ---------------------------------------------------------------------
 * Melepas barang tidak bisa dibatalkan, dan yang dilepas milik donatur.
 * Karena itu:
 *   • hanya tingkat MANAGER yang boleh mengubah; staff boleh membaca
 *   • batch harus berada di gudang akun ini
 *   • alasan wajib — dijaga lepas_stok() di _lib.php
 *
 * Seluruh logikanya memakai lepas_stok() dan selesaikan_perbaikan() yang
 * sama dengan web. Menyalinnya ke sini akan membuat aturan yang sama
 * berjalan dua versi, dan versi yang jarang dipakai selalu yang ketinggalan.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../_lib.php';

$p = auth_required();

if (in_array($p['role'] ?? '', ['supplier', 'toko'], true)) {
    err('Perbaikan & pelepasan adalah pekerjaan sisi gudang.', 403);
}

$isManager = ($p['tingkat'] ?? 'staff') === 'manager'
          || in_array($p['role'] ?? '', ['super_admin', 'pimpinan'], true);

/* Lingkup rak akun ini. lingkup_stok() di _lib.php membaca $_SESSION,
   sedangkan API bekerja dari token — jadi lingkupnya disusun langsung dari
   gudang_id di dalam token. Peran yang melihat seluruh rantai (super_admin,
   pimpinan) tidak dibatasi. */
function rak_lingkup_api(PDO $pdo, array $p): ?string {
    if (in_array($p['role'] ?? '', ['super_admin', 'pimpinan'], true)) { return null; }
    $gid = $p['gudang_id'] !== null ? (int) $p['gudang_id'] : 0;
    if ($gid <= 0) { return '0'; }
    $ids = array_map('intval', $pdo->query(
        "SELECT id FROM lokasi_rak WHERE gudang_id = $gid AND deleted_at IS NULL"
    )->fetchAll(PDO::FETCH_COLUMN));
    return $ids ? implode(',', $ids) : '0';
}

// ==================================================================== POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isManager) {
        err('Membuang, menjual, atau mengirim barang ke perbaikan hanya untuk tingkat Manager.', 403);
    }
    $b    = body();
    $aksi = $b['aksi'] ?? '';

    // Dipakai lepas_stok()/selesaikan_perbaikan() untuk mengisi user_id.
    $_SESSION['user_id'] = (int) $p['user_id'];

    if ($aksi === 'lepas') {
        $batchId = (int) ($b['batch_id'] ?? 0);
        $qty     = (int) ($b['qty'] ?? 0);
        $jenis   = (string) ($b['jenis'] ?? '');
        $alasan  = trim((string) ($b['alasan'] ?? ''));

        try {
            $pdo->beginTransaction();

            $inRak = rak_lingkup_api($pdo, $p);
            if ($inRak !== null) {
                $cek = $pdo->prepare(
                    "SELECT id FROM batch_barang WHERE id = ? AND lokasi_rak_id IN ($inRak)");
                $cek->execute([$batchId]);
                if (!$cek->fetch()) { throw new RuntimeException('Batch itu tidak ada di gudang Anda.'); }
            }

            $ekstra = [];
            if ($jenis === 'dijual') {
                $ekstra['nilai_jual']     = isset($b['nilai_jual']) ? (float) $b['nilai_jual'] : null;
                $ekstra['no_bukti_setor'] = trim((string) ($b['no_bukti_setor'] ?? '')) ?: null;
                if (!$ekstra['no_bukti_setor']) {
                    throw new RuntimeException(
                        'Nomor bukti setor wajib diisi untuk penjualan — inilah yang menautkan '
                      . 'uangnya ke barang yang keluar.');
                }
            }

            $id = lepas_stok($pdo, $batchId, $qty, $jenis, $alasan, $ekstra);
            $bid = (int) $pdo->query("SELECT barang_id FROM pelepasan_stok WHERE id = $id")->fetchColumn();
            recalc_stok($pdo, $bid);
            $pdo->commit();

            catat_aktivitas($pdo, 'create', 'pelepasan_stok', $id,
                ucfirst($jenis) . " $qty unit dari batch #$batchId: $alasan");
            ok(['id' => $id, 'pesan' => JENIS_PELEPASAN[$jenis][0] . " tercatat — $qty unit keluar dari stok tersedia."]);

        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            err(pesan_api($e));
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('[AMANAH API] lepas: ' . $e->getMessage());
            err('Terjadi kesalahan server.', 500);
        }
    }

    if ($aksi === 'selesai') {
        $pid   = (int) ($b['id'] ?? 0);
        $hasil = (string) ($b['hasil'] ?? '');
        try {
            $pdo->beginTransaction();
            $ekstra = ['biaya' => isset($b['biaya']) ? (float) $b['biaya'] : null];
            if ($hasil === 'dijual') {
                $ekstra['nilai_jual']     = isset($b['nilai_jual']) ? (float) $b['nilai_jual'] : null;
                $ekstra['no_bukti_setor'] = trim((string) ($b['no_bukti_setor'] ?? '')) ?: null;
                if (!$ekstra['no_bukti_setor']) {
                    throw new RuntimeException('Nomor bukti setor wajib diisi untuk penjualan.');
                }
            }
            $barangId = (int) $pdo->query(
                "SELECT barang_id FROM pelepasan_stok WHERE id = " . $pid)->fetchColumn();
            selesaikan_perbaikan($pdo, $pid, $hasil, $ekstra);
            if ($barangId) { recalc_stok($pdo, $barangId); }
            $pdo->commit();

            catat_aktivitas($pdo, 'update', 'pelepasan_stok', $pid, "Perbaikan selesai: $hasil");
            ok(['pesan' => 'Perbaikan ditutup — ' . HASIL_PERBAIKAN[$hasil][0] . '.']);

        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            err(pesan_api($e));
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('[AMANAH API] selesaikan perbaikan: ' . $e->getMessage());
            err('Terjadi kesalahan server.', 500);
        }
    }

    err('Aksi tidak dikenal.');
}

// ===================================================================== GET
only('GET');

try {
    $inRak  = rak_lingkup_api($pdo, $p);
    $syarat = $inRak === null ? '1=1' : "bb.lokasi_rak_id IN ($inRak)";

    $kandidat = $pdo->query(
        "SELECT bb.id, bb.nomor_batch, bb.qty_tersisa, bb.kondisi, bb.tanggal_expired,
                b.nama AS nama_barang, b.kode AS kode_barang, b.satuan,
                lr.kode AS kode_rak, g.nama AS nama_gudang,
                d.nama AS nama_donatur, d.anonim AS donatur_anonim, d.wilayah AS donatur_wilayah,
                (bb.tanggal_expired IS NOT NULL AND bb.tanggal_expired < CURDATE()) AS sudah_lewat
         FROM batch_barang bb
         JOIN barang b            ON b.id  = bb.barang_id
         LEFT JOIN lokasi_rak lr  ON lr.id = bb.lokasi_rak_id
         LEFT JOIN gudang g       ON g.id  = lr.gudang_id
         LEFT JOIN donatur d      ON d.id  = bb.donatur_id
         WHERE bb.qty_tersisa > 0 AND $syarat
           AND (bb.kondisi IN (" . kondisi_tidak_layak_sql() . ")
                OR (bb.tanggal_expired IS NOT NULL AND bb.tanggal_expired < CURDATE()))
         ORDER BY sudah_lewat DESC, bb.tanggal_expired ASC, b.nama"
    )->fetchAll();

    $petakanKandidat = function (array $k): array {
        [$lblK] = label_kondisi($k['kondisi']);
        return [
            'batch_id'    => (int) $k['id'],
            'nomor_batch' => $k['nomor_batch'],
            'barang'      => $k['nama_barang'],
            'kode_barang' => $k['kode_barang'],
            'satuan'      => $k['satuan'],
            'qty'         => (int) $k['qty_tersisa'],
            'kondisi'     => $k['kondisi'],
            'kondisi_label' => $lblK,
            'tanggal_expired' => $k['tanggal_expired'],
            'sudah_lewat' => (bool) $k['sudah_lewat'],
            'rak'         => $k['kode_rak'],
            'gudang'      => $k['nama_gudang'],
            'donatur'     => $k['nama_donatur']
                           ? nama_donatur_tampil(['nama' => $k['nama_donatur'],
                                                  'anonim' => $k['donatur_anonim'],
                                                  'wilayah' => $k['donatur_wilayah']])
                           : null,
        ];
    };

    $petakanCatatan = function (array $r): array {
        [$lblJ] = label_pelepasan($r['jenis']);
        [$lblH] = label_hasil($r['hasil']);
        return [
            'id'            => (int) $r['id'],
            'no_pelepasan'  => $r['no_pelepasan'],
            'tanggal'       => $r['tanggal'],
            'jenis'         => $r['jenis'],
            'jenis_label'   => $lblJ,
            'status'        => $r['status'],
            'hasil'         => $r['hasil'],
            'hasil_label'   => $r['hasil'] ? $lblH : null,
            'qty'           => (int) $r['qty'],
            'satuan'        => $r['satuan'],
            'barang'        => $r['nama_barang'],
            'kode_barang'   => $r['kode_barang'],
            'nomor_batch'   => $r['nomor_batch'],
            'alasan'        => $r['alasan'],
            'biaya'         => $r['biaya']      !== null ? (float) $r['biaya'] : null,
            'nilai_jual'    => $r['nilai_jual'] !== null ? (float) $r['nilai_jual'] : null,
            'no_bukti_setor'=> $r['no_bukti_setor'],
            'lama_hari'     => (int) $r['lama_hari'],
            'petugas'       => $r['petugas'],
            'donatur'       => $r['nama_donatur']
                             ? nama_donatur_tampil(['nama' => $r['nama_donatur'],
                                                    'anonim' => $r['donatur_anonim'],
                                                    'wilayah' => $r['donatur_wilayah']])
                             : null,
        ];
    };

    $proses  = array_map($petakanCatatan, $pdo->query(
        "SELECT * FROM v_pelepasan WHERE jenis = 'perbaikan' AND status = 'proses'
         ORDER BY lama_hari DESC, tanggal ASC")->fetchAll());

    $riwayat = array_map($petakanCatatan, $pdo->query(
        "SELECT * FROM v_pelepasan WHERE NOT (jenis = 'perbaikan' AND status = 'proses')
         ORDER BY id DESC LIMIT 40")->fetchAll());

    ok([
        'boleh_ubah' => $isManager,
        'kandidat'   => array_map($petakanKandidat, $kandidat),
        'proses'     => $proses,
        'riwayat'    => $riwayat,
        'jenis'      => array_map(function ($k, $v) {
            return ['nilai' => $k, 'label' => $v[0], 'keterangan' => $v[2]];
        }, array_keys(JENIS_PELEPASAN), JENIS_PELEPASAN),
        'hasil'      => array_map(function ($k, $v) {
            return ['nilai' => $k, 'label' => $v[0]];
        }, array_keys(HASIL_PERBAIKAN), HASIL_PERBAIKAN),
    ]);
} catch (Throwable $e) {
    error_log('[AMANAH API] pelepasan: ' . $e->getMessage());
    err('Terjadi kesalahan server.', 500);
}
