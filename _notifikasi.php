<?php
/**
 * AMANAH — Penyusun daftar notifikasi, dipakai WEB dan API.
 *
 * Notifikasi di sistem ini tidak disimpan di tabel; ia DITURUNKAN dari
 * keadaan data saat ditanya: dokumen yang menunggu, kiriman di jalan,
 * batch yang kedaluwarsa, stok yang habis, perbaikan yang menggantung.
 * Tabel `notifikasi` di skema tidak pernah ditulis — dan itu disengaja:
 * notifikasi yang disimpan bisa basi, sementara yang diturunkan selalu
 * benar pada saat dibaca.
 *
 * Sebelum 13 Sep 2026 logika ini hanya ada di api/notifikasi.php, jadi
 * aplikasi Android punya pusat notifikasi sementara web hanya lonceng
 * berangka. Sekarang keduanya memanggil satu fungsi ini; $p adalah
 * principal berbentuk isi token (lihat principal_sesi() untuk web).
 *
 * Berkas berawalan "_" — tertutup dari peramban lewat .htaccess.
 */

function butir_notifikasi(string $tipe, string $judul, string $pesan, string $rute,
                          int $jumlah, string $tingkat): array {
    return [
        'tipe'    => $tipe,
        'judul'   => $judul,
        'pesan'   => $pesan,
        'rute'    => $rute,
        'jumlah'  => $jumlah,
        // penting | waspada | info — menentukan warna dan urutannya.
        'tingkat' => $tingkat,
    ];
}

/**
 * @return array{data: array, jumlah: int, penting: int}
 */
function susun_notifikasi(PDO $pdo, array $p): array {
    $daftar = [];
    $inRak  = api_in_rak($pdo, $p);          // '' = tak dibatasi
    $syaratRak = $inRak === '' ? '1=1' : "bb.lokasi_rak_id IN ($inRak)";

    // ------------------------------------------- dokumen menunggu diputuskan
    $batasDok = '(' . api_syarat_perpindahan($pdo, $p, 'pp')
              . ' OR ' . api_syarat_persetujuan_saya($pdo, $p, 'pp') . ')';

    $n = (int) $pdo->query(
        "SELECT COUNT(*) FROM perpindahan pp
         WHERE pp.status = 'menunggu_persetujuan' AND $batasDok")->fetchColumn();
    if ($n > 0) {
        $daftar[] = butir_notifikasi('persetujuan', 'Menunggu persetujuan',
            "$n dokumen perpindahan belum diputuskan.", 'persetujuan', $n, 'penting');
    }

    // ------------------------------------------------- kiriman menunggu tiba
    $n = (int) $pdo->query(
        "SELECT COUNT(*) FROM perpindahan pp
         WHERE pp.status = 'dikirim' AND $batasDok")->fetchColumn();
    if ($n > 0) {
        $daftar[] = butir_notifikasi('penerimaan', 'Kiriman di jalan',
            "$n surat jalan menunggu dihitung dan dikonfirmasi tiba.",
            'penerimaan', $n, 'waspada');
    }

    // --------------------------------------------------------- kedaluwarsa
    /* Toko dan supplier tidak diberi peringatan kedaluwarsa gudang —
       stoknya bukan tanggung jawab mereka, dan lonceng yang berisi
       pekerjaan orang lain cepat diabaikan seluruhnya. */
    if (!api_eksternal($p)) {
        $st = $pdo->query(
            "SELECT
                SUM(CASE WHEN bb.tanggal_expired <  CURDATE() THEN 1 ELSE 0 END) AS lewat,
                SUM(CASE WHEN bb.tanggal_expired >= CURDATE()
                          AND bb.tanggal_expired <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                         THEN 1 ELSE 0 END) AS minggu_ini,
                SUM(CASE WHEN bb.tanggal_expired >  DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                          AND bb.tanggal_expired <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                         THEN 1 ELSE 0 END) AS bulan_ini
             FROM batch_barang bb
             WHERE bb.qty_tersisa > 0 AND bb.tanggal_expired IS NOT NULL
               AND bb.tanggal_expired <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
               AND $syaratRak");
        $e = $st->fetch() ?: [];

        $lewat = (int) ($e['lewat'] ?? 0);
        if ($lewat > 0) {
            $daftar[] = butir_notifikasi('expired', 'Sudah kedaluwarsa',
                "$lewat batch masih tercatat sebagai stok padahal tanggalnya sudah lewat. "
              . 'Keluarkan lewat Perbaikan & Pelepasan.',
                'pelepasan', $lewat, 'penting');
        }
        $minggu = (int) ($e['minggu_ini'] ?? 0);
        if ($minggu > 0) {
            $daftar[] = butir_notifikasi('expired_h7', 'Kedaluwarsa pekan ini',
                "$minggu batch kedaluwarsa dalam 7 hari. Salurkan lebih dulu.",
                'monitoring', $minggu, 'penting');
        }
        $bulan = (int) ($e['bulan_ini'] ?? 0);
        if ($bulan > 0) {
            $daftar[] = butir_notifikasi('expired_h30', 'Kedaluwarsa bulan ini',
                "$bulan batch kedaluwarsa dalam 30 hari.",
                'monitoring', $bulan, 'waspada');
        }

        // ------------------------------------------------------------ stok
        /* Dihitung dari batch di rak sendiri, bukan dari stok.qty_total.
           Bagi petugas gudang, yang berarti adalah yang ada di raknya —
           angka lembaga membuatnya menawarkan barang yang fisiknya ada di
           gudang lain. */
        $st = $pdo->query(
            "SELECT
                SUM(CASE WHEN x.qty <= 0 THEN 1 ELSE 0 END) AS habis,
                SUM(CASE WHEN x.qty > 0 AND x.qty <= b.stok_minimum THEN 1 ELSE 0 END) AS menipis
             FROM barang b
             JOIN (SELECT bb.barang_id, COALESCE(SUM(bb.qty_tersisa),0) AS qty
                     FROM batch_barang bb
                    WHERE $syaratRak
                    GROUP BY bb.barang_id) x ON x.barang_id = b.id
             WHERE b.deleted_at IS NULL AND b.is_active = 1");
        $s = $st->fetch() ?: [];

        $habis = (int) ($s['habis'] ?? 0);
        if ($habis > 0) {
            $daftar[] = butir_notifikasi('stok_habis', 'Stok habis',
                "$habis produk sudah tidak ada sisanya di rak Anda.",
                'barang', $habis, 'penting');
        }
        $menipis = (int) ($s['menipis'] ?? 0);
        if ($menipis > 0) {
            $daftar[] = butir_notifikasi('stok_menipis', 'Stok menipis',
                "$menipis produk sudah di bawah batas minimumnya.",
                'barang', $menipis, 'waspada');
        }

        // ------------------------------------------------------- perbaikan
        $st = $pdo->query(
            "SELECT COUNT(*) FROM pelepasan_stok
             WHERE jenis = 'perbaikan' AND status = 'proses'
               AND DATEDIFF(CURDATE(), tanggal) > 14");
        $lama = (int) $st->fetchColumn();
        if ($lama > 0) {
            $daftar[] = butir_notifikasi('perbaikan_lama', 'Perbaikan menggantung',
                "$lama barang sudah lebih dari dua pekan di perbaikan tanpa keputusan.",
                'pelepasan', $lama, 'waspada');
        }
    }

    /* Diurutkan menurut tingkat kepentingan, bukan waktu. Lonceng dibuka
       untuk tahu "apa yang harus dikerjakan sekarang", dan yang paling
       mendesak harus berada di puncak tanpa perlu digulir. */
    $urutan = ['penting' => 0, 'waspada' => 1, 'info' => 2];
    usort($daftar, function ($a, $b) use ($urutan) {
        $ta = $urutan[$a['tingkat']] ?? 9;
        $tb = $urutan[$b['tingkat']] ?? 9;
        return $ta === $tb ? $b['jumlah'] <=> $a['jumlah'] : $ta <=> $tb;
    });

    return [
        'data'    => $daftar,
        // Lencana menghitung BUTIR, bukan jumlah barangnya. "17" di atas
        // lonceng membuat orang mengira ada 17 hal berbeda yang menunggu,
        // padahal itu bisa satu masalah berisi 17 batch.
        'jumlah'  => count($daftar),
        'penting' => count(array_filter($daftar, fn($d) => $d['tingkat'] === 'penting')),
    ];
}

/** Halaman web yang dituju tiap rute notifikasi. */
function tautan_notifikasi(string $rute): string {
    $peta = [
        'persetujuan' => 'persetujuan.php',
        'penerimaan'  => 'penerimaan.php',
        'monitoring'  => 'monitoring.php',
        'pelepasan'   => 'pelepasan.php',
        'barang'      => 'barang.php',
    ];
    return $peta[$rute] ?? 'dashboard.php';
}
