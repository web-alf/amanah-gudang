<?php
/**
 * AMANAH — Stok yang masih bisa dikirim dari lokasi asal (JSON, revisi 18 Okt 2026)
 *
 * stok_asal.php?asal_tipe=gudang|rak|toko&asal_id=N&jenis=…&b=1,2,3
 *   → {ok, barang: {id: {stok, pesan, bisa, dok:[…]}}}
 *
 * Dipakai formulir surat jalan untuk menulis "tersedia N" di bawah tiap baris
 * dan menahan simpan bila jumlah di formulir melebihi yang bisa dikirim.
 * Aturannya sama dengan pemeriksaan server (periksa_stok_dokumen di
 * _stok_pesan.php): stok lokasi asal dikurangi pesanan surat jalan lain yang
 * menunggu persetujuan atau sedang dikirim.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_login();
require_role(['super_admin', 'admin_gudang', 'admin_terpadu', 'toko', 'supplier']);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$tipe  = (string) ($_GET['asal_tipe'] ?? '');
$id    = (int) ($_GET['asal_id'] ?? 0);
$jenis = (string) ($_GET['jenis'] ?? '');
$ids   = array_slice(array_values(array_unique(array_filter(array_map('intval', explode(',', (string) ($_GET['b'] ?? '')))))), 0, 300);
if (!in_array($tipe, ['gudang', 'rak', 'toko'], true) || $id <= 0 || !$ids || !isset(rute_sah()[$jenis])) {
    echo json_encode(['ok' => true, 'barang' => new stdClass()]); exit;
}
/* Lokasi di luar lingkup akun tidak dijawab — sama dengan daftar lokasi di formulirnya. */
$boleh = true;
if ($tipe === 'gudang') { $boleh = in_array($id, array_map('intval', gudang_saya($pdo)), true) || is_super(); }
if ($tipe === 'rak')    { $boleh = in_array($id, array_map('intval', rak_saya($pdo)), true) || is_super(); }
if (!$boleh) { echo json_encode(['ok' => false, 'pesan' => 'Lokasi di luar lingkup akun.']); exit; }

try {
    $hanyaLayak = jenis_hanya_layak($jenis);
    $pesan = stok_dipesan($pdo, $tipe, $id, $ids, STATUS_MEMESAN_STOK);
    $h = [];
    foreach ($ids as $bid) {
        $stok = stok_lokasi_asal($pdo, $bid, $tipe, $id, $hanyaLayak);
        $p = (int) ($pesan[$bid]['qty'] ?? 0);
        $h[$bid] = ['stok' => $stok, 'pesan' => $p, 'bisa' => max(0, $stok - $p), 'dok' => array_slice($pesan[$bid]['dok'] ?? [], 0, 3)];
    }
    echo json_encode(['ok' => true, 'layak' => $hanyaLayak, 'barang' => $h], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[AMANAH] stok_asal: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'pesan' => 'Gagal membaca stok.']);
}
