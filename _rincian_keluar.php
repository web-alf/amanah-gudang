<?php
/**
 * AMANAH — Keperluan Barang Keluar & data Rincian Barang Keluar (revisi 18 Okt 2026)
 *
 * Permintaan tim: rincian barang keluar per bulan menurut keperluan (bayi,
 * karyawan, dapur, ruang kesehatan, finance) dan, untuk tiap gudang, ke
 * mana saja barangnya pergi (tim penjualan Bekasi, Gudang Rans, dll.).
 *
 * Dua sumber barang keluar dari sebuah gudang:
 *   A. Barang Keluar (keluar.php) — pemakaian langsung, dikelompokkan menurut
 *      KEPERLUAN (kolom baru transaksi_keluar.keperluan, migrasi 04).
 *   B. Surat jalan dari gudang itu — transfer gudang, kirim toko, penyaluran,
 *      retur — dikelompokkan menurut TUJUAN. Yang sudah diterima/selesai
 *      dihitung "terkirim", yang masih dikirim "dalam perjalanan".
 *
 * Dimuat dari ujung _lib.php; dipakai keluar.php dan laporan_keluar.php.
 */

const KEPERLUAN_KELUAR = [
    'bayi'            => 'Bayi',
    'karyawan'        => 'Karyawan',
    'dapur'           => 'Dapur',
    'ruang_kesehatan' => 'Ruang Kesehatan',
    'finance'         => 'Finance',
    'lainnya'         => 'Lainnya',
];

function label_keperluan(?string $k): string {
    return KEPERLUAN_KELUAR[(string) $k] ?? 'Belum dikategorikan';
}

/** Rentang satu bulan 'YYYY-MM' → [awal, akhir+1 hari) dalam 'Y-m-d'. */
function rentang_bulan(string $bulan): array {
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $bulan)) { $bulan = date('Y-m'); }
    $awal = $bulan . '-01';
    return [$awal, date('Y-m-d', strtotime($awal . ' +1 month')), $bulan];
}

/**
 * A. Barang Keluar per keperluan dalam satu bulan.
 * @param int[] $gudang  id gudang lingkup laporan (kosong = semua)
 * @return array [keperluan => ['unit','jenis','nilai','dok','barang' => [[nama,kode,satuan,qty,nilai]…]]]
 */
function rincian_keluar_keperluan(PDO $pdo, string $awal, string $akhir, array $gudang): array {
    $adaKol = kolom_ada($pdo, 'transaksi_keluar', 'keperluan');
    $kolK = $adaKol ? "COALESCE(NULLIF(tk.keperluan, ''), '')" : "''";
    $sy = $gudang ? ' AND lr.gudang_id IN (' . implode(',', array_map('intval', $gudang)) . ')' : '';
    $st = $pdo->prepare(
        "SELECT $kolK AS kep, d.barang_id, b.nama, b.kode, b.satuan, SUM(d.qty) AS qty, SUM(d.qty * b.harga) AS nilai,
                COUNT(DISTINCT tk.id) AS dok, GROUP_CONCAT(DISTINCT NULLIF(tk.divisi_cabang, '') SEPARATOR ', ') AS divisi
         FROM transaksi_keluar tk
         JOIN transaksi_keluar_detail d ON d.transaksi_keluar_id = tk.id
         JOIN barang b ON b.id = d.barang_id
         LEFT JOIN batch_barang bb ON bb.id = d.batch_id
         LEFT JOIN lokasi_rak lr ON lr.id = bb.lokasi_rak_id
         WHERE tk.tanggal >= ? AND tk.tanggal < ? $sy
         GROUP BY kep, d.barang_id ORDER BY kep, qty DESC");
    $st->execute([$awal, $akhir]);
    $h = [];
    foreach ($st->fetchAll() as $r) {
        $k = (string) $r['kep'];
        if (!isset($h[$k])) { $h[$k] = ['unit' => 0, 'jenis' => 0, 'nilai' => 0.0, 'dok' => 0, 'barang' => []]; }
        $h[$k]['unit'] += (int) $r['qty']; $h[$k]['jenis']++; $h[$k]['nilai'] += (float) $r['nilai'];
        $h[$k]['dok'] = max($h[$k]['dok'], (int) $r['dok']);
        $h[$k]['barang'][] = ['nama' => $r['nama'], 'kode' => $r['kode'], 'satuan' => $r['satuan'], 'qty' => (int) $r['qty'], 'nilai' => (float) $r['nilai']];
    }
    uasort($h, fn($a, $b) => $b['unit'] <=> $a['unit']);
    return $h;
}

/**
 * B. Surat jalan keluar dari gudang (transfer, kirim toko, penyaluran, retur) per tujuan.
 * @return array [kunciTujuan => ['nama','jenis','dari','terkirim','perjalanan','nilai','dok','barang'=>[…]]]
 */
function rincian_keluar_tujuan(PDO $pdo, string $awal, string $akhir, array $gudang): array {
    $syAsal = $gudang
        ? "((p.asal_tipe = 'gudang' AND p.asal_id IN (" . implode(',', array_map('intval', $gudang)) . "))
            OR (p.asal_tipe = 'rak' AND p.asal_id IN (SELECT id FROM lokasi_rak WHERE gudang_id IN (" . implode(',', array_map('intval', $gudang)) . "))))"
        : "p.asal_tipe IN ('gudang', 'rak')";
    $st = $pdo->prepare(
        "SELECT p.id, p.jenis, p.status, p.asal_tipe, p.asal_id, p.tujuan_tipe, p.tujuan_id, p.tujuan_teks, p.penerima,
                d.barang_id, b.nama, b.kode, b.satuan, b.harga,
                CASE WHEN p.status IN ('selesai', 'diterima') THEN COALESCE(d.qty_diterima, d.qty) ELSE d.qty END AS qty
         FROM perpindahan p
         JOIN perpindahan_detail d ON d.perpindahan_id = p.id
         JOIN barang b ON b.id = d.barang_id
         WHERE p.jenis IN ('transfer_gudang', 'kirim_toko', 'penyaluran', 'retur')
           AND p.status IN ('dikirim', 'selesai', 'diterima')
           AND p.tanggal >= ? AND p.tanggal < ? AND $syAsal");
    $st->execute([$awal, $akhir]);
    $h = []; $namaCache = [];
    foreach ($st->fetchAll() as $r) {
        $kunci = $r['tujuan_tipe'] . ':' . ($r['tujuan_id'] ?: mb_strtolower(trim((string) $r['tujuan_teks'])));
        if (!isset($h[$kunci])) {
            $ck = $r['tujuan_tipe'] . ':' . $r['tujuan_id'] . ':' . $r['tujuan_teks'];
            $namaCache[$ck] = $namaCache[$ck] ?? nama_lokasi($pdo, (string) $r['tujuan_tipe'], $r['tujuan_id'] ? (int) $r['tujuan_id'] : null, $r['tujuan_teks']);
            $h[$kunci] = ['nama' => $namaCache[$ck], 'tipe' => $r['tujuan_tipe'], 'jenis' => [], 'terkirim' => 0, 'perjalanan' => 0, 'nilai' => 0.0, 'dok' => [], 'barang' => []];
        }
        $q = (int) $r['qty'];
        $jalan = $r['status'] === 'dikirim';
        $h[$kunci][$jalan ? 'perjalanan' : 'terkirim'] += $q;
        $h[$kunci]['nilai'] += $q * (float) $r['harga'];
        $h[$kunci]['dok'][(int) $r['id']] = true;
        $h[$kunci]['jenis'][$r['jenis']] = true;
        $bid = (int) $r['barang_id'];
        if (!isset($h[$kunci]['barang'][$bid])) { $h[$kunci]['barang'][$bid] = ['nama' => $r['nama'], 'kode' => $r['kode'], 'satuan' => $r['satuan'], 'qty' => 0]; }
        $h[$kunci]['barang'][$bid]['qty'] += $q;
    }
    foreach ($h as &$t) {
        $t['dok'] = count($t['dok']);
        uasort($t['barang'], fn($a, $b) => $b['qty'] <=> $a['qty']);
        $t['barang'] = array_values($t['barang']);
    }
    unset($t);
    uasort($h, fn($a, $b) => ($b['terkirim'] + $b['perjalanan']) <=> ($a['terkirim'] + $a['perjalanan']));
    return $h;
}

/** Tren beberapa bulan terakhir: [bulan => ['keperluan' => unit, 'kirim' => unit]]. */
function rincian_keluar_tren(PDO $pdo, string $bulanAkhir, int $n, array $gudang): array {
    $h = [];
    for ($i = $n - 1; $i >= 0; $i--) {
        $b = date('Y-m', strtotime($bulanAkhir . '-01 -' . $i . ' month'));
        [$aw, $ak] = rentang_bulan($b);
        $h[$b] = [
            'keperluan' => array_sum(array_column(rincian_keluar_keperluan($pdo, $aw, $ak, $gudang), 'unit')),
            'kirim'     => array_sum(array_map(fn($t) => $t['terkirim'] + $t['perjalanan'], rincian_keluar_tujuan($pdo, $aw, $ak, $gudang))),
        ];
    }
    return $h;
}
