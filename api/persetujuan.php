<?php
/**
 * GET  /api/persetujuan.php          — antrean dokumen menunggu persetujuan
 * GET  /api/persetujuan.php?id=N     — satu dokumen + rincian barangnya
 * POST /api/persetujuan.php          — kirim / setujui / tolak
 *   body: { id, aksi: 'kirim'|'setujui'|'tolak', alasan? }
 *
 * ---------------------------------------------------------------------
 * TIGA KERUSAKAN YANG DIPERBAIKI
 * ---------------------------------------------------------------------
 * 1. MENYETUJUI TIDAK MEMINDAHKAN APA PUN. Versi sebelumnya hanya
 *    menjalankan UPDATE status='disetujui'. Stok tidak berpindah, nomor
 *    surat jalan tidak pernah terbit. Dokumen tampak beres di layar
 *    sementara barangnya tidak ke mana-mana — dan selisihnya baru
 *    ketahuan saat stok fisik dihitung. Sekarang memanggil
 *    sahkan_perpindahan() dari _lib.php, fungsi yang sama yang dipakai
 *    halaman web.
 *
 * 2. KOLOM YANG TIDAK ADA. UPDATE-nya menulis dikirim_at, dikirim_oleh,
 *    disetujui_at, disetujui_oleh, dan SELECT-nya membaca dibuat_oleh.
 *    Tidak satu pun ada; nama sebenarnya approved_at, approved_by, dan
 *    user_id. Jadi ketiga aksinya pasti gagal.
 *
 * 3. TANPA BATAS LINGKUP. GET-nya mengembalikan seluruh antrean lembaga,
 *    dan POST-nya menerima id mana pun. Petugas gudang A bisa membaca —
 *    bahkan mengirim — dokumen gudang B hanya dengan menebak angka id.
 *    Ini persis kebocoran yang sudah diperbaiki di halaman web.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_lingkup.php';
require_once __DIR__ . '/../_lib.php';

$p      = auth_required();
$method = $_SERVER['REQUEST_METHOD'];

/* Batas baca yang sama dengan halaman web: dokumen dalam lingkup, ATAU
   dokumen yang memang harus disetujui akun ini meski di luar lingkupnya. */
$batas = '(' . api_syarat_perpindahan($pdo, $p, 'p')
       . ' OR ' . api_syarat_persetujuan_saya($pdo, $p, 'p') . ')';

// ====================================================================== GET
if ($method === 'GET') {
    $id = (int) ($_GET['id'] ?? 0);

    try {
        if ($id > 0) {
            $st = $pdo->prepare(
                "SELECT p.*, u.nama AS pembuat, ua.nama AS penyetuju
                 FROM perpindahan p
                 LEFT JOIN users u  ON u.id  = p.user_id
                 LEFT JOIN users ua ON ua.id = p.approved_by
                 WHERE p.id = ? AND $batas"
            );
            $st->execute([$id]);
            $dok = $st->fetch();
            if (!$dok) { err('Dokumen itu tidak ada, atau bukan untuk gudang Anda.', 404); }

            $stD = $pdo->prepare(
                "SELECT d.id, d.barang_id, d.qty, d.qty_diterima, d.nomor_batch, d.harga, d.subtotal,
                        b.nama AS nama_barang, b.kode AS kode_barang, b.satuan
                 FROM perpindahan_detail d
                 JOIN barang b ON b.id = d.barang_id
                 WHERE d.perpindahan_id = ? ORDER BY d.id"
            );
            $stD->execute([$id]);

            ok([
                'dokumen'     => $dok,
                'detail'      => $stD->fetchAll(),
                'boleh_setujui' => api_boleh_setujui_dokumen($p, $dok),
            ]);
        }

        [$limit, $offset, $page] = paginate(20);
        $status = $_GET['status'] ?? 'menunggu_persetujuan';
        $jenis  = trim($_GET['jenis'] ?? '');

        $where = ['p.status = ?', $batas];
        $par   = [$status];
        if ($jenis !== '') { $where[] = 'p.jenis = ?'; $par[] = $jenis; }
        $wSql = implode(' AND ', $where);

        $stCount = $pdo->prepare("SELECT COUNT(*) FROM perpindahan p WHERE $wSql");
        $stCount->execute($par);
        $total = (int) $stCount->fetchColumn();

        $st = $pdo->prepare(
            "SELECT p.id, p.no_perpindahan, p.no_surat_jalan, p.jenis, p.status, p.tanggal,
                    p.asal_tipe, p.asal_id, p.asal_teks, p.tujuan_tipe, p.tujuan_id, p.tujuan_teks,
                    p.catatan, p.alasan_tolak,
                    u.nama AS dibuat_nama,
                    (SELECT COALESCE(SUM(qty),0) FROM perpindahan_detail WHERE perpindahan_id=p.id) AS total_qty,
                    (SELECT COUNT(*)             FROM perpindahan_detail WHERE perpindahan_id=p.id) AS jml_item
             FROM perpindahan p
             LEFT JOIN users u ON u.id = p.user_id
             WHERE $wSql
             ORDER BY p.tanggal ASC, p.id ASC
             LIMIT $limit OFFSET $offset"
        );
        $st->execute($par);
        $data = $st->fetchAll();

        /* Rincian tiap dokumen ikut dikirim sekali jalan. Menyetujui berarti
           membandingkan beberapa dokumen berturut-turut; kalau tiap baris
           harus dibuka satu per satu, aplikasi memanggil server sebanyak
           jumlah antrean hanya untuk bisa memutuskan. */
        $rinci = [];
        if ($data) {
            $ids = implode(',', array_map(fn($r) => (int) $r['id'], $data));
            $stR = $pdo->query(
                "SELECT d.perpindahan_id, d.qty, d.nomor_batch,
                        b.nama AS nama_barang, b.kode AS kode_barang, b.satuan
                 FROM perpindahan_detail d
                 JOIN barang b ON b.id = d.barang_id
                 WHERE d.perpindahan_id IN ($ids) ORDER BY d.id"
            );
            foreach ($stR->fetchAll() as $r) { $rinci[(int) $r['perpindahan_id']][] = $r; }
        }
        foreach ($data as &$d) {
            $d['detail'] = $rinci[(int) $d['id']] ?? [];
            $d['boleh_setujui'] = api_boleh_setujui_dokumen($p, $d);
        }
        unset($d);

        ok(['data' => $data, 'total' => $total, 'limit' => $limit, 'page' => $page]);
    } catch (Throwable $e) {
        error_log('[AMANAH API] persetujuan baca: ' . $e->getMessage());
        err('Gagal mengambil data.', 500);
    }
}

// ===================================================================== POST
if ($method === 'POST') {
    $b    = body();
    $id   = (int) ($b['id'] ?? 0);
    $aksi = $b['aksi'] ?? '';
    if (!$id)   { err('id wajib diisi.'); }
    if (!in_array($aksi, ['kirim', 'setujui', 'tolak', 'batal', 'hapus'], true)) {
        err("Aksi '$aksi' tidak dikenal. Yang tersedia: kirim, setujui, tolak, batal, hapus.");
    }

    // Dibaca lepas dari transaksi: yang dinilai di sini izin, bukan stok.
    $st = $pdo->prepare(
        "SELECT p.* FROM perpindahan p WHERE p.id = ? AND $batas");
    $st->execute([$id]);
    $pp = $st->fetch();
    if (!$pp) { err('Dokumen itu tidak ada, atau bukan untuk gudang Anda.', 404); }

    // Dipakai sahkan_perpindahan() dan catat_aktivitas() untuk mengisi pelaku.
    $_SESSION['user_id'] = (int) $p['user_id'];

    try {
        if ($aksi === 'kirim') {
            if ($pp['status'] !== 'draft') {
                err("Hanya dokumen draft yang bisa dikirim untuk persetujuan "
                  . "(status saat ini: {$pp['status']}).");
            }
            $pdo->prepare("UPDATE perpindahan SET status='menunggu_persetujuan' WHERE id=?")
                ->execute([$id]);
            catat_aktivitas($pdo, 'submit', 'perpindahan', $id, 'Kirim untuk persetujuan');
            ok(['status' => 'menunggu_persetujuan',
                'pesan'  => "Dokumen {$pp['no_perpindahan']} dikirim untuk persetujuan."]);
        }

        /* BATAL & HAPUS — pintu keluar yang selama ini tidak ada.
           Aturannya di batalkan_perpindahan() / hapus_draft_perpindahan()
           (_lib.php), sama dengan halaman web. Draft dan dokumen yang
           menunggu belum menyentuh stok; 'dikirim' pun belum, karena stok
           baru berpindah saat penerimaan — jadi ketiganya bisa dihentikan
           tanpa membalik apa pun. */
        $stafGudang = in_array($p['role'] ?? '', ['super_admin', 'admin_gudang', 'admin_terpadu'], true);
        if ($aksi === 'batal') {
            $alasan = trim($b['alasan'] ?? '');
            if ($alasan === '') { err('Alasan pembatalan wajib diisi.'); }
            if (!$stafGudang && !api_boleh_setujui_dokumen($p, $pp)) {
                err('Akun Anda tidak berwenang membatalkan dokumen ini.', 403);
            }
            batalkan_perpindahan($pdo, $id, (int) $p['user_id'], $alasan);
            ok(['status' => 'dibatalkan', 'pesan' => "Dokumen {$pp['no_perpindahan']} dibatalkan."]);
        }
        if ($aksi === 'hapus') {
            if (!$stafGudang) { err('Hanya staf gudang yang bisa menghapus draft.', 403); }
            hapus_draft_perpindahan($pdo, $id);
            ok(['status' => 'dihapus', 'pesan' => "Draft {$pp['no_perpindahan']} dihapus."]);
        }

        // Dua aksi berikut mengubah nasib dokumen — izinnya diperiksa
        // terhadap DOKUMENNYA, bukan sekadar peran pemanggil.
        if (!api_boleh_setujui_dokumen($p, $pp)) {
            err('Akun Anda tidak berwenang memutuskan dokumen ini.', 403);
        }
        if ($pp['status'] !== 'menunggu_persetujuan') {
            err("Statusnya harus 'menunggu_persetujuan' (saat ini: {$pp['status']}).");
        }

        if ($aksi === 'tolak') {
            $alasan = trim($b['alasan'] ?? '');
            if ($alasan === '') {
                err('Alasan penolakan wajib diisi — pembuat dokumen perlu tahu apa yang harus diperbaiki.');
            }
            $pdo->prepare(
                "UPDATE perpindahan
                 SET status='ditolak', alasan_tolak=?, approved_by=?, approved_at=NOW()
                 WHERE id=?"
            )->execute([$alasan, (int) $p['user_id'], $id]);
            catat_aktivitas($pdo, 'reject', 'perpindahan', $id, "Tolak perpindahan: $alasan");
            ok(['status' => 'ditolak', 'pesan' => "Dokumen {$pp['no_perpindahan']} ditolak."]);
        }

        // setujui — inilah yang benar-benar memindahkan stok.
        $hasil = sahkan_perpindahan($pdo, $id, (int) $p['user_id']);
        catat_aktivitas($pdo, 'approve', 'perpindahan', $id,
            "Setujui perpindahan {$hasil['no_perpindahan']}, surat jalan {$hasil['no_sj']}");

        ok([
            'status'       => $hasil['status'],
            'no_surat_jalan' => $hasil['no_sj'],
            'ada_penerima' => $hasil['ada_penerima'],
            'pesan'        => $hasil['ada_penerima']
                ? "Disetujui. Surat jalan {$hasil['no_sj']} terbit; stok berpindah setelah "
                . "penerimaan dikonfirmasi di tujuan."
                : "Disetujui. Surat jalan {$hasil['no_sj']} terbit dan stok sudah berpindah.",
        ]);

    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        err(pesan_api($e));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('[AMANAH API] persetujuan aksi: ' . $e->getMessage());
        err('Terjadi kesalahan server.', 500);
    }
}

err('Method tidak diizinkan.', 405);
