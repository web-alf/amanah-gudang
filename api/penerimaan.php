<?php
/**
 * GET  /api/penerimaan.php        — dokumen yang menunggu diterima
 * GET  /api/penerimaan.php?id=N   — satu dokumen + rincian untuk dihitung
 * POST /api/penerimaan.php        — konfirmasi penerimaan
 *   body: { id, catatan_terima?,
 *           hitung: [ { detail_id, qty_diterima, selisih_catatan? } ] }
 *
 * ---------------------------------------------------------------------
 * YANG DIPERBAIKI
 * ---------------------------------------------------------------------
 * Versi sebelumnya menambahkan stok sebesar jumlah YANG DIKIRIM, bukan
 * jumlah YANG DIHITUNG petugas. Itu menghapus satu-satunya alasan layar
 * penerimaan ada: kalau yang datang 8 dari 10, sistem tetap mencatat 10,
 * dan dua unit yang hilang tidak pernah muncul di mana pun.
 *
 * Ia juga membuat batch baru hanya dengan (barang_id, lokasi, qty) —
 * tanpa nomor batch, tanggal masuk, kondisi, sumber, maupun donatur.
 * Akibatnya barang donasi berubah menjadi barang pembelian begitu
 * berpindah lokasi, dan pertanyaan "titipan siapa" tidak bisa dijawab
 * lagi setelah barangnya sampai di gudang tujuan.
 *
 * Sekarang keduanya lewat terapkan_perpindahan() di _lib.php — fungsi
 * yang sama yang dipakai halaman web, yang mewarisi kondisi/sumber/
 * donatur dari batch asalnya.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_lingkup.php';
require_once __DIR__ . '/../_lib.php';

$payload = auth_required();
$method  = $_SERVER['REQUEST_METHOD'];

/* Menerima barang MENAMBAH stok, jadi hanya pihak yang benar-benar
   membongkar kiriman yang boleh mengonfirmasinya. Pimpinan tetap boleh
   MEMBACA — mengawasi memang tugasnya — tapi POST-nya ditolak. */
$isToko      = ($payload['role'] ?? '') === 'toko';
$bolehTerima = ($payload['role'] ?? '') === 'super_admin'
            || api_terikat_gudang($payload)
            || $isToko;

/**
 * Syarat SQL "dokumen ini ditujukan ke lokasi saya".
 *
 * Dipakai daftar maupun konfirmasi, supaya yang terlihat dan yang boleh
 * diselesaikan persis sama. Kalau keduanya ditulis terpisah, cepat atau
 * lambat salah satunya lebih longgar — dan yang lebih longgar biasanya
 * jalur POST-nya.
 */
function syarat_tujuan_saya(PDO $pdo, array $p, string $alias = 'p'): string {
    $a = $alias . '.';
    if (($p['role'] ?? '') === 'toko') {
        $ids = api_toko_saya($pdo, $p);
        $in  = $ids ? implode(',', $ids) : '0';
        return "({$a}tujuan_tipe = 'toko' AND {$a}tujuan_id IN ($in))";
    }
    if (api_terikat_gudang($p)) {
        $gid = api_gudang_id($p) ?: 0;
        $rak = api_in_rak($pdo, $p) ?: '0';
        return "(({$a}tujuan_tipe = 'gudang' AND {$a}tujuan_id = $gid)
              OR ({$a}tujuan_tipe = 'rak'    AND {$a}tujuan_id IN ($rak)))";
    }
    // Super Admin & Pimpinan melihat seluruh rantai.
    return '1=1';
}

$batasTujuan = syarat_tujuan_saya($pdo, $payload, 'p');

// ====================================================================== GET
if ($method === 'GET') {
    $id = (int) ($_GET['id'] ?? 0);

    try {
        if ($id > 0) {
            $st = $pdo->prepare(
                "SELECT p.*, u.nama AS dibuat_nama
                 FROM perpindahan p
                 LEFT JOIN users u ON u.id = p.user_id
                 WHERE p.id = ? AND $batasTujuan"
            );
            $st->execute([$id]);
            $dok = $st->fetch();
            if (!$dok) { err('Dokumen itu tidak ada, atau bukan untuk lokasi Anda.', 404); }

            $stD = $pdo->prepare(
                "SELECT d.id AS detail_id, d.barang_id, d.qty, d.qty_diterima,
                        d.nomor_batch, d.selisih_catatan,
                        b.nama AS nama_barang, b.kode AS kode_barang, b.satuan
                 FROM perpindahan_detail d
                 JOIN barang b ON b.id = d.barang_id
                 WHERE d.perpindahan_id = ? ORDER BY d.id"
            );
            $stD->execute([$id]);

            ok([
                'dokumen'       => $dok,
                'detail'        => $stD->fetchAll(),
                'boleh_terima'  => $bolehTerima && $dok['status'] === 'dikirim',
            ]);
        }

        [$limit, $offset, $page] = paginate(20);

        $stCount = $pdo->prepare(
            "SELECT COUNT(*) FROM perpindahan p WHERE p.status = 'dikirim' AND $batasTujuan");
        $stCount->execute();
        $total = (int) $stCount->fetchColumn();

        $st = $pdo->prepare(
            "SELECT p.id, p.no_perpindahan, p.no_surat_jalan, p.jenis, p.tanggal,
                    p.asal_tipe, p.asal_id, p.asal_teks,
                    p.tujuan_tipe, p.tujuan_id, p.tujuan_teks,
                    p.no_resi, p.ekspedisi, p.pengirim, p.catatan,
                    u.nama AS dibuat_nama,
                    (SELECT COUNT(*)                FROM perpindahan_detail WHERE perpindahan_id=p.id) AS jml_item,
                    (SELECT COALESCE(SUM(qty),0)    FROM perpindahan_detail WHERE perpindahan_id=p.id) AS total_qty
             FROM perpindahan p
             LEFT JOIN users u ON u.id = p.user_id
             WHERE p.status = 'dikirim' AND $batasTujuan
             ORDER BY p.tanggal ASC, p.id ASC
             LIMIT $limit OFFSET $offset"
        );
        $st->execute();
        $data = $st->fetchAll();

        // Rincian ikut sekali jalan supaya petugas bisa mulai menghitung
        // tanpa membuka tiap dokumen satu per satu lebih dulu.
        $rinci = [];
        if ($data) {
            $ids = implode(',', array_map(fn($r) => (int) $r['id'], $data));
            foreach ($pdo->query(
                "SELECT d.perpindahan_id, d.id AS detail_id, d.barang_id, d.qty, d.nomor_batch,
                        b.nama AS nama_barang, b.kode AS kode_barang, b.satuan
                 FROM perpindahan_detail d
                 JOIN barang b ON b.id = d.barang_id
                 WHERE d.perpindahan_id IN ($ids) ORDER BY d.id")->fetchAll() as $r) {
                $rinci[(int) $r['perpindahan_id']][] = $r;
            }
        }
        foreach ($data as &$d) { $d['detail'] = $rinci[(int) $d['id']] ?? []; }
        unset($d);

        ok(['data' => $data, 'total' => $total, 'limit' => $limit, 'page' => $page,
            'boleh_terima' => $bolehTerima]);
    } catch (Throwable $e) {
        error_log('[AMANAH API] penerimaan baca: ' . $e->getMessage());
        err('Gagal mengambil data.', 500);
    }
}

// ===================================================================== POST
if ($method === 'POST') {
    if (!$bolehTerima) {
        err('Akun Anda boleh membaca daftar penerimaan, tetapi tidak mengonfirmasinya.', 403);
    }

    $b       = body();
    $id      = (int) ($b['id'] ?? 0);
    $catatan = trim($b['catatan_terima'] ?? '');
    if (!$id) { err('id wajib diisi.'); }

    // Hasil hitung fisik, dikunci per id baris rincian.
    $hitung = [];
    foreach (($b['hitung'] ?? []) as $h) {
        $did = (int) ($h['detail_id'] ?? 0);
        if ($did > 0) { $hitung[$did] = $h; }
    }

    try {
        $pdo->beginTransaction();

        $st = $pdo->prepare("SELECT * FROM perpindahan WHERE id = ? FOR UPDATE");
        $st->execute([$id]);
        $pp = $st->fetch();
        if (!$pp) { throw new RuntimeException('Dokumen tidak ditemukan.'); }

        // Lingkup diperiksa ulang di sini, bukan dipercayakan pada daftar.
        $cek = $pdo->prepare("SELECT 1 FROM perpindahan p WHERE p.id = ? AND $batasTujuan");
        $cek->execute([$id]);
        if (!$cek->fetchColumn()) { throw new RuntimeException('Dokumen ini bukan untuk lokasi Anda.'); }

        /* HANYA 'dikirim'. Dokumen 'disetujui' berarti stoknya sudah
           diterapkan saat persetujuan (jenis tanpa pihak penerima) —
           menerimanya lagi di sini akan menggandakan stok. */
        if ($pp['status'] !== 'dikirim') {
            throw new RuntimeException('Dokumen ini tidak menunggu penerimaan (status: '
                . $pp['status'] . ').');
        }

        $stD = $pdo->prepare("SELECT * FROM perpindahan_detail WHERE perpindahan_id = ? ORDER BY id");
        $stD->execute([$id]);
        $detail = $stD->fetchAll();
        if (!$detail) { throw new RuntimeException('Dokumen tidak memiliki rincian barang.'); }

        $updD = $pdo->prepare(
            "UPDATE perpindahan_detail SET qty_diterima = ?, selisih_catatan = ?
             WHERE id = ? AND perpindahan_id = ?"
        );

        $totalTerima = 0;
        $adaSelisih  = false;
        foreach ($detail as $i => $d) {
            $did     = (int) $d['id'];
            $dikirim = (int) $d['qty'];

            // Baris yang tidak disebut dianggap diterima utuh — itulah
            // keadaan yang paling sering, dan memaksa aplikasi mengirim
            // semua baris hanya menambah peluang salah.
            $terima = array_key_exists($did, $hitung) && $hitung[$did]['qty_diterima'] !== null
                    ? (int) $hitung[$did]['qty_diterima']
                    : $dikirim;

            /* DIBATASI 0..jumlah yang dikirim. Tanpa batas atas, petugas
               penerima bisa mengetik angka berapa pun dan sistem akan
               menciptakan stok dari udara — tanpa melewati persetujuan
               siapa pun. */
            $terima = max(0, min($dikirim, $terima));
            $ket    = trim($hitung[$did]['selisih_catatan'] ?? '');

            // Kekurangan wajib dijelaskan, supaya bisa dikonfirmasi ke
            // pengirim dan tidak ada barang yang hilang tanpa jejak.
            if ($terima < $dikirim && $ket === '') {
                throw new RuntimeException(
                    'Baris ' . ($i + 1) . ' (' . ($d['nomor_batch'] ?: 'tanpa nomor batch')
                  . ') diterima kurang dari jumlah di surat jalan. Isi dulu catatan '
                  . 'selisihnya sebelum menyimpan.'
                );
            }

            if ($terima !== $dikirim) { $adaSelisih = true; }
            $totalTerima += $terima;

            $updD->execute([$terima, $ket ?: null, $did, $id]);
            $detail[$i]['qty_diterima'] = $terima;
        }

        if ($totalTerima <= 0) {
            throw new RuntimeException(
                'Seluruh baris tercatat diterima 0. Bila barang memang tidak datang sama '
              . 'sekali, jangan tandai diterima — hubungi pengirim agar dokumennya dibatalkan.'
            );
        }

        // Baru di sinilah stok benar-benar berpindah, mewarisi kondisi,
        // sumber, dan donatur dari batch asalnya.
        terapkan_perpindahan($pdo, $pp, $detail);

        $pdo->prepare(
            "UPDATE perpindahan
             SET status = 'selesai', diterima_oleh = ?, diterima_at = NOW(), catatan_terima = ?
             WHERE id = ?"
        )->execute([(int) $payload['user_id'], $catatan ?: null, $id]);

        $pdo->commit();

        catat_aktivitas($pdo, 'receive', 'perpindahan', $id,
            "Terima {$pp['no_perpindahan']}: $totalTerima unit"
            . ($adaSelisih ? ' (ada selisih)' : ''));

        ok([
            'status'       => 'selesai',
            'total_terima' => $totalTerima,
            'ada_selisih'  => $adaSelisih,
            'pesan'        => "Penerimaan {$pp['no_perpindahan']} dikonfirmasi — $totalTerima unit masuk stok."
                            . ($adaSelisih ? ' Ada selisih dari surat jalan; catatannya tersimpan.' : ''),
        ]);

    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        err(pesan_api($e));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('[AMANAH API] penerimaan simpan: ' . $e->getMessage());
        err('Gagal menyimpan penerimaan.', 500);
    }
}

err('Method tidak diizinkan.', 405);
