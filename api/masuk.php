<?php
/**
 * GET  /api/masuk.php              — daftar transaksi masuk
 * GET  /api/masuk.php?id=N         — detail + item
 * POST /api/masuk.php              — catat transaksi masuk baru
 *
 * ---------------------------------------------------------------------
 * MENGAPA BERKAS INI MEMANGGIL _lib.php
 * ---------------------------------------------------------------------
 * Versi sebelumnya menyimpan sendiri: INSERT ke `transaksi_masuk`
 * (nomor_dokumen, dibuat_oleh) dan `batch_barang` (no_batch,
 * tgl_kadaluarsa, transaksi_masuk_id). Tidak satu pun kolom itu ada —
 * nama sebenarnya no_transaksi, user_id, nomor_batch, tanggal_expired.
 * Jadi setiap POST pasti gagal. Tidak pernah ketahuan karena aplikasi
 * Android sampai sekarang hanya membaca, tidak pernah menyimpan.
 *
 * Sebabnya bukan salah ketik, melainkan aturan yang sama ditulis dua kali.
 * Karena itu sekarang jalur simpannya memakai fungsi yang dipakai halaman
 * web: tambah_ke_rak(), donatur_cocok_atau_buat(), recalc_stok(),
 * dengan_nomor_dokumen(). Satu aturan, satu tempat — kalau berubah,
 * berubah untuk web dan ponsel sekaligus.
 *
 * Ikut dibawa serta yang hilang dari API lama: kondisi tiga nilai,
 * sumber pembelian/donasi, pencatatan donatur, dan jenis donasi.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../_lib.php';

$payload = auth_required();
if (!in_array($payload['role'], ['super_admin', 'admin_gudang', 'admin_terpadu'], true)) {
    err('Hak akses tidak cukup.', 403);
}

$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id']) ? (int) $_GET['id'] : null;

/** Rak yang boleh disentuh akun ini, atau null bila tak dibatasi. */
function rak_boleh(PDO $pdo, array $p): ?array {
    if (!in_array($p['role'], ['admin_gudang', 'admin_terpadu'], true)) { return null; }
    $gid = $p['gudang_id'] !== null ? (int) $p['gudang_id'] : 0;
    if ($gid <= 0) { return []; }
    return array_map('intval', $pdo->query(
        "SELECT id FROM lokasi_rak WHERE gudang_id = $gid AND deleted_at IS NULL"
    )->fetchAll(PDO::FETCH_COLUMN));
}

// ====================================================================== GET
if ($method === 'GET') {
    if ($id) {
        try {
            $st = $pdo->prepare(
                "SELECT h.*, u.nama AS dibuat_nama, sp.nama AS supplier_nama
                 FROM transaksi_masuk h
                 LEFT JOIN users u     ON u.id  = h.user_id
                 LEFT JOIN supplier sp ON sp.id = h.supplier_id
                 WHERE h.id = ?"
            );
            $st->execute([$id]);
            $header = $st->fetch();
            if (!$header) { err('Transaksi tidak ditemukan.', 404); }

            /* Kondisi, sumber, dan donatur tinggal di batch_barang, bukan di
               baris detail — jadi detailnya di-JOIN ke batch supaya jawaban
               "barang siapa, kondisi apa" ikut terbawa ke ponsel. */
            $stDet = $pdo->prepare(
                "SELECT d.id, d.barang_id, d.qty, d.harga, d.subtotal,
                        d.nomor_batch, d.tanggal_expired, d.lokasi_rak_id,
                        b.nama AS barang_nama, b.satuan, b.kode AS barang_kode,
                        lr.kode AS rak_kode, g.nama AS gudang_nama,
                        bb.kondisi, bb.sumber,
                        dn.nama AS donatur_nama, dn.anonim AS donatur_anonim,
                        dn.wilayah AS donatur_wilayah, dn.kode AS donatur_kode
                 FROM transaksi_masuk_detail d
                 JOIN barang b            ON b.id  = d.barang_id
                 LEFT JOIN batch_barang bb ON bb.id = d.batch_id
                 LEFT JOIN lokasi_rak lr  ON lr.id = d.lokasi_rak_id
                 LEFT JOIN gudang g       ON g.id  = lr.gudang_id
                 LEFT JOIN donatur dn     ON dn.id = bb.donatur_id
                 WHERE d.transaksi_masuk_id = ?"
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
                    'harga'           => (float) $r['harga'],
                    'subtotal'        => (float) $r['subtotal'],
                    'nomor_batch'     => $r['nomor_batch'],
                    'tanggal_expired' => $r['tanggal_expired'],
                    'rak_kode'        => $r['rak_kode'],
                    'gudang_nama'     => $r['gudang_nama'],
                    'kondisi'         => $r['kondisi'],
                    'kondisi_label'   => $lblK,
                    'sumber'          => $r['sumber'],
                    // Nama donatur selalu lewat nama_donatur_tampil(): yang
                    // minta dirahasiakan tidak boleh bocor lewat layar detail.
                    'donatur'         => $r['donatur_nama']
                        ? nama_donatur_tampil(['nama' => $r['donatur_nama'],
                                               'anonim' => $r['donatur_anonim'],
                                               'wilayah' => $r['donatur_wilayah']])
                        : null,
                    'donatur_kode'    => $r['donatur_kode'],
                ];
            }
            ok(['header' => $header, 'detail' => $detail]);
        } catch (Throwable $e) {
            error_log('[AMANAH API] masuk detail: ' . $e->getMessage());
            err('Gagal mengambil data.', 500);
        }
    }

    [$limit, $offset, $page] = paginate(20);
    $dari = $_GET['dari'] ?? date('Y-m-01');
    $ke   = $_GET['ke']   ?? date('Y-m-d');

    $where = ['DATE(h.tanggal) BETWEEN ? AND ?'];
    $par   = [$dari, $ke];

    $rak = rak_boleh($pdo, $payload);
    if ($rak !== null) {
        $in = $rak ? implode(',', $rak) : '0';
        $where[] = "EXISTS(SELECT 1 FROM transaksi_masuk_detail d2
                           WHERE d2.transaksi_masuk_id = h.id AND d2.lokasi_rak_id IN ($in))";
    }

    // Saringan yang sama dengan halaman web.
    $sumber = $_GET['sumber'] ?? '';
    if (in_array($sumber, ['pembelian', 'donasi'], true)) {
        $where[] = "EXISTS(SELECT 1 FROM transaksi_masuk_detail d3
                           JOIN batch_barang bb3 ON bb3.id = d3.batch_id
                           WHERE d3.transaksi_masuk_id = h.id AND bb3.sumber = ?)";
        $par[] = $sumber;
    }
    $kondisi = $_GET['kondisi'] ?? '';
    if (isset(KONDISI_BARANG[$kondisi])) {
        $where[] = "EXISTS(SELECT 1 FROM transaksi_masuk_detail d4
                           JOIN batch_barang bb4 ON bb4.id = d4.batch_id
                           WHERE d4.transaksi_masuk_id = h.id AND bb4.kondisi = ?)";
        $par[] = $kondisi;
    }
    if (!empty($_GET['supplier_id'])) {
        $where[] = 'h.supplier_id = ?'; $par[] = (int) $_GET['supplier_id'];
    }
    $q = trim($_GET['q'] ?? '');
    if ($q !== '') {
        $where[] = '(h.no_transaksi LIKE ? OR h.catatan LIKE ?)';
        $like = '%' . like_escape($q) . '%';
        array_push($par, $like, $like);
    }

    $sqlWhere = 'WHERE ' . implode(' AND ', $where);

    try {
        $stCount = $pdo->prepare("SELECT COUNT(*) FROM transaksi_masuk h $sqlWhere");
        $stCount->execute($par);
        $total = (int) $stCount->fetchColumn();

        $st = $pdo->prepare(
            "SELECT h.id, h.no_transaksi, h.tanggal, h.total, h.catatan,
                    sp.nama AS supplier_nama,
                    COUNT(DISTINCT d.id) AS jml_item,
                    COALESCE(SUM(d.qty),0) AS total_qty,
                    u.nama AS dibuat_nama,
                    MAX(bb.sumber) AS sumber
             FROM transaksi_masuk h
             LEFT JOIN supplier sp ON sp.id = h.supplier_id
             LEFT JOIN users u     ON u.id  = h.user_id
             LEFT JOIN transaksi_masuk_detail d ON d.transaksi_masuk_id = h.id
             LEFT JOIN batch_barang bb ON bb.id = d.batch_id
             $sqlWhere
             GROUP BY h.id
             ORDER BY h.tanggal DESC, h.id DESC
             LIMIT $limit OFFSET $offset"
        );
        $st->execute($par);
        ok(['data' => $st->fetchAll(), 'total' => $total, 'limit' => $limit, 'page' => $page]);
    } catch (Throwable $e) {
        error_log('[AMANAH API] masuk daftar: ' . $e->getMessage());
        err('Gagal mengambil data.', 500);
    }
}

// ===================================================================== POST
if ($method === 'POST') {
    $b          = body();
    $supplierId = !empty($b['supplier_id']) ? (int) $b['supplier_id'] : null;
    $catatan    = trim($b['catatan'] ?? '');
    $sumberMasuk = sumber_sah($b['sumber'] ?? 'pembelian');
    $jenisDonasi = jenis_donasi_sah($b['jenis_donasi'] ?? null);

    $donaturIsi = [
        'nama'    => trim($b['donatur_nama'] ?? ''),
        'telepon' => trim($b['donatur_telepon'] ?? ''),
        'jenis'   => $b['donatur_jenis'] ?? 'pribadi',
        'wilayah' => trim($b['donatur_wilayah'] ?? ''),
        'anonim'  => !empty($b['donatur_anonim']),
    ];

    /* Aturan yang sama dengan web: barang titipan harus jelas dari siapa.
       Nama + nomor HP itulah kunci pengenal donatur lama; tanpa keduanya
       setiap penerimaan akan melahirkan kode donatur baru dan peringkat
       "paling sering memberi" jadi tidak ada artinya. */
    if ($sumberMasuk === 'donasi' && $donaturIsi['nama'] === '') {
        err('Barang donasi wajib menyebutkan nama donaturnya.');
    }
    if ($sumberMasuk === 'donasi' && kunci_telepon($donaturIsi['telepon']) === '') {
        err('Nomor HP donatur wajib diisi — bersama namanya, itulah yang dipakai '
          . 'sistem untuk mengenali donatur yang sudah pernah memberi.');
    }

    $items = [];
    foreach (($b['items'] ?? []) as $i => $it) {
        $bid = (int) ($it['barang_id'] ?? 0);
        $qty = (int) ($it['qty'] ?? 0);
        if ($bid <= 0 || $qty <= 0) { continue; }
        $rak = (int) ($it['lokasi_rak_id'] ?? 0) ?: null;
        if (!$rak) {
            err('Item ke-' . ($i + 1) . ': lokasi rak wajib diisi agar stok per gudang terhitung benar.');
        }
        $items[] = [
            'barang_id' => $bid,
            'qty'       => $qty,
            'batch'     => trim($it['nomor_batch'] ?? '') ?: ('B' . date('ymd')),
            'exp'       => trim($it['tanggal_expired'] ?? '') ?: null,
            'rak'       => $rak,
            'kondisi'   => kondisi_sah($it['kondisi'] ?? 'baru'),
            'harga'     => (float) ($it['harga'] ?? 0),
        ];
    }
    if (!$items) { err('Minimal satu item wajib diisi.'); }

    // Rak di luar gudang sendiri ditolak di server. Aplikasi memang hanya
    // menawarkan rak yang benar, tapi tokennya bisa dipakai dari mana saja.
    $rakBoleh = rak_boleh($pdo, $payload);
    if ($rakBoleh !== null) {
        foreach ($items as $n => $it) {
            if (!in_array($it['rak'], $rakBoleh, true)) {
                err('Item ke-' . ($n + 1) . ': rak itu bukan milik gudang Anda.', 403);
            }
        }
    }

    $cocokDonatur = null;
    try {
        $hasil = dengan_nomor_dokumen($pdo, 'transaksi_masuk', 'no_transaksi', 'TM',
            function (string $noTrx) use ($pdo, $supplierId, $catatan, $items, $payload,
                                          $sumberMasuk, $donaturIsi, $jenisDonasi, &$cocokDonatur) {
                $pdo->beginTransaction();
                kunci_stok($pdo, array_column($items, 'barang_id'));

                $total = 0;
                foreach ($items as $it) { $total += $it['qty'] * $it['harga']; }

                $pdo->prepare(
                    "INSERT INTO transaksi_masuk (no_transaksi, tanggal, supplier_id, user_id, total, catatan)
                     VALUES (?, NOW(), ?, ?, ?, ?)"
                )->execute([$noTrx, $supplierId, (int) $payload['user_id'], $total, $catatan ?: null]);
                $trxId = (int) $pdo->lastInsertId();

                /* Titipan lewat jalur ini juga dicatat sebagai satu peristiwa
                   memberi. Peringkat donatur dihitung dari tabel `donasi`;
                   tanpa baris ini donatur_id memang menempel di batch, tapi
                   pemberiannya tidak pernah terhitung. */
                $donaturMasuk = null;
                if ($sumberMasuk === 'donasi') {
                    $cocokDonatur = donatur_cocok_atau_buat($pdo, $donaturIsi, null);
                    $donaturMasuk = $cocokDonatur['id'];

                    $noDonasi = nomor_dokumen($pdo, 'donasi', 'no_donasi', 'DN');
                    $pdo->prepare(
                        "INSERT INTO donasi (no_donasi, donatur_id, supplier_id, tanggal,
                                             jenis, cara_terima, catatan, dibuat_oleh)
                         VALUES (?,?,?,CURDATE(),?,'diantar',?,?)"
                    )->execute([$noDonasi, $donaturMasuk, $supplierId, $jenisDonasi,
                                $catatan ?: null, (int) $payload['user_id']]);
                    $donasiId = (int) $pdo->lastInsertId();
                    /* Sambungan penerimaan ↔ donasi. Jenis donasi TIDAK diwajibkan di
                       sini: aplikasi Android versi lama mengirim 'umum', dan menolaknya
                       berarti petugas lapangan tidak bisa menyimpan sama sekali.
                       Penerimaan itu tampil sebagai "Belum diklasifikasi". */
                    if (kolom_ada($pdo, 'transaksi_masuk', 'donasi_id')) {
                        $pdo->prepare("UPDATE transaksi_masuk SET donasi_id = ? WHERE id = ?")->execute([$donasiId, $trxId]);
                    }

                    $stDD = $pdo->prepare(
                        "INSERT INTO donasi_detail
                           (donasi_id, barang_id, kondisi, nomor_batch, tanggal_expired, qty, nilai_taksir)
                         VALUES (?,?,?,?,?,?,?)"
                    );
                    foreach ($items as $it) {
                        $stDD->execute([$donasiId, $it['barang_id'], $it['kondisi'], $it['batch'],
                                        $it['exp'], $it['qty'], $it['qty'] * $it['harga']]);
                    }
                }

                $stD = $pdo->prepare(
                    "INSERT INTO transaksi_masuk_detail
                       (transaksi_masuk_id, barang_id, batch_id, nomor_batch, tanggal_expired,
                        lokasi_rak_id, qty, harga, subtotal)
                     VALUES (?,?,?,?,?,?,?,?,?)"
                );

                $unik = [];
                foreach ($items as $it) {
                    $batchId = tambah_ke_rak($pdo, $it['barang_id'], $it['rak'], $it['batch'],
                                             $it['exp'], $it['qty'], $it['harga'], $supplierId,
                                             $it['kondisi'], $sumberMasuk, $donaturMasuk);
                    $stD->execute([$trxId, $it['barang_id'], $batchId, $it['batch'], $it['exp'],
                                   $it['rak'], $it['qty'], $it['harga'], $it['qty'] * $it['harga']]);
                    $unik[$it['barang_id']] = true;
                }
                foreach (array_keys($unik) as $bid) { recalc_stok($pdo, (int) $bid); }

                $pdo->commit();
                return ['id' => $trxId, 'no' => $noTrx];
            });

        $totQty = array_sum(array_column($items, 'qty'));
        catat_aktivitas($pdo, 'create', 'transaksi_masuk', $hasil['id'],
            "Barang masuk {$hasil['no']}: " . count($items) . ' jenis, ' . $totQty . ' unit');

        $pesan = "Barang masuk {$hasil['no']} tercatat — " . count($items) . ' jenis, ' . $totQty . ' unit.';
        $info  = null;
        if ($cocokDonatur) {
            $pesan .= $cocokDonatur['baru']
                ? ' Donatur baru terdaftar dengan kode ' . $cocokDonatur['kode'] . '.'
                : ' Tercatat atas donatur lama ' . $cocokDonatur['kode'] . '.';
            $info = [
                'kode'    => $cocokDonatur['kode'],
                'baru'    => (bool) $cocokDonatur['baru'],
                'bentrok' => !empty($cocokDonatur['bentrok']),
            ];
        }

        ok(['id' => $hasil['id'], 'no_transaksi' => $hasil['no'],
            'donatur' => $info, 'pesan' => $pesan]);

    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        err(pesan_api($e));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('[AMANAH API] masuk simpan: ' . $e->getMessage());
        err('Gagal menyimpan barang masuk.', 500);
    }
}

err('Method tidak diizinkan.', 405);
