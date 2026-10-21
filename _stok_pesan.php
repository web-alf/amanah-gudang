<?php
/**
 * AMANAH — Stok yang sudah DIPESAN dokumen lain (revisi 18 Okt 2026)
 *
 * Masalah lapangan: dua surat jalan bisa sama-sama mengambil stok yang sama,
 * karena stok baru benar-benar berkurang saat dokumen disetujui (penyaluran,
 * mutasi) atau saat diterima di tujuan (transfer gudang, kirim toko).
 * Selama itu, angka stok terlihat utuh — surat jalan kedua lolos, lalu gagal
 * atau berselisih saat penerimaan, dan tim tidak tahu barang mana yang kurang.
 *
 * Sekarang stok yang sudah dicantumkan dokumen lain yang BELUM menerapkan
 * stoknya dihitung "dipesan", dan dikurangkan dari stok yang boleh diambil:
 *
 *   membuat surat jalan / mengirim draft  →  dipesan = menunggu_persetujuan + dikirim
 *   menyetujui dokumen                    →  dipesan = dikirim saja
 *       (dokumen yang menunggu tidak saling mengunci; yang disetujui lebih
 *        dulu yang mendapat stoknya, yang berikutnya ditolak dengan rincian)
 *
 * Draft tidak memesan stok (bisa ditinggalkan), tetapi diperiksa ulang saat
 * dikirim untuk persetujuan. Barang Keluar & penyesuaian tidak memeriksa
 * pesanan — keduanya langsung memotong stok.
 *
 * Dimuat dari ujung _lib.php.
 */

const STATUS_MEMESAN_STOK = ['menunggu_persetujuan', 'dikirim'];

/** Potongan SQL "dokumen ini mengambil dari lokasi asal ini" (id hasil intval, aman disisipkan). */
function syarat_asal_dokumen(string $tipe, int $id, string $a = 'p'): ?string {
    switch ($tipe) {
        case 'gudang': return "(($a.asal_tipe = 'gudang' AND $a.asal_id = $id) OR ($a.asal_tipe = 'rak' AND $a.asal_id IN (SELECT id FROM lokasi_rak WHERE gudang_id = $id)))";
        case 'rak':    return "($a.asal_tipe = 'rak' AND $a.asal_id = $id)";
        case 'toko':   return "($a.asal_tipe = 'toko' AND $a.asal_id = $id)";
        default:       return null;
    }
}

/**
 * Jumlah yang dipesan dokumen lain per barang di satu lokasi asal.
 * @return array<int, array{qty:int, dok:string[]}>
 */
function stok_dipesan(PDO $pdo, string $tipe, ?int $id, array $barangIds, array $status = STATUS_MEMESAN_STOK, ?int $kecualiPp = null): array {
    $barangIds = array_values(array_unique(array_filter(array_map('intval', $barangIds))));
    if (!$id || !$barangIds || !$status) { return []; }
    $syarat = syarat_asal_dokumen($tipe, (int) $id);
    if ($syarat === null) { return []; }
    $inB = implode(',', $barangIds);
    $inS = implode(',', array_map([$pdo, 'quote'], $status));
    $st = $pdo->prepare(
        "SELECT d.barang_id, SUM(d.qty) AS qty, GROUP_CONCAT(DISTINCT COALESCE(p.no_surat_jalan, p.no_perpindahan) ORDER BY p.id SEPARATOR ', ') AS dok
         FROM perpindahan_detail d JOIN perpindahan p ON p.id = d.perpindahan_id
         WHERE d.barang_id IN ($inB) AND p.status IN ($inS) AND $syarat AND p.id <> ?
         GROUP BY d.barang_id");
    $st->execute([(int) ($kecualiPp ?? 0)]);
    $h = [];
    foreach ($st->fetchAll() as $r) {
        $h[(int) $r['barang_id']] = ['qty' => (int) $r['qty'], 'dok' => array_filter(explode(', ', (string) $r['dok']))];
    }
    return $h;
}

/** Stok satu barang di lokasi asal (siap salur saja bila $hanyaLayak). */
function stok_lokasi_asal(PDO $pdo, int $barangId, string $tipe, ?int $id, bool $hanyaLayak): int {
    if ($hanyaLayak) {
        return (int) stok_layak_salur_lokasi($pdo, $barangId,
            $tipe === 'rak' ? (int) $id : null, $tipe === 'gudang' ? (int) $id : null, $tipe === 'toko' ? (int) $id : null);
    }
    switch ($tipe) {
        case 'gudang': return (int) stok_di_gudang($pdo, $barangId, (int) $id);
        case 'rak':    return (int) stok_tersedia($pdo, $barangId, (int) $id);
        case 'toko':   return (int) stok_di_toko($pdo, $barangId, (int) $id);
        default:       return (int) stok_tersedia($pdo, $barangId);
    }
}

/**
 * Periksa kecukupan stok sebuah dokumen (total per barang, seluruh baris —
 * isi semua kardus + barang tanpa kardus). Mengembalikan daftar pesan per
 * barang yang kurang; kosong = cukup.
 *
 * @param array $items [['barang_id' => int, 'qty' => int], …]
 */
function periksa_stok_dokumen(PDO $pdo, string $jenis, string $asalTipe, ?int $asalId, array $items,
                              array $statusPesan = STATUS_MEMESAN_STOK, ?int $kecualiPp = null): array {
    if ($jenis === '' || $jenis === 'penerimaan' || in_array($asalTipe, ['supplier', 'eksternal', ''], true) || !$asalId) { return []; }
    $total = [];
    foreach ($items as $it) {
        $bid = (int) ($it['barang_id'] ?? 0); $q = (int) ($it['qty'] ?? 0);
        if ($bid > 0 && $q > 0) { $total[$bid] = ($total[$bid] ?? 0) + $q; }
    }
    if (!$total) { return []; }
    $hanyaLayak = jenis_hanya_layak($jenis);
    $pesan = stok_dipesan($pdo, $asalTipe, (int) $asalId, array_keys($total), $statusPesan, $kecualiPp);
    $nama = [];
    foreach ($pdo->query("SELECT id, nama, kode, satuan FROM barang WHERE id IN (" . implode(',', array_keys($total)) . ")")->fetchAll() as $b) { $nama[(int) $b['id']] = $b; }
    $galat = [];
    foreach ($total as $bid => $diminta) {
        $ada   = stok_lokasi_asal($pdo, $bid, $asalTipe, (int) $asalId, $hanyaLayak);
        $dpsn  = (int) ($pesan[$bid]['qty'] ?? 0);
        $bisa  = max(0, $ada - $dpsn);
        if ($diminta <= $bisa) { continue; }
        $b = $nama[$bid] ?? ['nama' => "Barang #$bid", 'kode' => '', 'satuan' => 'unit'];
        $sat = $b['satuan'] ?: 'unit';
        $m = $b['nama'] . ($b['kode'] ? " ({$b['kode']})" : '') . ': diminta ' . angka($diminta) . " $sat, "
           . ($hanyaLayak ? 'stok siap salur' : 'stok') . ' di lokasi asal ' . angka($ada);
        if ($dpsn > 0) {
            $dok = $pesan[$bid]['dok'];
            $m .= ', sudah dipesan dokumen lain ' . angka($dpsn) . ' (' . implode(', ', array_slice($dok, 0, 3)) . (count($dok) > 3 ? ', …' : '') . ')';
        }
        $m .= ' — yang masih bisa dikirim ' . angka($bisa) . " $sat.";
        if ($hanyaLayak) { $m .= ' Barang Rusak/Bocor, Expired, dan tidak layak tidak ikut dihitung.'; }
        $galat[] = $m;
    }
    return $galat;
}

/** Baris detail sebuah dokumen dalam bentuk [['barang_id','qty'], …]. */
function baris_stok_dokumen(PDO $pdo, int $ppId): array {
    $st = $pdo->prepare("SELECT barang_id, qty FROM perpindahan_detail WHERE perpindahan_id = ?");
    $st->execute([$ppId]);
    return $st->fetchAll();
}
